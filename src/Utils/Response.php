<?php

namespace Mercotruck\Utils;

class Response
{
    /**
     * Limpia todos los buffers de salida
     */
    private static function clearBuffers(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    /**
     * Devuelve una respuesta JSON de éxito
     */
    public static function ok(array $data = []): void
    {
        self::clearBuffers();
        http_response_code(200);
        echo json_encode(array_merge(['status' => 'ok'], $data), JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Devuelve una respuesta JSON de error
     */
    public static function error(string $message, int $code = 400): void
    {
        self::clearBuffers();
        http_response_code($code);
        echo json_encode(['status' => 'error', 'error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Devuelve una respuesta 404
     */
    public static function notFound(): void
    {
        self::clearBuffers();
        http_response_code(404);
        echo json_encode(['status' => 'error', 'error' => 'not-found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Devuelve una respuesta 500
     */
    public static function internalError(string $message = 'Internal Server Error'): void
    {
        self::clearBuffers();
        http_response_code(500);
        echo json_encode(['status' => 'error', 'error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Devuelve una respuesta JSON personalizada con código HTTP
     */
    public static function json(array $data = [], int $code = 200): void
    {
        self::clearBuffers();
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
