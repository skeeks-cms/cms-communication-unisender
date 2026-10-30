<?php
/**
 * The Unisender Go driver on an isolated SQLite memory database with a
 * recorded HTTP transport: answers and webhooks shaped by the official
 * documentation (https://godocs.unisender.ru/web-api-ref: email/send,
 * system/info, errors 102 and 429, the callback format with `auth`, the
 * event dump). No network, no site schema, no key of a real account.
 *
 * Covers: the key only in the header; the letter of the CRM as a request
 * (one call, our metadata, To/CC, Reply-To of the sender's working mailbox,
 * no tracking, our opt-out, files, idempotence key); refusals (wrong key,
 * "too many requests" — the letter waits, server errors — unknown outcome,
 * refused addresses — the stop list); the consent rule of advertising
 * letters; the webhook signature (right, wrong key, changed body, gzip),
 * storing a call once, applying events once (repeats of the service),
 * delivery state of the letter and the campaign recipient, a hard bounce
 * and a complaint to the stop list, an opt-out through the service, a
 * foreign letter; the webhook action (403, 200); the campaign channel
 * (consent only, senders, order, pace); the event dump; replies whose
 * Message-ID the service replaced.
 *
 * Usage: php tests/unisender.php [vendor/autoload.php]
 */

$autoloadCandidates = array_filter([$argv[1] ?? null, dirname(__DIR__, 3).'/autoload.php', '/app/vendor/autoload.php']);
foreach ($autoloadCandidates as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        require_once dirname($autoload).'/yiisoft/yii2/Yii.php';
        break;
    }
}
Yii::setAlias('@skeeks/cms/communication', dirname(__DIR__, 2).'/cms-communication/src');
Yii::setAlias('@skeeks/cms/communication/unisender', dirname(__DIR__).'/src');

use skeeks\cms\communication\campaign\MailboxCampaignChannel;
use skeeks\cms\communication\CommunicationComponent;
use skeeks\cms\communication\jobs\MailSendJobHandler;
use skeeks\cms\communication\mail\MailingServices;
use skeeks\cms\communication\mail\MailSendException;
use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommAccountAccess;
use skeeks\cms\communication\models\CmsCommCampaign;
use skeeks\cms\communication\models\CmsCommCampaignRecipient;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommContactTopic;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommMessageEvent;
use skeeks\cms\communication\models\CmsCommSuppression;
use skeeks\cms\communication\models\CmsCommTopic;
use skeeks\cms\communication\services\Campaigns;
use skeeks\cms\communication\services\MailIngestService;
use skeeks\cms\communication\services\MailingConsents;
use skeeks\cms\communication\services\MailingContacts;
use skeeks\cms\communication\services\MailingPolicy;
use skeeks\cms\communication\unisender\campaign\UnisenderCampaignChannel;
use skeeks\cms\communication\unisender\controllers\UnisenderWebhookController;
use skeeks\cms\communication\unisender\models\CmsCommUnisenderCallback;
use skeeks\cms\communication\unisender\UnisenderApi;
use skeeks\cms\communication\unisender\UnisenderCallbacks;
use skeeks\cms\communication\unisender\UnisenderDriver;
use skeeks\cms\communication\unisender\UnisenderEventDump;
use skeeks\cms\communication\unisender\WebhookReceiver;

function uniExpect($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Every employee is an administrator in this test. */
class UniTestAuth extends \yii\base\Component
{
    public function checkAccess($userId, $permission, $params = [])
    {
        return true;
    }
}

/** Records pushes instead of a queue. */
class UniTestJobs extends \yii\base\Component
{
    public array $pushed = [];

    public function push($type, array $payload = [], array $options = [])
    {
        $this->pushed[] = [$type, $payload, $options];

        return null;
    }
}

$originFile = sys_get_temp_dir().'/unisender-origin-test-'.getmypid();
@unlink($originFile);
register_shutdown_function(static fn() => @unlink($originFile));
new \yii\web\Application([
    'id' => 'cms-communication-unisender',
    'params' => ['unisenderOriginFile' => $originFile],
    'basePath' => sys_get_temp_dir(),
    'timeZone' => 'UTC',
    'components' => [
        'db' => ['class' => \yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'cache' => ['class' => \yii\caching\ArrayCache::class],
        'formatter' => ['class' => \yii\i18n\Formatter::class, 'timeZone' => 'Europe/Moscow'],
        'request' => ['cookieValidationKey' => 'test', 'enableCsrfValidation' => false],
        'user' => ['class' => \yii\web\User::class, 'identityClass' => \skeeks\cms\models\CmsUser::class, 'enableSession' => false],
        'skeeks' => ['class' => \skeeks\cms\Skeeks::class],
        'jobs' => ['class' => UniTestJobs::class],
        'authManager' => ['class' => UniTestAuth::class],
        'communication' => [
            'class' => CommunicationComponent::class,
            'secretKey' => 'test-secret-key-of-the-unisender-test',
            'drivers' => ['imap' => \skeeks\cms\communication\imap\ImapDriver::class, 'unisender' => UnisenderDriver::class],
            'campaignChannels' => ['mailbox' => MailboxCampaignChannel::class, 'unisender' => UnisenderCampaignChannel::class],
        ],
    ],
]);

// A web application (the webhook action) but console output of failures.
Yii::$app->errorHandler->unregister();
set_exception_handler(static function (\Throwable $e) {
    fwrite(STDERR, 'FAIL: '.$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n".$e->getTraceAsString()."\n");
    exit(1);
});

$db = Yii::$app->db;
$create = static fn(string $table, array $columns) => $db->createCommand()->createTable($table, $columns)->execute();
$create('cms_comm_connection', ['id' => 'pk', 'created_by' => 'integer', 'updated_by' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer',
    'cms_site_id' => 'integer', 'name' => 'string', 'driver' => 'string', 'driver_config' => 'text', 'secret' => 'text', 'is_active' => 'integer default 1']);
$create('cms_comm_account', ['id' => 'pk', 'created_by' => 'integer', 'updated_by' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer',
    'cms_site_id' => 'integer', 'cms_comm_connection_id' => 'integer', 'channel' => 'string', 'address' => 'string', 'name' => 'string', 'reply_to' => 'string',
    'is_active' => 'integer default 1', 'is_expose_linked' => 'integer default 1', 'mailing_settings' => 'text']);
$create('cms_comm_account_access', ['id' => 'pk', 'cms_comm_account_id' => 'integer', 'cms_user_id' => 'integer', 'read_level' => 'string', 'send_level' => 'string',
    'is_default' => 'integer default 0']);
$create('cms_comm_message', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_site_id' => 'integer', 'cms_comm_conversation_id' => 'integer',
    'cms_comm_account_id' => 'integer', 'channel' => 'string', 'direction' => 'string', 'status' => 'string', 'external_id' => 'string', 'cms_comm_topic_id' => 'integer',
    'cms_comm_campaign_id' => 'integer', 'provider_ref' => 'string', 'unsubscribe_url' => 'string', 'in_reply_to' => 'string', 'references_list' => 'text',
    'from_address' => 'string', 'from_name' => 'string', 'to_list' => 'text', 'cc_list' => 'text', 'bcc_list' => 'text', 'subject' => 'string', 'body_text' => 'text',
    'body_html' => 'text', 'attachments' => 'text', 'is_internal' => 'integer default 0', 'is_auto' => 'integer default 0', 'is_history' => 'integer default 0',
    'box_state' => 'string', 'message_at' => 'integer', 'sent_by_user_id' => 'integer', 'error' => 'text', 'delivery_status' => 'string', 'delivery_at' => 'integer']);
$create('cms_comm_message_search', ['cms_comm_message_id' => 'integer', 'cms_site_id' => 'integer', 'cms_comm_conversation_id' => 'integer', 'search_text' => 'text']);
$create('cms_comm_message_file', ['id' => 'pk', 'cms_comm_message_id' => 'integer', 'priority' => 'integer default 100', 'name' => 'string']);
$create('cms_comm_conversation', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_site_id' => 'integer', 'channel' => 'string',
    'thread_key' => 'string', 'subject' => 'string', 'last_message_at' => 'integer']);
$create('cms_comm_message_event', ['id' => 'pk', 'created_at' => 'integer', 'cms_site_id' => 'integer', 'cms_comm_message_id' => 'integer', 'cms_comm_connection_id' => 'integer',
    'provider' => 'string', 'event_key' => 'string', 'event' => 'string', 'event_at' => 'integer', 'address' => 'string', 'detail' => 'string', 'data' => 'text']);
$db->createCommand()->createIndex('event_key', 'cms_comm_message_event', ['provider', 'event_key'], true)->execute();
$create('cms_comm_unisender_callback', ['id' => 'pk', 'created_at' => 'integer', 'cms_comm_connection_id' => 'integer', 'body_hash' => 'string', 'body' => 'text',
    'status' => "string default 'new'", 'attempts' => 'integer default 0', 'events' => 'integer', 'applied_at' => 'integer', 'error' => 'string']);
$db->createCommand()->createIndex('callback_hash', 'cms_comm_unisender_callback', ['cms_comm_connection_id', 'body_hash'], true)->execute();
$create('cms_comm_topic', ['id' => 'pk', 'created_by' => 'integer', 'updated_by' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer',
    'cms_site_id' => 'integer', 'name' => 'string', 'description' => 'text', 'is_active' => 'integer default 1', 'priority' => 'integer default 100']);
$create('cms_comm_contact', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_site_id' => 'integer', 'kind' => 'string', 'value' => 'string', 'code' => 'string']);
$create('cms_comm_contact_topic', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_comm_contact_id' => 'integer', 'cms_comm_topic_id' => 'integer',
    'status' => 'string', 'basis' => 'string', 'consent_at' => 'integer', 'consent_by' => 'integer', 'unsubscribed_at' => 'integer', 'unsubscribed_by' => 'integer',
    'unsubscribe_source' => 'string', 'cms_comm_message_id' => 'integer', 'note' => 'string']);
$create('cms_comm_suppression', ['id' => 'pk', 'created_at' => 'integer', 'created_by' => 'integer', 'cms_site_id' => 'integer', 'cms_comm_contact_id' => 'integer',
    'reason' => 'string', 'source' => 'string', 'cms_comm_message_id' => 'integer', 'note' => 'string']);
$create('cms_comm_campaign', ['id' => 'pk', 'created_by' => 'integer', 'updated_by' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer',
    'cms_site_id' => 'integer', 'name' => 'string', 'status' => 'string', 'cms_comm_topic_id' => 'integer', 'audience_type' => 'string', 'audience' => 'text',
    'channel' => 'string', 'sender_mode' => 'string', 'cms_comm_account_id' => 'integer', 'subject' => 'string', 'body_html' => 'text', 'offer_kind' => 'string',
    'offer_type' => 'string', 'offer_id' => 'integer', 'task_on_open' => 'integer default 1', 'task_on_reply' => 'integer default 1', 'launched_at' => 'integer',
    'launched_by' => 'integer', 'finished_at' => 'integer', 'one_per_company' => 'integer default 0', 'quiet_days' => 'integer']);
$create('cms_comm_campaign_recipient', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_comm_campaign_id' => 'integer',
    'record_type' => 'string', 'record_id' => 'integer', 'address' => 'string', 'name' => 'string', 'subject' => 'string', 'body_html' => 'text',
    'text_by' => 'string', 'text_client' => 'string', 'text_user_id' => 'integer', 'text_at' => 'integer', 'text_edited_by' => 'integer', 'cms_comm_account_id' => 'integer',
    'sender_user_id' => 'integer', 'status' => 'string', 'reason' => 'string', 'error' => 'string', 'cms_comm_message_id' => 'integer', 'send_ref' => 'string',
    'sent_at' => 'integer', 'opened_at' => 'integer', 'replied_at' => 'integer', 'accepted_at' => 'integer', 'unsubscribed_at' => 'integer', 'visited_at' => 'integer',
    'delivered_at' => 'integer', 'bounced_at' => 'integer']);
$create('cms_lead', ['id' => 'pk', 'cms_site_id' => 'integer', 'name' => 'string', 'status' => 'string', 'source_type' => 'string', 'executor_id' => 'integer',
    'cms_company_id' => 'integer', 'cms_user_id' => 'integer']);
$create('cms_user', ['id' => 'pk', 'username' => 'string', 'first_name' => 'string', 'last_name' => 'string', 'patronymic' => 'string', 'is_company' => 'integer default 0',
    'company_name' => 'string', 'alias' => 'string', 'email' => 'string', 'phone' => 'string', 'image_id' => 'integer', 'work_shedule' => 'text', 'is_worker' => 'integer',
    'is_active' => 'integer default 1']);
$db->createCommand()->batchInsert('cms_user', ['id', 'username', 'first_name', 'is_worker'], [[7, 'anna', 'Анна', 1], [8, 'boris', 'Борис', 1], [1, 'admin', 'Админ', 1]])->execute();

/* Records requests, answers from a queue: the shapes of the documentation. */
$requests = [];
$responses = [];
$transport = static function (string $url, array $headers, string $body, int $timeout) use (&$requests, &$responses) {
    $requests[] = ['url' => $url, 'headers' => $headers, 'body' => json_decode($body, true), 'raw' => $body];
    $response = array_shift($responses) ?? ['status' => 200, 'body' => '{"status":"success"}'];
    if ($response instanceof \Throwable) {
        throw $response;
    }
    return $response + ['headers' => []];
};
$json = static fn(array $data, int $status = 200, array $headers = []) => ['status' => $status, 'body' => json_encode($data, JSON_UNESCAPED_UNICODE), 'headers' => $headers];
$apiKey = 'test-key-0123456789abcdef';

// The API: the key only in the header, the server of the account, the info of the account.
$api = new UnisenderApi($apiKey, 'go2.unisender.ru', $transport);
$responses[] = $json(['status' => 'success', 'user_id' => 11344, 'email' => 'user@example.com', 'accounting' => ['period_start' => '2026-10-01 00:00:00',
    'period_end' => '2026-11-01 00:00:00', 'emails_included' => 6000, 'emails_sent' => 12]]);
$info = $api->call('system/info');
uniExpect($info['user_id'] === 11344 && $requests[0]['url'] === 'https://go2.unisender.ru/ru/transactional/api/v1/system/info.json'
    && in_array('X-API-KEY: '.$apiKey, $requests[0]['headers'], true) && strpos($requests[0]['url'].$requests[0]['raw'], $apiKey) === false,
    'The key goes only in the header; the server of the account is used.');
uniExpect((new UnisenderApi($apiKey, 'evil.example.com', $transport)) instanceof UnisenderApi, 'An unknown server is replaced.');
$requests = [];
(new UnisenderApi($apiKey, 'evil.example.com', $transport))->call('system/info');
uniExpect(strpos($requests[0]['url'], 'https://goapi.unisender.ru/') === 0, 'The key never goes to a host that is not Unisender Go.');
$responses[] = $json(['status' => 'error', 'code' => 102, 'message' => 'Invalid API key'], 401);
try {
    $api->call('system/info');
    uniExpect(false, 'A wrong key is an error.');
} catch (\skeeks\cms\communication\unisender\UnisenderApiException $e) {
    uniExpect($e->apiCode === 102 && strpos($e->getMessage(), 'Неверный API-ключ') !== false && strpos($e->getMessage(), $apiKey) === false,
        'A wrong key reads as a reason a person can fix, without the key.');
}

// The connection: the key is stored encrypted and never in the public settings.
$connection = new CmsCommConnection(['cms_site_id' => 1, 'name' => 'news@skeeks.com', 'driver' => 'unisender', 'is_active' => 1]);
$driver = $connection->component()->createDriver('unisender');
$driver->setAttributes(['api_key' => $apiKey, 'server' => 'go1.unisender.ru', 'per_minute' => 2, 'per_day' => 0], false);
uniExpect($driver->validate(), 'Settings are valid: '.json_encode($driver->getErrors(), JSON_UNESCAPED_UNICODE));
$connection->applyDriver($driver);
$connection->save(false);
uniExpect(strpos((string)$connection->driver_config, $apiKey) === false && strpos((string)$connection->secret, $apiKey) === false
    && $connection->createDriver(true)->api_key === $apiKey && $connection->createDriver(false)->api_key === ''
    && !isset($connection->toArray()['secret']), 'The key is a secret: encrypted, out of settings, forms and the API.');
uniExpect(MailingServices::driverIds() === ['unisender'] && MailingServices::isServiceDriver('unisender') && !MailingServices::isServiceDriver('imap'),
    'Unisender Go is a mailing service driver, IMAP is not.');

$service = new CmsCommAccount(['cms_site_id' => 1, 'channel' => 'email', 'address' => 'news@skeeks.com', 'name' => 'SkeekS', 'reply_to' => 'info@skeeks.com',
    'cms_comm_connection_id' => $connection->id, 'is_active' => 1]);
$service->save(false);
$imap = new CmsCommConnection(['cms_site_id' => 1, 'name' => 'anna@skeeks.com', 'driver' => 'imap', 'is_active' => 1]);
$imap->save(false);
$work = new CmsCommAccount(['cms_site_id' => 1, 'channel' => 'email', 'address' => 'anna@skeeks.com', 'name' => 'Анна', 'cms_comm_connection_id' => $imap->id, 'is_active' => 1]);
$work->save(false);
foreach ([[$service->id, 7, 1], [$work->id, 7, 0], [$service->id, 1, 1]] as [$accountId, $userId, $default]) {
    (new CmsCommAccountAccess(['cms_comm_account_id' => $accountId, 'cms_user_id' => $userId, 'read_level' => 'all', 'send_level' => 'any', 'is_default' => $default]))->save(false);
}
uniExpect(MailingServices::isService($service) && !MailingServices::isService($work)
    && (int)MailboxCampaignChannel::mailboxOf(7, 1)->id === (int)$work->id, 'The working mailbox of an employee is never the mailing service, even when it is the default.');
uniExpect(\skeeks\cms\communication\forms\MailboxConnectionForm::modes()['service:unisender'] === 'Сервис рассылок Unisender Go — только отправка',
    'The mailbox form offers the mailing service as a kind of connection.');

// A letter of the CRM as one call of email/send.
$topic = new CmsCommTopic(['cms_site_id' => 1, 'name' => 'Новые решения', 'is_active' => 1]);
$topic->save(false);
$campaign = new CmsCommCampaign(['cms_site_id' => 1, 'name' => 'Плитка', 'status' => CmsCommCampaign::STATUS_RUNNING, 'cms_comm_topic_id' => $topic->id,
    'channel' => UnisenderCampaignChannel::ID, 'sender_mode' => CmsCommCampaign::SENDER_ACCOUNT, 'cms_comm_account_id' => $service->id, 'launched_at' => time() - 60]);
$campaign->save(false);
$letter = static function (array $attributes) use ($service) {
    $m = new CmsCommMessage($attributes + ['cms_site_id' => 1, 'cms_comm_conversation_id' => 1, 'cms_comm_account_id' => $service->id, 'channel' => 'email',
            'direction' => 'out', 'status' => 'sending', 'external_id' => 'sx.'.bin2hex(random_bytes(6)).'@skeeks.com', 'message_at' => time(),
            'subject' => 'Плитка: сайт за неделю', 'body_html' => '<p>Здравствуйте, {{ name }}! $5</p>', 'body_text' => 'Здравствуйте', 'sent_by_user_id' => 7,
            'to_list' => json_encode(['client@example.com' => 'Иван'])]);
    $m->save(false);

    return $m;
};
$m1 = $letter(['cms_comm_topic_id' => $topic->id, 'cms_comm_campaign_id' => $campaign->id, 'unsubscribe_url' => 'https://skeeks.com/~unsub/abcdefabcdefabcdef?t=1&m=1',
    'cc_list' => json_encode(['boss@example.com' => ''])]);
$r1 = new CmsCommCampaignRecipient(['cms_comm_campaign_id' => $campaign->id, 'record_type' => 'lead', 'record_id' => 1, 'address' => 'client@example.com',
    'status' => 'sent', 'cms_comm_message_id' => $m1->id, 'sent_at' => time(), 'cms_comm_account_id' => $service->id, 'sender_user_id' => 7]);
$r1->save(false);
$email = (new MailSendJobHandler())->buildEmail(CmsCommMessage::findOne($m1->id));
$driver = $connection->createDriver(true);
$driver->transport = $transport;
$request = $driver->buildRequest($email, CmsCommMessage::findOne($m1->id));
uniExpect(count($request['recipients']) === 2 && $request['recipients'][0]['email'] === 'client@example.com'
    && $request['recipients'][0]['metadata'] === ['sx_message' => (string)$m1->id, 'sx_site' => '1', 'sx_origin' => UnisenderDriver::origin(), 'sx_campaign' => (string)$campaign->id,
        'campaign_id' => (string)$campaign->id, 'sx_recipient' => (string)$r1->id],
    'Every address is a recipient with our metadata: letter, site, campaign, recipient.');
uniExpect($request['headers']['To'] === 'Иван <client@example.com>' && $request['headers']['CC'] === 'boss@example.com'
    && $request['from_email'] === 'news@skeeks.com' && $request['from_name'] === 'SkeekS' && !isset($request['template_engine'])
    && strpos($request['body']['html'], '<body>') !== false && strpos($request['body']['html'], '&#123;&#123; name &#125;&#125;! $5') !== false && strpos($request['body']['html'], '{{ name') === false && strpos($request['body']['plaintext'], 'Здравствуйте') === 0,
    'Visible To and CC, our sender, our text untouched by templates and wrapped in <body>.');
uniExpect(substr_count($request['body']['html'], '{{UnsubscribeUrl}}') === 1 && strpos($request['body']['html'], 'Отписаться от писем</a></div></body>') !== false
    && substr($request['body']['plaintext'], -strlen('Отписаться от писем: {{UnsubscribeUrl}}')) === 'Отписаться от писем: {{UnsubscribeUrl}}',
    'A letter without our link gets our small opt-out line with the service tag instead of the block of the service.');
uniExpect($request['reply_to'] === 'anna@skeeks.com' && $request['reply_to_name'] === 'Анна', 'Replies go to the working mailbox of the employee the letter is from.');
uniExpect($request['track_links'] === 0 && $request['track_read'] === 0 && !isset($request['skip_unsubscribe']) && strlen($request['idempotence_key']) === 40
    && $request['tags'] === ['crm-campaign-'.$campaign->id], 'No tracking of the service, its opt-out block stays without permission, a key against doubles.');
uniExpect(UnisenderDriver::literal('<p>{{Name|другой текст}}</p>', true) === '<p>&#123;&#123;Name|другой текст&#125;&#125;</p>'
    && UnisenderDriver::literal('Тема {{Email}}', false) === "Тема {\u{200B}{Email}\u{200B}}" && UnisenderDriver::literal('без скобок', false) === 'без скобок',
    'Braces of people are broken for the template engine of the service: entities in HTML, a zero-width space in text.');
$unsub = 'https://skeeks.com/~unsub/abcdefabcdefabcdef?t=1&m=1';
[$uHtml, $uText] = UnisenderDriver::serviceUnsubscribe('<p>Привет</p><a href="'.htmlspecialchars($unsub).'">Отписаться</a>', "Привет\nОтписаться: ".$unsub, $unsub);
uniExpect($uHtml === '<p>Привет</p><a href="{{UnsubscribeUrl}}">Отписаться</a>' && $uText === "Привет\nОтписаться: {{UnsubscribeUrl}}",
    'Without permission our opt-out link becomes the service tag, so the service adds no block of its own.');
$driver->own_unsubscribe = 1;
$driver->reply_to_mode = UnisenderDriver::REPLY_TO_ACCOUNT;
$request = $driver->buildRequest($email, CmsCommMessage::findOne($m1->id));
uniExpect($request['skip_unsubscribe'] === 1 && $request['options']['unsubscribe_url'] === 'https://skeeks.com/~unsub/abcdefabcdefabcdef?t=1&m=1'
    && strpos($request['body']['html'], '{{UnsubscribeUrl}}') === false && $request['body']['plaintext'] === 'Здравствуйте'
    && $request['reply_to'] === 'info@skeeks.com', 'With permission our opt-out is the only one; replies to the common address by the setting.');
$driver->own_unsubscribe = 0;
$driver->reply_to_mode = UnisenderDriver::REPLY_TO_SENDER;
// An ordinary letter (no mailing): the opt-out by "Отписка в обычных письмах".
$plain = $letter([]);
$plainEmail = (new MailSendJobHandler())->buildEmail(CmsCommMessage::findOne($plain->id));
$request = $driver->buildRequest($plainEmail, CmsCommMessage::findOne($plain->id));
uniExpect($driver->plain_unsubscribe === UnisenderDriver::PLAIN_UNSUBSCRIBE_LINE && strpos($request['body']['html'], 'href="{{UnsubscribeUrl}}"') !== false
    && !isset($request['skip_unsubscribe']), 'By default an ordinary letter ends with our small line, not with the block of the service.');
$driver->plain_unsubscribe = UnisenderDriver::PLAIN_UNSUBSCRIBE_SERVICE;
$request = $driver->buildRequest($plainEmail, CmsCommMessage::findOne($plain->id));
uniExpect(strpos($request['body']['html'], '{{UnsubscribeUrl}}') === false && !isset($request['skip_unsubscribe']) && $request['body']['plaintext'] === 'Здравствуйте',
    'With "the block of the service" the letter is untouched: the service adds its block.');
$driver->plain_unsubscribe = UnisenderDriver::PLAIN_UNSUBSCRIBE_NONE;
$request = $driver->buildRequest($plainEmail, CmsCommMessage::findOne($plain->id));
uniExpect(strpos($request['body']['html'], '{{UnsubscribeUrl}}') === false && $request['skip_unsubscribe'] === 1 && !isset($request['options']),
    'Without opt-out the letter asks the service to add none (allowed by its support).');
$driver->plain_unsubscribe = UnisenderDriver::PLAIN_UNSUBSCRIBE_LINE;

// Sending: advertising only with consent.
$requests = [];
try {
    $driver->send($email);
    uniExpect(false, 'An advertising letter to a contact without consent must not leave.');
} catch (MailSendException $e) {
    uniExpect(!$e->uncertain && !$e->retryAfter && strpos($e->getMessage(), 'только тем, кто дал согласие') !== false && !$requests,
        'Without consent the letter fails before any call of the service.');
}
foreach (['client@example.com', 'boss@example.com'] as $address) {
    MailingConsents::subscribe(MailingContacts::get(1, 'email', $address), (int)$topic->id, CmsCommContactTopic::BASIS_SITE, CmsCommContactTopic::SOURCE_MANAGER);
}
$responses[] = $json(['status' => 'success', 'job_id' => '1ZymBc-00041N-9X', 'emails' => ['client@example.com'], 'failed_emails' => ['boss@example.com' => 'complained']]);
$driver->send($email);
uniExpect(CmsCommMessage::findOne($m1->id)->provider_ref === '1ZymBc-00041N-9X' && $requests[0]['body']['message']['subject'] === 'Плитка: сайт за неделю',
    'One call per letter; the job id of the send is kept at the letter.');
$boss = MailingContacts::get(1, 'email', 'boss@example.com');
uniExpect(CmsCommSuppression::find()->andWhere(['cms_comm_contact_id' => $boss->id, 'reason' => CmsCommSuppression::REASON_COMPLAINT, 'source' => 'provider'])->exists()
    && CmsCommMessage::findOne($m1->id)->delivery_status === null,
    'An address the service refused for a complaint goes to the stop list; a refused copy does not change the letter.');
try {
    $driver->send($email);
    uniExpect(false, 'The stop list holds the next advertising letter.');
} catch (MailSendException $e) {
    uniExpect(strpos($e->getMessage(), 'boss@example.com') !== false, 'The next advertising letter to the complained address does not leave.');
}
MailingConsents::unsuppress($boss, CmsCommSuppression::REASON_COMPLAINT);

// Refusals: "too many requests" — the letter waits; a server error — unknown outcome; nothing accepted — failed.
$responses[] = $json(['status' => 'error', 'code' => 0, 'message' => 'Too many requests'], 429, ['Retry-After' => '30']);
try {
    $driver->send($email);
    uniExpect(false, '429 is an error.');
} catch (MailSendException $e) {
    uniExpect($e->retryAfter === 30 && !$e->uncertain, '"Too many requests": nothing was taken, the letter waits as long as the service asks.');
}
$responses[] = $json(['status' => 'error', 'code' => 150, 'message' => 'An unknown internal error occurred'], 500);
try {
    $driver->send($email);
    uniExpect(false, '500 is an error.');
} catch (MailSendException $e) {
    uniExpect($e->uncertain, 'A server error: the service may have taken it, never resent by itself.');
}
$responses[] = new \RuntimeException('Нет ответа от Unisender Go: timeout');
try {
    $driver->send($email);
    uniExpect(false, 'A network failure is an error.');
} catch (MailSendException $e) {
    uniExpect($e->uncertain, 'No answer: the outcome is unknown.');
}
$responses[] = $json(['status' => 'error', 'code' => 204, 'message' => 'No valid recipients',
    'failed_emails' => ['client@example.com' => 'permanent_unavailable', 'boss@example.com' => 'complained']], 400);
try {
    $driver->send($email);
    uniExpect(false, 'Nothing accepted is an error.');
} catch (MailSendException $e) {
    uniExpect(!$e->uncertain && strpos($e->getMessage(), 'многократные недоставки') !== false, 'Nothing accepted: failed with the reason of every address.');
}
$client = MailingContacts::get(1, 'email', 'client@example.com');
uniExpect(CmsCommSuppression::find()->andWhere(['cms_comm_contact_id' => $client->id, 'reason' => CmsCommSuppression::REASON_BOUNCE])->exists(),
    'A permanently unavailable address goes to the stop list.');
uniExpect(CmsCommMessage::findOne($m1->id)->delivery_status === CmsCommMessage::DELIVERY_BOUNCED, 'The letter refused for its addressee is not delivered.');
MailingConsents::unsuppress($client, CmsCommSuppression::REASON_BOUNCE);
MailingConsents::unsuppress($boss, CmsCommSuppression::REASON_COMPLAINT);
CmsCommMessageEvent::deleteAll(['cms_comm_message_id' => $m1->id]);
CmsCommMessage::updateAll(['delivery_status' => null, 'delivery_at' => null], ['id' => $m1->id]);
uniExpect(CmsCommCampaignRecipient::findOne($r1->id)->bounced_at, 'The campaign recipient of a refused letter is not delivered.');
CmsCommCampaignRecipient::updateAll(['bounced_at' => null], ['id' => $r1->id]);

// The send job of the CRM: "too many requests" — the letter is queued again and the same run waits; then sent, no "Sent" folder.
$context = new class extends \skeeks\cms\job\runtime\JobContext {
    public array $values = [];

    public function getPayload()
    {
        return $this->values;
    }
};
$reporter = new class implements \skeeks\cms\job\contracts\JobReporterInterface {
    public array $stages = [];
    public function setStage(string $stage, ?string $message = null): void { $this->stages[] = $stage; }
    public function setTotal(?int $total): void {}
    public function advance(int $by = 1): void {}
    public function countSuccess(int $by = 1): void {}
    public function countWarning(int $by = 1): void {}
    public function countError(int $by = 1): void {}
    public function countSkipped(int $by = 1): void {}
    public function info(string $message, array $context = []): void {}
    public function warning(string $message, array $context = []): void { $this->stages[] = 'warning: '.$message; }
    public function error(string $message, array $context = []): void {}
    public function itemError(string $itemType, $itemId, string $message, array $row = []): void {}
    public function heartbeat(): void {}
    public function isCancelled(): bool { return false; }
    public function addArtifact(string $type, string $path, array $options = []): \skeeks\cms\job\models\CmsJobRunArtifact { throw new \LogicException(); }
    public function setResult(array $result): void {}
};
// The job builds the driver from the connection: its transport is the recorded one through the container.
Yii::$container->set(UnisenderDriver::class, ['transport' => $transport]);
$m5 = $letter(['status' => 'queued', 'created_at' => time()]);
$context->values = ['messageId' => (int)$m5->id];
$responses[] = $json(['status' => 'error', 'code' => 0, 'message' => 'Too many requests'], 429);
try {
    (new MailSendJobHandler())->run($context, $reporter);
    uniExpect(false, 'The job must wait in the queue.');
} catch (\skeeks\cms\job\exceptions\JobRequeueException $e) {
    uniExpect($e->delay === 60 && CmsCommMessage::findOne($m5->id)->status === CmsCommMessage::STATUS_QUEUED, 'The letter is queued again; the run comes back in a minute.');
}
$responses[] = $json(['status' => 'success', 'job_id' => 'job-5', 'emails' => ['client@example.com']]);
$reporter->stages = [];
(new MailSendJobHandler())->run($context, $reporter);
$m5 = CmsCommMessage::findOne($m5->id);
uniExpect($m5->status === CmsCommMessage::STATUS_SENT && $m5->error === null && $m5->provider_ref === 'job-5' && $m5->delivery_status === null
    && !in_array('sent-folder', $reporter->stages, true), 'Then it is sent: "sent" is only "the service took it", no copy into a folder.');
Yii::$container->clear(UnisenderDriver::class);

// A campaign of managers' mailboxes never goes through the service.
$cold = new CmsCommCampaign(['cms_site_id' => 1, 'name' => 'Холодная', 'status' => CmsCommCampaign::STATUS_RUNNING, 'cms_comm_topic_id' => $topic->id,
    'channel' => MailboxCampaignChannel::ID, 'sender_mode' => CmsCommCampaign::SENDER_ACCOUNT, 'cms_comm_account_id' => $service->id]);
$cold->save(false);
$m2 = $letter(['cms_comm_campaign_id' => $cold->id, 'cms_comm_topic_id' => $topic->id]);
try {
    $driver->send((new MailSendJobHandler())->buildEmail(CmsCommMessage::findOne($m2->id)));
    uniExpect(false, 'A cold campaign must not leave through the service.');
} catch (MailSendException $e) {
    uniExpect(strpos($e->getMessage(), 'ящиков менеджеров') !== false, 'A letter of a managers\' mailbox campaign fails at the service.');
}
uniExpect((new MailboxCampaignChannel())->unavailableReason($cold, \skeeks\cms\models\CmsUser::findOne(7)) !== null,
    'The managers\' mailbox channel refuses a service mailbox before launch.');

// The webhook: the signature of the documentation.
$sign = static function (array $data, string $key): string {
    $data = ['auth' => str_repeat('x', 32)] + $data;
    $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $auth = md5(str_replace(str_repeat('x', 32), $key, $body));

    return str_replace(str_repeat('x', 32), $auth, $body);
};
$eventTime = gmdate('Y-m-d H:i:s', time() - 30);
$status = static function (string $status, array $extra = []) use ($m1, $eventTime) {
    return ['event_name' => 'transactional_email_status', 'event_data' => $extra + ['job_id' => '1ZymBc-00041N-9X',
            'metadata' => ['sx_message' => (string)$m1->id, 'sx_site' => '1', 'sx_origin' => UnisenderDriver::origin()], 'email' => 'client@example.com',
            'status' => $status, 'event_time' => $eventTime]];
};
$payload = static fn(array $events) => ['events_by_user' => [['user_id' => 456, 'events' => $events]]];
$body = $sign($payload([$status('soft_bounced', ['delivery_info' => ['delivery_status' => 'err_will_retry', 'destination_response' => '451 Try later']]),
    $status('delivered'), ['event_name' => 'transactional_spam_block', 'event_data' => ['block_time' => '2026-10-24 10:00:00', 'block_type' => 'one_smtp',
        'domain' => 'mail.ru', 'SMTP_blocks_count' => 8, 'domain_status' => 'blocked']]]), $apiKey);
uniExpect(WebhookReceiver::verify($body, $apiKey) && WebhookReceiver::verify(WebhookReceiver::body(gzencode($body)), $apiKey),
    'The signature of the documentation is accepted, also gzipped.');
uniExpect(!WebhookReceiver::verify($body, 'other-key') && !WebhookReceiver::verify(str_replace('delivered', 'hard_bounced', $body), $apiKey)
    && !WebhookReceiver::verify('{"auth":"","events_by_user":[]}', $apiKey) && !WebhookReceiver::verify($body, ''),
    'Another key, a changed body, an empty signature or no key are refused.');
uniExpect(WebhookReceiver::body(gzencode(str_repeat('a', WebhookReceiver::MAX_BODY + 1))) === null
    && WebhookReceiver::body(str_repeat('a', WebhookReceiver::MAX_BODY + 1)) === null && WebhookReceiver::body("\x1f\x8bbroken") === null,
    'A body that unpacks beyond the limit, a too large or a broken archive is refused before the signature.');

// The action: 403 on a wrong signature, 200 and one stored call on a right one.
$receive = static function (string $raw, int $id) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $request = new \yii\web\Request(['cookieValidationKey' => 'test']);
    $request->setRawBody($raw);
    Yii::$app->set('request', $request);
    Yii::$app->set('response', new \yii\web\Response());
    $result = (new UnisenderWebhookController('unisender-webhook', Yii::$app))->runAction('receive', ['id' => $id]);

    return [Yii::$app->response->statusCode, $result];
};
[$code] = $receive($sign($payload([$status('delivered')]), 'wrong-key'), (int)$connection->id);
uniExpect($code === 403 && !CmsCommUnisenderCallback::find()->exists(), 'A wrong signature is refused and nothing is stored.');
[$code] = $receive($body, (int)$imap->id);
uniExpect($code === 404, 'Only a Unisender Go connection takes webhooks.');
[$code] = $receive(gzencode(str_repeat(' ', WebhookReceiver::MAX_BODY + 1)), (int)$connection->id);
[$plainCode] = $receive(str_repeat(' ', WebhookReceiver::MAX_BODY + 1), (int)$connection->id);
uniExpect($code === 413 && $plainCode === 413 && !CmsCommUnisenderCallback::find()->exists(), 'A gzip bomb or a too large body answers 413 and stores nothing.');
Yii::$app->jobs->pushed = [];
[$code, $answer] = $receive($body, (int)$connection->id);
[$again] = $receive($body, (int)$connection->id);
uniExpect($code === 200 && $answer === ['status' => 'ok'] && $again === 200 && (int)CmsCommUnisenderCallback::find()->count() === 1
    && Yii::$app->jobs->pushed[0][0] === UnisenderCallbacks::JOB && count(Yii::$app->jobs->pushed) === 2,
    'A verified call is stored once; a repeat while it waits stores nothing and wakes the job again (a lost push is recovered).');

// Applying: delivery only by confirmation, a later "deferred" never undoes it.
$result = UnisenderCallbacks::apply($connection);
$m1 = CmsCommMessage::findOne($m1->id);
uniExpect($result['events'] === 2 && count($result['blocks']) === 1 && $m1->delivery_status === CmsCommMessage::DELIVERY_DELIVERED
    && $m1->getDeliveryName() === 'Доставлено' && CmsCommCampaignRecipient::findOne($r1->id)->delivered_at
    && CmsCommUnisenderCallback::find()->one()->status === CmsCommUnisenderCallback::STATUS_APPLIED && CmsCommUnisenderCallback::find()->one()->body === '',
    'Soft bounce, then delivered: the letter and the recipient are delivered; a spam block is reported; the raw body is not kept.');
$events = \skeeks\cms\communication\services\DeliveryEvents::ofMessage((int)$m1->id);
uniExpect(count($events) === 2 && $events[0]->event === CmsCommMessageEvent::EVENT_DEFERRED && strpos((string)$events[0]->detail, '451 Try later') !== false
    && strpos((string)$events[0]->data, 'ip') === false, 'Events of the letter with the reason; no personal data of the reader is kept.');
// The service repeats a call (retry_count makes it another body): no event twice.
$retry = $sign($payload([$status('delivered'), $status('soft_bounced', ['delivery_info' => ['delivery_status' => 'err_will_retry', 'destination_response' => '451 Try later']])])
    + ['retry_count' => 1], $apiKey);
UnisenderCallbacks::store($connection, $retry);
$result = UnisenderCallbacks::apply($connection);
uniExpect($result['events'] === 0 && (int)CmsCommMessageEvent::find()->count() === 2 && CmsCommMessage::findOne($m1->id)->delivery_status === 'delivered',
    'A repeated call writes no event twice and does not move the state back.');

// The id of the installation: random, kept in its file, the same on every call.
uniExpect(preg_match('/^[0-9a-f]{16}$/', UnisenderDriver::origin()) && trim((string)file_get_contents($originFile)) === UnisenderDriver::origin(),
    'The installation has a random id kept in a file, not made of the database connection.');

// Isolation: an event of another installation, site or send never touches the letter.
$foreign = static fn(array $metadata, string $jobId) => ['event_name' => 'transactional_email_status', 'event_data' => [
    'job_id' => $jobId, 'metadata' => $metadata + ['sx_message' => (string)$m1->id], 'email' => 'client@example.com', 'status' => 'hard_bounced',
    'event_time' => '2026-10-24 11:00:00', 'delivery_info' => ['delivery_status' => 'err_user_unknown']]];
$eventsBefore = (int)CmsCommMessageEvent::find()->andWhere(['cms_comm_message_id' => $m1->id])->count();
UnisenderCallbacks::store($connection, $sign($payload([
    // Another installation with the same letter id: its own send.
    $foreign(['sx_site' => '1', 'sx_origin' => 'other0install000'], 'foreign-job-1'),
    // Another site of the same database.
    $foreign(['sx_site' => '999', 'sx_origin' => UnisenderDriver::origin()], 'foreign-job-2'),
    // Our installation, but not the send of this letter.
    $foreign(['sx_site' => '1', 'sx_origin' => UnisenderDriver::origin()], 'other-job'),
]), $apiKey));
UnisenderCallbacks::apply($connection);
uniExpect(CmsCommMessage::findOne($m1->id)->delivery_status === CmsCommMessage::DELIVERY_DELIVERED
    && (int)CmsCommMessageEvent::find()->andWhere(['cms_comm_message_id' => $m1->id])->count() === $eventsBefore
    && !CmsCommSuppression::find()->andWhere(['cms_comm_contact_id' => $client->id, 'reason' => CmsCommSuppression::REASON_BOUNCE])->exists(),
    'Events of another installation, another site or another send change neither the letter nor the stop list.');
// A letter without a known send (outcome unknown) takes only an event of this installation and site.
$unknown = $letter(['to_list' => json_encode(['maybe@example.com' => ''])]);
$event = static fn(array $metadata) => ['event_name' => 'transactional_email_status', 'event_data' => ['job_id' => 'job-x', 'metadata' => $metadata + ['sx_message' => (string)$unknown->id],
    'email' => 'maybe@example.com', 'status' => 'delivered', 'event_time' => '2026-10-24 11:00:00']];
UnisenderCallbacks::store($connection, $sign($payload([$event(['sx_site' => '1', 'sx_origin' => 'other0install000'])]), $apiKey));
UnisenderCallbacks::apply($connection);
$foreignUnknown = CmsCommMessage::findOne($unknown->id)->delivery_status;
UnisenderCallbacks::store($connection, $sign($payload([$event(['sx_site' => '1', 'sx_origin' => UnisenderDriver::origin()])]), $apiKey));
UnisenderCallbacks::apply($connection);
uniExpect($foreignUnknown === null && CmsCommMessage::findOne($unknown->id)->delivery_status === CmsCommMessage::DELIVERY_DELIVERED,
    'A letter with an unknown outcome takes the event of this installation only.');
// The proof of the send wins over a changed id of the installation (its file was lost).
$proven = $letter(['to_list' => json_encode(['proven@example.com' => '']), 'provider_ref' => 'job-proven']);
UnisenderCallbacks::store($connection, $sign($payload([['event_name' => 'transactional_email_status', 'event_data' => ['job_id' => 'job-proven',
    'metadata' => ['sx_message' => (string)$proven->id, 'sx_site' => '1', 'sx_origin' => 'formerid00000000'], 'email' => 'proven@example.com', 'status' => 'delivered',
    'event_time' => '2026-10-24 11:00:00']]]), $apiKey));
UnisenderCallbacks::apply($connection);
uniExpect(CmsCommMessage::findOne($proven->id)->delivery_status === CmsCommMessage::DELIVERY_DELIVERED,
    'An event with the job id of the letter\'s send is ours even when the id of the installation changed.');

// Consequences: an opt-out through the service, a complaint, a hard bounce of a dead address, not of our sender.
UnisenderCallbacks::store($connection, $sign($payload([$status('unsubscribed')]), $apiKey));
UnisenderCallbacks::apply($connection);
uniExpect(MailingPolicy::check(1, 'email', 'client@example.com', (int)$topic->id, MailingPolicy::MODE_SERVICE)['reason'] === MailingPolicy::REASON_UNSUBSCRIBED
    && CmsCommContactTopic::findOne(['cms_comm_contact_id' => $client->id, 'cms_comm_topic_id' => $topic->id])->unsubscribe_source === CmsCommContactTopic::SOURCE_PROVIDER,
    'An opt-out through the service opts the contact out of the topic of the letter.');
UnisenderCallbacks::store($connection, $sign($payload([$status('spam')]), $apiKey));
UnisenderCallbacks::apply($connection);
uniExpect(CmsCommSuppression::find()->andWhere(['cms_comm_contact_id' => $client->id, 'reason' => CmsCommSuppression::REASON_COMPLAINT])->exists()
    && CmsCommMessage::findOne($m1->id)->delivery_status === CmsCommMessage::DELIVERY_COMPLAINED, 'A complaint goes to the stop list with its reason.');

$m3 = $letter(['to_list' => json_encode(['gone@example.com' => '']), 'provider_ref' => 'job-3']);
$m4 = $letter(['to_list' => json_encode(['picky@example.com' => ''])]);
UnisenderCallbacks::store($connection, $sign($payload([
    // Found by the job id: no metadata.
    ['event_name' => 'transactional_email_status', 'event_data' => ['job_id' => 'job-3', 'email' => 'gone@example.com', 'status' => 'hard_bounced',
        'event_time' => '2026-10-24 10:00:00', 'delivery_info' => ['delivery_status' => 'err_user_unknown', 'destination_response' => '550 5.1.1 User unknown']]],
    // Our sender is blacklisted: not the address's fault.
    ['event_name' => 'transactional_email_status', 'event_data' => ['job_id' => 'job-4', 'metadata' => ['sx_message' => (string)$m4->id], 'email' => 'picky@example.com',
        'status' => 'hard_bounced', 'event_time' => '2026-10-24 10:00:00', 'delivery_info' => ['delivery_status' => 'err_blacklisted']]],
    // Metadata of a letter that is not of this connection.
    ['event_name' => 'transactional_email_status', 'event_data' => ['job_id' => 'job-unknown', 'metadata' => ['sx_message' => (string)($m2->id + 1000)], 'email' => 'x@example.com',
        'status' => 'delivered', 'event_time' => '2026-10-24 10:00:00']],
]), $apiKey));
UnisenderCallbacks::apply($connection);
uniExpect(CmsCommMessage::findOne($m3->id)->delivery_status === CmsCommMessage::DELIVERY_BOUNCED
    && CmsCommSuppression::find()->andWhere(['cms_comm_contact_id' => MailingContacts::get(1, 'email', 'gone@example.com')->id, 'reason' => 'bounce'])->exists(),
    'A hard bounce of an address that does not exist: the letter is not delivered, the address goes to the stop list.');
uniExpect(CmsCommMessage::findOne($m4->id)->delivery_status === CmsCommMessage::DELIVERY_BOUNCED
    && !CmsCommSuppression::find()->andWhere(['cms_comm_contact_id' => MailingContacts::get(1, 'email', 'picky@example.com')->id])->exists(),
    'A bounce because of our sender keeps the address off the stop list.');
uniExpect(CmsCommMessageEvent::find()->andWhere(['address' => 'x@example.com'])->one()->cms_comm_message_id === null,
    'An event of an unknown letter is kept without a letter.');
$stats = Campaigns::stats(CmsCommCampaign::findOne($campaign->id));
uniExpect($stats['delivered'] === 1 && $stats['bounced'] === 0 && $stats['percent']['delivered'] == 100, 'The report counts delivered letters by confirmation.');

// A call that cannot be applied stays waiting with the reason, then failed.
$broken = new CmsCommUnisenderCallback(['created_at' => time(), 'cms_comm_connection_id' => $connection->id, 'body_hash' => sha1('x'), 'status' => 'new',
    'body' => $sign($payload([['event_name' => 'transactional_email_status', 'event_data' => ['email' => 'a@b.c', 'status' => 'delivered', 'job_id' => str_repeat('j', 70)]]]), $apiKey)]);
$broken->save(false);
$db->createCommand()->dropTable('cms_comm_message_event')->execute();
for ($i = 0; $i < 5; $i++) {
    UnisenderCallbacks::apply($connection);
}
uniExpect(CmsCommUnisenderCallback::findOne($broken->id)->status === CmsCommUnisenderCallback::STATUS_FAILED && CmsCommUnisenderCallback::findOne($broken->id)->attempts === 5
    && CmsCommUnisenderCallback::findOne($broken->id)->error, 'A call that fails is tried five times, then kept failed with its reason.');
$create('cms_comm_message_event', ['id' => 'pk', 'created_at' => 'integer', 'cms_site_id' => 'integer', 'cms_comm_message_id' => 'integer', 'cms_comm_connection_id' => 'integer',
    'provider' => 'string', 'event_key' => 'string', 'event' => 'string', 'event_at' => 'integer', 'address' => 'string', 'detail' => 'string', 'data' => 'text']);

// The campaign channel: consent only, senders, order, pace.
$channel = new UnisenderCampaignChannel();
uniExpect($channel->policyMode() === MailingPolicy::MODE_SERVICE && $channel->unavailableReason($campaign, \skeeks\cms\models\CmsUser::findOne(7)) === null
    && $channel->unavailableReason(new CmsCommCampaign(['cms_site_id' => 1, 'sender_mode' => CmsCommCampaign::SENDER_ACCOUNT, 'cms_comm_account_id' => $work->id]),
        \skeeks\cms\models\CmsUser::findOne(7)) !== null, 'The service channel needs a service mailbox and asks consent of every recipient.');
uniExpect(MailingPolicy::check(1, 'email', 'cold@example.com', (int)$topic->id, MailingPolicy::MODE_SERVICE)['allowed'] === false
    && MailingPolicy::check(1, 'email', 'cold@example.com', (int)$topic->id, MailingPolicy::MODE_MANAGER)['allowed'] === true,
    'A cold contact is excluded from the service and allowed to a manager\'s mailbox.');
$db->createCommand()->batchInsert('cms_lead', ['id', 'cms_site_id', 'name', 'status', 'executor_id'], [[11, 1, 'Лид Анны', 'new', 7], [12, 1, 'Лид Бориса', 'new', 8]])->execute();
$new = [new CmsCommCampaignRecipient(['record_type' => 'lead', 'record_id' => 11, 'status' => 'pending']),
    new CmsCommCampaignRecipient(['record_type' => 'lead', 'record_id' => 12, 'status' => 'pending'])];
$channel->assignSenders($campaign, $new, \skeeks\cms\models\CmsUser::findOne(1));
uniExpect((int)$new[0]->cms_comm_account_id === (int)$service->id && (int)$new[0]->sender_user_id === 7 && (int)$new[1]->sender_user_id === 1,
    'The letter is from the responsible employee who may send from the service mailbox, otherwise from the launcher.');
$second = new CmsCommCampaign(['cms_site_id' => 1, 'name' => 'Позже', 'status' => CmsCommCampaign::STATUS_RUNNING, 'channel' => UnisenderCampaignChannel::ID,
    'launched_at' => time()]);
$second->save(false);
foreach ([[$second->id, 21], [$campaign->id, 22], [$cold->id, 23]] as [$campaignId, $recordId]) {
    (new CmsCommCampaignRecipient(['cms_comm_campaign_id' => $campaignId, 'record_type' => 'lead', 'record_id' => $recordId, 'status' => 'pending',
        'cms_comm_account_id' => $service->id]))->save(false);
}
uniExpect((int)UnisenderCampaignChannel::nextRecipient((int)$service->id)->record_id === 22, 'Letters of the earlier service campaign first; other channels are not taken.');
uniExpect(UnisenderCampaignChannel::pace(CmsCommAccount::findOne($service->id)) === ['perMinute' => 2, 'perDay' => 0], 'The pace comes from the connection, without its key.');
Yii::$app->jobs->pushed = [];
$channel->start($campaign);
uniExpect(Yii::$app->jobs->pushed[0][0] === UnisenderCampaignChannel::JOB && Yii::$app->jobs->pushed[0][1] === ['accountId' => (int)$service->id],
    'Starting pushes the job of the service mailbox.');

// The event dump: rows in the shape of webhook events.
$requests = [];
$responses = [$json(['status' => 'success', 'dump_id' => 'D1']), $json(['status' => 'success', 'event_dump' => ['dump_id' => 'D1', 'dump_status' => 'ready',
    'files' => [['url' => 'https://go2.unisender.ru/event-dump/D1-1.csv', 'size' => 100]]]]), $json(['status' => 'success'])];
$csv = "event_time,job_id,email,status,delivery_status,metadata,destination_response\n"
    ."2026-10-24 12:00:00,job-9,client@example.com,delivered,,\"{\"\"sx_message\"\":\"\"{$m1->id}\"\"}\",\n";
$rows = (new UnisenderEventDump($api, static fn(string $url) => $csv))->fetch(time() - 3600, ['email' => 'client@example.com']);
uniExpect(count($rows) === 1 && $rows[0]['metadata'] === ['sx_message' => (string)$m1->id] && $requests[0]['body']['filter'] === ['email' => 'client@example.com']
    && strpos($requests[2]['url'], 'event-dump/delete.json') !== false, 'The dump is read and removed afterwards.');
$event = WebhookReceiver::event($rows[0], (int)$connection->id);
uniExpect($event && $event->messageId === (int)$m1->id && $event->event === CmsCommMessageEvent::EVENT_DELIVERED && $event->at === gmmktime(12, 0, 0, 10, 24, 2026),
    'A row of the dump is an event of the letter (time in UTC).');

// The apply job goes on while calls wait (more than one portion) and comes back for a failed call.
CmsCommUnisenderCallback::deleteAll();
for ($i = 0; $i < 201; $i++) {
    UnisenderCallbacks::store($connection, $sign($payload([$status('opened', ['event_time' => sprintf('2026-10-24 12:%02d:%02d', intdiv($i, 60), $i % 60)])]), $apiKey));
}
$context->values = ['connectionId' => (int)$connection->id];
(new \skeeks\cms\communication\unisender\jobs\UnisenderApplyJobHandler())->run($context, $reporter);
uniExpect(!UnisenderCallbacks::waiting($connection) && (int)CmsCommUnisenderCallback::find()->andWhere(['status' => 'applied'])->count() === 201,
    'One run applies every waiting call, not only the first portion.');
UnisenderCallbacks::store($connection, $sign($payload([$status('clicked')]), $apiKey));
$db->createCommand()->dropTable('cms_comm_message_event')->execute();
try {
    (new \skeeks\cms\communication\unisender\jobs\UnisenderApplyJobHandler())->run($context, $reporter);
    uniExpect(false, 'A failed call must bring the job back.');
} catch (\skeeks\cms\job\exceptions\JobRequeueException $e) {
    uniExpect($e->delay === \skeeks\cms\communication\unisender\jobs\UnisenderApplyJobHandler::RETRY_DELAY && UnisenderCallbacks::waiting($connection),
        'A failed call waits and the job comes back for it later.');
}
$create('cms_comm_message_event', ['id' => 'pk', 'created_at' => 'integer', 'cms_site_id' => 'integer', 'cms_comm_message_id' => 'integer', 'cms_comm_connection_id' => 'integer',
    'provider' => 'string', 'event_key' => 'string', 'event' => 'string', 'event_at' => 'integer', 'address' => 'string', 'detail' => 'string', 'data' => 'text']);

// "Проверить подключение" takes only the webhook of this very connection.
$driver = $connection->createDriver(true);
$driver->transport = $transport;
$info = $json(['status' => 'success', 'user_id' => 1, 'email' => 'user@example.com']);
$other = new CmsCommConnection(['cms_site_id' => 1, 'name' => 'other', 'driver' => 'unisender', 'is_active' => 1]);
$other->save(false);
$responses = [$info, $json(['status' => 'success', 'objects' => [['url' => 'https://crm.example/~unisender/'.(int)$other->id, 'status' => 'active', 'delivery_info' => 1]]])];
$checks = $driver->checkConnection();
uniExpect($driver->getConnectionId() === (int)$connection->id && $checks[1]['ok'] === false, 'The webhook of another Unisender Go connection is not this one\'s.');
$responses = [$info, $json(['status' => 'success', 'objects' => [['url' => 'https://other.example/~unisender/999', 'status' => 'active', 'delivery_info' => 1]]])];
$checks = $driver->checkConnection();
uniExpect($checks[1]['ok'] === false && strpos($checks[1]['message'], 'Не подключён') === 0, 'A webhook of another connection is not ours.');
$responses = [$info, $json(['status' => 'success', 'objects' => [['url' => 'https://crm.example/~unisender/'.(int)$connection->id, 'status' => 'active',
    'delivery_info' => 1, 'events' => ['email_status' => ['delivered', 'hard_bounced', 'spam', 'unsubscribed', 'soft_bounced']]]]])];
$checks = $driver->checkConnection();
uniExpect($checks[1]['ok'] === true && strpos($checks[1]['message'], 'работает') !== false, 'The webhook of this connection with the needed events works.');
$responses = [$info, $json(['status' => 'success', 'objects' => [['url' => 'https://crm.example/~unisender/'.(int)$connection->id, 'status' => 'active',
    'delivery_info' => 1, 'events' => ['email_status' => ['delivered']]]]])];
$checks = $driver->checkConnection();
uniExpect($checks[1]['ok'] === false && strpos($checks[1]['message'], 'hard_bounced') !== false, 'A webhook without bounces and complaints is reported.');

// The agent wakes the apply job of a connection with waiting calls.
UnisenderCallbacks::store($connection, $sign($payload([$status('clicked', ['event_time' => '2026-10-24 13:00:00'])]), $apiKey));
Yii::$app->jobs->pushed = [];
(new \skeeks\cms\communication\unisender\jobs\UnisenderApplyAllJobHandler())->run($context, $reporter);
uniExpect(count(Yii::$app->jobs->pushed) === 1 && Yii::$app->jobs->pushed[0][0] === UnisenderCallbacks::JOB
    && Yii::$app->jobs->pushed[0][1] === ['connectionId' => (int)$connection->id], 'Waiting calls are picked up by the agent even without a new webhook.');
UnisenderCallbacks::apply($connection);

// A service campaign whose mailbox left the service sends nothing more.
$moved = new CmsCommCampaignRecipient(['cms_comm_campaign_id' => $campaign->id, 'record_type' => 'lead', 'record_id' => 11, 'status' => 'pending',
    'cms_comm_account_id' => $work->id]);
try {
    $channel->deliver(new \skeeks\cms\communication\campaign\CampaignLetter($campaign, $moved, $campaign, \skeeks\cms\models\CmsUser::findOne(7)));
    uniExpect(false, 'A service campaign must not leave through an ordinary mailbox.');
} catch (\DomainException $e) {
    uniExpect(strpos($e->getMessage(), 'больше не подключён') !== false, 'The channel refuses a mailbox that left the service.');
}
$queued = $letter(['status' => 'queued', 'cms_comm_account_id' => $work->id, 'cms_comm_campaign_id' => $campaign->id]);
$context->values = ['messageId' => (int)$queued->id];
try {
    (new MailSendJobHandler())->run($context, $reporter);
    uniExpect(false, 'A queued letter of a service campaign must not leave through an ordinary mailbox.');
} catch (\skeeks\cms\job\exceptions\JobPermanentException $e) {
    uniExpect(CmsCommMessage::findOne($queued->id)->status === CmsCommMessage::STATUS_FAILED, 'A queued letter of the service campaign fails at an ordinary mailbox.');
}

// Replies whose Message-ID the service replaced find the letter by subject and address.
uniExpect(MailIngestService::baseSubject('Re: RE[2]: Ответ:  Плитка:   сайт за неделю') === 'плитка: сайт за неделю', 'Reply prefixes are removed from the subject.');

echo "OK: cms-communication-unisender\n";
