<?php

return [
    'components' => [
        'communication' => [
            'drivers' => [
                'unisender' => \skeeks\cms\communication\unisender\UnisenderDriver::class,
            ],
            // "Сервис рассылок" in the campaign form: only contacts with consent.
            'campaignChannels' => [
                'unisender' => \skeeks\cms\communication\unisender\campaign\UnisenderCampaignChannel::class,
            ],
        ],
        'jobRegistry' => ['types' => [
            // Campaign letters of one Unisender Go mailbox: a minute's worth per step, then the run waits.
            'comm.campaign.unisender' => [
                'type' => 'comm.campaign.unisender',
                'title' => 'Рассылка через Unisender Go',
                'handler' => \skeeks\cms\communication\unisender\jobs\UnisenderCampaignJobHandler::class,
                'queue' => 'communication',
                'visibility' => 'transient',
                'timeout' => 600,
                'leaseSeconds' => 300,
                'maxAttempts' => 1,
                // A step claims each recipient in the transaction of its letter: a repeated step sends nothing twice.
                'idempotent' => true,
                'overlapPolicy' => 'skip',
                'resourceKey' => static fn($payload) => 'comm-campaign-unisender:'.(int)($payload['accountId'] ?? 0),
                'dedupKey' => static fn($payload) => 'comm.campaign.unisender:'.(int)($payload['accountId'] ?? 0),
            ],
            // Stored webhooks of Unisender Go turned into delivery events. "coalesce": a call stored
            // while a run works is applied by the run waiting beside it.
            'comm.unisender.apply' => [
                'type' => 'comm.unisender.apply',
                'title' => 'Unisender Go: события доставки',
                'handler' => \skeeks\cms\communication\unisender\jobs\UnisenderApplyJobHandler::class,
                'queue' => 'communication',
                'visibility' => 'transient',
                'timeout' => 300,
                'leaseSeconds' => 120,
                // A run stopped by a crash is taken again; an applied call is never applied twice.
                'maxAttempts' => 3,
                // An event is recorded once (provider + key).
                'idempotent' => true,
                'overlapPolicy' => 'coalesce',
                'resourceKey' => static fn($payload) => 'comm-unisender:'.(int)($payload['connectionId'] ?? 0),
                'dedupKey' => static fn($payload) => 'comm.unisender.apply:'.(int)($payload['connectionId'] ?? 0),
            ],
            // Wakes the apply job of every connection with waiting calls: a run lost to a crash
            // or a failed call is not left behind when no new webhook comes.
            'comm.unisender.apply-all' => [
                'type' => 'comm.unisender.apply-all',
                'title' => 'Unisender Go: подобрать необработанные события',
                'handler' => \skeeks\cms\communication\unisender\jobs\UnisenderApplyAllJobHandler::class,
                'queue' => 'communication',
                'visibility' => 'transient',
                'timeout' => 120,
                'leaseSeconds' => 60,
                'maxAttempts' => 1,
                'idempotent' => true,
                'overlapPolicy' => 'skip',
                'dedupKey' => static fn() => 'comm.unisender.apply-all',
            ],
        ]],
        'cmsAgent' => [
            'jobs' => [
                'comm.unisender.apply-all' => [
                    'jobType' => 'comm.unisender.apply-all',
                    'name' => 'Unisender Go: подобрать необработанные события доставки',
                    'interval' => 600,
                ],
            ],
        ],
    ],
];
