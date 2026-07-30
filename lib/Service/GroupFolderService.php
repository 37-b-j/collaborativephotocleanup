<?php
declare(strict_types=1);

namespace OCA\CollaborativePhotoCleanup\Service;

use OCP\IDBConnection;
use OCP\Files\IRootFolder;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\Share\IManager;

class GroupFolderService
{
    public function __construct(
        private IDBConnection $db,
        private IRootFolder $rootFolder,
        private IManager $shareManager,
    ) {}

    /**
     * Prüft, ob ein Ordner ein Group Share ist und gibt die Teilnehmer zurück.
     * @return array{isGroupFolder: bool, owner: string|null, members: string[], totalMembers: int}
     */
    public function analyzeFolder(string $userId, string $folderPath): array
    {
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $folder = null;

            // Versuche den Ordner aufzulösen
            $userPath = rtrim($userFolder->getPath(), '/');
            $fullPath = $folderPath;

            if (str_starts_with($fullPath, $userPath . '/')) {
                $relPath = substr($fullPath, strlen($userPath) + 1);
                try {
                    $node = $userFolder->get($relPath);
                    if ($node instanceof Folder) $folder = $node;
                } catch (NotFoundException $e) {}
            }

            if ($folder === null) {
                $relPath = ltrim($folderPath, '/');
                try {
                    $node = $userFolder->get($relPath);
                    if ($node instanceof Folder) $folder = $node;
                } catch (NotFoundException $e) {}
            }

            if ($folder === null) {
                return [
                    'isGroupFolder' => false,
                    'owner' => null,
                    'members' => [],
                    'totalMembers' => 0,
                ];
            }

            $folderOwner = $folder->getOwner();
            $ownerId = $folderOwner ? $folderOwner->getUID() : null;

            // Prüfe auf Shares für diesen Ordner-Pfad
            $qb = $this->db->getQueryBuilder();
            $qb->select('uid_owner', 'uid_initiator', 'share_with')
                ->from('share')
                ->where($qb->expr()->eq('share_type', $qb->createNamedParameter(1, \PDO::PARAM_INT))) // group share
                ->andWhere($qb->expr()->like('file_target', $qb->createNamedParameter('%' . basename($fullPath) . '%')));
            $result = $qb->executeQuery();

            $members = [];
            $allUsers = [];
            while ($row = $result->fetch()) {
                $allUsers[$row['uid_owner']] = true;
                $allUsers[$row['uid_initiator']] = true;
                $allUsers[$row['share_with']] = true;
            }
            $result->closeCursor();

            // Hole alle User, die jemals in diesem Kontext Votes haben
            $qb2 = $this->db->getQueryBuilder();
            $qb2->selectDistinct('user_id')
                ->from('photocleanup_votes');
            $r2 = $qb2->executeQuery();
            $voterIds = [];
            while ($row = $r2->fetch()) {
                $uid = $row['user_id'];
                $voterIds[$uid] = true;
                $allUsers[$uid] = true;
            }
            $r2->closeCursor();

            $members = array_keys($allUsers);
            sort($members);

            return [
                'isGroupFolder' => count($members) > 1,
                'owner' => $ownerId,
                'members' => $members,
                'totalMembers' => count($members),
            ];
        } catch (\Exception $e) {
            return [
                'isGroupFolder' => false,
                'owner' => null,
                'members' => [],
                'totalMembers' => 0,
            ];
        }
    }

    /**
     * Prüft, ob ALLE User eines Group Shares DELETE für eine Datei gevotet haben.
     * @return bool true wenn 100% Konsens erreicht
     */
    public function hasFullConsensus(int $fileId, array $members): bool
    {
        if (empty($members)) return false;

        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('user_id', 'cnt'))
            ->from('photocleanup_votes')
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('vote', $qb->createNamedParameter(0, \PDO::PARAM_INT))); // DELETE vote
        $result = $qb->executeQuery();
        $deleteCount = (int)$result->fetch()['cnt'];
        $result->closeCursor();

        return $deleteCount >= count($members);
    }
}
