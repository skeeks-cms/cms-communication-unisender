<?php

return [
    // The webhook of Unisender Go posts delivery events here (controllers\UnisenderWebhookController).
    'controllerMap' => [
        'unisender-webhook' => \skeeks\cms\communication\unisender\controllers\UnisenderWebhookController::class,
    ],
    'components' => [
        'urlManager' => [
            'rules' => [
                'POST ~unisender/<id:\d+>' => 'unisender-webhook/receive',
            ],
        ],
    ],
];
