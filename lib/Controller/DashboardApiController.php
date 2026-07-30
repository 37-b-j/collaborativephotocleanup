<?php

declare(strict_types=1);

namespace OCA\CollaborativePhotoCleanup\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\Files\IRootFolder;
use OCP\Files\File;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;
use OCA\CollaborativePhotoCleanup\Service\GroupFolderService;

class DashboardApiController extends Controller
{
    private const VOTE_DELETE = 0;
    private const VOTE_KEEP = 1;

    public function __construct(
        string $appName,
        IRequest $request,
        private IDBConnection $db,
        private IUserSession $userSession,
        private IRootFolder $rootFolder,
        private LoggerInterface $logger,
        private ?GroupFolderService $groupFolderService = null,
    ) {
        parent::__construct($appName, $request);
    }

    private function getUserId(): ?string
    {
        $user = $this->userSession->getUser();
        return $user ? $user->getUID() : null;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * GET /api/v1/dashboard/unvoted
     * Gibt Dateien zuruck, die der Benutzer noch nicht bewertet hat.
     */
    public function unvoted(): JSONResponse
    {
        $userId = $this->getUserId();
        if (!$userId) {
            return new JSONResponse(["error" => "Not authenticated"], 401);
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $allImages = [];
            $this->collectImages($userFolder, $allImages);

            // Hole alle vom User bereits gevoteten fileIds
            $qb = $this->db->getQueryBuilder();
            $qb->select("file_id")
                ->from("photocleanup_votes")
                ->where($qb->expr()->eq("user_id", $qb->createNamedParameter($userId)));
            $result = $qb->executeQuery();
            $votedIds = [];
            while ($row = $result->fetch()) {
                $votedIds[(int)$row["file_id"]] = true;
            }
            $result->closeCursor();

            $unvoted = [];
            foreach ($allImages as $img) {
                if (!isset($votedIds[$img["fileId"]])) {
                    $unvoted[] = $img;
                }
            }

            return new JSONResponse([
                "images" => $unvoted,
                "total" => count($unvoted),
                "totalAll" => count($allImages),
                "voted" => count($votedIds),
            ]);
        } catch (\Exception $e) {
            $this->logger->error("Dashboard unvoted error", ["error" => $e->getMessage()]);
            return new JSONResponse(["error" => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * GET /api/v1/dashboard/my-votes
     * Gibt die eigenen Votes detailliert zuruck (mit Datei-Infos).
     */
    public function myVotes(): JSONResponse
    {
        $userId = $this->getUserId();
        if (!$userId) {
            return new JSONResponse(["error" => "Not authenticated"], 401);
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select("file_id", "vote", "voted_at")
                ->from("photocleanup_votes")
                ->where($qb->expr()->eq("user_id", $qb->createNamedParameter($userId)))
                ->orderBy("voted_at", "DESC");

            $result = $qb->executeQuery();
            $votes = [];
            $userFolder = $this->rootFolder->getUserFolder($userId);

            while ($row = $result->fetch()) {
                $fileId = (int)$row["file_id"];
                $fileInfo = null;
                try {
                    $nodes = $userFolder->getById($fileId);
                    if (!empty($nodes) && $nodes[0] instanceof File) {
                        $fileInfo = [
                            "name" => $nodes[0]->getName(),
                            "size" => $nodes[0]->getSize(),
                            "mimeType" => $nodes[0]->getMimeType(),
                            "path" => $nodes[0]->getPath(),
                        ];
                    }
                } catch (\Exception $e) {
                    // File might be deleted
                }

                $votes[] = [
                    "fileId" => $fileId,
                    "vote" => (int)$row["vote"],
                    "votedAt" => $row["voted_at"],
                    "fileInfo" => $fileInfo,
                ];
            }
            $result->closeCursor();

            return new JSONResponse([
                "votes" => $votes,
                "total" => count($votes),
                "keep" => count(array_filter($votes, fn($v) => $v["vote"] === self::VOTE_KEEP)),
                "delete" => count(array_filter($votes, fn($v) => $v["vote"] === self::VOTE_DELETE)),
            ]);
        } catch (\Exception $e) {
            $this->logger->error("Dashboard myVotes error", ["error" => $e->getMessage()]);
            return new JSONResponse(["error" => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * GET /api/v1/dashboard/statistics
     * Aggregierte Statistiken uber alle Votes.
     */
    public function statistics(): JSONResponse
    {
        $userId = $this->getUserId();
        if (!$userId) {
            return new JSONResponse(["error" => "Not authenticated"], 401);
        }

        try {
            // Total votes
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count("id", "cnt"))
                ->from("photocleanup_votes");
            $r = $qb->executeQuery();
            $totalVotes = (int)$r->fetch()["cnt"];
            $r->closeCursor();

            // My votes
            $qb2 = $this->db->getQueryBuilder();
            $qb2->select($qb2->func()->count("id", "cnt"))
                ->from("photocleanup_votes")
                ->where($qb2->expr()->eq("user_id", $qb2->createNamedParameter($userId)));
            $r2 = $qb2->executeQuery();
            $myVotes = (int)$r2->fetch()["cnt"];
            $r2->closeCursor();

            // My keep
            $qb3 = $this->db->getQueryBuilder();
            $qb3->select($qb3->func()->count("id", "cnt"))
                ->from("photocleanup_votes")
                ->where($qb3->expr()->eq("user_id", $qb3->createNamedParameter($userId)))
                ->andWhere($qb3->expr()->eq("vote", $qb3->createNamedParameter(self::VOTE_KEEP, \PDO::PARAM_INT)));
            $r3 = $qb3->executeQuery();
            $myKeep = (int)$r3->fetch()["cnt"];
            $r3->closeCursor();

            // My delete
            $qb4 = $this->db->getQueryBuilder();
            $qb4->select($qb4->func()->count("id", "cnt"))
                ->from("photocleanup_votes")
                ->where($qb4->expr()->eq("user_id", $qb4->createNamedParameter($userId)))
                ->andWhere($qb4->expr()->eq("vote", $qb4->createNamedParameter(self::VOTE_DELETE, \PDO::PARAM_INT)));
            $r4 = $qb4->executeQuery();
            $myDelete = (int)$r4->fetch()["cnt"];
            $r4->closeCursor();

            // Unique users
            $qb5 = $this->db->getQueryBuilder();
            $qb5->select($qb5->func()->count("DISTINCT user_id", "cnt"))
                ->from("photocleanup_votes");
            $r5 = $qb5->executeQuery();
            $activeUsers = (int)$r5->fetch()["cnt"];
            $r5->closeCursor();

            // Total images in user folder
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $allImages = [];
            $this->collectImages($userFolder, $allImages);

            return new JSONResponse([
                "totalVotes" => $totalVotes,
                "myVotes" => $myVotes,
                "myKeep" => $myKeep,
                "myDelete" => $myDelete,
                "totalImages" => count($allImages),
                "unvoted" => count($allImages) - $myVotes,
                "activeUsers" => $activeUsers,
                "progress" => count($allImages) > 0
                    ? round(($myVotes / count($allImages)) * 100, 1)
                    : 0,
            ]);
        } catch (\Exception $e) {
            $this->logger->error("Dashboard statistics error", ["error" => $e->getMessage()]);
            return new JSONResponse(["error" => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * GET /api/v1/dashboard/group-votes
     * Zeigt alle Votes gruppiert nach Datei (alle Benutzer).
     */
    public function groupVotes(): JSONResponse
    {
        $userId = $this->getUserId();
        if (!$userId) {
            return new JSONResponse(["error" => "Not authenticated"], 401);
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select("file_id", "user_id", "vote", "voted_at")
                ->from("photocleanup_votes")
                ->orderBy("file_id", "ASC")
                ->addOrderBy("voted_at", "DESC");

            $result = $qb->executeQuery();
            $grouped = [];
            while ($row = $result->fetch()) {
                $fid = (int)$row["file_id"];
                if (!isset($grouped[$fid])) {
                    $grouped[$fid] = [
                        "fileId" => $fid,
                        "votes" => [],
                        "keepCount" => 0,
                        "deleteCount" => 0,
                        "totalVotes" => 0,
                    ];
                }
                $vote = (int)$row["vote"];
                $grouped[$fid]["votes"][] = [
                    "userId" => $row["user_id"],
                    "vote" => $vote,
                    "votedAt" => $row["voted_at"],
                ];
                if ($vote === self::VOTE_KEEP) $grouped[$fid]["keepCount"]++;
                elseif ($vote === self::VOTE_DELETE) $grouped[$fid]["deleteCount"]++;
                $grouped[$fid]["totalVotes"]++;
            }
            $result->closeCursor();

            // Hole Datei-Infos
            $userFolder = $this->rootFolder->getUserFolder($userId);
            foreach ($grouped as $fid => &$g) {
                try {
                    $nodes = $userFolder->getById($fid);
                    if (!empty($nodes) && $nodes[0] instanceof File) {
                        $g["fileInfo"] = [
                            "name" => $nodes[0]->getName(),
                            "size" => $nodes[0]->getSize(),
                            "mimeType" => $nodes[0]->getMimeType(),
                            "path" => $nodes[0]->getPath(),
                        ];
                    }
                } catch (\Exception $e) {
                    $g["fileInfo"] = null;
                }
            }
            unset($g);

            return new JSONResponse([
                "files" => array_values($grouped),
                "totalFiles" => count($grouped),
                "totalVotes" => array_sum(array_column($grouped, "totalVotes")),
            ]);
        } catch (\Exception $e) {
            $this->logger->error("Dashboard groupVotes error", ["error" => $e->getMessage()]);
            return new JSONResponse(["error" => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * GET /api/v1/dashboard/consensus?folder=X
     * Gruppen von Dateien nach Konsens-Level: wie viele User wollen jeweils löschen.
     * Zeigt nur Dateien, bei denen der aktuelle User DELETE gevotet hat.
     */
    public function consensus(): JSONResponse
    {
        $userId = $this->getUserId();
        if (!$userId) {
            return new JSONResponse(["error" => "Not authenticated"], 401);
        }

        $folderPath = $this->request->getParam("folder", "");

        try {
            // Alle DELETE-Votes holen
            $qb = $this->db->getQueryBuilder();
            $qb->select("v.file_id", "v.user_id")
                ->from("photocleanup_votes", "v")
                ->where($qb->expr()->eq("v.vote", $qb->createNamedParameter(self::VOTE_DELETE, \PDO::PARAM_INT)));
            $result = $qb->executeQuery();

            $fileDeleteUsers = [];
            while ($row = $result->fetch()) {
                $fid = (int)$row["file_id"];
                $fileDeleteUsers[$fid][] = $row["user_id"];
            }
            $result->closeCursor();

            // Nur Dateien, bei denen aktueller User DELETE gevotet hat
            $myDeleteFiles = [];
            foreach ($fileDeleteUsers as $fid => $users) {
                if (in_array($userId, $users)) {
                    $myDeleteFiles[$fid] = count($users);
                }
            }

            if (empty($myDeleteFiles)) {
                return new JSONResponse([
                    "tabs" => [],
                    "maxConsensus" => 0,
                    "totalUsers" => 0,
                    "myDeleteCount" => 0,
                ]);
            }

            // Alle unique User im Datensatz
            $allUsers = [];
            foreach ($fileDeleteUsers as $users) {
                foreach ($users as $u) {
                    $allUsers[$u] = true;
                }
            }
            $totalUsers = count($allUsers);

            $userFolder = $this->rootFolder->getUserFolder($userId);
            $targetFolder = null;
            if (!empty($folderPath)) {
                $targetFolder = $this->resolveFolder($folderPath, $userFolder);
            }

            $consensusGroups = [];
            foreach ($myDeleteFiles as $fid => $deleteCount) {
                if ($targetFolder !== null) {
                    try {
                        $nodes = $userFolder->getById($fid);
                        if (empty($nodes)) continue;
                        $filePath = $nodes[0]->getPath();
                        $targetPath = $targetFolder->getPath();
                        if (!str_starts_with($filePath, $targetPath . "/") && $filePath !== $targetPath) {
                            continue;
                        }
                    } catch (\Exception $e) {
                        continue;
                    }
                }

                $fileInfo = null;
                try {
                    $nodes = $userFolder->getById($fid);
                    if (!empty($nodes) && $nodes[0] instanceof File) {
                        $fileInfo = [
                            "name" => $nodes[0]->getName(),
                            "size" => $nodes[0]->getSize(),
                            "mimeType" => $nodes[0]->getMimeType(),
                            "path" => $nodes[0]->getPath(),
                        ];
                    }
                } catch (\Exception $e) {}

                $consensusGroups[$deleteCount][] = [
                    "fileId" => $fid,
                    "deleteCount" => $deleteCount,
                    "fileInfo" => $fileInfo,
                ];
            }

            krsort($consensusGroups);
            $tabs = [];
            $maxConsensus = 0;
            foreach ($consensusGroups as $level => $files) {
                if ($level > $maxConsensus) $maxConsensus = $level;
                $label = $level === $totalUsers
                    ? "Alle $totalUsers User wollen loschen"
                    : "$level von $totalUsers Usern wollen loschen";
                $tabs[(string)$level] = [
                    "label" => $label,
                    "consensus" => $level,
                    "totalUsers" => $totalUsers,
                    "isUnanimous" => $level === $totalUsers,
                    "files" => $files,
                    "count" => count($files),
                ];
            }

            return new JSONResponse([
                "tabs" => $tabs,
                "maxConsensus" => $maxConsensus,
                "totalUsers" => $totalUsers,
                "myDeleteCount" => array_sum(array_map(function($g) { return count($g); }, $consensusGroups)),
            ]);
        } catch (\Exception $e) {
            $this->logger->error("Dashboard consensus error", ["error" => $e->getMessage()]);
            return new JSONResponse(["error" => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * GET /api/v1/dashboard/folder-users
     * Listet alle User mit Vote-Statistiken.
     */
    public function folderUsers(): JSONResponse
    {
        $userId = $this->getUserId();
        if (!$userId) {
            return new JSONResponse(["error" => "Not authenticated"], 401);
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count("DISTINCT user_id", "cnt"))
                ->from("photocleanup_votes");
            $r = $qb->executeQuery();
            $totalUsers = (int)$r->fetch()["cnt"];
            $r->closeCursor();

            $qb2 = $this->db->getQueryBuilder();
            $qb2->select("user_id")
                ->selectAlias($qb2->func()->count("id"), "total")
                ->selectAlias($qb2->func()->sum("CASE WHEN vote = 0 THEN 1 ELSE 0 END"), "deletes")
                ->selectAlias($qb2->func()->sum("CASE WHEN vote = 1 THEN 1 ELSE 0 END"), "keeps")
                ->from("photocleanup_votes")
                ->groupBy("user_id");
            $r2 = $qb2->executeQuery();
            $users = [];
            while ($row = $r2->fetch()) {
                $users[] = [
                    "userId" => $row["user_id"],
                    "totalVotes" => (int)$row["total"],
                    "deleteVotes" => (int)($row["deletes"] ?? 0),
                    "keepVotes" => (int)($row["keeps"] ?? 0),
                ];
            }
            $r2->closeCursor();

            return new JSONResponse([
                "totalUsers" => $totalUsers,
                "users" => $users,
            ]);
        } catch (\Exception $e) {
            $this->logger->error("Dashboard folderUsers error", ["error" => $e->getMessage()]);
            return new JSONResponse(["error" => $e->getMessage()], 500);
        }
    }

    private function resolveFolder(string $folderPath, Folder $userFolder): ?Folder
    {
        if (empty($folderPath)) return null;
        $userPath = rtrim($userFolder->getPath(), "/");
        if (str_starts_with($folderPath, $userPath . "/")) {
            $relPath = substr($folderPath, strlen($userPath) + 1);
            try {
                $node = $userFolder->get($relPath);
                if ($node instanceof Folder) return $node;
            } catch (\OCP\Files\NotFoundException $e) {}
        }
        $relPath = ltrim($folderPath, "/");
        try {
            $node = $userFolder->get($relPath);
            if ($node instanceof Folder) return $node;
        } catch (\OCP\Files\NotFoundException $e) {}
        return null;
    }

        /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * GET /api/v1/dashboard/folder-info?folder=X
     * Prüft, ob ein Ordner ein Group Share ist und wer teilnimmt.
     */
    public function folderInfo(): JSONResponse
    {
        $userId = $this->getUserId();
        if (!$userId) {
            return new JSONResponse(["error" => "Not authenticated"], 401);
        }

        $folderPath = $this->request->getParam("folder", "");

        try {
            if ($this->groupFolderService) {
                $info = $this->groupFolderService->analyzeFolder($userId, $folderPath);
                return new JSONResponse($info);
            }
            return new JSONResponse([
                "isGroupFolder" => false,
                "owner" => null,
                "members" => [$userId],
                "totalMembers" => 1,
            ]);
        } catch (\Exception $e) {
            $this->logger->error("Dashboard folderInfo error", ["error" => $e->getMessage()]);
            return new JSONResponse(["error" => $e->getMessage()], 500);
        }
    }

private function collectImages(Folder $folder, array &$result): void
    {
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node instanceof File && str_starts_with($node->getMimeType(), "image/")) {
                $result[] = [
                    "fileId" => $node->getId(),
                    "name" => $node->getName(),
                    "size" => $node->getSize(),
                    "mimeType" => $node->getMimeType(),
                    "path" => $node->getPath(),
                ];
            } elseif ($node instanceof Folder) {
                $this->collectImages($node, $result);
            }
        }
    }
}
