<?php

declare(strict_types=1);

namespace OCI\Http\Handler;

use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET / — Landing page / dashboard redirect.
 */
final class HomeHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly TwigEnvironment $twig,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $html = $this->twig->render('pages/dashboard/index.html.twig', [
            'title' => 'Dashboard',
        ]);

        return ApiResponse::html($html);
    }
}
