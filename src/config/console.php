<?php

return [
    'controllerMap' => [
        'migrate' => [
            'migrationPath' => [
                '@skeeks/cms/communication/unisender/migrations',
            ],
        ],
        // php yii unisender/webhook <id>, php yii unisender/events <id> [hours] [apply]
        'unisender' => \skeeks\cms\communication\unisender\console\UnisenderController::class,
    ],
];
