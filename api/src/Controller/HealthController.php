<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness check. Deliberately does nothing but prove the stack is wired:
 * nginx reaches php-fpm, the kernel boots, routing resolves, JSON comes back.
 */
final class HealthController
{
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }
}
