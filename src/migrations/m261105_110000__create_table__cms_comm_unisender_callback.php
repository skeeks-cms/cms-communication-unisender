<?php

use yii\db\Migration;

/**
 * Unisender Go webhooks stored as they came (the body after the signature
 * check), before applying: the web request only writes a row and answers 200
 * at once, the job comm.unisender.apply turns it into delivery events.
 *
 * Repeatable: a run stopped after any statement is run again and adds only
 * what is missing. The rollback refuses while calls are not applied (waiting
 * or failed).
 */
class m261105_110000__create_table__cms_comm_unisender_callback extends Migration
{
    private const TABLE = '{{%cms_comm_unisender_callback}}';

    public function safeUp()
    {
        $tableOptions = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;

        $table = self::TABLE;
        if (!$this->db->getTableSchema($table, true)) {
            $this->createTable($table, [
                'id'                     => $this->primaryKey(),
                'created_at'             => $this->integer()->null(),
                'cms_comm_connection_id' => $this->integer()->notNull(),
                'body_hash'              => $this->string(40)->notNull()->comment('sha1 тела: тот же вызов не пишется дважды'),
                'body'                   => $this->getDb()->getSchema()->createColumnSchemaBuilder('mediumtext')->notNull()
                    ->comment('Тело вызова; после применения очищается'),
                'status'                 => $this->string(16)->notNull()->defaultValue('new')->comment('new, applied, failed'),
                'attempts'               => $this->integer()->notNull()->defaultValue(0),
                'events'                 => $this->integer()->null()->comment('Сколько событий записано'),
                'applied_at'             => $this->integer()->null(),
                'error'                  => $this->string(1000)->null(),
            ], $tableOptions);
            $this->addCommentOnTable($table, 'Вебхуки Unisender Go: события доставки как пришли, до применения');
        }
        if (!$this->hasIndex('cms_comm_unisender_callback__hash')) {
            $this->createIndex('cms_comm_unisender_callback__hash', $table, ['cms_comm_connection_id', 'body_hash'], true);
        }
        if (!$this->hasIndex('cms_comm_unisender_callback__status')) {
            $this->createIndex('cms_comm_unisender_callback__status', $table, ['cms_comm_connection_id', 'status', 'id']);
        }
        if (!isset($this->db->getTableSchema($table, true)->foreignKeys['cms_comm_unisender_callback__connection_id'])) {
            $this->addForeignKey('cms_comm_unisender_callback__connection_id', $table, 'cms_comm_connection_id', '{{%cms_comm_connection}}', 'id', 'CASCADE', 'CASCADE');
        }
    }

    public function safeDown()
    {
        if (!$this->db->getTableSchema(self::TABLE, true)) {
            return true;
        }
        // Waiting and failed calls were never applied: their events would be lost.
        $waiting = (new \yii\db\Query())->from(self::TABLE)->andWhere(['status' => ['new', 'failed']])->count('*', $this->db);
        if ($waiting) {
            echo "Откат невозможен: {$waiting} вызовов вебхука Unisender Go не применены (события доставки потеряются).\n";

            return false;
        }
        $this->dropTable(self::TABLE);

        return true;
    }

    private function hasIndex(string $name): bool
    {
        return in_array($name, array_map(static fn($index) => $index->name, $this->db->getSchema()->getTableIndexes(self::TABLE, true)), true);
    }
}
