<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\unisender;

use skeeks\cms\communication\mail\DeliveryEvent;
use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\DeliveryEvents;
use skeeks\cms\communication\unisender\models\CmsCommUnisenderCallback;
use yii\db\IntegrityException;

/**
 * Receipt and applying of Unisender Go webhooks, like the updates of bots:
 * the web request stores the verified body with one insert (a repeated
 * identical call is not written twice) and pushes the job; the job applies
 * the stored calls in order, each in its own transaction, through
 * services\DeliveryEvents (a repeated event is a no-op there).
 */
final class UnisenderCallbacks
{
    public const JOB = 'comm.unisender.apply';

    /** Stores a verified body. @return bool false — the same call was stored before */
    public static function store(CmsCommConnection $connection, string $body): bool
    {
        $row = new CmsCommUnisenderCallback([
            'created_at' => time(),
            'cms_comm_connection_id' => (int)$connection->id,
            'body_hash' => sha1($body),
            'body' => $body,
            'status' => CmsCommUnisenderCallback::STATUS_NEW,
        ]);
        try {
            $row->save(false);
        } catch (IntegrityException $e) {
            return false;
        }

        return true;
    }

    /** Calls of the connection still to apply. */
    public static function waiting(CmsCommConnection $connection): bool
    {
        return CmsCommUnisenderCallback::find()
            ->andWhere(['cms_comm_connection_id' => (int)$connection->id, 'status' => CmsCommUnisenderCallback::STATUS_NEW])->exists();
    }

    public static function push(CmsCommConnection $connection): void
    {
        \Yii::$app->jobs->push(self::JOB, ['connectionId' => (int)$connection->id], ['siteId' => (int)$connection->cms_site_id]);
    }

    /**
     * Applies a portion of the waiting calls of the connection; the calls in
     * $skip (failed in this run) wait for the next run.
     *
     * @return array{calls: int, events: int, failed: int, failedIds: int[], blocks: array}
     */
    public static function apply(CmsCommConnection $connection, int $limit = 200, array $skip = []): array
    {
        $result = ['calls' => 0, 'events' => 0, 'failed' => 0, 'failedIds' => [], 'blocks' => []];
        $query = CmsCommUnisenderCallback::find()
            ->andWhere(['cms_comm_connection_id' => (int)$connection->id, 'status' => CmsCommUnisenderCallback::STATUS_NEW]);
        if ($skip) {
            $query->andWhere(['not in', 'id', array_map('intval', $skip)]);
        }
        $rows = $query->orderBy(['id' => SORT_ASC])->limit($limit)->all();
        foreach ($rows as $row) {
            $result['calls']++;
            try {
                $count = CmsCommUnisenderCallback::getDb()->transaction(function () use ($row, $connection, &$result) {
                    $count = 0;
                    foreach (WebhookReceiver::events((string)$row->body, (int)$connection->id) as $event) {
                        if (self::record($connection, $event)) {
                            $count++;
                        }
                    }
                    foreach (WebhookReceiver::spamBlocks((string)$row->body) as $block) {
                        $result['blocks'][] = $block;
                    }
                    // The raw body (with the IP and device of readers) is not kept once applied:
                    // its hash still stops a repeated call.
                    CmsCommUnisenderCallback::updateAll(['status' => CmsCommUnisenderCallback::STATUS_APPLIED, 'applied_at' => time(), 'events' => $count,
                        'attempts' => (int)$row->attempts + 1, 'error' => null, 'body' => ''], ['id' => (int)$row->id]);

                    return $count;
                });
                $result['events'] += $count;
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['failedIds'][] = (int)$row->id;
                $attempts = (int)$row->attempts + 1;
                CmsCommUnisenderCallback::updateAll([
                    'attempts' => $attempts,
                    'status' => $attempts >= CmsCommUnisenderCallback::MAX_ATTEMPTS ? CmsCommUnisenderCallback::STATUS_FAILED : CmsCommUnisenderCallback::STATUS_NEW,
                    'error' => mb_substr($e->getMessage(), 0, 1000),
                ], ['id' => (int)$row->id]);
                \Yii::warning('Вебхук Unisender Go #'.(int)$row->id.' не применён: '.$e->getMessage(), self::class);
            }
        }

        return $result;
    }

    /**
     * One event of the connection: our letter by its metadata, otherwise by
     * the job id of the send; only a letter of a mailbox of this connection.
     * The job id of the send equal to `provider_ref` of the letter proves it
     * (ids of the service are unique in the account). A letter without a known
     * send (the outcome was unknown) is taken only for an event of this
     * installation (`sx_origin`) and of its site (`sx_site`). An event of
     * another installation or send touches no letter and no stop list.
     */
    public static function record(CmsCommConnection $connection, DeliveryEvent $event): bool
    {
        $metadata = is_array($event->data['metadata'] ?? null) ? $event->data['metadata'] : [];
        $mailboxes = CmsCommAccount::find()->select('id')->andWhere(['cms_comm_connection_id' => (int)$connection->id]);
        $jobId = (string)($event->data['job_id'] ?? '');
        $message = null;
        if ($event->messageId) {
            $message = CmsCommMessage::find()->andWhere(['id' => $event->messageId, 'cms_comm_account_id' => $mailboxes])->one();
            $ref = $message ? (string)$message->provider_ref : '';
            $proven = $message && $jobId !== '' && $ref === $jobId;
            if ($message && !$proven) {
                $ours = (!isset($metadata['sx_origin']) || (string)$metadata['sx_origin'] === UnisenderDriver::origin())
                    && (!isset($metadata['sx_site']) || (int)$metadata['sx_site'] === (int)$message->cms_site_id);
                if (($jobId !== '' && $ref !== '') || !$ours) {
                    $message = null;
                }
            }
            if (!$message && isset($metadata['sx_origin']) && (string)$metadata['sx_origin'] !== UnisenderDriver::origin()) {
                // Another installation on the same account: not even an event without a letter.
                return false;
            }
        }
        // The job id finds only a letter sent without our metadata: a refused match above stays refused.
        if (!$message && !$event->messageId && $jobId !== '') {
            $message = CmsCommMessage::find()->andWhere(['provider_ref' => $jobId, 'cms_comm_account_id' => $mailboxes])
                ->orderBy(['id' => SORT_DESC])->one();
        }
        $event->messageId = $message ? (int)$message->id : null;

        return DeliveryEvents::record($event, $message ? (int)$message->cms_site_id : (int)$connection->cms_site_id) !== null;
    }
}
