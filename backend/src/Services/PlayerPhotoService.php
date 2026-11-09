<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\PlayerRepository;
use Closure;
use DomainException;
use RuntimeException;

final class PlayerPhotoService
{
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;
    private readonly Closure $uploadedFileChecker;
    private readonly Closure $uploadedFileMover;

    public function __construct(
        private readonly PlayerRepository $repository = new PlayerRepository(),
        private readonly ?string $imageDirectory = null,
        ?Closure $uploadedFileChecker = null,
        ?Closure $uploadedFileMover = null
    ) {
        $this->uploadedFileChecker = $uploadedFileChecker
            ?? static fn (string $path): bool => is_uploaded_file($path);
        $this->uploadedFileMover = $uploadedFileMover
            ?? static fn (string $source, string $destination): bool => move_uploaded_file($source, $destination);
    }

    public function upload(int $playerId, array $file): ?array
    {
        $player = $this->repository->findById($playerId);
        if ($player === null) {
            return null;
        }
        $directory = $this->imageDirectory
            ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'players';
        $existingFilename = basename(str_replace('\\', '/', (string) ($player['photo_url'] ?? '')));
        $existingPhotoPath = $directory . DIRECTORY_SEPARATOR . $existingFilename;
        $resolvedPhotoDirectory = realpath($directory);
        $resolvedExistingPhoto = is_link($existingPhotoPath) ? false : realpath($existingPhotoPath);
        if (
            !empty($player['photo_url'])
            && $resolvedPhotoDirectory !== false
            && $resolvedExistingPhoto !== false
            && dirname($resolvedExistingPhoto) === $resolvedPhotoDirectory
            && is_file($resolvedExistingPhoto)
        ) {
            throw new DomainException('El jugador ya tiene una fotografía registrada.');
        }

        $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if (!is_int($uploadError) || $uploadError !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException($this->uploadErrorMessage($uploadError));
        }

        $temporaryPath = $file['tmp_name'] ?? null;
        $reportedSize = $file['size'] ?? null;
        if (!is_string($temporaryPath) || $temporaryPath === '' || !is_file($temporaryPath)) {
            throw new \InvalidArgumentException('El archivo de imagen no es válido.');
        }
        if (!is_int($reportedSize) || $reportedSize < 1 || $reportedSize > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('La imagen debe pesar como máximo 5 MB.');
        }

        $fileInfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $fileInfo->file($temporaryPath);
        $imageInfo = @getimagesize($temporaryPath);
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (
            !is_string($mimeType)
            || !isset($extensions[$mimeType])
            || $imageInfo === false
            || $imageInfo['mime'] !== $mimeType
        ) {
            throw new \InvalidArgumentException('El archivo debe ser una imagen JPG, PNG o WEBP válida.');
        }

        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo preparar el directorio de imágenes de jugadores.');
        }
        $resolvedDirectory = realpath($directory);
        if ($resolvedDirectory === false || !is_writable($resolvedDirectory)) {
            throw new RuntimeException('El directorio de imágenes de jugadores no está disponible.');
        }

        $filename = sprintf('player-%d-%s.%s', $playerId, bin2hex(random_bytes(12)), $extensions[$mimeType]);
        $destination = $resolvedDirectory . DIRECTORY_SEPARATOR . $filename;
        if (
            !($this->uploadedFileChecker)($temporaryPath)
            || !($this->uploadedFileMover)($temporaryPath, $destination)
        ) {
            throw new RuntimeException('No se pudo guardar la fotografía del jugador.');
        }

        try {
            if (!$this->repository->setPhotoIfEmpty($playerId, $filename)) {
                if (!unlink($destination)) {
                    error_log('No se pudo limpiar una foto duplicada de jugador.');
                }
                $currentPlayer = $this->repository->findById($playerId);
                if ($currentPlayer === null) {
                    return null;
                }
                throw new DomainException('El jugador ya tiene una fotografía registrada.');
            }

            return $this->repository->findById($playerId);
        } catch (\Throwable $exception) {
            if (is_file($destination) && !unlink($destination)) {
                error_log('No se pudo limpiar una foto de jugador después de fallar la actualización de datos.');
            }
            throw $exception;
        }
    }

    private function uploadErrorMessage(mixed $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'La imagen debe pesar como máximo 5 MB.',
            UPLOAD_ERR_PARTIAL => 'La imagen se recibió parcialmente. Intenta subirla de nuevo.',
            UPLOAD_ERR_NO_FILE => 'Selecciona una imagen para subir.',
            default => 'No se pudo recibir la imagen. Intenta de nuevo.',
        };
    }
}
