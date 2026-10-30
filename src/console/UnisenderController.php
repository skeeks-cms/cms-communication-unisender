<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\unisender\console;

use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\unisender\UnisenderCallbacks;
use skeeks\cms\communication\unisender\UnisenderDriver;
use skeeks\cms\communication\unisender\UnisenderEventDump;
use skeeks\cms\communication\unisender\WebhookReceiver;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Unisender Go from the console:
 *
 * - `php yii unisender/webhook <connection id or mailbox> [https://crm.site]` — points
 *   the webhook of the account at `/~unisender/<id>` of the CRM site with
 *   the events the CRM needs and delivery details;
 * - `php yii unisender/events <connection id or mailbox> [hours] [apply]` — statuses of
 *   the letters of the last hours asked from the service (event dump); with
 *   apply=1 they are recorded as if the webhook brought them (a stand the
 *   webhook does not reach).
 *
 * The API key is read from the encrypted connection and never printed.
 */
class UnisenderController extends Controller
{
    public const WEBHOOK_EVENTS = ['delivered', 'soft_bounced', 'hard_bounced', 'spam', 'unsubscribed', 'subscribed'];

    public function actionWebhook(string $connection, string $baseUrl = '')
    {
        $connection = $this->connection($connection);
        $baseUrl = rtrim($baseUrl !== '' ? $baseUrl : \Yii::$app->communication->siteUrl((int)$connection->cms_site_id), '/');
        $host = (string)parse_url($baseUrl, PHP_URL_HOST);
        if (strpos($baseUrl, 'https://') !== 0 || in_array($host, ['localhost', '127.0.0.1'], true)) {
            $this->stderr("Вебхук ставится только на публичный https-адрес сайта CRM, а не на «{$baseUrl}».\n");
            return ExitCode::USAGE;
        }
        $url = $baseUrl.WebhookReceiver::PATH.(int)$connection->id;
        $result = $this->driver($connection)->api()->call('webhook/set', [
            'url' => $url,
            'status' => 'active',
            'event_format' => 'json_post',
            'delivery_info' => 1,
            'single_event' => 0,
            'max_parallel' => 10,
            'events' => ['email_status' => self::WEBHOOK_EVENTS, 'spam_block' => ['*']],
        ]);
        $this->stdout('Вебхук: '.$url.' — '.(($result['object']['status'] ?? $result['status'] ?? '') ?: 'сохранён')."\n");

        return ExitCode::OK;
    }

    public function actionEvents(string $connection, int $hours = 24, int $apply = 0)
    {
        $connection = $this->connection($connection);
        $rows = (new UnisenderEventDump($this->driver($connection)->api()))->fetch(time() - max(1, $hours) * 3600);
        $recorded = 0;
        foreach ($rows as $row) {
            $this->stdout(sprintf("%s  %-14s %-32s %s %s\n", $row['event_time'] ?? '', $row['status'] ?? '', $row['email'] ?? '',
                isset($row['metadata']['sx_message']) ? 'письмо #'.$row['metadata']['sx_message'] : '',
                $row['delivery_status'] ?? ''));
            if ($apply && ($event = WebhookReceiver::event($row, (int)$connection->id)) && UnisenderCallbacks::record($connection, $event)) {
                $recorded++;
            }
        }
        $this->stdout('Событий: '.count($rows).($apply ? ', записано новых: '.$recorded : '')."\n");

        return ExitCode::OK;
    }

    /** The connection by its id or by the address of its mailbox ("news@skeeks.com"). */
    private function connection(string $ref): CmsCommConnection
    {
        if (ctype_digit($ref)) {
            $connection = CmsCommConnection::findOne((int)$ref);
        } else {
            $account = CmsCommAccount::find()->andWhere(['address' => mb_strtolower(trim($ref))])
                ->andWhere(['cms_comm_connection_id' => CmsCommConnection::find()->select('id')->andWhere(['driver' => UnisenderDriver::ID])])->one();
            $connection = $account ? $account->connection : null;
        }
        if (!$connection || (string)$connection->driver !== UnisenderDriver::ID) {
            throw new \yii\console\Exception("Подключение Unisender Go «{$ref}» не найдено: укажите id подключения или адрес ящика.");
        }

        return $connection;
    }

    private function driver(CmsCommConnection $connection): UnisenderDriver
    {
        $driver = $connection->createDriver(true);
        if (!$driver instanceof UnisenderDriver || trim((string)$driver->api_key) === '') {
            throw new \yii\console\Exception('В подключении не сохранён API-ключ Unisender Go.');
        }

        return $driver;
    }
}
