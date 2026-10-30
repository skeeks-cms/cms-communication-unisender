<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\unisender;

use skeeks\cms\communication\campaign\MailboxCampaignChannel;
use skeeks\cms\communication\drivers\ConnectionDriver;
use skeeks\cms\communication\mail\DeliveryEvent;
use skeeks\cms\communication\mail\MailingServiceInterface;
use skeeks\cms\communication\mail\MailSendException;
use skeeks\cms\communication\mail\MailSessionInterface;
use skeeks\cms\communication\models\CmsCommCampaign;
use skeeks\cms\communication\models\CmsCommCampaignRecipient;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommMessageEvent;
use skeeks\cms\communication\services\DeliveryEvents;
use skeeks\cms\communication\services\MailingPolicy;
use skeeks\yii2\form\fields\BoolField;
use skeeks\yii2\form\fields\NumberField;
use skeeks\yii2\form\fields\SelectField;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Unisender Go (https://go.unisender.ru): letters leave through its Web API
 * (`email/send`), one call per letter, with our metadata (letter, campaign,
 * recipient), so the delivery events of its webhook come back to the right
 * letter (WebhookReceiver, services\DeliveryEvents). Only sending: replies
 * go to a working mailbox through Reply-To.
 *
 * The letter is the one the CRM made (personal text, offer link, marks and
 * the code of the visit, our opt-out link): the service neither tracks
 * openings nor rewrites links (track_read/track_links = 0, allowed by its
 * support), and with "Своя отписка" it adds no opt-out block of its own
 * (skip_unsubscribe, our List-Unsubscribe through options.unsubscribe_url).
 * Without it our link in an advertising letter becomes the service tag
 * {{UnsubscribeUrl}}: no block of the service either, the opt-out happens at
 * the service and comes back as the "unsubscribed" event.
 *
 * An advertising letter (with a topic) leaves only to a contact with consent
 * (MailingPolicy::MODE_SERVICE), whatever sent it.
 */
class UnisenderDriver extends ConnectionDriver implements MailingServiceInterface
{
    public const ID = 'unisender';

    /** Replies go to the working mailbox of the employee the letter is from. */
    public const REPLY_TO_SENDER = 'sender';
    /** Replies go to "Ответы направлять на" of the mailbox (one common address). */
    public const REPLY_TO_ACCOUNT = 'account';

    /** An ordinary letter (not a mailing) ends with our small grey opt-out line with the service tag. */
    public const PLAIN_UNSUBSCRIBE_LINE = 'line';
    /** An ordinary letter gets the standard opt-out block of the service. */
    public const PLAIN_UNSUBSCRIBE_SERVICE = 'service';
    /** An ordinary letter has no opt-out at all (skip_unsubscribe, allowed by the support only). */
    public const PLAIN_UNSUBSCRIBE_NONE = 'none';

    public $api_key = '';
    public $server = 'goapi.unisender.ru';
    public $reply_to_mode = self::REPLY_TO_SENDER;
    public $tracking_off = 1;
    public $own_unsubscribe = 0;
    public $plain_unsubscribe = self::PLAIN_UNSUBSCRIBE_LINE;
    public $per_minute = 60;
    public $per_day = 0;

    /** @var callable|null HTTP transport of tests (UnisenderApi) */
    public $transport;

    /** Delivery statuses of Unisender Go meaning that the address itself takes no letters. */
    public const DEAD_ADDRESS = ['err_user_unknown', 'err_user_inactive', 'err_mailbox_discarded', 'err_domain_inactive', 'err_unreachable'];

    /** Readable delivery statuses (https://godocs.unisender.ru/web-api-ref#callback-format). */
    public const DELIVERY_STATUSES = [
        'err_user_unknown' => 'адрес не существует',
        'err_user_inactive' => 'адрес не используется',
        'err_will_retry' => 'временный отказ, будет ещё попытка',
        'err_mailbox_discarded' => 'адрес удалён',
        'err_mailbox_full' => 'ящик переполнен',
        'err_spam_rejected' => 'письмо отвергнуто как спам',
        'err_blacklisted' => 'адрес или домен отправителя в чёрном списке',
        'err_too_large' => 'письмо слишком большое',
        'err_unsubscribed' => 'адресат ранее отписался',
        'err_unreachable' => 'адрес постоянно недоступен',
        'err_skip_letter' => 'отправка отменена: адрес временно недоступен',
        'err_domain_inactive' => 'домен не принимает почту',
        'err_destination_misconfigured' => 'почта получателя настроена неверно',
        'err_delivery_failed' => 'доставка не удалась',
        'err_spam_skipped' => 'отправка отменена: рассылка заблокирована как спам',
        'err_lost' => 'письмо утеряно сервисом',
    ];

    /** Why the service did not take an address (failed_emails of email/send). */
    public const REFUSALS = [
        'unsubscribed' => 'адрес отписан в Unisender Go',
        'invalid' => 'адрес не существует или записан неверно',
        'duplicate' => 'адрес повторяется в письме',
        'temporary_unavailable' => 'адрес временно недоступен (3 суток)',
        'permanent_unavailable' => 'адрес недоступен: многократные недоставки',
        'complained' => 'адресат жаловался на письма',
        'blocked' => 'отправка на адрес запрещена Unisender Go',
    ];

    public static function title(): string
    {
        return 'Unisender Go';
    }

    public static function serviceName(): string
    {
        return 'Unisender Go';
    }

    public static function channel(): string
    {
        return 'email';
    }

    public function secretAttributes(): array
    {
        return ['api_key'];
    }

    public function rules()
    {
        return [
            [['api_key'], 'string', 'max' => 255],
            [['api_key'], 'filter', 'filter' => 'trim'],
            [['api_key'], 'required', 'when' => fn() => $this->requireSecrets],
            [['server'], 'in', 'range' => array_keys(UnisenderApi::SERVERS)],
            [['reply_to_mode'], 'in', 'range' => [self::REPLY_TO_SENDER, self::REPLY_TO_ACCOUNT]],
            [['tracking_off', 'own_unsubscribe'], 'boolean'],
            [['plain_unsubscribe'], 'in', 'range' => array_keys(self::plainUnsubscribeModes())],
            [['per_minute'], 'integer', 'min' => 1, 'max' => 3000],
            [['per_day'], 'integer', 'min' => 0],
        ];
    }

    public function attributeLabels()
    {
        return [
            'api_key' => 'API-ключ',
            'server' => 'Сервер API',
            'reply_to_mode' => 'Ответы клиентов',
            'tracking_off' => 'Учёт открытий и переходов у Unisender Go выключен',
            'own_unsubscribe' => 'Своя ссылка отписки в рассылках',
            'plain_unsubscribe' => 'Отписка в обычных письмах',
            'per_minute' => 'Писем в минуту',
            'per_day' => 'Писем в сутки',
        ];
    }

    public function attributeHints()
    {
        return [
            'api_key' => 'Личный кабинет Unisender Go → Аккаунт → Безопасность → API-ключ (или ключ проекта). Хранится в зашифрованном виде.',
            'server' => 'Тот, что в адресе личного кабинета: go1.unisender.ru или go2.unisender.ru.',
            'reply_to_mode' => 'Письма уходят с адреса этого ящика, а ответ клиента приходит в рабочий ящик и попадает в ленту сделки.',
            'tracking_off' => 'Открытия и переходы считает CRM. Выключить учёт у себя Unisender Go разрешает через свою поддержку: '
                .'пока не разрешили, выберите «Нет», иначе письма не уйдут.',
            'own_unsubscribe' => 'Ссылка «Отписаться» в письме рассылки ведёт на нашу страницу отписки, заголовок List-Unsubscribe — наш. Нужно разрешение поддержки Unisender Go '
                .'(«динамическое отключение ссылки отписки»), его дают не раньше чем через 2 недели регулярных отправок. Без него ссылка ведёт на страницу отписки '
                .'Unisender Go (их блока в конце письма нет), а отписку CRM узнаёт по вебхуку.',
            'plain_unsubscribe' => 'Обычное письмо — переписка с клиентом, не рассылка. Unisender Go не отправляет письма без отписки: '
                .'либо наша маленькая строка «Отписаться от писем» внизу, либо его стандартный блок. «Без отписки» — только после разрешения поддержки '
                .'(«динамическое отключение ссылки отписки»), иначе письма не уйдут.',
            'per_minute' => 'Скорость рассылки кампании через этот ящик.',
            'per_day' => '0 — без ограничения (сколько позволяет тариф). Бесплатный тариф: 100 писем в сутки и только на свои домены.',
        ];
    }

    public function getConfigFormFields()
    {
        return [
            'api_key',
            'server' => ['class' => SelectField::class, 'items' => UnisenderApi::SERVERS, 'allowNull' => false],
            'reply_to_mode' => ['class' => SelectField::class, 'allowNull' => false, 'items' => [
                self::REPLY_TO_SENDER => 'В рабочий ящик сотрудника, от чьего имени письмо',
                self::REPLY_TO_ACCOUNT => 'На адрес из «Ответы направлять на» этого ящика',
            ]],
            'tracking_off' => ['class' => BoolField::class, 'allowNull' => false],
            'own_unsubscribe' => ['class' => BoolField::class, 'allowNull' => false],
            'plain_unsubscribe' => ['class' => SelectField::class, 'allowNull' => false, 'items' => self::plainUnsubscribeModes()],
            'per_minute' => ['class' => NumberField::class],
            'per_day' => ['class' => NumberField::class],
        ];
    }

    /** @var string|null the id of this installation, read once per process */
    private static ?string $origin = null;

    /**
     * The installation that sent a letter (metadata `sx_origin`): letter ids of
     * two databases sharing one account of the service (a stand with a copy of
     * the site's database, two sites) are the same numbers, an event of another
     * installation must not touch our letter. A random id made once and kept
     * in a file of the installation (`runtime/unisender-origin`, the param
     * `unisenderOriginFile`), never in the database: a copied database keeps
     * its own id on the stand.
     */
    public static function origin(): string
    {
        if (self::$origin !== null) {
            return self::$origin;
        }
        $file = (string)(\Yii::$app->params['unisenderOriginFile']
            ?? (defined('ROOT_DIR') ? ROOT_DIR.'/runtime/unisender-origin' : \Yii::getAlias('@runtime/unisender-origin')));
        $id = is_readable($file) ? trim((string)file_get_contents($file)) : '';
        if (!preg_match('/^[0-9a-f]{16}$/', $id)) {
            if (!is_dir(dirname($file))) {
                @mkdir(dirname($file), 0775, true);
            }
            // "x": only one process makes the id, the others read it.
            $handle = @fopen($file, 'x');
            if ($handle) {
                fwrite($handle, bin2hex(random_bytes(8)));
                fclose($handle);
            }
            $id = is_readable($file) ? trim((string)file_get_contents($file)) : '';
            if (!preg_match('/^[0-9a-f]{16}$/', $id)) {
                throw new \RuntimeException('Не удалось записать идентификатор установки в '.$file.': проверьте права на каталог.');
            }
        }

        return self::$origin = $id;
    }

    /**
     * The template engine "simple" of the service (kept on, see buildRequest)
     * reads `{{...}}` anywhere in the letter: `{{Name|text}}` of a client or
     * of a quoted letter would be replaced. The braces of people's text are
     * broken for it: in HTML by entities (the reader sees the same braces),
     * in plain text and the subject by an invisible zero-width space.
     */
    public static function literal(string $value, bool $html): string
    {
        if (strpos($value, '{{') === false && strpos($value, '}}') === false) {
            return $value;
        }
        if ($html) {
            return str_replace(['{{', '}}'], ['&#123;&#123;', '&#125;&#125;'], $value);
        }

        return str_replace(['{{', '}}'], ["{\u{200B}{", "}\u{200B}}"], $value);
    }

    public static function plainUnsubscribeModes(): array
    {
        return [
            self::PLAIN_UNSUBSCRIBE_LINE => 'Наша маленькая строка «Отписаться от писем»',
            self::PLAIN_UNSUBSCRIBE_SERVICE => 'Стандартный блок Unisender Go',
            self::PLAIN_UNSUBSCRIBE_NONE => 'Без отписки (нужно разрешение поддержки)',
        ];
    }

    public function api(): UnisenderApi
    {
        return new UnisenderApi((string)$this->api_key, (string)$this->server, $this->transport);
    }

    /* MailDriverInterface */

    public function checkConnection(): array
    {
        $results = [];
        try {
            $info = $this->api()->call('system/info');
            $text = 'Аккаунт '.($info['email'] ?? '').' (№'.($info['user_id'] ?? '?').')';
            if (!empty($info['project_name'])) {
                $text .= ', проект «'.$info['project_name'].'»';
            }
            if (isset($info['accounting']['emails_sent'])) {
                $text .= '. За период с '.($info['accounting']['period_start'] ?? '?').' отправлено писем: '.(int)$info['accounting']['emails_sent']
                    .' из '.(int)($info['accounting']['emails_included'] ?? 0).' по тарифу';
            } elseif (isset($info['project_accounting']['email_counter'])) {
                $limit = (int)($info['project_accounting']['email_counter_limit'] ?? 0);
                $text .= '. Отправлено проектом: '.(int)$info['project_accounting']['email_counter'].($limit ? ' из '.$limit : '');
            }
            $results[] = ['label' => 'API Unisender Go', 'ok' => true, 'message' => $text.'.'];
        } catch (\Throwable $e) {
            return [['label' => 'API Unisender Go', 'ok' => false, 'message' => $e->getMessage()]];
        }

        try {
            // Exactly the address of this connection, on this site when the host is known.
            $id = $this->getConnectionId();
            if (!$id) {
                throw new \RuntimeException('сохраните подключение: адрес вебхука содержит его номер.');
            }
            $host = \Yii::$app->has('request') && \Yii::$app->request instanceof \yii\web\Request
                ? (string)parse_url((string)\Yii::$app->request->hostInfo, PHP_URL_HOST) : '';
            $hooks = [];
            for ($offset = 0; $offset < 1000; $offset += 50) {
                $page = (array)($this->api()->call('webhook/list', ['limit' => 50, 'offset' => $offset])['objects'] ?? []);
                foreach ($page as $hook) {
                    $url = (string)($hook['url'] ?? '');
                    if (rtrim((string)parse_url($url, PHP_URL_PATH), '/') === WebhookReceiver::PATH.$id
                        && strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'https'
                        && ($host === '' || strcasecmp((string)parse_url($url, PHP_URL_HOST), $host) === 0)) {
                        $hooks[] = $hook;
                    }
                }
                if (count($page) < 50) {
                    break;
                }
            }
            if (!$hooks) {
                $results[] = ['label' => 'Вебхук событий доставки', 'ok' => false,
                    'message' => 'Не подключён: без него CRM не узнает о доставке, отказах и жалобах. Подключить на сайте CRM: php yii unisender/webhook <адрес этого ящика>.'];
            }
            foreach ($hooks as $hook) {
                $events = (array)($hook['events']['email_status'] ?? []);
                $missing = array_diff(['delivered', 'hard_bounced', 'spam', 'unsubscribed'], $events);
                $active = ($hook['status'] ?? '') === 'active' && (!$events || in_array('*', $events, true) || !$missing);
                $results[] = ['label' => 'Вебхук событий доставки', 'ok' => $active, 'message' => (string)$hook['url'].' — '
                    .($active ? 'работает' : (($hook['status'] ?? '') === 'stopped' ? 'остановлен Unisender Go: адрес сутки не отвечал' : 'выключен'))
                    .(empty($hook['delivery_info']) ? '. Включите «подробности доставки» (delivery_info), чтобы видеть причины отказов.' : '')
                    .($events && !in_array('*', $events, true) && $missing ? '. Не передаются события: '.implode(', ', $missing)
                        .' — поставьте вебхук заново командой unisender/webhook.' : '')];
            }
        } catch (\Throwable $e) {
            $results[] = ['label' => 'Вебхук событий доставки', 'ok' => false, 'message' => 'Не удалось проверить: '.$e->getMessage()];
        }

        return $results;
    }

    public function openSession(): MailSessionInterface
    {
        return new NullMailSession();
    }

    public function send(Email $email): void
    {
        $messageId = self::messageIdOf($email);
        $message = $messageId !== '' ? CmsCommMessage::find()
            ->andWhere(['external_id' => $messageId, 'direction' => CmsCommMessage::DIRECTION_OUT])
            ->orderBy(['id' => SORT_DESC])->one() : null;
        if ($message) {
            $this->guard($message, $email);
        }
        $request = $this->buildRequest($email, $message);

        try {
            $result = $this->api()->call('email/send', ['message' => $request], 60);
        } catch (UnisenderApiException $e) {
            $refused = array_change_key_case((array)($e->data['failed_emails'] ?? []), CASE_LOWER);
            if ($message && $refused) {
                // Every recipient refused (code 204): the reasons still reach the letter and the stop list.
                $this->recordRefusals($message, $refused);
                throw MailSendException::failed('Unisender Go не принял письмо: '.self::refusalText($refused));
            }
            if ($e->isTemporary()) {
                throw MailSendException::retryLater($e->getMessage(), $e->retryAfter ?: 60);
            }
            if ($e->status >= 500) {
                // The service may have taken it before failing: never sent again by itself.
                throw MailSendException::uncertain('Unisender Go ответил ошибкой сервера: '.$e->getMessage());
            }
            throw MailSendException::failed($e->getMessage());
        } catch (\RuntimeException $e) {
            throw MailSendException::uncertain($e->getMessage());
        }

        $failed = array_change_key_case((array)($result['failed_emails'] ?? []), CASE_LOWER);
        if ($message && $failed) {
            $this->recordRefusals($message, $failed);
        }
        $accepted = array_map('mb_strtolower', (array)($result['emails'] ?? []));
        if ($message && !empty($result['job_id'])) {
            CmsCommMessage::updateAll(['provider_ref' => mb_substr((string)$result['job_id'], 0, 255)], ['id' => (int)$message->id]);
        }
        if (!$accepted && $failed) {
            throw MailSendException::failed('Unisender Go не принял письмо: '.self::refusalText($failed));
        }
    }

    /**
     * Rules of a mailing service checked at the last moment, whatever sent
     * the letter: advertising only with consent, campaign letters only of
     * the service channel.
     */
    private function guard(CmsCommMessage $message, Email $email): void
    {
        if ($message->cms_comm_campaign_id) {
            $campaign = CmsCommCampaign::findOne((int)$message->cms_comm_campaign_id);
            if ($campaign && $campaign->channel === MailboxCampaignChannel::ID) {
                throw MailSendException::failed('Кампания «ящиков менеджеров» не отправляет через сервис рассылок: выберите в ней канал «Сервис рассылок».');
            }
        }
        if (!$message->hasAttribute('cms_comm_topic_id') || !$message->cms_comm_topic_id) {
            return;
        }
        foreach (self::recipients($email) as $address => $name) {
            $check = MailingPolicy::check((int)$message->cms_site_id, 'email', $address, (int)$message->cms_comm_topic_id, MailingPolicy::MODE_SERVICE);
            if (!$check['allowed']) {
                throw MailSendException::failed('Через сервис рассылок рекламное письмо уходит только тем, кто дал согласие. '
                    .$address.': '.(MailingPolicy::reasons()[$check['reason']] ?? (string)$check['reason']).'.');
            }
        }
    }

    /**
     * The body of `email/send` for one letter: one recipient entry per
     * address with our metadata, the visible To and CC as headers.
     */
    public function buildRequest(Email $email, ?CmsCommMessage $message): array
    {
        $from = $email->getFrom()[0] ?? null;
        if (!$from instanceof Address) {
            throw MailSendException::failed('У письма нет адреса отправителя.');
        }
        $html = $email->getHtmlBody();
        $html = is_resource($html) ? (string)stream_get_contents($html) : (string)$html;
        $text = $email->getTextBody();
        $text = is_resource($text) ? (string)stream_get_contents($text) : (string)$text;
        if ($html !== '' && stripos($html, '<body') === false) {
            // Unisender Go refuses HTML without <body> (API code 221).
            $html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>';
        }
        // The text of people stays literal for the template engine of the service; only our tag is added after.
        $html = self::literal($html, true);
        $text = self::literal($text, false);
        $unsubscribe = $message && $message->hasAttribute('unsubscribe_url') ? (string)$message->unsubscribe_url : '';
        if ($unsubscribe !== '' && !$this->own_unsubscribe) {
            [$html, $text] = self::serviceUnsubscribe($html, $text, $unsubscribe);
        } elseif ($unsubscribe === '' && $this->plain_unsubscribe === self::PLAIN_UNSUBSCRIBE_LINE) {
            [$html, $text] = self::serviceUnsubscribe($html, $text);
        }

        $metadata = [];
        if ($message) {
            $metadata = ['sx_message' => (string)(int)$message->id, 'sx_site' => (string)(int)$message->cms_site_id, 'sx_origin' => self::origin()];
            if ($message->cms_comm_campaign_id) {
                $metadata['sx_campaign'] = (string)(int)$message->cms_comm_campaign_id;
                // The system key groups the letters of one campaign in the reports of Unisender Go.
                $metadata['campaign_id'] = (string)(int)$message->cms_comm_campaign_id;
                $recipientId = CmsCommCampaignRecipient::find()->select('id')->andWhere(['cms_comm_message_id' => (int)$message->id])->scalar();
                if ($recipientId) {
                    $metadata['sx_recipient'] = (string)(int)$recipientId;
                }
            }
        }
        $recipients = [];
        foreach (self::recipients($email) as $address => $name) {
            $recipients[] = ['email' => $address] + ($metadata ? ['metadata' => $metadata] : []);
        }
        if (!$recipients) {
            throw MailSendException::failed('У письма нет получателей.');
        }

        $request = [
            'recipients' => $recipients,
            'subject' => self::literal((string)$email->getSubject(), false),
            'from_email' => $from->getAddress(),
            'from_name' => $from->getName(),
            'body' => array_filter(['html' => $html, 'plaintext' => $text], static fn($v) => $v !== ''),
            // The default template engine ("simple", {{ }} only) stays on: with "none" the service
            // leaves its own Sender header unsubstituted ("${_nameFrom}"), verified 30.10.2026.
            'global_language' => 'ru',
            'tags' => [$message && $message->cms_comm_campaign_id ? 'crm-campaign-'.(int)$message->cms_comm_campaign_id : 'crm'],
            'headers' => array_filter([
                'To' => self::addressList($email->getTo()),
                'CC' => self::addressList($email->getCc()),
                'X-Sx-Message-Id' => self::messageIdOf($email),
            ], static fn($v) => $v !== ''),
        ];
        if ($key = self::messageIdOf($email)) {
            // A repeated call with the same key within a minute is refused by the service.
            $request['idempotence_key'] = sha1($key);
        }
        if ($replyTo = $this->replyTo($email, $message)) {
            $request['reply_to'] = $replyTo->getAddress();
            if ($replyTo->getName() !== '') {
                $request['reply_to_name'] = $replyTo->getName();
            }
        }
        if ($this->tracking_off) {
            $request['track_links'] = 0;
            $request['track_read'] = 0;
        }
        if ($this->own_unsubscribe && $unsubscribe !== '') {
            $request['skip_unsubscribe'] = 1;
            $request['options'] = ['unsubscribe_url' => $unsubscribe];
        } elseif ($unsubscribe === '' && $this->plain_unsubscribe === self::PLAIN_UNSUBSCRIBE_NONE) {
            $request['skip_unsubscribe'] = 1;
        }
        $files = $this->attachments($email);
        if ($files['attachments']) {
            $request['attachments'] = $files['attachments'];
        }
        if ($files['inline']) {
            $request['inline_attachments'] = $files['inline'];
        }

        return $request;
    }

    /**
     * Without "Своя отписка" our opt-out link becomes the service tag {{UnsubscribeUrl}}:
     * the service then adds no block of its own at the end of the letter, the address is
     * opted out at the service and comes back to the CRM as the "unsubscribed" event.
     * A letter without our link (ordinary correspondence, "Отписка в обычных письмах" =
     * our line) gets our small grey line with the tag instead of the block of the service.
     */
    public static function serviceUnsubscribe(string $html, string $text, string $url = ''): array
    {
        $tag = '{{UnsubscribeUrl}}';
        if ($url !== '') {
            $html = str_replace([htmlspecialchars($url, ENT_QUOTES, 'UTF-8'), $url], $tag, $html);
            $text = str_replace($url, $tag, $text);
        }
        if ($html !== '' && strpos($html, $tag) === false) {
            $line = '<div style="margin-top:28px;padding-top:12px;border-top:1px solid #e5e7eb;color:#8a8f98;font-size:12px;line-height:1.5">'
                .'<a href="'.$tag.'" style="color:#8a8f98">Отписаться от писем</a></div>';
            $html = stripos($html, '</body>') !== false ? (string)preg_replace('~</body>~i', $line.'</body>', $html, 1) : $html.$line;
        }
        if ($text !== '' && strpos($text, $tag) === false) {
            $text = rtrim($text)."\n\n--\nОтписаться от писем: ".$tag;
        }

        return [$html, $text];
    }

    /**
     * Reply-To of the letter: the working mailbox of the employee who sends
     * it (not a mailing service mailbox), otherwise "Ответы направлять на".
     */
    private function replyTo(Email $email, ?CmsCommMessage $message): ?Address
    {
        if ($this->reply_to_mode === self::REPLY_TO_SENDER && $message && $message->sent_by_user_id) {
            $mailbox = MailboxCampaignChannel::mailboxOf((int)$message->sent_by_user_id, (int)$message->cms_site_id);
            if ($mailbox && $mailbox->address) {
                return new Address((string)$mailbox->address, (string)$mailbox->name);
            }
        }

        return $email->getReplyTo()[0] ?? null;
    }

    /** @return array{attachments: array, inline: array} */
    private function attachments(Email $email): array
    {
        $result = ['attachments' => [], 'inline' => []];
        $names = [];
        foreach ($email->getAttachments() as $part) {
            if (!$part instanceof DataPart) {
                continue;
            }
            $type = $part->getMediaType().'/'.$part->getMediaSubtype();
            $content = base64_encode($part->getBody());
            $inline = method_exists($part, 'getDisposition') && $part->getDisposition() === 'inline' && $part->hasContentId();
            if ($inline) {
                // The text refers to it as cid:<content id>.
                $result['inline'][] = ['type' => $type, 'name' => $part->getContentId(), 'content' => $content];
                continue;
            }
            $name = str_replace('/', '_', (string)($part->getFilename() ?: 'file'));
            $base = $name;
            for ($i = 2; isset($names[mb_strtolower($name)]); $i++) {
                $dot = strrpos($base, '.');
                $name = $dot ? substr($base, 0, $dot).' ('.$i.')'.substr($base, $dot) : $base.' ('.$i.')';
            }
            $names[mb_strtolower($name)] = true;
            $result['attachments'][] = ['type' => $type, 'name' => $name, 'content' => $content];
        }

        return $result;
    }

    /** Addresses the service refused: events of the letter and the stop list, as if its webhook said so. */
    private function recordRefusals(CmsCommMessage $message, array $failed): void
    {
        $events = [
            'unsubscribed' => CmsCommMessageEvent::EVENT_UNSUBSCRIBED,
            'complained' => CmsCommMessageEvent::EVENT_COMPLAINED,
            'invalid' => CmsCommMessageEvent::EVENT_BOUNCED,
            'permanent_unavailable' => CmsCommMessageEvent::EVENT_BOUNCED,
        ];
        foreach ($failed as $address => $reason) {
            $event = $events[(string)$reason] ?? null;
            if (!$event) {
                continue;
            }
            try {
                DeliveryEvents::record(new DeliveryEvent([
                    'provider' => self::ID,
                    'key' => 'send:'.(int)$message->id.':'.sha1((string)$address),
                    'event' => $event,
                    'at' => time(),
                    'address' => (string)$address,
                    'detail' => 'Unisender Go не принял адрес: '.(self::REFUSALS[(string)$reason] ?? (string)$reason),
                    'addressDead' => $event === CmsCommMessageEvent::EVENT_BOUNCED,
                    'refused' => true,
                    'messageId' => (int)$message->id,
                    'connectionId' => $message->account ? (int)$message->account->cms_comm_connection_id : null,
                    'data' => ['failed_emails' => [(string)$address => (string)$reason]],
                ]), (int)$message->cms_site_id);
            } catch (\Throwable $e) {
                \Yii::warning('Отказ Unisender Go по адресу письма #'.(int)$message->id.' не записан: '.$e->getMessage(), self::class);
            }
        }
    }

    public static function refusalText(array $failed): string
    {
        $parts = [];
        foreach ($failed as $address => $reason) {
            $parts[] = $address.' — '.(self::REFUSALS[(string)$reason] ?? (string)$reason);
        }

        return implode('; ', $parts);
    }

    /** @return array address => name of To, CC and BCC, each once */
    private static function recipients(Email $email): array
    {
        $result = [];
        foreach (array_merge($email->getTo(), $email->getCc(), $email->getBcc()) as $address) {
            $result[mb_strtolower($address->getAddress())] = $address->getName();
        }

        return $result;
    }

    private static function addressList(array $addresses): string
    {
        return implode(', ', array_map(static fn(Address $a) => $a->getName() !== '' ? $a->getName().' <'.$a->getAddress().'>' : $a->getAddress(), $addresses));
    }

    private static function messageIdOf(Email $email): string
    {
        $header = $email->getHeaders()->get('Message-ID');

        return $header ? trim((string)$header->getBodyAsString(), '<> ') : '';
    }
}
