<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\unisender\jobs;

use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\unisender\campaign\UnisenderCampaignChannel;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;

/**
 * Campaign letters of one Unisender Go mailbox: a minute's worth of letters
 * per step (UnisenderCampaignChannel::sendNext()), then the same run waits
 * in the queue. Ends when the running campaigns have nothing left for the
 * mailbox; the scheduled comm.campaign.tick restarts it after a pause.
 */
class UnisenderCampaignJobHandler extends AbstractJobHandler
{
    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $account = CmsCommAccount::findOne((int)$context->get('accountId'));
        if (!$account) {
            return;
        }
        $channel = \Yii::$app->communication->getCampaignChannel(UnisenderCampaignChannel::ID);
        if (!$channel instanceof UnisenderCampaignChannel) {
            return;
        }
        $wait = $channel->sendNext($account);
        if ($wait === null) {
            $reporter->setStage('complete', 'Писем кампаний для ящика больше нет');
            return;
        }
        $reporter->setStage('wait', 'Следующие письма через '.$wait.' с');
        // Outside any transaction: the step is committed already.
        $context->requestRequeue($wait);
    }
}
