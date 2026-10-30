<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\unisender;

/**
 * Unisender Go answered with an error: `status` is the HTTP code, `apiCode`
 * the API code (https://godocs.unisender.ru/web-api-ref#api-errors). The
 * request was refused, nothing was sent. The message never contains the key.
 */
class UnisenderApiException extends \RuntimeException
{
    /** Readable reasons of the codes a person can fix. */
    private const CODES = [
        101 => 'Не задан API-ключ.',
        102 => 'Неверный API-ключ: скопируйте его заново в личном кабинете Unisender Go (Аккаунт → Безопасность).',
        105 => 'Доступ к API выключен в настройках аккаунта Unisender Go.',
        106 => 'Аккаунт Unisender Go неактивен: обратитесь в их поддержку.',
        114 => 'Аккаунт не найден на этом сервере: выберите сервер go1 или go2 — тот, что в адресе вашего личного кабинета.',
        204 => 'Ни один получатель не принят.',
        205 => 'Неверный адрес отправителя.',
        206 => 'Адрес отправителя не на подтверждённом домене: добавьте и подтвердите домен в Unisender Go (Настройки → Домены отправки).',
        215 => 'Аккаунту Unisender Go запрещена отправка: обратитесь в их поддержку.',
        221 => 'В HTML письма нет тега <body>.',
    ];

    public int $status;
    public int $apiCode;
    public ?int $retryAfter;
    /** The decoded answer of the service, e.g. `failed_emails` of a refusal of every recipient (code 204). */
    public array $data = [];

    public function __construct(int $status, int $apiCode, string $message, ?int $retryAfter = null, array $data = [])
    {
        $text = self::CODES[$apiCode] ?? '';
        if ($text === '' && $status === 429) {
            $text = 'Слишком много запросов: Unisender Go просит подождать.';
        }
        $original = trim($message);
        parent::__construct(trim($text.($original !== '' ? ' (Unisender Go: '.mb_substr($original, 0, 300).')' : ''))
            ?: 'Ошибка Unisender Go: HTTP '.$status.($apiCode ? ', код '.$apiCode : ''));
        $this->status = $status;
        $this->apiCode = $apiCode;
        $this->retryAfter = $retryAfter;
        $this->data = $data;
    }

    /** The refusal is temporary: "too many requests" or the server is busy. */
    public function isTemporary(): bool
    {
        return $this->status === 429;
    }
}
