<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\TeamRepository;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class TeamLogoService
{
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;
    private readonly Closure $uploadedFileChecker;
    private readonly Closure $uploadedFileMover;

    public function __construct(
        private readonly TeamRepository $repository = new TeamRepository(),
        private readonly ?string $imageDirectory = null,
        ?Closure $uploadedFileChecker = null,
        ?Closure $uploadedFileMover = null,
    ) {
        $this->uploadedFileChecker = $uploadedFileChecker
            ?? static fn (string $path): bool => is_uploaded_file($path);
        $this->uploadedFileMover = $uploadedFileMover
            ?? static fn (string $source, string $destination): bool => move_uploaded_file($source, $destination);
    }

    public function upload(int $teamId, array $file): ?array
    {
        $team = $this->repository->findById($teamId);
        if ($team === null) {
            return null;
        }

        $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if (!is_int($uploadError) || $uploadError !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException($this->uploadErrorMessage($uploadError));
        }

        $temporaryPath = $file['tmp_name'] ?? null;
        $reportedSize = $file['size'] ?? null;
        if (!is_string($temporaryPath) || $temporaryPath === '' || !is_file($temporaryPath)) {
            throw new InvalidArgumentException('El archivo de imagen no es válido.');
        }
        $actualSize = filesize($temporaryPath);
        if (
            !is_int($reportedSize)
            || $reportedSize < 1
            || $reportedSize > self::MAX_FILE_SIZE
            || $actualSize === false
            || $actualSize < 1
            || $actualSize > self::MAX_FILE_SIZE
        ) {
            throw new InvalidArgumentException('El logotipo debe pesar como máximo 5 MB.');
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
            throw new InvalidArgumentException('El archivo debe ser una imagen JPG, PNG o WEBP válida.');
        }

        $directory = $this->imageDirectory
            ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'teams';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo preparar el directorio de logotipos de equipos.');
        }
        $resolvedDirectory = realpath($directory);
        if ($resolvedDirectory === false || !is_writable($resolvedDirectory)) {
            throw new RuntimeException('El directorio de logotipos de equipos no está disponible.');
        }

        $filename = sprintf('team-%d-%s.%s', $teamId, bin2hex(random_bytes(12)), $extensions[$mimeType]);
        $destination = $resolvedDirectory . DIRECTORY_SEPARATOR . $filename;
        if (
            !($this->uploadedFileChecker)($temporaryPath)
            || !($this->uploadedFileMover)($temporaryPath, $destination)
        ) {
            throw new RuntimeException('No se pudo guardar el logotipo del equipo.');
        }

        try {
            $updatedTeam = $this->repository->setLogo($teamId, $filename);
            if ($updatedTeam === null) {
                if (!unlink($destination)) {
                    error_log('No se pudo limpiar un logotipo de equipo sin registro asociado.');
                }
                return null;
            }
        } catch (Throwable $exception) {
            if (is_file($destination) && !unlink($destination)) {
                error_log('No se pudo limpiar un logotipo de equipo después de fallar la actualización.');
            }
            throw $exception;
        }

        $this->removePreviousLogo((string) ($team['logo_url'] ?? ''), $teamId, $resolvedDirectory);
        return $updatedTeam;
    }

    private function removePreviousLogo(string $logoUrl, int $teamId, string $directory): void
    {
        if (preg_match('/^team-' . $teamId . '-[a-f0-9]{24}\.(?:jpg|png|webp)$/', $logoUrl) !== 1) {
            return;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $logoUrl;
        $resolvedPath = is_link($path) ? false : realpath($path);
        if (
            $resolvedPath !== false
            && dirname($resolvedPath) === $directory
            && is_file($resolvedPath)
            && !unlink($resolvedPath)
        ) {
            error_log('No se pudo eliminar el logotipo anterior del equipo.');
        }
    }

    private function uploadErrorMessage(mixed $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El logotipo debe pesar como máximo 5 MB.',
            UPLOAD_ERR_PARTIAL => 'La imagen se recibió parcialmente. Intenta subirla de nuevo.',
            UPLOAD_ERR_NO_FILE => 'Selecciona una imagen para subir.',
            default => 'No se pudo recibir la imagen. Intenta de nuevo.',
        };
    }
}
