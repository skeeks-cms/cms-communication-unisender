<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\unisender;

use skeeks\cms\communication\mail\DeliveryEvent;
use skeeks\cms\communication\models\CmsCommMessageEvent;

/**
 * The protocol of Unisender Go webhooks
 * (https://godocs.unisender.ru/web-api-ref#callback-format):
 *
 * - the signature: `auth` is the MD5 of the raw body in which the value of
 *   `auth` is replaced by the API key of the user or project;
 * - the body may come gzipped (event_format json_post_gzip);
 * - `events_by_user[0].events[]`: `transactional_email_status` (job_id,
 *   metadata, email, status, event_time UTC, delivery_info) and
 *   `transactional_spam_block` (our servers blocked by a receiving domain);
 * - our endpoint answers 200 within 3 seconds, otherwise the service repeats
 *   the call every 10 minutes for 24 hours and then stops the webhook.
 */
final class WebhookReceiver
{
    /** The address of the webhook on the CRM site: `/~unisender/<connection id>`. */
    public const PATH = '/~unisender/';

    /** Status of Unisender Go => models\CmsCommMessageEvent::EVENT_* */
    public const STATUSES = [
        'accepted' => CmsCommMessageEvent::EVENT_ACCEPTED,
        'sent' => CmsCommMessageEvent::EVENT_SENT,
        'delivered' => CmsCommMessageEvent::EVENT_DELIVERED,
        'opened' => CmsCommMessageEvent::EVENT_OPENED,
        'clicked' => CmsCommMessageEvent::EVENT_CLICKED,
        'unsubscribed' => CmsCommMessageEvent::EVENT_UNSUBSCRIBED,
        'subscribed' => CmsCommMessageEvent::EVENT_OTHER,
        'soft_bounced' => CmsCommMessageEvent::EVENT_DEFERRED,
        'hard_bounced' => CmsCommMessageEvent::EVENT_BOUNCED,
        'spam' => CmsCommMessageEvent::EVENT_COMPLAINED,
    ];

    /** The largest body taken, packed or unpacked: a call of the service is far smaller. */
    public const MAX_BODY = 4 * 1024 * 1024;

    /**
     * The raw body as sent: a gzipped body is unpacked piece by piece, never
     * beyond MAX_BODY (a small archive must not unpack into the whole memory
     * before the signature is checked). Null — the body is too large or broken.
     */
    public static function body(string $raw): ?string
    {
        if (strlen($raw) > self::MAX_BODY) {
            return null;
        }
        if (strncmp($raw, "\x1f\x8b", 2) !== 0) {
            return $raw;
        }
        $inflate = @inflate_init(ZLIB_ENCODING_GZIP);
        if ($inflate === false) {
            return null;
        }
        $plain = '';
        // A piece of 4 KB unpacks into ~4 MB at most: the limit is checked after every piece.
        foreach (str_split($raw, 4096) as $piece) {
            $part = @inflate_add($inflate, $piece, ZLIB_SYNC_FLUSH);
            if ($part === false) {
                return null;
            }
            $plain .= $part;
            if (strlen($plain) > self::MAX_BODY) {
                return null;
            }
        }

        return $plain;
    }

    /** The signature of the body is made with this key. */
    public static function verify(string $body, string $apiKey): bool
    {
        $apiKey = trim($apiKey);
        $data = json_decode($body, true);
        $auth = is_array($data) ? (string)($data['auth'] ?? '') : '';
        if ($apiKey === '' || !preg_match('/^[0-9a-f]{32}$/i', $auth)) {
            return false;
        }

        return hash_equals(strtolower($auth), md5(str_replace($auth, $apiKey, $body)));
    }

    /**
     * Delivery events of a verified body in the common form.
     *
     * @return DeliveryEvent[]
     */
    public static function events(string $body, int $connectionId): array
    {
        $data = json_decode($body, true);
        $result = [];
        foreach ((array)($data['events_by_user'] ?? []) as $user) {
            foreach ((array)($user['events'] ?? []) as $item) {
                if (($item['event_name'] ?? '') !== 'transactional_email_status' || !is_array($item['event_data'] ?? null)) {
                    continue;
                }
                if ($event = self::event($item['event_data'], $connectionId)) {
                    $result[] = $event;
                }
            }
        }

        return $result;
    }

    /** Blocks of our servers by receiving domains: shown in the job report only. */
    public static function spamBlocks(string $body): array
    {
        $data = json_decode($body, true);
        $result = [];
        foreach ((array)($data['events_by_user'] ?? []) as $user) {
            foreach ((array)($user['events'] ?? []) as $item) {
                if (($item['event_name'] ?? '') === 'transactional_spam_block' && is_array($item['event_data'] ?? null)) {
                    $result[] = $item['event_data'];
                }
            }
        }

        return $result;
    }

    /** One status event; also a row of an event dump (UnisenderEventDump). */
    public static function event(array $data, int $connectionId): ?DeliveryEvent
    {
        $status = (string)($data['status'] ?? '');
        $address = trim((string)($data['email'] ?? ''));
        if ($status === '' || $address === '') {
            return null;
        }
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $info = is_array($data['delivery_info'] ?? null) ? $data['delivery_info'] : [];
        $deliveryStatus = (string)($info['delivery_status'] ?? '');
        $at = null;
        if (!empty($data['event_time'])) {
            $time = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$data['event_time'], new \DateTimeZone('UTC'));
            $at = $time ? $time->getTimestamp() : null;
        }
        $event = self::STATUSES[$status] ?? CmsCommMessageEvent::EVENT_OTHER;

        $detail = [];
        // ok_* codes only repeat the status; the reasons of failures are err_*.
        if ($deliveryStatus !== '' && strpos($deliveryStatus, 'ok_') !== 0) {
            $detail[] = UnisenderDriver::DELIVERY_STATUSES[$deliveryStatus] ?? $deliveryStatus;
        }
        if (!empty($info['destination_response'])) {
            $detail[] = 'ответ сервера получателя: '.mb_substr(trim((string)$info['destination_response']), 0, 500);
        }
        if ($status === 'subscribed') {
            $detail[] = 'подписался снова по ссылке Unisender Go';
        }

        $messageId = isset($metadata['sx_message']) && ctype_digit((string)$metadata['sx_message']) ? (int)$metadata['sx_message'] : null;
        // Personal details of the reader (IP, device) are not kept.
        $kept = array_intersect_key($data, array_flip(['job_id', 'metadata', 'email', 'status', 'event_time']));
        if ($info) {
            $kept['delivery_info'] = array_intersect_key($info, array_flip(['delivery_status', 'destination_response']));
        }

        return new DeliveryEvent([
            'provider' => UnisenderDriver::ID,
            'key' => sha1(implode('|', [(string)($data['job_id'] ?? ''), mb_strtolower($address), $status, (string)($data['event_time'] ?? ''),
                $deliveryStatus, (string)($data['url'] ?? '')])),
            'event' => $event,
            'at' => $at,
            'address' => $address,
            'detail' => $detail ? implode('; ', $detail) : null,
            'addressDead' => $status === 'hard_bounced' && in_array($deliveryStatus, UnisenderDriver::DEAD_ADDRESS, true),
            'messageId' => $messageId,
            'connectionId' => $connectionId,
            'data' => $kept,
        ]);
    }
}
