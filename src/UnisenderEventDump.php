<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\unisender;

/**
 * Statuses of sent letters asked from Unisender Go directly
 * (`event-dump/create` → `event-dump/get` until ready → CSV files →
 * `event-dump/delete`), for a site the webhook does not reach (a local
 * stand) and for checks. The rows have the shape of webhook events, so
 * WebhookReceiver::event() reads them too.
 */
final class UnisenderEventDump
{
    private UnisenderApi $api;
    /** @var callable fn(string $url): string — downloads a file of the dump */
    private $download;

    public function __construct(UnisenderApi $api, ?callable $download = null)
    {
        $this->api = $api;
        $this->download = $download ?? static function (string $url): string {
            // Files are on the hosts of Unisender Go and need no key.
            if (!preg_match('~^https://[a-z0-9.-]+\.unisender\.ru/~i', $url)) {
                throw new \RuntimeException('Файл выгрузки не на сервере Unisender Go.');
            }
            $data = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 60]]));
            if ($data === false) {
                throw new \RuntimeException('Файл выгрузки Unisender Go не скачан.');
            }
            return $data;
        };
    }

    /**
     * Events since a moment (UTC on the service side).
     *
     * @param array $filter job_id, email, status, campaign_id
     * @return array[] rows: event_time, job_id, email, status, delivery_status, metadata (array), destination_response
     */
    public function fetch(int $since, array $filter = [], int $limit = 1000, int $waitSeconds = 90): array
    {
        $created = $this->api->call('event-dump/create', array_filter([
            'start_time' => gmdate('Y-m-d H:i:s', $since),
            'limit' => $limit,
            'filter' => array_filter($filter, static fn($v) => $v !== null && $v !== '') ?: null,
            'dump_fields' => ['event_time', 'job_id', 'email', 'status', 'delivery_status', 'metadata', 'destination_response'],
            'delimiter' => ',',
            'format' => 'csv',
        ], static fn($v) => $v !== null));
        $dumpId = (string)($created['dump_id'] ?? '');
        if ($dumpId === '') {
            throw new \RuntimeException('Unisender Go не начал выгрузку событий.');
        }
        try {
            $files = [];
            $deadline = time() + $waitSeconds;
            do {
                $dump = (array)($this->api->call('event-dump/get', ['dump_id' => $dumpId])['event_dump'] ?? []);
                $status = (string)($dump['dump_status'] ?? '');
                $files = (array)($dump['files'] ?? []);
                if ($status === 'failed') {
                    throw new \RuntimeException('Unisender Go не смог подготовить выгрузку событий.');
                }
                if ($status === 'ready') {
                    break;
                }
                sleep(3);
            } while (time() < $deadline);
            if ($status !== 'ready') {
                throw new \RuntimeException('Выгрузка событий Unisender Go не готова за '.$waitSeconds.' с, повторите позже.');
            }
            $rows = [];
            foreach ($files as $file) {
                $content = ($this->download)((string)$file['url']);
                if (substr((string)$file['url'], -3) === '.gz') {
                    $content = (string)gzdecode($content);
                }
                $rows = array_merge($rows, self::parseCsv($content));
            }

            return $rows;
        } finally {
            try {
                $this->api->call('event-dump/delete', ['dump_id' => $dumpId]);
            } catch (\Throwable $e) {
                // At most ten dumps are kept; an old one is removed by the service after 8 hours anyway.
            }
        }
    }

    /** Rows of a CSV file of the dump with the header line; metadata decoded. */
    public static function parseCsv(string $content): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        $header = fgetcsv($stream, 0, ',', '"', '\\');
        $rows = [];
        while ($header && ($line = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            if ($line === [null] || count($line) !== count($header)) {
                continue;
            }
            $row = array_combine($header, $line);
            $metadata = json_decode((string)($row['metadata'] ?? ''), true);
            $row['metadata'] = is_array($metadata) ? $metadata : [];
            if (isset($row['delivery_status']) || isset($row['destination_response'])) {
                $row['delivery_info'] = array_filter([
                    'delivery_status' => (string)($row['delivery_status'] ?? ''),
                    'destination_response' => (string)($row['destination_response'] ?? ''),
                ]);
            }
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
