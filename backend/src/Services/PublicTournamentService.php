<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\PublicTournamentRepository;
use RuntimeException;

final class PublicTournamentService
{
    public function __construct(
        private readonly PublicTournamentRepository $repository = new PublicTournamentRepository(),
        private readonly ?string $galleryRoot = null
    ) {
    }

    public function list(int $page = 1, int $limit = 12): array
    {
        return $this->repository->list($page, $limit);
    }

    public function getFirstMatchOfDay(string $date): ?array
    {
        return $this->repository->getFirstMatchOfDay($date);
    }

    public function getTopScorers(int $tournamentId, int $limit): array
    {
        $this->requireTournament($tournamentId);

        return $this->repository->getTopScorers($tournamentId, $limit);
    }

    public function getStandings(int $tournamentId, ?int $groupId = null): array
    {
        $this->requireTournament($tournamentId);
        if ($groupId !== null && !$this->repository->groupBelongsToTournament($tournamentId, $groupId)) {
            throw new \DomainException('Grupo no encontrado en el torneo.');
        }

        return (new StandingsService())->calculate($tournamentId, $groupId);
    }

    public function getGroups(int $tournamentId): array
    {
        $this->requireTournament($tournamentId);

        return (new TournamentFormatService())->listGroups($tournamentId);
    }

    public function getUpcomingMatches(int $tournamentId, int $limit = 5): array
    {
        $this->requireTournament($tournamentId);

        return $this->repository->getUpcomingMatches($tournamentId, min(5, $limit));
    }

    public function getImages(int $tournamentId, string $urlPrefix): array
    {
        $this->requireTournament($tournamentId);
        $files = $this->getImageFiles($tournamentId);

        return array_map(
            static fn (string $filename): array => [
                'tournament_id' => $tournamentId,
                'filename' => $filename,
                'url' => $urlPrefix . '/' . rawurlencode($filename),
            ],
            $files
        );
    }

    public function getImage(int $tournamentId, string $filename): ?array
    {
        $this->requireTournament($tournamentId);
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

        foreach ($this->getImageFiles($tournamentId) as $availableFile) {
            if ($availableFile !== $filename) {
                continue;
            }

            $path = $this->tournamentDirectory($tournamentId) . DIRECTORY_SEPARATOR . $filename;
            $mimeType = match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
                'gif' => 'image/gif',
            };

            return ['path' => $path, 'mime_type' => $mimeType];
        }

        if ($filename !== $tournamentId . '.jpg') {
            return null;
        }

        $root = $this->galleryRoot ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'tournament';
        if (!is_dir($root)) {
            return null;
        }
        if (!is_readable($root)) {
            throw new RuntimeException('No se puede leer el directorio de galerías.');
        }

        $rootPath = realpath($root);
        if ($rootPath === false) {
            throw new RuntimeException('No se puede resolver el directorio de galerías.');
        }

        $path = $rootPath . DIRECTORY_SEPARATOR . $filename;
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        if (!is_readable($path)) {
            throw new RuntimeException('No se puede leer un archivo de la galería.');
        }

        $filePath = realpath($path);
        if ($filePath === false || dirname($filePath) !== $rootPath) {
            return null;
        }

        return ['path' => $filePath, 'mime_type' => 'image/jpeg'];
    }

    private function requireTournament(int $tournamentId): void
    {
        if (!$this->repository->tournamentExists($tournamentId)) {
            throw new \DomainException('Torneo no encontrado.');
        }
    }

    private function getImageFiles(int $tournamentId): array
    {
        $root = $this->galleryRoot ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'tournament';
        if (!is_dir($root)) {
            return [];
        }
        if (!is_readable($root)) {
            throw new RuntimeException('No se puede leer el directorio de galerías.');
        }

        $rootPath = realpath($root);
        if ($rootPath === false) {
            throw new RuntimeException('No se puede resolver el directorio de galerías.');
        }

        $directory = $rootPath . DIRECTORY_SEPARATOR . $tournamentId;
        if (is_link($directory) || !is_dir($directory)) {
            return [];
        }
        if (!is_readable($directory)) {
            throw new RuntimeException('No se puede leer la galería del torneo.');
        }

        $directoryPath = realpath($directory);
        if ($directoryPath === false || dirname($directoryPath) !== $rootPath) {
            return [];
        }

        $entries = scandir($directoryPath);
        if ($entries === false) {
            throw new RuntimeException('No se puede enumerar la galería del torneo.');
        }

        $files = [];
        foreach ($entries as $filename) {
            if (str_starts_with($filename, '.') || !$this->isAllowedImage($filename)) {
                continue;
            }

            $path = $directoryPath . DIRECTORY_SEPARATOR . $filename;
            if (is_link($path) || !is_file($path)) {
                continue;
            }
            if (!is_readable($path)) {
                throw new RuntimeException('No se puede leer un archivo de la galería.');
            }

            $filePath = realpath($path);
            if ($filePath === false || dirname($filePath) !== $directoryPath) {
                continue;
            }

            $files[] = $filename;
        }

        sort($files, SORT_NATURAL | SORT_FLAG_CASE);

        return $files;
    }

    private function tournamentDirectory(int $tournamentId): string
    {
        $root = $this->galleryRoot ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'tournament';

        return $root . DIRECTORY_SEPARATOR . $tournamentId;
    }

    private function isAllowedImage(string $filename): bool
    {
        return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
    }
}
