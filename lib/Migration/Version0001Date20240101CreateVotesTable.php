<?php
declare(strict_types=1);

namespace OCA\CollaborativePhotoCleanup\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0001Date20240101CreateVotesTable extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('photocleanup_votes')) {
            $table = $schema->createTable('photocleanup_votes');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('file_id', 'bigint', [
                'notnull' => true,
            ]);
            $table->addColumn('user_id', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('vote', 'smallint', [
                'notnull' => true,
                'comment' => '0=delete, 1=keep',
            ]);
            $table->addColumn('voted_at', 'datetime', [
                'notnull' => false,
                'default' => null,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['file_id', 'user_id'], 'unique_vote');
            $table->addIndex(['file_id'], 'idx_votes_file_id');
            $table->addIndex(['user_id'], 'idx_votes_user_id');
            $table->addIndex(['vote'], 'idx_votes_vote');
        }

        return $schema;
    }
}
