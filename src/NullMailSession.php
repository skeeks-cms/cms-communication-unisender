<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\unisender;

use skeeks\cms\communication\mail\MailSessionInterface;

/**
 * A mailing service has no folders: nothing to receive, nowhere to store a
 * copy. The CRM row of the letter is its copy.
 */
class NullMailSession implements MailSessionInterface
{
    public function folders(): array
    {
        return [];
    }

    public function select(string $folder): array
    {
        return ['uidvalidity' => null, 'uidnext' => null];
    }

    public function uidsAfter(int $uid): array
    {
        return [];
    }

    public function uidsSince(int $timestamp): array
    {
        return [];
    }

    public function fetch(array $uids): array
    {
        return [];
    }

    public function hasMessageId(string $folder, string $messageId): bool
    {
        return false;
    }

    public function append(string $folder, string $raw): void
    {
    }

    public function close(): void
    {
    }
}
