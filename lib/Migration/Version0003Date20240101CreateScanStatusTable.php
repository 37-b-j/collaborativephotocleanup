<?php
declare(strict_types=1);

namespace OCA\CollaborativePhotoCleanup\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0003Date20240101CreateScanStatusTable extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('photocleanup_scan_st')) {
            $table = $schema->createTable('photocleanup_scan_st');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('folder_path', 'string', [
                'notnull' => true,
                'length' => 1024,
            ]);
            $table->addColumn('recursive', 'boolean', [
                'notnull' => false,
                'default' => true,
            ]);
            $table->addColumn('total_files', 'integer', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('processed_files', 'integer', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('last_scan', 'datetime', [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('status', 'string', [
                'notnull' => true,
                'length' => 16,
                'default' => 'pending',
                'comment' => 'pending, scanning, completed, failed',
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('updated_at', 'datetime', [
                'notnull' => false,
                'default' => null,
            ]);
            $table->setPrimaryKey(['id']);
        }

        return $schema;
    }
}
