<?php
declare(strict_types=1);

namespace OCA\CollaborativePhotoCleanup\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0002Date20240101CreateClustersTable extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('photocleanup_clusters')) {
            $table = $schema->createTable('photocleanup_clusters');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('cluster_uid', 'string', [
                'notnull' => true,
                'length' => 32,
            ]);
            $table->addColumn('file_id', 'bigint', [
                'notnull' => true,
            ]);
            $table->addColumn('phash', 'bigint', [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('face_count', 'integer', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('is_favorite', 'boolean', [
                'notnull' => false,
                'default' => false,
            ]);
            $table->addColumn('similarity_score', 'float', [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('folder_path', 'string', [
                'notnull' => false,
                'length' => 1024,
                'default' => null,
            ]);
            $table->addColumn('processed_at', 'datetime', [
                'notnull' => false,
                'default' => null,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['cluster_uid'], 'idx_cluster_uid');
            $table->addIndex(['phash'], 'idx_clusters_phash');
            $table->addIndex(['face_count'], 'idx_clusters_face_count');
            $table->addIndex(['file_id'], 'idx_clusters_file_id');
            // $table->addIndex(['folder_path'], 'idx_clusters_folder_path');
        }

        return $schema;
    }
}
