<?php
declare(strict_types=1);

namespace OCA\CollaborativePhotoCleanup\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0004Date20240102MigratePhashToHex extends SimpleMigrationStep {

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('photocleanup_clusters');
        if ($table->hasColumn('phash')) {
            $column = $table->getColumn('phash');
            $type = $column->getType();
            $length = $column->getLength() ?? 0;

            if ($type->getName() === 'string' && $length >= 64) {
                return $schema;
            }

            $table->changeColumn('phash', [
                'type' => 'string',
                'length' => 64,
            ]);
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('photocleanup_clusters')
            ->where($qb->expr()->neq('phash', $qb->createNamedParameter('')));
        $deleted = $qb->executeStatement();
        $output->info("Cleared {$deleted} stale hash records for migration to 16x16 hex format");
    }
}