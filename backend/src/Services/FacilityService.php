<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class FacilityService
{
    public function __construct(private readonly ?string $galleryRoot = null)
    {
    }

    public function getImages(string $urlPrefix): array
    {
        $directory = $this->facilityDirectory();
        $entries = scandir($directory);
        if ($entries === false) {
            throw new RuntimeException('No se pudo enumerar la galería de instalaciones.');
        }

        $files = [];
        foreach ($entries as $filename) {
            if (str_starts_with($filename, '.') || !$this->isAllowedImage($filename)) {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $filename;
            if (is_link($path) || !is_file($path)) {
                continue;
            }
            if (!is_readable($path)) {
                throw new RuntimeException('No se puede leer un archivo de la galería de instalaciones.');
            }

            $filePath = realpath($path);
            if ($filePath === false || dirname($filePath) !== $directory) {
                continue;
            }

            $files[] = $filename;
        }

        sort($files, SORT_NATURAL | SORT_FLAG_CASE);

        return array_map(
            static fn (string $filename): array => [
                'filename' => $filename,
                'url' => rtrim($urlPrefix, '/') . '/' . rawurlencode($filename),
            ],
            $files
        );
    }

    public function getImage(string $filename): ?array
    {
        if (
            $filename === ''
            || str_starts_with($filename, '.')
            || str_contains($filename, '/')
            || str_contains($filename, '\\')
            || basename($filename) !== $filename
            || !$this->isAllowedImage($filename)
        ) {
            return null;
        }

        $directory = $this->facilityDirectory();
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        if (!is_readable($path)) {
            throw new RuntimeException('No se puede leer un archivo de la galería de instalaciones.');
        }

        $filePath = realpath($path);
        if ($filePath === false || dirname($filePath) !== $directory) {
            return null;
        }

        $mimeType = match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
        };

        return ['path' => $filePath, 'mime_type' => $mimeType];
    }

    private function facilityDirectory(): string
    {
        $root = $this->galleryRoot
            ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'facilities';

        if (!is_dir($root) || !is_readable($root)) {
            throw new RuntimeException('La galería de instalaciones no está disponible.');
        }

        $directory = realpath($root);
        if ($directory === false) {
            throw new RuntimeException('No se puede resolver la galería de instalaciones.');
        }

        return $directory;
    }

    private function isAllowedImage(string $filename): bool
    {
        return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
    }
}
