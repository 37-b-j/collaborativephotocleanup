<?php

declare(strict_types=1);

namespace OCA\CollaborativePhotoCleanup\Cron;

use OCA\CollaborativePhotoCleanup\Service\PhashCalculator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\Files\IRootFolder;
use OCP\Files\Folder;
use OCP\Files\File;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class PhashComputeJob extends TimedJob
{
    private const BATCH_SIZE = 500;
    private const CLUSTER_THRESHOLD = 25;
    private const SUPPORTED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        ITimeFactory $time,
        private PhashCalculator $phashCalculator,
        private IRootFolder $rootFolder,
        private IDBConnection $db,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(1800);
        $this->setTimeSensitivity(IJob::TIME_SENSITIVE);
        $this->setAllowParallelRuns(false);
    }

    protected function run($arguments): void
    {
        $this->logger->info('PhashComputeJob: Starte');
        try {
            $pendingScans = $this->getPendingScans();
            if (empty($pendingScans)) {
                $this->processGlobalUnhashedImages();
            } else {
                foreach ($pendingScans as $scan) {
                    $this->processScanJob($scan);
                }
            }
            $this->maybeTriggerClustering();
        } catch (\Throwable $e) {
            $this->logger->error('PhashComputeJob Fehler: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    private function processGlobalUnhashedImages(): void
    {
        $unhashedFiles = $this->findUnhashedImagesGlobally(self::BATCH_SIZE);
        if (empty($unhashedFiles)) return;
        $this->computeAndStoreBatch($unhashedFiles);
    }

    private function processScanJob(array $scan): void
    {
        $scanId = (int)$scan['id'];
        $folderPath = $scan['folder_path'];
        $recursive = (bool)($scan['recursive'] ?? true);
        $this->updateScanStatus($scanId, 'scanning');
        try {
            $imageFiles = $this->findImagesInFolder($folderPath, $recursive, self::BATCH_SIZE);
            if (empty($imageFiles)) {
                $this->completeScan($scanId, 0, 0);
                return;
            }
            $this->updateScanTotals($scanId, count($imageFiles), 0);
            $processed = $this->computeAndStoreBatch($imageFiles);
            $this->completeScan($scanId, count($imageFiles), $processed);
        } catch (\Throwable $e) {
            $this->logger->error('PhashComputeJob processScanJob Fehler (scanId=' . $scanId . '): ' . $e->getMessage(), ['exception' => $e]);
            $this->updateScanStatus($scanId, 'failed');
        }
    }

    private function computeAndStoreBatch(array $files): int
    {
        $processed = 0;
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        foreach ($files as $file) {
            $fileId = (int)$file['fileId'];
            $folderPath = $file['folderPath'] ?? null;
            try {
                $nodes = $this->rootFolder->getById($fileId);
                if (empty($nodes)) continue;
                $node = $nodes[0];
                if (!($node instanceof \OCP\Files\File)) continue;
                $content = $node->getContent();
                if (empty($content)) continue;
                $phash = $this->phashCalculator->calculateFromContent($content);
                if ($phash === null) continue;
                $faceCount = $this->countFaces($content);
                $clusterUid = substr(md5($phash . $fileId), 0, 32);
                $this->upsertClusterRecord($fileId, $phash, $clusterUid, $folderPath, $now, $faceCount);
                $processed++;
            } catch (\Throwable $e) {
                $this->logger->error('PhashComputeJob computeAndStoreBatch Fehler (fileId=' . $fileId . '): ' . $e->getMessage(), ['exception' => $e]);
            }
        }
        return $processed;
    }

    private function upsertClusterRecord(int $fileId, string $phash, string $clusterUid, ?string $folderPath, string $now, int $faceCount = 0): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'phash')
            ->from('photocleanup_clusters')
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, \PDO::PARAM_INT)));
        $result = $qb->executeQuery();
        $existing = $result->fetch();
        $result->closeCursor();
        if ($existing) {
            if ((string)$existing['phash'] !== $phash) {
                $uq = $this->db->getQueryBuilder();
                $uq->update('photocleanup_clusters')
                    ->set('phash', $uq->createNamedParameter($phash, \PDO::PARAM_STR))
                    ->set('cluster_uid', $uq->createNamedParameter($clusterUid))
                    ->set('face_count', $uq->createNamedParameter($faceCount, \PDO::PARAM_INT))
                    ->set('processed_at', $uq->createNamedParameter($now))
                    ->where($uq->expr()->eq('id', $uq->createNamedParameter((int)$existing['id'], \PDO::PARAM_INT)));
                $uq->executeStatement();
            }
        } else {
            $iq = $this->db->getQueryBuilder();
            $iq->insert('photocleanup_clusters')
                ->values([
                    'cluster_uid' => $iq->createNamedParameter($clusterUid),
                    'file_id' => $iq->createNamedParameter($fileId, \PDO::PARAM_INT),
                    'phash' => $iq->createNamedParameter($phash, \PDO::PARAM_STR),
                    'face_count' => $iq->createNamedParameter($faceCount, \PDO::PARAM_INT),
                    'is_favorite' => $iq->createNamedParameter(false, \PDO::PARAM_BOOL),
                    'folder_path' => $iq->createNamedParameter($folderPath),
                    'processed_at' => $iq->createNamedParameter($now),
                ]);
            $iq->executeStatement();
        }
    }

    private function maybeTriggerClustering(): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id', 'cnt'))
            ->from('photocleanup_clusters')
            ->where($qb->expr()->isNotNull('processed_at'))
            ->andWhere($qb->expr()->gte('processed_at', $qb->createNamedParameter((new \DateTime())->modify('-35 minutes')->format('Y-m-d H:i:s'))));
        $result = $qb->executeQuery();
        $newCount = (int)$result->fetch()['cnt'];
        $result->closeCursor();
        if ($newCount === 0) return;
        try {
            $this->performPhpClustering(self::CLUSTER_THRESHOLD);
        } catch (\Throwable $e) {
            $this->logger->error('PhashComputeJob maybeTriggerClustering Fehler: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Holt alle gespeicherten Hashes und clustert sie via Hamming-Distanz in PHP.
     */
    private function performPhpClustering(int $threshold): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'phash')
            ->from('photocleanup_clusters')
            ->where($qb->expr()->isNotNull('phash'))
            ->andWhere($qb->expr()->neq('phash', $qb->createNamedParameter('')))
            ->andWhere($qb->expr()->neq('phash', $qb->createNamedParameter('0')))
            ->setMaxResults(5000);
        $result = $qb->executeQuery();
        $rows = $result->fetchAll(\PDO::FETCH_ASSOC);
        $result->closeCursor();

        if (count($rows) < 2) {
            return 0;
        }

        $n = count($rows);
        $parent = range(0, $n - 1);

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $dist = PhashCalculator::hammingDistance(
                    (string)$rows[$i]['phash'],
                    (string)$rows[$j]['phash']
                );
                if ($dist <= $threshold) {
                    $ra = $this->ufFind($parent, $i);
                    $rb = $this->ufFind($parent, $j);
                    if ($ra !== $rb) {
                        $parent[$rb] = $ra;
                    }
                }
            }
        }

        $groups = [];
        for ($i = 0; $i < $n; $i++) {
            $root = $this->ufFind($parent, $i);
            $groups[$root][] = (int)$rows[$i]['id'];
        }

        $updated = 0;
        foreach ($groups as $group) {
            if (count($group) < 2) continue;
            $newClusterUid = substr(md5(implode(',', $group)), 0, 32);
            foreach ($group as $id) {
                $uq = $this->db->getQueryBuilder();
                $uq->update('photocleanup_clusters')
                    ->set('cluster_uid', $uq->createNamedParameter($newClusterUid))
                    ->where($uq->expr()->eq('id', $uq->createNamedParameter($id, \PDO::PARAM_INT)));
                $uq->executeStatement();
                $updated++;
            }
        }

        $this->logger->info("PhashComputeJob: Clustering abgeschlossen - {$updated} Einträge in " . count($groups) . " Gruppen");

        return $updated;
    }

    private function ufFind(array &$parent, int $x): int
    {
        if ($parent[$x] !== $x) {
            $parent[$x] = $this->ufFind($parent, $parent[$x]);
        }
        return $parent[$x];
    }

    private function getPendingScans(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('photocleanup_scan_st')
            ->where(
            $qb->expr()->orX(
                $qb->expr()->eq('status', $qb->createNamedParameter('pending')),
                $qb->expr()->eq('status', $qb->createNamedParameter('scanning'))
            )
        )
            ->orderBy('created_at', 'ASC')
            ->setMaxResults(5);
        $result = $qb->executeQuery();
        $scans = $result->fetchAll();
        $result->closeCursor();
        return $scans ?: [];
    }

    private function findUnhashedImagesGlobally(int $limit): array
    {
        $sql = "SELECT fc.fileid, fc.path FROM oc_filecache fc ";
        $sql .= "WHERE fc.mimetype IN (4, 7, 41) ";
        $sql .= "AND fc.fileid NOT IN (SELECT DISTINCT pcc.file_id FROM oc_photocleanup_clusters pcc WHERE pcc.phash != '' AND pcc.phash != '0') ";
        $sql .= "LIMIT :limit";
        $stmt = $this->db->executeQuery($sql, ['limit' => $limit], ['limit' => \PDO::PARAM_INT]);
        $files = [];
        while ($row = $stmt->fetch()) {
            $files[] = ['fileId' => (int)$row['fileid'], 'folderPath' => dirname($row['path'])];
        }
        $stmt->closeCursor();
        return $files;
    }

    private function findImagesInFolder(string $folderPath, bool $recursive, int $limit): array
    {
        $files = [];
        try {
            if (!$this->rootFolder->nodeExists($folderPath)) return [];
            $node = $this->rootFolder->get($folderPath);
            if (!$node instanceof Folder) return [];
            $this->collectImagesFromNode($node, $recursive, $files, $limit);
        } catch (\Throwable $e) {
            $this->logger->error('PhashComputeJob findImagesInFolder Fehler (path=' . $folderPath . '): ' . $e->getMessage(), ['exception' => $e]);
        }
        return $files;
    }

    private function collectImagesFromNode(Folder $folder, bool $recursive, array &$files, int $limit): void
    {
        foreach ($folder->getDirectoryListing() as $node) {
            if (count($files) >= $limit) return;
            if ($node instanceof File && in_array($node->getMimeType(), self::SUPPORTED_MIMES, true)) {
                $files[] = ['fileId' => $node->getId(), 'folderPath' => $folder->getPath()];
            } elseif ($recursive && $node instanceof Folder) {
                $this->collectImagesFromNode($node, $recursive, $files, $limit);
            }
        }
    }

    private function updateScanStatus(int $scanId, string $status): void
    {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $qb = $this->db->getQueryBuilder();
        $qb->update('photocleanup_scan_st')
            ->set('status', $qb->createNamedParameter($status))
            ->set('updated_at', $qb->createNamedParameter($now))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($scanId, \PDO::PARAM_INT)));
        $qb->executeStatement();
    }

    private function updateScanTotals(int $scanId, int $totalFiles, int $processedFiles): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update('photocleanup_scan_st')
            ->set('total_files', $qb->createNamedParameter($totalFiles, \PDO::PARAM_INT))
            ->set('processed_files', $qb->createNamedParameter($processedFiles, \PDO::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s')))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($scanId, \PDO::PARAM_INT)));
        $qb->executeStatement();
    }

    private function completeScan(int $scanId, int $totalFiles, int $processedFiles): void
    {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $qb = $this->db->getQueryBuilder();
        $qb->update('photocleanup_scan_st')
            ->set('status', $qb->createNamedParameter('completed'))
            ->set('total_files', $qb->createNamedParameter($totalFiles, \PDO::PARAM_INT))
            ->set('processed_files', $qb->createNamedParameter($processedFiles, \PDO::PARAM_INT))
            ->set('last_scan', $qb->createNamedParameter($now))
            ->set('updated_at', $qb->createNamedParameter($now))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($scanId, \PDO::PARAM_INT)));
        $qb->executeStatement();
    }
    private function countFaces(string $imageData): int {
        $tmp = tmpfile();
        if (!$tmp) return 0;
        $path = stream_get_meta_data($tmp)["uri"];
        fwrite($tmp, $imageData);
        $exif = @exif_read_data($path, "FILE", true);
        fclose($tmp);
        if (!$exif) return 0;
        if (isset($exif["MakerNote"])) {
            $maker = serialize($exif["MakerNote"]);
            $faceCount = substr_count($maker, "Face");
            if ($faceCount > 0) return $faceCount;
        }
        if (isset($exif["XMLPacket"])) {
            $xmp = $exif["XMLPacket"];
            if (preg_match_all("/mwg-rs:RegionList/", $xmp, $matches)) {
                return count($matches[0]);
            }
            $faceCount = substr_count($xmp, "mwg-rs:Region");
            if ($faceCount > 0) return $faceCount;
        }
        return 0;
    }

}
