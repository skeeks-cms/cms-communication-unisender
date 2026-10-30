<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\unisender\models;

use yii\db\ActiveRecord;

/**
 * One call of the Unisender Go webhook as it came (after the signature
 * check), applied later by the job comm.unisender.apply.
 *
 * @property int         $id
 * @property int|null    $created_at
 * @property int         $cms_comm_connection_id
 * @property string      $body_hash
 * @property string      $body
 * @property string      $status
 * @property int         $attempts
 * @property int|null    $events
 * @property int|null    $applied_at
 * @property string|null $error
 */
class CmsCommUnisenderCallback extends ActiveRecord
{
    public const STATUS_NEW = 'new';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_FAILED = 'failed';

    /** Attempts to apply before a call stays failed with its reason. */
    public const MAX_ATTEMPTS = 5;

    public static function tableName()
    {
        return '{{%cms_comm_unisender_callback}}';
    }
}
