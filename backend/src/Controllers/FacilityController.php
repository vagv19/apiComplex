<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\FacilityService;
use RuntimeException;

final class FacilityController
{
    public function __construct(private readonly FacilityService $service = new FacilityService())
    {
    }

    public function images(Request $request, array $params): void
    {
        $suffix = '/api/v1/public/facilities/images';
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if (!is_string($requestPath) || !str_ends_with($requestPath, $suffix)) {
            error_log('No se pudo resolver la ruta pública de la galería de instalaciones.');
            Response::json(500, false, null, 'No fue posible consultar la galería de instalaciones.');
            return;
        }

        try {
            $prefix = substr($requestPath, 0, -strlen($suffix));
            $urlPrefix = rtrim($prefix, '/') . $suffix;
            Response::json(
                200,
                true,
                $this->service->getImages($urlPrefix),
                'Galería de instalaciones consultada.'
            );
        } catch (RuntimeException $exception) {
            error_log($exception->getMessage());
            Response::json(500, false, null, 'No fue posible consultar la galería de instalaciones.');
        }
    }

    public function image(Request $request, array $params): void
    {
        $filename = $params['filename'] ?? '';
        if (!is_string($filename)) {
            Response::json(404, false, null, 'Imagen no encontrada.');
            return;
        }

        try {
            $image = $this->service->getImage(rawurldecode($filename));
            if ($image === null) {
                Response::json(404, false, null, 'Imagen no encontrada.');
                return;
            }

            $stream = fopen($image['path'], 'rb');
            if ($stream === false) {
                throw new RuntimeException('No se pudo abrir un archivo de la galería de instalaciones.');
            }

            header('Content-Type: ' . $image['mime_type']);
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: public, max-age=3600');
            if (fpassthru($stream) === false) {
                error_log('No se pudo completar la lectura de un archivo de la galería de instalaciones.');
            }
            fclose($stream);
        } catch (RuntimeException $exception) {
            error_log($exception->getMessage());
            Response::json(500, false, null, 'No fue posible leer la imagen de las instalaciones.');
        }
    }
}
