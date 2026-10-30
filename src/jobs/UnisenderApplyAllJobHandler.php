<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\unisender\jobs;

use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\unisender\UnisenderCallbacks;
use skeeks\cms\communication\unisender\UnisenderDriver;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;

/**
 * The agent comm.unisender.apply-all: wakes the apply job of every Unisender
 * Go connection that has calls still to apply. A run of the apply job lost to
 * a crash, or a failed call waiting for its retry, is taken again even when
 * no new webhook comes (the service already got 200 for them).
 */
class UnisenderApplyAllJobHandler extends AbstractJobHandler
{
    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $pushed = 0;
        foreach (CmsCommConnection::find()->andWhere(['driver' => UnisenderDriver::ID, 'is_active' => 1])->all() as $connection) {
            if (UnisenderCallbacks::waiting($connection)) {
                UnisenderCallbacks::push($connection);
                $pushed++;
            }
        }
        $reporter->setResult(['connections' => $pushed]);
        $reporter->setStage('complete', $pushed ? 'Поставлено на применение подключений: '.$pushed : 'Необработанных событий нет');
    }
}
