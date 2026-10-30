<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\unisender;

/**
 * HTTP calls of the Unisender Go Web API (https://godocs.unisender.ru/web-api-ref):
 * `POST https://<server>/ru/transactional/api/v1/<method>.json` with a JSON
 * body. The key goes only in the X-API-KEY header, never into an address,
 * a log or an exception.
 *
 * The server is the one the account was registered on (go1 or go2: the host
 * of the personal account), `goapi.unisender.ru` finds it itself.
 *
 * The transport is replaceable for tests: a callable
 * fn(string $url, array $headers, string $body, int $timeout): array{status: int, body: string, headers: array}
 * that throws \RuntimeException when the network fails.
 */
class UnisenderApi
{
    public const SERVERS = [
        'go1.unisender.ru' => 'go1.unisender.ru',
        'go2.unisender.ru' => 'go2.unisender.ru',
        'goapi.unisender.ru' => 'goapi.unisender.ru (определит сам)',
    ];

    public const PATH = '/ru/transactional/api/v1/';

    private string $apiKey;
    private string $server;
    /** @var callable|null */
    private $transport;

    public function __construct(string $apiKey, string $server, ?callable $transport = null)
    {
        $this->apiKey = trim($apiKey);
        $server = strtolower(trim($server));
        $this->server = isset(self::SERVERS[$server]) ? $server : 'goapi.unisender.ru';
        $this->transport = $transport;
    }

    /**
     * A call of the API.
     *
     * @throws UnisenderApiException the API answered with an error status (nothing done)
     * @throws \RuntimeException the network failed or the answer is not JSON (the outcome is unknown)
     */
    public function call(string $method, array $body = [], int $timeout = 30): array
    {
        if ($this->apiKey === '') {
            throw new UnisenderApiException(401, 101, 'Не задан API-ключ Unisender Go.');
        }
        $url = 'https://'.$this->server.self::PATH.$method.'.json';
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-KEY: '.$this->apiKey,
        ];
        $payload = json_encode($body ?: new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $response = $this->send($url, $headers, (string)$payload, $timeout);

        $data = json_decode((string)$response['body'], true);
        $status = (int)$response['status'];
        if ($status >= 400 || (is_array($data) && ($data['status'] ?? null) === 'error')) {
            $headers = array_change_key_case($response['headers'] ?? [], CASE_LOWER);
            $retry = isset($headers['retry-after']) && is_numeric($headers['retry-after']) ? (int)$headers['retry-after'] : null;
            throw new UnisenderApiException($status ?: 400, is_array($data) ? (int)($data['code'] ?? 0) : 0,
                is_array($data) ? (string)($data['message'] ?? '') : '', $retry, is_array($data) ? $data : []);
        }
        if (!is_array($data)) {
            throw new \RuntimeException('Unisender Go ответил не JSON: HTTP '.$status);
        }

        return $data;
    }

    private function send(string $url, array $headers, string $body, int $timeout): array
    {
        if ($this->transport) {
            return ($this->transport)($url, $headers, $body, $timeout);
        }

        $curl = curl_init($url);
        $responseHeaders = [];
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$responseHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[trim($parts[0])] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        $result = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($result === false) {
            // The message of curl has the host, never the key (it is in a header).
            throw new \RuntimeException('Нет ответа от Unisender Go: '.$error);
        }

        return ['status' => $status, 'body' => (string)$result, 'headers' => $responseHeaders];
    }
}
