<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\unisender\controllers;

use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\unisender\UnisenderCallbacks;
use skeeks\cms\communication\unisender\UnisenderDriver;
use skeeks\cms\communication\unisender\WebhookReceiver;
use yii\web\Response;

/**
 * Webhook of Unisender Go: `POST /~unisender/<connection id>`. Checks the
 * signature (`auth` — MD5 of the body with the API key in its place),
 * stores the body as it came (UnisenderCallbacks) and answers 200 at once:
 * the service waits 3 seconds. Applying is the job comm.unisender.apply.
 * A body over WebhookReceiver::MAX_BODY answers 413, a wrong signature 403,
 * a failure to store 500 (the service calls
 * again in 10 minutes). No session, no CSRF: the signature is the access.
 */
class UnisenderWebhookController extends \yii\web\Controller
{
    public $enableCsrfValidation = false;

    public function actionReceive($id)
    {
        $response = \Yii::$app->response;
        $response->format = Response::FORMAT_JSON;
        $request = \Yii::$app->request;
        if (!$request->isPost) {
            $response->statusCode = 405;
            return ['status' => 'error'];
        }
        $connection = CmsCommConnection::findOne((int)$id);
        if (!$connection || (string)$connection->driver !== UnisenderDriver::ID || !$connection->is_active) {
            $response->statusCode = 404;
            return ['status' => 'error'];
        }
        try {
            $driver = $connection->createDriver(true);
        } catch (\Throwable $e) {
            \Yii::error('Вебхук Unisender Go #'.(int)$connection->id.': ключ не читается: '.$e->getMessage(), self::class);
            $response->statusCode = 500;
            return ['status' => 'error'];
        }
        $raw = self::rawBody($request);
        $body = $raw === null ? null : WebhookReceiver::body($raw);
        if ($body === null) {
            $response->statusCode = 413;
            return ['status' => 'error'];
        }
        if (!$driver instanceof UnisenderDriver || !WebhookReceiver::verify($body, (string)$driver->api_key)) {
            $response->statusCode = 403;
            return ['status' => 'error'];
        }
        try {
            // A repeated call is stored once, but it still wakes the job while calls wait:
            // a failure between storing and pushing must not leave them behind.
            if (UnisenderCallbacks::store($connection, $body) || UnisenderCallbacks::waiting($connection)) {
                UnisenderCallbacks::push($connection);
            }
        } catch (\Throwable $e) {
            \Yii::error('Вебхук Unisender Go #'.(int)$connection->id.': не записан: '.$e->getMessage(), self::class);
            $response->statusCode = 500;
            return ['status' => 'error'];
        }

        return ['status' => 'ok'];
    }

    /**
     * The raw body, never more than WebhookReceiver::MAX_BODY bytes in memory:
     * Content-Length is checked first, the input is read up to the limit + 1.
     * Null — the body is too large.
     */
    private static function rawBody(\yii\web\Request $request): ?string
    {
        $length = $request->headers->get('Content-Length');
        if ($length !== null && is_numeric($length) && (int)$length > WebhookReceiver::MAX_BODY) {
            return null;
        }
        $input = @fopen('php://input', 'rb');
        $raw = $input ? (string)stream_get_contents($input, WebhookReceiver::MAX_BODY + 1) : '';
        if ($input) {
            fclose($input);
        }
        if ($raw === '') {
            // Outside a web server (tests) the body was set on the request.
            $raw = (string)$request->getRawBody();
        }

        return strlen($raw) > WebhookReceiver::MAX_BODY ? null : $raw;
    }
}
