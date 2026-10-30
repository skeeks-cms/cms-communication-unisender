<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\unisender\campaign;

use skeeks\cms\communication\campaign\CampaignChannelInterface;
use skeeks\cms\communication\campaign\CampaignLetter;
use skeeks\cms\communication\campaign\MailboxCampaignChannel;
use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommCampaign;
use skeeks\cms\communication\models\CmsCommCampaignRecipient;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\Campaigns;
use skeeks\cms\communication\services\MailboxPace;
use skeeks\cms\communication\services\MailComposeService;
use skeeks\cms\communication\services\MailingPolicy;
use skeeks\cms\communication\unisender\UnisenderDriver;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\User;

/**
 * "Сервис рассылок": campaign letters through a mailbox of Unisender Go,
 * hundreds and thousands of them, only to contacts with consent
 * (MailingPolicy::MODE_SERVICE: cold contacts are excluded in the summary
 * with the reason). Every letter is still an ordinary message of the lead's
 * feed made by the campaign, and every letter is a separate call of the API
 * (UnisenderDriver::send()) with our metadata.
 *
 * The letter is from the employee responsible for the record when they may
 * send from the service mailbox (their name in {Менеджер}, their working
 * mailbox in Reply-To), otherwise from the launching employee.
 *
 * The job `comm.campaign.unisender` of the mailbox sends a minute's worth of
 * letters (the connection's "Писем в минуту"), then waits a minute; the
 * day's limit ("Писем в сутки") moves the rest to the next CRM day.
 */
class UnisenderCampaignChannel implements CampaignChannelInterface
{
    public const ID = 'unisender';
    public const JOB = 'comm.campaign.unisender';

    public function title(): string
    {
        return 'Сервис рассылок (Unisender Go)';
    }

    public function hint(): string
    {
        return 'Письма уходят через Unisender Go с выбранного ящика сервиса (поле «Ящик», отправитель — «Выбранный ящик»): сотни и тысячи писем, '
            .'с подтверждением доставки. Только тем, кто дал согласие на тему рассылки: остальных сводка исключит с причиной. Ответы приходят в рабочий ящик.';
    }

    public function policyMode(): string
    {
        return MailingPolicy::MODE_SERVICE;
    }

    public function unavailableReason(CmsCommCampaign $campaign, User $user): ?string
    {
        $account = $campaign->sender_mode === CmsCommCampaign::SENDER_ACCOUNT ? $campaign->account : null;
        if (!$account || !self::isServiceMailbox($account)) {
            return 'Выберите в поле «Отправитель» — «Выбранный ящик», а в поле «Ящик» — ящик сервиса рассылок Unisender Go.';
        }
        if (!$account->is_active || !$account->connection->is_active) {
            return 'Ящик сервиса рассылок выключен.';
        }
        if (!(new MailComposeService($user, (int)$campaign->cms_site_id))->canSendFrom((int)$account->id)) {
            return 'Вам нельзя отправлять письма с ящика сервиса рассылок: дайте себе право отправки в «Доступ сотрудников» ящика.';
        }

        return null;
    }

    public static function isServiceMailbox(?CmsCommAccount $account): bool
    {
        $connection = $account ? $account->connection : null;

        return $connection && (string)$connection->driver === UnisenderDriver::ID;
    }

    public function assignSenders(CmsCommCampaign $campaign, array $recipients, User $launcher): void
    {
        $accountId = (int)$campaign->cms_comm_account_id;
        $allowed = [];
        foreach ($recipients as $recipient) {
            $recipient->cms_comm_account_id = $accountId;
            $responsible = (int)Campaigns::responsibleOf($recipient->getRecord());
            if ($responsible && !array_key_exists($responsible, $allowed)) {
                $user = CmsUser::findOne($responsible);
                $allowed[$responsible] = $user && (new MailComposeService($user, (int)$campaign->cms_site_id))->canSendFrom($accountId);
            }
            $recipient->sender_user_id = $responsible && $allowed[$responsible] ? $responsible : (int)$launcher->id;
        }
    }

    public function start(CmsCommCampaign $campaign): void
    {
        if ($campaign->cms_comm_account_id) {
            self::push((int)$campaign->cms_comm_account_id, (int)$campaign->cms_site_id);
        }
    }

    /** The sending job of the service mailbox; a running or waiting one is enough (skip). */
    public static function push(int $accountId, int $siteId, int $delay = 0): void
    {
        \Yii::$app->jobs->push(self::JOB, ['accountId' => $accountId], [
            'siteId' => $siteId,
            'delay' => $delay,
            'triggerType' => 'manual',
        ]);
    }

    /** Pace of the mailbox from its connection (no secrets are read). */
    public static function pace(CmsCommAccount $account): array
    {
        $config = $account->connection ? $account->connection->getPublicConfig() : [];

        return [
            'perMinute' => max(1, (int)($config['per_minute'] ?? 60)),
            'perDay' => max(0, (int)($config['per_day'] ?? 0)),
        ];
    }

    /**
     * One step of the job: hands a minute's worth of letters to the mail
     * send jobs.
     *
     * @return int|null seconds until the next step; null when nothing is left to send
     */
    public function sendNext(CmsCommAccount $account, ?int $now = null): ?int
    {
        $now = $now ?? time();
        $pace = self::pace($account);
        $batch = $pace['perMinute'];
        if ($pace['perDay']) {
            $left = $pace['perDay'] - MailboxPace::sentToday((int)$account->id, $now);
            if ($left <= 0) {
                return self::nextRecipient((int)$account->id) ? max(60, MailboxPace::dayStart($now, 1) - $now) : null;
            }
            $batch = min($batch, $left);
        }
        $service = new Campaigns();
        for ($i = 0; $i < $batch; $i++) {
            $recipient = self::nextRecipient((int)$account->id);
            if (!$recipient) {
                return null;
            }
            $service->deliver($recipient);
        }

        return self::nextRecipient((int)$account->id) ? 60 : null;
    }

    /** The next waiting recipient of the running service campaigns of the mailbox: the earlier campaign first. */
    public static function nextRecipient(int $accountId): ?CmsCommCampaignRecipient
    {
        $r = CmsCommCampaignRecipient::tableName();
        $c = CmsCommCampaign::tableName();

        return CmsCommCampaignRecipient::find()
            ->innerJoin($c, $c.'.id = '.$r.'.cms_comm_campaign_id')
            ->andWhere([$r.'.cms_comm_account_id' => $accountId, $r.'.status' => CmsCommCampaignRecipient::STATUS_PENDING])
            ->andWhere([$c.'.status' => CmsCommCampaign::STATUS_RUNNING, $c.'.channel' => self::ID])
            ->orderBy([$c.'.launched_at' => SORT_ASC, $r.'.id' => SORT_ASC])
            ->select($r.'.*')
            ->one();
    }

    /**
     * The letter is made and queued exactly like a mailbox letter: the driver of the mailbox makes the
     * difference. The mailbox is checked again: switched to another connection after the launch, it
     * sends no letter of the service campaign (the mail send job checks the queued ones).
     */
    public function deliver(CampaignLetter $letter): CmsCommMessage
    {
        if (!self::isServiceMailbox(CmsCommAccount::findOne((int)$letter->recipient->cms_comm_account_id))) {
            throw new \DomainException('Ящик кампании больше не подключён к сервису рассылок: письмо не отправлено.');
        }

        return (new MailboxCampaignChannel())->deliver($letter);
    }
}
