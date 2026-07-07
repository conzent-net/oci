<?php

declare(strict_types=1);

namespace OCI\Http\Response;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Helper for building standard JSON/HTML responses.
 */
final class ApiResponse
{
    /**
     * @param array<string, mixed> $data
     */
    public static function success(array $data = [], int $status = 200): ResponseInterface
    {
        return self::json([
            'success' => true,
            'data' => $data,
        ], $status);
    }

    /**
     * @param array<string, mixed> $errors
     */
    public static function error(string $message, int $status = 400, array $errors = []): ResponseInterface
    {
        $body = [
            'success' => false,
            'error' => [
                'message' => $message,
            ],
        ];

        if ($errors !== []) {
            $body['error']['errors'] = $errors;
        }

        return self::json($body, $status);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public static function paginated(
        array $items,
        int $total,
        int $page,
        int $perPage,
    ): ResponseInterface {
        return self::json([
            'success' => true,
            'data' => $items,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
            ],
        ]);
    }

    public static function html(string $content, int $status = 200): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'text/html; charset=utf-8'],
            $content,
        );
    }

    public static function redirect(string $url, int $status = 302): ResponseInterface
    {
        return new Response($status, ['Location' => $url]);
    }

    public static function noContent(): ResponseInterface
    {
        return new Response(204);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }
}
