<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\unisender\jobs;

use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\unisender\UnisenderCallbacks;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;

/**
 * Applies the stored webhooks of one Unisender Go connection in order
 * (UnisenderCallbacks::apply()): delivery events of letters, the stop list,
 * opt-outs. The run goes on portion by portion while calls wait; past
 * SLICE seconds it continues in a new run at once. A call that failed waits
 * RETRY_DELAY seconds for the next run, up to
 * CmsCommUnisenderCallback::MAX_ATTEMPTS times.
 */
class UnisenderApplyJobHandler extends AbstractJobHandler
{
    public const SLICE = 60;
    public const RETRY_DELAY = 300;

    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $connection = CmsCommConnection::findOne((int)$context->get('connectionId'));
        if (!$connection) {
            return;
        }
        $started = time();
        $totals = ['calls' => 0, 'events' => 0, 'failed' => 0];
        $failedIds = [];
        do {
            $result = UnisenderCallbacks::apply($connection, 200, $failedIds);
            foreach ($result['blocks'] as $block) {
                $reporter->warning('Unisender Go: сервер '.($block['domain'] ?? '?').' заблокировал отправку ('.($block['block_type'] ?? '').', '
                    .($block['domain_status'] ?? '').')');
            }
            $failedIds = array_merge($failedIds, $result['failedIds']);
            foreach (['calls', 'events', 'failed'] as $key) {
                $totals[$key] += $result[$key];
            }
            $reporter->heartbeat();
        } while ($result['calls'] > 0 && time() - $started < self::SLICE);

        $reporter->setResult($totals);
        $reporter->setStage('complete', 'Событий доставки записано: '.$totals['events']);
        if ($result['calls'] > 0) {
            // Out of time with calls left: the rest at once in a new run.
            $context->requestRequeue(0);
        }
        if ($failedIds && UnisenderCallbacks::waiting($connection)) {
            $reporter->warning('Не применены вызовы вебхука: '.count($failedIds).' (повтор через '.self::RETRY_DELAY.' с)');
            $context->requestRequeue(self::RETRY_DELAY);
        }
    }
}
