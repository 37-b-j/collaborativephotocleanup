<?php

declare(strict_types=1);

namespace OCA\CollaborativePhotoCleanup\Service;

use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

class PhashCalculator {

    private const HASH_SIZE = 16;
    private const HASH_HEX_LEN = 64;

    private const SUPPORTED_MIMETYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    private const CHUNK_SIZE = 100;

    private IRootFolder $rootFolder;
    private LoggerInterface $logger;

    public function __construct(
        IRootFolder $rootFolder,
        LoggerInterface $logger
    ) {
        $this->rootFolder = $rootFolder;
        $this->logger = $logger;
    }

    public function calculateForFile(int $fileId): ?string {
        try {
            $nodes = $this->rootFolder->getById($fileId);
            if (empty($nodes)) {
                $this->logger->warning("PhashCalculator: Datei mit ID $fileId nicht gefunden");
                return null;
            }

            $file = $nodes[0];
            if (!$file instanceof \OCP\Files\File) {
                $this->logger->warning("PhashCalculator: Knoten $fileId ist keine Datei");
                return null;
            }

            return $this->calculateFromFileNode($file);
        } catch (NotFoundException $e) {
            $this->logger->warning("PhashCalculator: Datei $fileId nicht gefunden: " . $e->getMessage());
            return null;
        } catch (\Throwable $e) {
            $this->logger->error("PhashCalculator: Fehler bei Datei $fileId: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            return null;
        }
    }

    public function calculateForPath(string $path): ?string {
        try {
            if (!$this->rootFolder->nodeExists($path)) {
                $this->logger->warning("PhashCalculator: Pfad '$path' existiert nicht");
                return null;
            }

            $node = $this->rootFolder->get($path);
            if (!$node instanceof \OCP\Files\File) {
                $this->logger->warning("PhashCalculator: Pfad '$path' ist keine Datei");
                return null;
            }

            return $this->calculateFromFileNode($node);
        } catch (NotFoundException $e) {
            $this->logger->warning("PhashCalculator: Pfad '$path' nicht gefunden: " . $e->getMessage());
            return null;
        } catch (\Throwable $e) {
            $this->logger->error("PhashCalculator: Fehler bei Pfad '$path': " . $e->getMessage(), [
                'exception' => $e,
            ]);
            return null;
        }
    }

    public function calculateFromContent(string $imageData): ?string {
        try {
            if (empty($imageData)) {
                return null;
            }

            return $this->computeHash($imageData);
        } catch (\Throwable $e) {
            $this->logger->error('PhashCalculator: Fehler bei Bilddaten: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return null;
        }
    }

    public function batchCalculate(array $fileIds): array {
        $results = [];
        $chunks = array_chunk($fileIds, self::CHUNK_SIZE);
        $total = count($fileIds);
        $processed = 0;

        foreach ($chunks as $chunkIndex => $chunk) {
            $this->logger->info(
                sprintf(
                    'PhashCalculator: Batch-Chunk %d/%d, %d Dateien',
                    $chunkIndex + 1,
                    count($chunks),
                    count($chunk)
                )
            );

            foreach ($chunk as $fileId) {
                $hash = $this->calculateForFile((int)$fileId);
                $results[(int)$fileId] = $hash;
                $processed++;

                if ($processed % 50 === 0) {
                    $this->logger->info(
                        sprintf(
                            'PhashCalculator: Fortschritt %d/%d (%.1f%%)',
                            $processed,
                            $total,
                            ($processed / $total) * 100
                        )
                    );
                }
            }

            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        $successCount = count(array_filter($results, fn($v) => $v !== null));
        $this->logger->info(
            sprintf(
                'PhashCalculator: Batch abgeschlossen - %d/%d erfolgreich',
                $successCount,
                $total
            )
        );

        return $results;
    }

    public function isSupportedMimeType(string $mimeType): bool {
        return in_array($mimeType, self::SUPPORTED_MIMETYPES, true);
    }

    /**
     * Hamming-Distanz zwischen zwei Hex-Hash-Strings.
     */
    public static function hammingDistance(string $hash1, string $hash2): int {
        if ($hash1 === '' || $hash2 === '') {
            return PHP_INT_MAX;
        }

        $bin1 = hex2bin($hash1);
        $bin2 = hex2bin($hash2);

        if ($bin1 === false || $bin2 === false) {
            return PHP_INT_MAX;
        }

        if (function_exists('gmp_init') && function_exists('gmp_hamdist')) {
            $gmp1 = gmp_init(bin2hex($bin1), 16);
            $gmp2 = gmp_init(bin2hex($bin2), 16);
            return (int) gmp_hamdist($gmp1, $gmp2);
        }

        $dist = 0;
        $len = strlen($bin1);
        for ($i = 0; $i < $len; $i++) {
            $xor = ord($bin1[$i]) ^ ord($bin2[$i]);
            while ($xor) {
                $dist += $xor & 1;
                $xor >>= 1;
            }
        }
        return $dist;
    }

    private function calculateFromFileNode(\OCP\Files\File $file): ?string {
        $mimeType = $file->getMimeType();

        if (!$this->isSupportedMimeType($mimeType)) {
            $this->logger->debug(
                sprintf(
                    'PhashCalculator: Nicht unterstütztes Format \'%s\' für Datei %d',
                    $mimeType,
                    $file->getId()
                )
            );
            return null;
        }

        try {
            $fileContent = $file->getContent();

            if (empty($fileContent)) {
                $this->logger->warning(
                    sprintf(
                        'PhashCalculator: Leere Datei %d (%s)',
                        $file->getId(),
                        $file->getName()
                    )
                );
                return null;
            }

            return $this->computeHash($fileContent);
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf(
                    'PhashCalculator: Fehler beim Lesen von Datei %d: %s',
                    $file->getId(),
                    $e->getMessage()
                )
            );
            return null;
        }
    }

    /**
     * Berechnet 16x16 Average Hash (aHash) und gibt Hex-String zurück.
     */
    private function computeHash(string $imageData): ?string {
        $img = @imagecreatefromstring($imageData);
        if (!$img) {
            return null;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        if ($w <= 0 || $h <= 0) {
            imagedestroy($img);
            return null;
        }

        $size = self::HASH_SIZE;
        $resized = imagecreatetruecolor($size, $size);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $size, $size, $w, $h);
        imagedestroy($img);

        $totalPixels = $size * $size;
        $pixels = [];
        $total = 0;

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $rgb = imagecolorat($resized, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $gray = (int) (0.299 * $r + 0.587 * $g + 0.114 * $b);
                $pixels[] = $gray;
                $total += $gray;
            }
        }
        imagedestroy($resized);

        $avg = $total / $totalPixels;

        $bits = '';
        foreach ($pixels as $p) {
            $bits .= $p >= $avg ? '1' : '0';
        }

        $hex = '';
        for ($i = 0; $i < $totalPixels; $i += 4) {
            $nibble = bindec(substr($bits, $i, 4));
            $hex .= dechex($nibble);
        }

        return $hex;
    }
}