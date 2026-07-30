<?php
declare(strict_types=1);

namespace OCA\CollaborativePhotoCleanup\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\IRequest;
use OCP\Files\IRootFolder;
use OCP\Files\Folder;
use OCP\Files\File;
use OCP\IUserSession;
use OCP\IConfig;
use OCA\CollaborativePhotoCleanup\Service\ClusterService;

class ApiController extends Controller {
    private IRootFolder $rootFolder;
    private IUserSession $userSession;
    private ?ClusterService $clusterService;

    public function __construct(
        IRequest $request,
        IRootFolder $rootFolder,
        IUserSession $userSession,
        ?ClusterService $clusterService = null
    ) {
        parent::__construct('collaborativephotocleanup', $request);
        $this->rootFolder = $rootFolder;
        $this->userSession = $userSession;
        $this->clusterService = $clusterService;
    }

    private function getUserFolder(): Folder {
        $user = $this->userSession->getUser();
        if (!$user) throw new \RuntimeException('Not logged in');
        return $this->rootFolder->getUserFolder($user->getUID());
    }

    private function resolveFolder(string $folderPath): Folder {
        $userFolder = $this->getUserFolder();
        if (empty($folderPath)) return $userFolder;
        $userPath = rtrim($userFolder->getPath(), '/');
        error_log("[PhC] resolveFolder: folderPath='$folderPath' userPath='$userPath'");
        if (str_starts_with($folderPath, $userPath . '/')) {
            $relPath = substr($folderPath, strlen($userPath) + 1);
            error_log("[PhC] resolveFolder: trying abs relPath='$relPath'");
            try {
                $node = $userFolder->get($relPath);
                if ($node instanceof Folder) return $node;
            } catch (\OCP\Files\NotFoundException $e) {}
        }
        $relPath = ltrim($folderPath, '/');
        error_log("[PhC] resolveFolder: trying relPath='$relPath'");
        try {
            $node = $userFolder->get($relPath);
            if ($node instanceof Folder) return $node;
        } catch (\OCP\Files\NotFoundException $e) {}
        throw new \RuntimeException('Folder not found: ' . $folderPath);
    }

    private function collectPhotosRecursive(Folder $folder, array &$result): void {
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node instanceof File && $this->isImage($node)) {
                $result[] = [
                    'fileId' => $node->getId(),
                    'name' => $node->getName(),
                    'path' => $node->getPath(),
                    'size' => $node->getSize(),
                    'mime' => $node->getMimeType(),
                ];
            } elseif ($node instanceof Folder) {
                $this->collectPhotosRecursive($node, $result);
            }
        }
    }

    private function collectPhotosFlat(Folder $folder, array &$result): void {
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node instanceof File && $this->isImage($node)) {
                $result[] = [
                    'fileId' => $node->getId(),
                    'name' => $node->getName(),
                    'path' => $node->getPath(),
                    'size' => $node->getSize(),
                    'mime' => $node->getMimeType(),
                ];
            }
        }
    }

    private function isImage(File $file): bool {
        return str_starts_with($file->getMimeType(), 'image/');
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function photos(): JSONResponse {
        error_log("[PhC] DEBUG photos(): QUERY_STRING=" . ($_SERVER["QUERY_STRING"] ?? "none") . " GET=" . json_encode($_GET) . " params=" . json_encode($this->request->getParams()));
        try {
            $folderPath = $this->request->getParam('folder', '');
            if (empty($folderPath)) {
                $folderPath = $_GET['folder'] ?? '';
            }
            $subfolders = filter_var($this->request->getParam('subfolders', 'true'), FILTER_VALIDATE_BOOLEAN);
            error_log("[PhC] photos(): folder='$folderPath' subfolders=" . ($subfolders ? 'true' : 'false'));
            $folder = $this->resolveFolder($folderPath);
            error_log("[PhC] photos(): resolved folder path='" . $folder->getPath() . "'");
            $photos = [];
            if ($subfolders) {
                $this->collectPhotosRecursive($folder, $photos);
            } else {
                $this->collectPhotosFlat($folder, $photos);
            }
            error_log("[PhC] photos(): count=" . count($photos));
            return new JSONResponse(['photos' => $photos, 'count' => count($photos), 'folder' => $folderPath]);
        } catch (\RuntimeException $e) {
            error_log("[PhC] photos() ERROR: " . $e->getMessage());
            return new JSONResponse(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function folders(): JSONResponse {
        try {
            $folderPath = $this->request->getParam('folder', '');
            if (empty($folderPath)) {
                $folderPath = $_GET['folder'] ?? '';
            }
            $folder = $this->resolveFolder($folderPath);
            $folders = [];
            foreach ($folder->getDirectoryListing() as $node) {
                if ($node instanceof Folder) {
                    $photoCount = 0;
                    $this->countPhotosRecursive($node, $photoCount);
                    $folders[] = [
                        'name' => $node->getName(),
                        'path' => $node->getPath(),
                        'fileId' => $node->getId(),
                        'photoCount' => $photoCount,
                    ];
                }
            }
            return new JSONResponse(['folders' => $folders]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 404);
        }
    }

    private function countPhotosRecursive(Folder $folder, int &$count): void {
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node instanceof File && $this->isImage($node)) {
                $count++;
            } elseif ($node instanceof Folder) {
                $this->countPhotosRecursive($node, $count);
            }
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function photo(int $fileId): JSONResponse {
        try {
            $userFolder = $this->getUserFolder();
            $nodes = $userFolder->getById($fileId);
            if (empty($nodes)) return new JSONResponse(['error' => 'File not found'], 404);
            $file = $nodes[0];
            if (!($file instanceof File)) return new JSONResponse(['error' => 'Not a file'], 400);
            return new JSONResponse([
                'fileId' => $file->getId(),
                'name' => $file->getName(),
                'path' => $file->getPath(),
                'size' => $file->getSize(),
                'mime' => $file->getMimeType(),
            ]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function preview(int $fileId) {
        try {
            $nodes = $this->rootFolder->getById($fileId);
            if (empty($nodes)) {
                return new JSONResponse(['error' => 'File not found'], 404);
            }
            $file = $nodes[0];
            if (!($file instanceof File)) {
                return new JSONResponse(['error' => 'Not a file'], 400);
            }
            return new FileDisplayResponse($file);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function stats(): JSONResponse {
        try {
            $folderPath = $this->request->getParam('folder', '');
            if (empty($folderPath)) {
                $folderPath = $_GET['folder'] ?? '';
            }
            $subfolders = filter_var($this->request->getParam('subfolders', 'true'), FILTER_VALIDATE_BOOLEAN);
            $folder = $this->resolveFolder($folderPath);
            $photos = [];
            if ($subfolders) {
                $this->collectPhotosRecursive($folder, $photos);
            } else {
                $this->collectPhotosFlat($folder, $photos);
            }
            $totalSize = 0;
            foreach ($photos as $p) $totalSize += $p['size'];
            return new JSONResponse([
                'totalPhotos' => count($photos),
                'totalSize' => $totalSize,
                'folder' => $folderPath,
            ]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function clusters(): JSONResponse {
        try {
            $folderPath = $this->request->getParam('folder', '');
            if (empty($folderPath)) {
                $folderPath = $_GET['folder'] ?? '';
            }
            $folder = $this->resolveFolder($folderPath);
            $photos = [];
            $this->collectPhotosRecursive($folder, $photos);
            return new JSONResponse(['clusters' => [], 'total' => 0, 'photoCount' => count($photos)]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function similarClusters(): JSONResponse {
        \set_time_limit(300);
        try {
            $folderPath = $this->request->getParam('folder', '');
            if (empty($folderPath)) {
                $folderPath = $_GET['folder'] ?? '';
            }
            $threshold = (int)($this->request->getParam('threshold', '25'));
            error_log("[PhC] similarClusters(): folder='$folderPath' threshold=$threshold");
            $folder = $this->resolveFolder($folderPath);
            if (!$this->clusterService) return new JSONResponse(['error' => 'ClusterService not available'], 500);
            $result = $this->clusterService->findClusters($folder, $threshold);
            return new JSONResponse(array_merge($result, ['folder' => $folderPath, 'threshold' => $threshold]));
        } catch (\RuntimeException $e) {
            error_log("[PhC] similarClusters() ERROR: " . $e->getMessage());
            return new JSONResponse(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function scanStatus(): JSONResponse {
        try {
            $folderPath = $this->request->getParam('folder', '');
            if (empty($folderPath)) {
                $folderPath = $_GET['folder'] ?? '';
            }
            $folder = $this->resolveFolder($folderPath);
            if (!$this->clusterService) return new JSONResponse(['error' => 'ClusterService not available'], 500);
            $status = $this->clusterService->getScanStatus($folder);
            return new JSONResponse($status);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function clusterDetail(): JSONResponse {
        try {
            $folderPath = $this->request->getParam('folder', '');
            if (empty($folderPath)) {
                $folderPath = $_GET['folder'] ?? '';
            }
            $threshold = (int)($this->request->getParam('threshold', '25'));
            $folder = $this->resolveFolder($folderPath);
            if (!$this->clusterService) return new JSONResponse(['error' => 'ClusterService not available'], 500);
            $result = $this->clusterService->findClusters($folder, $threshold);
            return new JSONResponse(array_merge($result, ['total' => count($result['clusters'] ?? [])]));
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 404);
        }
    }
}
