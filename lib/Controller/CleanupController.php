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

class CleanupController extends Controller
{
    private const VOTE_DELETE = 0;
    private const VOTE_KEEP = 1;
    private const QUARANTINE_FOLDER = 'photo-cleanup-quarantine';

    private IDBConnection $db;
    private IUserSession $userSession;
    private IRootFolder $rootFolder;
    private LoggerInterface $logger;

    public function __construct(
        string $appName,
        IRequest $request,
        IDBConnection $db,
        IUserSession $userSession,
        IRootFolder $rootFolder,
        LoggerInterface $logger,
        private ?GroupFolderService $groupFolderService = null,
    ) {
        parent::__construct($appName, $request);
        $this->db = $db;
        $this->userSession = $userSession;
        $this->rootFolder = $rootFolder;
        $this->logger = $logger;
    }

    private function getUserFolder(): Folder {
        $user = $this->userSession->getUser();
        if (!$user) throw new \RuntimeException('Not logged in');
        return $this->rootFolder->getUserFolder($user->getUID());
    }

    private function resolveFolder(string $folderPath): ?Folder {
        $userFolder = $this->getUserFolder();
        if (empty($folderPath)) return $userFolder;
        $userPath = rtrim($userFolder->getPath(), '/');
        // Full path like /admin/files/Photos
        if (str_starts_with($folderPath, $userPath . '/')) {
            $relPath = substr($folderPath, strlen($userPath) + 1);
            try {
                $node = $userFolder->get($relPath);
                if ($node instanceof Folder) return $node;
            } catch (\OCP\Files\NotFoundException $e) {}
        }
        // Relative path
        $relPath = ltrim($folderPath, '/');
        try {
            $node = $userFolder->get($relPath);
            if ($node instanceof Folder) return $node;
        } catch (\OCP\Files\NotFoundException $e) {}
        return null;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function execute(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }
        $userId = $user->getUID();
        $folderPath = $this->request->getParam('folder', '');

        // Group folder consensus check
        $isGroupFolder = false;
        $groupMembers = [];
        if ($this->groupFolderService && !empty($folderPath)) {
            $folderInfo = $this->groupFolderService->analyzeFolder($userId, $folderPath);
            $isGroupFolder = $folderInfo["isGroupFolder"];
            $groupMembers = $folderInfo["members"];
        }


        try {
            // Get target folder for filtering
            $targetFolder = null;
            $targetFolderPath = null;
            if (!empty($folderPath)) {
                $targetFolder = $this->resolveFolder($folderPath);
                if ($targetFolder) {
                    $targetFolderPath = $targetFolder->getPath();
                }
            }

            $qb = $this->db->getQueryBuilder();
            $qb->select('file_id')
                ->from('photocleanup_votes')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('vote', $qb->createNamedParameter(self::VOTE_DELETE, \PDO::PARAM_INT)));
            $result = $qb->executeQuery();
            $deleteFileIds = [];
            while ($row = $result->fetch()) {
                $deleteFileIds[] = (int)$row['file_id'];
            }
            $result->closeCursor();

            if (empty($deleteFileIds)) {
                return new JSONResponse([
                    'success' => true,
                    'message' => 'No delete votes to process',
                    'processed' => 0,
                    'failed' => 0,
                ]);
            }

            $userFolder = $this->rootFolder->getUserFolder($userId);
            $quarantineFolder = $this->ensureQuarantineFolder($userFolder);

            $processed = [];
            $failed = [];
            $skipped = [];
            $skippedConsensus = 0;

            foreach ($deleteFileIds as $fileId) {
                try {
                    $nodes = $userFolder->getById($fileId);
                    if (empty($nodes) || !($nodes[0] instanceof File)) {
                        // File not accessible (system file, different storage, or deleted) - skip silently
                        continue;
                    }
                    $file = $nodes[0];

                    // Filter by folder if specified
                    if ($targetFolderPath !== null) {
                        $filePath = $file->getPath();
                        if (!str_starts_with($filePath, $targetFolderPath . '/') && $filePath !== $targetFolderPath) {
                            continue; // Skip files outside target folder
                        }
                    }

                    // Skip system files (appdata, trashbin, versions)
                    $filePath = $file->getPath();
                    if (strpos($filePath, 'appdata_') !== false 
                        || strpos($filePath, 'files_trashbin') !== false 
                        || strpos($filePath, 'files_versions') !== false
                        || strpos($filePath, 'uploads') === 0) {
                        $skipped[] = $fileId;
                        continue;
                    }
                    if (strpos($file->getPath(), self::QUARANTINE_FOLDER) !== false) {
                        $skipped[] = $fileId;
                        continue;
                    }

                    $originalPath = $file->getPath();
                    $originalName = $file->getName();
                    $targetPath = $quarantineFolder->getPath() . '/' . $originalName;

                    $counter = 1;
                    while ($quarantineFolder->nodeExists($originalName)) {
                        $parts = pathinfo($originalName);
                        $originalName = $parts['filename'] . '_' . $counter . '.' . $parts['extension'];
                        $targetPath = $quarantineFolder->getPath() . '/' . $originalName;
                        $counter++;
                    }

                    // Group folder consensus guard — removed (forced delete)
                    $file->move($targetPath);
                    $processed[] = [
                        'fileId' => $fileId,
                        'originalPath' => $originalPath,
                        'quarantinePath' => $targetPath,
                        'timestamp' => date('Y-m-d H:i:s'),
                    ];
                } catch (\Exception $e) {
                    $failed[] = $fileId;
                }
            }

            return new JSONResponse([
                'success' => true,
                'message' => 'Cleanup executed',
                'processed' => count($processed),
                'failed' => count($failed),
                'skipped' => count($skipped),
                'details' => [
                    'processed' => $processed,
                    'failed' => $failed,
                    'skipped' => $skipped,
                ],
            ]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
        public function preview(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }
        $userId = $user->getUID();
        $folderPath = $this->request->getParam('folder', '');

        // Group folder consensus check
        $isGroupFolder = false;
        $groupMembers = [];
        if ($this->groupFolderService && !empty($folderPath)) {
            $folderInfo = $this->groupFolderService->analyzeFolder($userId, $folderPath);
            $isGroupFolder = $folderInfo["isGroupFolder"];
            $groupMembers = $folderInfo["members"];
        }


        try {
            // Get target folder for filtering
            $targetFolder = null;
            $targetFolderPath = null;
            if (!empty($folderPath)) {
                $targetFolder = $this->resolveFolder($folderPath);
                if ($targetFolder) {
                    $targetFolderPath = $targetFolder->getPath();
                }
            }

            $qb = $this->db->getQueryBuilder();
            $qb->select('file_id')
                ->from('photocleanup_votes')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('vote', $qb->createNamedParameter(self::VOTE_DELETE, \PDO::PARAM_INT)));
            $result = $qb->executeQuery();
            $deleteFileIds = [];
            while ($row = $result->fetch()) {
                $deleteFileIds[] = (int)$row['file_id'];
            }
            $result->closeCursor();

            $userFolder = $this->rootFolder->getUserFolder($userId);
            $files = [];

            foreach ($deleteFileIds as $fileId) {
                try {
                    $nodes = $userFolder->getById($fileId);
                    if (empty($nodes) || !($nodes[0] instanceof \OCP\Files\File)) {
                        // File not accessible (system file, different storage, or deleted) - skip silently
                        continue;
                    }
                    $file = $nodes[0];
                    $filePath = $file->getPath();

                    // Skip system files (appdata, trashbin, versions)
                    if (strpos($filePath, 'appdata_') !== false 
                        || strpos($filePath, 'files_trashbin') !== false 
                        || strpos($filePath, 'files_versions') !== false) {
                        continue;
                    }

                    // Ensure file is under user folder (not system)
                    $userFolderPath = $userFolder->getPath();
                    if (!str_starts_with($filePath, $userFolderPath . '/') && $filePath !== $userFolderPath) {
                        continue;
                    }

                    // Filter by folder if specified
                    if ($targetFolderPath !== null) {
                        if (!str_starts_with($filePath, $targetFolderPath . '/') && $filePath !== $targetFolderPath) {
                            continue;
                        }
                    }

                    $files[] = [
                        'fileId' => $fileId,
                        'name' => $file->getName(),
                        'size' => $file->getSize(),
                        'path' => $filePath,
                        'status' => 'ready',
                        'previewUrl' => \OC::$WEBROOT . '/index.php/core/preview?fileId=' . $fileId . '&x=256&y=256&a=1',
                    ];
                } catch (\Exception $e) {
                    $files[] = ['fileId' => $fileId, 'name' => '', 'size' => 0, 'status' => 'error', 'error' => $e->getMessage()];
                }
            }

            $totalSize = 0;
            foreach ($files as $f) {
                if (isset($f['size'])) $totalSize += $f['size'];
            }

            return new JSONResponse([
                'files' => $files,
                'totalFiles' => count($files),
                'totalSize' => $totalSize,
                'totalSizeHuman' => $this->formatBytes($totalSize),
                'quarantineFolder' => self::QUARANTINE_FOLDER,
                'folder' => $folderPath,
            ]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }
/**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function quarantine(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }
        $userId = $user->getUID();

        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            if (!$userFolder->nodeExists(self::QUARANTINE_FOLDER)) {
                return new JSONResponse(['files' => [], 'total' => 0, 'quarantineFolder' => self::QUARANTINE_FOLDER]);
            }

            $quarantine = $userFolder->get(self::QUARANTINE_FOLDER);
            if (!($quarantine instanceof Folder)) {
                return new JSONResponse(['error' => 'Quarantine is not a folder'], 500);
            }

            $nodes = $quarantine->getDirectoryListing();
            $files = [];
            foreach ($nodes as $node) {
                if ($node instanceof File) {
                    $files[] = [
                        'name' => $node->getName(),
                        'size' => $node->getSize(),
                        'path' => $node->getPath(),
                        'modified' => $node->getMTime(),
                        'previewUrl' => \OC::$WEBROOT . '/index.php/core/preview?fileId=' . $node->getId() . '&x=256&y=256&a=1',
                    ];
                }
            }

            return new JSONResponse(['files' => $files, 'total' => count($files), 'quarantineFolder' => self::QUARANTINE_FOLDER]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function restore(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }
        $userId = $user->getUID();

        $name = $this->request->getParam('name', '');
        if (empty($name)) {
            return new JSONResponse(['error' => 'No name provided'], 400);
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            if (!$userFolder->nodeExists(self::QUARANTINE_FOLDER)) {
                return new JSONResponse(['error' => 'Quarantine folder not found'], 404);
            }

            $quarantine = $userFolder->get(self::QUARANTINE_FOLDER);
            if (!($quarantine instanceof Folder)) {
                return new JSONResponse(['error' => 'Quarantine is not a folder'], 500);
            }

            if (!$quarantine->nodeExists($name)) {
                return new JSONResponse(['error' => 'File not found in quarantine'], 404);
            }

            $file = $quarantine->get($name);
            if (!($file instanceof File)) {
                return new JSONResponse(['error' => 'Not a file'], 400);
            }

            $newName = $name;
            $counter = 1;
            while ($userFolder->nodeExists($newName)) {
                $parts = pathinfo($name);
                $newName = $parts['filename'] . '_restored_' . $counter . '.' . ($parts['extension'] ?? '');
                $counter++;
            }

            $targetPath = $userFolder->getPath() . '/' . $newName;
            $file->move($targetPath);

            // Also remove the delete vote
            $fileId = $file->getId();
            $qb = $this->db->getQueryBuilder();
            $qb->delete('photocleanup_votes')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, \PDO::PARAM_INT)))
                ->executeStatement();

            return new JSONResponse([
                'success' => true,
                'restored' => $newName,
                'targetPath' => $targetPath,
            ]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function emptyQuarantine(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }
        $userId = $user->getUID();

        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            if (!$userFolder->nodeExists(self::QUARANTINE_FOLDER)) {
                return new JSONResponse(['success' => true, 'deleted' => 0]);
            }

            $quarantine = $userFolder->get(self::QUARANTINE_FOLDER);
            if (!($quarantine instanceof Folder)) {
                return new JSONResponse(['error' => 'Quarantine is not a folder'], 500);
            }

            $nodes = $quarantine->getDirectoryListing();
            $deleted = 0;
            $errors = [];

            foreach ($nodes as $node) {
                try {
                    $node->delete();
                    $deleted++;
                } catch (\Exception $e) {
                    $errors[] = ['name' => $node->getName(), 'error' => $e->getMessage()];
                }
            }

            if (count($quarantine->getDirectoryListing()) === 0) {
                $quarantine->delete();
            }

            return new JSONResponse([
                'success' => true,
                'deleted' => $deleted,
                'errors' => count($errors),
                'errorDetails' => $errors,
            ]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function ensureQuarantineFolder(Folder $userFolder): Folder
    {
        if (!$userFolder->nodeExists(self::QUARANTINE_FOLDER)) {
            return $userFolder->newFolder(self::QUARANTINE_FOLDER);
        }
        $node = $userFolder->get(self::QUARANTINE_FOLDER);
        if ($node instanceof Folder) {
            return $node;
        }
        $counter = 1;
        while ($userFolder->nodeExists(self::QUARANTINE_FOLDER . '-' . $counter)) {
            $counter++;
        }
        return $userFolder->newFolder(self::QUARANTINE_FOLDER . '-' . $counter);
    }

    private function formatBytes($bytes): string
    {
        if (!is_numeric($bytes) || $bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
}
