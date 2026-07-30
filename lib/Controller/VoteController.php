<?php

declare(strict_types=1);

namespace OCA\CollaborativePhotoCleanup\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

class VoteController extends Controller
{
    private const VOTE_DELETE = 0;
    private const VOTE_KEEP = 1;
    private const RATE_LIMIT_MAX = 9999;
    private const RATE_LIMIT_WINDOW = 1;

    public function __construct(
        string $appName,
        IRequest $request,
        private IDBConnection $db,
        private IUserSession $userSession,
        private IRootFolder $rootFolder,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * POST /api/v1/vote
     */
    public function vote(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(["error" => "Not authenticated"], 401);
        }

        $fileId = (int) $this->request->getParam("fileId", 0);
        $vote = (int) $this->request->getParam("vote", -1);

        if ($fileId <= 0) {
            return new JSONResponse(["error" => "Invalid or missing fileId"], 400);
        }
        if (!in_array($vote, [self::VOTE_DELETE, self::VOTE_KEEP], true)) {
            return new JSONResponse(["error" => "Invalid vote value. Use 0 (delete) or 1 (keep)"], 400);
        }

        $userId = $user->getUID();

        if (!$this->checkRateLimit($userId)) {
            return new JSONResponse(["error" => "Rate limit exceeded"], 429);
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $files = $userFolder->getById($fileId);
            if (empty($files)) {
                return new JSONResponse(["error" => "File not found or no access"], 404);
            }
        } catch (\Exception $e) {
            $this->logger->warning("File access check failed", ["fileId" => $fileId, "error" => $e->getMessage()]);
            return new JSONResponse(["error" => "File access denied"], 403);
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select("id", "vote")
                ->from("photocleanup_votes")
                ->where($qb->expr()->eq("file_id", $qb->createNamedParameter($fileId, \PDO::PARAM_INT)))
                ->andWhere($qb->expr()->eq("user_id", $qb->createNamedParameter($userId)));
            $result = $qb->executeQuery();
            $existing = $result->fetch();
            $result->closeCursor();

            $now = (new \DateTime())->format("Y-m-d H:i:s");

            if ($existing) {
                $uq = $this->db->getQueryBuilder();
                $uq->update("photocleanup_votes")
                    ->set("vote", $uq->createNamedParameter($vote, \PDO::PARAM_INT))
                    ->set("voted_at", $uq->createNamedParameter($now))
                    ->where($uq->expr()->eq("id", $uq->createNamedParameter($existing["id"], \PDO::PARAM_INT)));
                $uq->executeStatement();

                $this->logger->info("Vote updated", ["fileId" => $fileId, "userId" => $userId, "vote" => $vote]);
                return new JSONResponse(["success" => true, "action" => "updated", "fileId" => $fileId, "vote" => $vote]);
            }

            $iq = $this->db->getQueryBuilder();
            $iq->insert("photocleanup_votes")
                ->values([
                    "file_id" => $iq->createNamedParameter($fileId, \PDO::PARAM_INT),
                    "user_id" => $iq->createNamedParameter($userId),
                    "vote" => $iq->createNamedParameter($vote, \PDO::PARAM_INT),
                    "voted_at" => $iq->createNamedParameter($now),
                ]);
            $iq->executeStatement();

            $this->logger->info("Vote created", ["fileId" => $fileId, "userId" => $userId, "vote" => $vote]);
            return new JSONResponse(["success" => true, "action" => "created", "fileId" => $fileId, "vote" => $vote]);
        } catch (\Exception $e) {
            $this->logger->error("Vote database error", ["fileId" => $fileId, "error" => $e->getMessage()]);
            return new JSONResponse(["error" => "Database error: " . $e->getMessage()], 500);
        }

    }
    public function delete(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(["error" => "Not authenticated"], 401);
        }
        $userId = $user->getUID();
        $fileId = $this->request->getParam("fileId");
        if (!$fileId) {
            return new JSONResponse(["error" => "Missing fileId"], 400);
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete("photocleanup_votes")
            ->where($qb->expr()->eq("user_id", $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq("file_id", $qb->createNamedParameter($fileId, \PDO::PARAM_INT)));
        $qb->executeStatement();
        return new JSONResponse(["success" => true, "message" => "Vote deleted"]);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * GET /api/v1/my-votes
     */
    public function getMyVotes(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(["error" => "Not authenticated"], 401);
        }
        $userId = $user->getUID();

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select("file_id", "vote", "voted_at")
                ->from("photocleanup_votes")
                ->where($qb->expr()->eq("user_id", $qb->createNamedParameter($userId)))
                ->orderBy("voted_at", "DESC");

            $result = $qb->executeQuery();
            $votes = [];
            while ($row = $result->fetch()) {
                $votes[] = [
                    "fileId" => (int)$row["file_id"],
                    "vote" => (int)$row["vote"],
                    "votedAt" => $row["voted_at"],
                ];
            }
            $result->closeCursor();

            return new JSONResponse(["votes" => $votes, "total" => count($votes)]);
        } catch (\Exception $e) {
            $this->logger->error("Failed to fetch my votes", ["error" => $e->getMessage()]);
            return new JSONResponse(["error" => $e->getMessage()], 500);
        }

    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * GET /api/v1/vote-stats/{fileId}
     */
    public function getVoteStats(int $fileId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(["error" => "Not authenticated"], 401);
        }

        if ($fileId <= 0) {
            return new JSONResponse(["error" => "Invalid fileId"], 400);
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count("id", "total"))
                ->from("photocleanup_votes")
                ->where($qb->expr()->eq("file_id", $qb->createNamedParameter($fileId, \PDO::PARAM_INT)));
            $result = $qb->executeQuery();
            $total = (int)$result->fetch()["total"];
            $result->closeCursor();

            $qb2 = $this->db->getQueryBuilder();
            $qb2->select($qb2->func()->count("id", "cnt"))
                ->from("photocleanup_votes")
                ->where($qb2->expr()->eq("file_id", $qb2->createNamedParameter($fileId, \PDO::PARAM_INT)))
                ->andWhere($qb2->expr()->eq("vote", $qb2->createNamedParameter(self::VOTE_KEEP, \PDO::PARAM_INT)));
            $result2 = $qb2->executeQuery();
            $keep = (int)$result2->fetch()["cnt"];
            $result2->closeCursor();

            $qb3 = $this->db->getQueryBuilder();
            $qb3->select($qb3->func()->count("id", "cnt"))
                ->from("photocleanup_votes")
                ->where($qb3->expr()->eq("file_id", $qb3->createNamedParameter($fileId, \PDO::PARAM_INT)))
                ->andWhere($qb3->expr()->eq("vote", $qb3->createNamedParameter(self::VOTE_DELETE, \PDO::PARAM_INT)));
            $result3 = $qb3->executeQuery();
            $del = (int)$result3->fetch()["cnt"];
            $result3->closeCursor();

            $vq = $this->db->getQueryBuilder();
            $vq->select("user_id", "vote", "voted_at")
                ->from("photocleanup_votes")
                ->where($vq->expr()->eq("file_id", $vq->createNamedParameter($fileId, \PDO::PARAM_INT)))
                ->orderBy("voted_at", "DESC");
            $vr = $vq->executeQuery();
            $voters = [];
            while ($row = $vr->fetch()) {
                $voters[] = [
                    "userId" => $row["user_id"],
                    "vote" => (int)$row["vote"],
                    "votedAt" => $row["voted_at"],
                ];
            }
            $vr->closeCursor();

            $mq = $this->db->getQueryBuilder();
            $mq->select("vote", "voted_at")
                ->from("photocleanup_votes")
                ->where($mq->expr()->eq("file_id", $mq->createNamedParameter($fileId, \PDO::PARAM_INT)))
                ->andWhere($mq->expr()->eq("user_id", $mq->createNamedParameter($user->getUID())));
            $mr = $mq->executeQuery();
            $mv = $mr->fetch();
            $mr->closeCursor();

            return new JSONResponse([
                "fileId" => $fileId,
                "totalVotes" => $total,
                "keepCount" => $keep,
                "deleteCount" => $del,
                "myVote" => $mv ? (int)$mv["vote"] : null,
                "voters" => $voters,
            ]);
        } catch (\Exception $e) {
            $this->logger->error("Failed to fetch vote stats", ["fileId" => $fileId, "error" => $e->getMessage()]);
            return new JSONResponse(["error" => $e->getMessage()], 500);
        }

    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * POST /api/v1/vote/batch-sync
     */
    public function batchSync(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(["error" => "Not authenticated"], 401);
        }
        $userId = $user->getUID();

        $votes = $this->request->getParam("votes", []);
        if (!is_array($votes) || empty($votes)) {
            return new JSONResponse(["error" => "No votes provided. Expected array in votes field."], 400);
        }

        $results = [];
        $errors = [];

        foreach ($votes as $vd) {
            $fid = (int)($vd["fileId"] ?? 0);
            $v = (int)($vd["vote"] ?? -1);

            if ($fid <= 0 || !in_array($v, [self::VOTE_DELETE, self::VOTE_KEEP], true)) {
                $errors[] = ["fileId" => $fid, "vote" => $v, "error" => "Invalid data"];
                continue;
            }

            try {
                $qb = $this->db->getQueryBuilder();
                $qb->select("id")
                    ->from("photocleanup_votes")
                    ->where($qb->expr()->eq("file_id", $qb->createNamedParameter($fid, \PDO::PARAM_INT)))
                    ->andWhere($qb->expr()->eq("user_id", $qb->createNamedParameter($userId)));
                $result = $qb->executeQuery();
                $existing = $result->fetch();
                $result->closeCursor();

                $now = (new \DateTime())->format("Y-m-d H:i:s");

                if ($existing) {
                    $u = $this->db->getQueryBuilder();
                    $u->update("photocleanup_votes")
                        ->set("vote", $u->createNamedParameter($v, \PDO::PARAM_INT))
                        ->set("voted_at", $u->createNamedParameter($now))
                        ->where($u->expr()->eq("id", $u->createNamedParameter($existing["id"], \PDO::PARAM_INT)));
                    $u->executeStatement();
                } else {
                    $i = $this->db->getQueryBuilder();
                    $i->insert("photocleanup_votes")
                        ->values([
                            "file_id" => $i->createNamedParameter($fid, \PDO::PARAM_INT),
                            "user_id" => $i->createNamedParameter($userId),
                            "vote" => $i->createNamedParameter($v, \PDO::PARAM_INT),
                            "voted_at" => $i->createNamedParameter($now),
                        ]);
                    $i->executeStatement();
                }

                $results[] = ["fileId" => $fid, "vote" => $v, "success" => true];
            } catch (\Exception $e) {
                $this->logger->error("Batch sync vote error", ["fileId" => $fid, "error" => $e->getMessage()]);
                $errors[] = ["fileId" => $fid, "error" => $e->getMessage()];
            }

        }

        return new JSONResponse([
            "success" => count($errors) === 0,
            "synced" => count($results),
            "errors" => count($errors),
            "errorDetails" => $errors,
        ]);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * POST /api/v1/vote/bulk-delete
     */
    public function bulkDelete(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(["error" => "Not authenticated"], 401);
        }
        $userId = $user->getUID();

        $fileIds = $this->request->getParam("fileIds", []);
        if (!is_array($fileIds) || empty($fileIds)) {
            return new JSONResponse(["error" => "No fileIds provided"], 400);
        }

        $now = (new \DateTime())->format("Y-m-d H:i:s");
        $results = [];
        $errors = [];

        foreach ($fileIds as $fid) {
            $fid = (int)$fid;
            if ($fid <= 0) {
                $errors[] = ["fileId" => $fid, "error" => "Invalid fileId"];
                continue;
            }

            try {
                $qb = $this->db->getQueryBuilder();
                $qb->select("id")
                    ->from("photocleanup_votes")
                    ->where($qb->expr()->eq("file_id", $qb->createNamedParameter($fid, \PDO::PARAM_INT)))
                    ->andWhere($qb->expr()->eq("user_id", $qb->createNamedParameter($userId)));
                $result = $qb->executeQuery();
                $existing = $result->fetch();
                $result->closeCursor();

                if ($existing) {
                    $u = $this->db->getQueryBuilder();
                    $u->update("photocleanup_votes")
                        ->set("vote", $u->createNamedParameter(self::VOTE_DELETE, \PDO::PARAM_INT))
                        ->set("voted_at", $u->createNamedParameter($now))
                        ->where($u->expr()->eq("id", $u->createNamedParameter($existing["id"], \PDO::PARAM_INT)));
                    $u->executeStatement();
                } else {
                    $i = $this->db->getQueryBuilder();
                    $i->insert("photocleanup_votes")
                        ->values([
                            "file_id" => $i->createNamedParameter($fid, \PDO::PARAM_INT),
                            "user_id" => $i->createNamedParameter($userId),
                            "vote" => $i->createNamedParameter(self::VOTE_DELETE, \PDO::PARAM_INT),
                            "voted_at" => $i->createNamedParameter($now),
                        ]);
                    $i->executeStatement();
                }

                $results[] = ["fileId" => $fid, "vote" => self::VOTE_DELETE, "success" => true];
            } catch (\Exception $e) {
                $this->logger->error("Bulk delete vote error", ["fileId" => $fid, "error" => $e->getMessage()]);
                $errors[] = ["fileId" => $fid, "error" => $e->getMessage()];
            }

        }

        return new JSONResponse([
            "success" => count($errors) === 0,
            "synced" => count($results),
            "errors" => count($errors),
            "errorDetails" => $errors,
        ]);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * POST /api/v1/vote/bulk-keep
     */
    public function bulkKeep(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(["error" => "Not authenticated"], 401);
        }
        $userId = $user->getUID();

        $fileIds = $this->request->getParam("fileIds", []);
        if (!is_array($fileIds) || empty($fileIds)) {
            return new JSONResponse(["error" => "No fileIds provided"], 400);
        }

        $now = (new \DateTime())->format("Y-m-d H:i:s");
        $results = [];
        $errors = [];

        foreach ($fileIds as $fid) {
            $fid = (int)$fid;
            if ($fid <= 0) {
                $errors[] = ["fileId" => $fid, "error" => "Invalid fileId"];
                continue;
            }

            try {
                $qb = $this->db->getQueryBuilder();
                $qb->select("id")
                    ->from("photocleanup_votes")
                    ->where($qb->expr()->eq("file_id", $qb->createNamedParameter($fid, \PDO::PARAM_INT)))
                    ->andWhere($qb->expr()->eq("user_id", $qb->createNamedParameter($userId)));
                $result = $qb->executeQuery();
                $existing = $result->fetch();
                $result->closeCursor();

                if ($existing) {
                    $u = $this->db->getQueryBuilder();
                    $u->update("photocleanup_votes")
                        ->set("vote", $u->createNamedParameter(self::VOTE_KEEP, \PDO::PARAM_INT))
                        ->set("voted_at", $u->createNamedParameter($now))
                        ->where($u->expr()->eq("id", $u->createNamedParameter($existing["id"], \PDO::PARAM_INT)));
                    $u->executeStatement();
                } else {
                    $i = $this->db->getQueryBuilder();
                    $i->insert("photocleanup_votes")
                        ->values([
                            "file_id" => $i->createNamedParameter($fid, \PDO::PARAM_INT),
                            "user_id" => $i->createNamedParameter($userId),
                            "vote" => $i->createNamedParameter(self::VOTE_KEEP, \PDO::PARAM_INT),
                            "voted_at" => $i->createNamedParameter($now),
                        ]);
                    $i->executeStatement();
                }

                $results[] = ["fileId" => $fid, "vote" => self::VOTE_KEEP, "success" => true];
            } catch (\Exception $e) {
                $this->logger->error("Bulk keep vote error", ["fileId" => $fid, "error" => $e->getMessage()]);
                $errors[] = ["fileId" => $fid, "error" => $e->getMessage()];
            }

        }

        return new JSONResponse([
            "success" => count($errors) === 0,
            "synced" => count($results),
            "errors" => count($errors),
            "errorDetails" => $errors,
        ]);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * DELETE /api/v1/vote/delete-all
     */
    public function deleteAll(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(["error" => "Not authenticated"], 401);
        }
        $userId = $user->getUID();

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete("photocleanup_votes")
                ->where($qb->expr()->eq("user_id", $qb->createNamedParameter($userId)));
            $deleted = $qb->executeStatement();

            $this->logger->info("All votes deleted", ["userId" => $userId, "count" => $deleted]);
            return new JSONResponse(["success" => true, "deleted" => $deleted]);
        } catch (\Exception $e) {
            $this->logger->error("Failed to delete all votes", ["error" => $e->getMessage()]);
            return new JSONResponse(["error" => $e->getMessage()], 500);
        }
    }

    private function checkRateLimit(string $userId): bool
    {
        try {
            $windowStart = (new \DateTime())->modify("- " . self::RATE_LIMIT_WINDOW . " seconds")
                ->format("Y-m-d H:i:s");

            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count("id", "cnt"))
                ->from("photocleanup_votes")
                ->where($qb->expr()->eq("user_id", $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->gte("voted_at", $qb->createNamedParameter($windowStart)));

            $result = $qb->executeQuery();
            $count = (int)$result->fetch()["cnt"];
            $result->closeCursor();

            return true; # Rate limit disabled
        } catch (\Exception $e) {
            $this->logger->warning("Rate limit check failed, allowing vote", ["error" => $e->getMessage()]);
            return true;
        }

    }

}
