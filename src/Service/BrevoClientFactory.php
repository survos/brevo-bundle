<?php

declare(strict_types=1);

namespace Survos\BrevoBundle\Service;

use Brevo\Brevo;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the official SDK on Symfony HttpClient rather than letting php-http/discovery pick one,
 * so Brevo calls show up in the profiler and share the app's HttpClient config.
 */
final class BrevoClientFactory
{
    public static function create(HttpClientInterface $httpClient, #[\SensitiveParameter] string $apiKey): Brevo
    {
        return new Brevo($apiKey, ['client' => new Psr18Client($httpClient)]);
    }
}
