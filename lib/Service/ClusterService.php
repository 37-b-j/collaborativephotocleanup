<?php
declare(strict_types=1);

namespace OCA\CollaborativePhotoCleanup\Service;

use OCP\Files\IRootFolder;
use OCP\Files\Folder;
use OCP\Files\File;
use OCP\IUserSession;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class ClusterService {
    private IRootFolder $rootFolder;
    private IUserSession $userSession;
    private IDBConnection $db;
    private LoggerInterface $logger;
    private PhashCalculator $phashCalculator;

    public function __construct(
        IRootFolder $rootFolder,
        IUserSession $userSession,
        IDBConnection $db,
        PhashCalculator $phashCalculator,
        ?LoggerInterface $logger = null
    ) {
        $this->rootFolder = $rootFolder;
        $this->userSession = $userSession;
        $this->db = $db;
        $this->phashCalculator = $phashCalculator;
        $this->logger = $logger ?? \OCP\Server::get(LoggerInterface::class);
    }

    private function getUserFolder(): Folder {
        $user = $this->userSession->getUser();
        if (!$user) throw new \RuntimeException("Not logged in");
        return $this->rootFolder->getUserFolder($user->getUID());
    }

    public function countFacesFromEXIF(string $imageData): int {
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

    private static function ufFind(array &$parent, int $x): int {
        if ($parent[$x] !== $x) {
            $parent[$x] = self::ufFind($parent, $parent[$x]);
        }
        return $parent[$x];
    }

    private static function ufUnion(array &$parent, int $a, int $b): void {
        $ra = self::ufFind($parent, $a);
        $rb = self::ufFind($parent, $b);
        if ($ra !== $rb) {
            $parent[$rb] = $ra;
        }
    }

    private function getCachedPHashes(array $fileIds): array {
        if (empty($fileIds)) return [];
        $result = [];
        try {
            $placeholders = implode(",", array_fill(0, count($fileIds), "?"));
            $stmt = $this->db->executeQuery(
                "SELECT file_id, phash, face_count FROM oc_photocleanup_clusters WHERE file_id IN ($placeholders)",
                $fileIds,
                array_fill(0, count($fileIds), \PDO::PARAM_INT)
            );
            while ($row = $stmt->fetch()) {
                $hash = (string)$row["phash"];
                if ($hash !== '' && $hash !== '0') {
                    $result[(int)$row["file_id"]] = [
                        'phash' => $hash,
                        'face_count' => (int)($row['face_count'] ?? 0),
                    ];
                }
            }
            $stmt->closeCursor();
        } catch (\Exception $e) {
            $this->logger->error('ClusterService getCachedPHashes Fehler: ' . $e->getMessage(), ['exception' => $e]);
        }
        return $result;
    }

    private function storePHash(int $fileId, string $phash, string $folderPath, int $faceCount = 0): void {
        try {
            $now = (new \DateTime())->format("Y-m-d H:i:s");
            $clusterUid = substr(md5($phash . $fileId), 0, 32);

            $qb = $this->db->getQueryBuilder();
            $qb->select("id")
                ->from("photocleanup_clusters")
                ->where($qb->expr()->eq("file_id", $qb->createNamedParameter($fileId, \PDO::PARAM_INT)));
            $existing = $qb->executeQuery()->fetch();
            if ($existing) {
                $uq = $this->db->getQueryBuilder();
                $uq->update("photocleanup_clusters")
                    ->set("phash", $uq->createNamedParameter($phash, \PDO::PARAM_STR))
                    ->set("cluster_uid", $uq->createNamedParameter($clusterUid))
                    ->set("face_count", $uq->createNamedParameter($faceCount, \PDO::PARAM_INT))
                    ->set("processed_at", $uq->createNamedParameter($now))
                    ->where($uq->expr()->eq("id", $uq->createNamedParameter((int)$existing["id"], \PDO::PARAM_INT)));
                $uq->executeStatement();
            } else {
                $iq = $this->db->getQueryBuilder();
                $iq->insert("photocleanup_clusters")
                    ->values([
                        "cluster_uid" => $iq->createNamedParameter($clusterUid),
                        "file_id" => $iq->createNamedParameter($fileId, \PDO::PARAM_INT),
                        "phash" => $iq->createNamedParameter($phash, \PDO::PARAM_STR),
                        "face_count" => $iq->createNamedParameter($faceCount, \PDO::PARAM_INT),
                        "is_favorite" => $iq->createNamedParameter(false, \PDO::PARAM_BOOL),
                        "folder_path" => $iq->createNamedParameter($folderPath),
                        "processed_at" => $iq->createNamedParameter($now),
                    ]);
                $iq->executeStatement();
            }
        } catch (\Exception $e) {
            $this->logger->error('ClusterService storePHash Fehler (fileId=' . $fileId . '): ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    private const MAX_ON_DEMAND_HASHES = 200;

    public function findClusters(Folder $folder, int $threshold = 25): array {
        \set_time_limit(300);
        try {
            $target = $folder;

            $images = [];
            $this->collectImages($target, $images);

            if (count($images) < 2) {
                return [
                    'clusters' => [],
                    'totalImages' => count($images),
                    'hashedImages' => count($images),
                    'needsScan' => false,
                ];
            }

            $fileIds = array_map(fn($img) => $img["node"]->getId(), $images);
            $cachedHashes = $this->getCachedPHashes($fileIds);

            $uncachedCount = 0;
            $computedCount = 0;
            $totalImages = count($images);

            $hashes = [];
            foreach ($images as $index => $img) {
                $fileId = $img["node"]->getId();
                $cached = $cachedHashes[$fileId] ?? null;

                if ($cached !== null) {
                    $hash = $cached['phash'];
                    $faceCount = $cached['face_count'];
                } else {
                    if ($computedCount >= self::MAX_ON_DEMAND_HASHES) {
                        $uncachedCount++;
                        continue;
                    }
                    try {
                        $content = $img["node"]->getContent();
                        $hash = $this->phashCalculator->calculateFromContent($content);
                        if ($hash === null) continue;
                        $faceCount = $this->countFacesFromEXIF($content);
                        $this->storePHash($fileId, $hash, $img["node"]->getParent()->getPath(), $faceCount);
                        $computedCount++;
                    } catch (\Exception $e) {
                        $this->logger->error('ClusterService findClusters hash Fehler (fileId=' . $fileId . '): ' . $e->getMessage(), ['exception' => $e]);
                        continue;
                    }
                }

                $hashes[] = [
                    "index" => $index,
                    "hash" => $hash,
                    "faces" => $faceCount,
                ];
            }

            $cachedOnlyCount = count($cachedHashes);
            $hashedImages = $cachedOnlyCount + $computedCount;
            $skippedImages = $uncachedCount;
            $needsScan = $skippedImages > 0;

            if (count($hashes) < 2) {
                return [
                    'clusters' => [],
                    'totalImages' => $totalImages,
                    'hashedImages' => $hashedImages,
                    'needsScan' => $needsScan,
                ];
            }

            $n = count($hashes);
            $parent = range(0, $n - 1);

            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $dist = PhashCalculator::hammingDistance($hashes[$i]["hash"], $hashes[$j]["hash"]);
                    if ($dist <= $threshold) {
                        self::ufUnion($parent, $i, $j);
                    }
                }
            }

            $groups = [];
            for ($i = 0; $i < $n; $i++) {
                $root = self::ufFind($parent, $i);
                if (!isset($groups[$root])) $groups[$root] = [];
                $groups[$root][] = $hashes[$i];
            }

            $clusters = [];
            $clusterId = 0;
            foreach ($groups as $group) {
                if (count($group) < 2) continue;

                usort($group, function ($a, $b) use ($images) {
                    if ($a["faces"] !== $b["faces"]) return $b["faces"] - $a["faces"];
                    $sa = $images[$a["index"]]["node"]->getSize();
                    $sb = $images[$b["index"]]["node"]->getSize();
                    return $sb - $sa;
                });

                $clusterImages = [];
                foreach ($group as $item) {
                    $img = $images[$item["index"]];
                    $clusterImages[] = [
                        "fileId" => $img["node"]->getId(),
                        "name" => $img["node"]->getName(),
                        "size" => $img["node"]->getSize(),
                        "mimeType" => $img["node"]->getMimeType(),
                        "path" => $img["node"]->getPath(),
                        "faces" => $item["faces"],
                    ];
                }

                $clusterId++;
                $clusters[] = [
                    "id" => $clusterId,
                    "images" => $clusterImages,
                    "count" => count($clusterImages),
                    "favorite" => $clusterImages[0],
                    "totalFaces" => array_sum(array_column($clusterImages, "faces")),
                ];
            }

            return [
                'clusters' => $clusters,
                'totalImages' => $totalImages,
                'hashedImages' => $hashedImages,
                'needsScan' => $needsScan,
            ];
        } catch (\Exception $e) {
            $this->logger->error('ClusterService findClusters Fehler: ' . $e->getMessage(), ['exception' => $e]);
            return [
                'clusters' => [],
                'totalImages' => 0,
                'hashedImages' => 0,
                'needsScan' => false,
            ];
        }
    }

    /**
     * Gibt Scan-Status für einen Ordner zurück: wie viele Bilder insgesamt,
     * wie viele bereits gehasht sind, wie viele noch fehlen.
     */
    public function getScanStatus(Folder $folder): array {
        try {
            $images = [];
            $this->collectImages($folder, $images);
            $totalImages = count($images);
            if ($totalImages === 0) {
                return ['totalImages' => 0, 'hashedImages' => 0, 'pendingImages' => 0, 'ready' => true];
            }

            $fileIds = array_map(fn($img) => $img["node"]->getId(), $images);
            $cached = $this->getCachedPHashes($fileIds);
            $hashedImages = count($cached);
            $pendingImages = $totalImages - $hashedImages;

            return [
                'totalImages' => $totalImages,
                'hashedImages' => $hashedImages,
                'pendingImages' => $pendingImages,
                'ready' => $pendingImages === 0,
            ];
        } catch (\Exception $e) {
            $this->logger->error('ClusterService getScanStatus Fehler: ' . $e->getMessage(), ['exception' => $e]);
            return ['totalImages' => 0, 'hashedImages' => 0, 'pendingImages' => 0, 'ready' => true];
        }
    }

    private function collectImages(Folder $folder, array &$result): void {
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node instanceof File && $this->isImage($node)) {
                $result[] = ["node" => $node];
            } elseif ($node instanceof Folder) {
                $this->collectImages($node, $result);
            }
        }
    }

    private function isImage(File $file): bool {
        return str_starts_with($file->getMimeType(), "image/");
    }
}