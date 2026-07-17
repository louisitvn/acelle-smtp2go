<?php

namespace Acelle\Plugin\Smtp2go\Smtp2go;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Minimal HTTP wrapper for SMTP2GO API v3 (https://developers.smtp2go.com).
 *
 *   Auth: header `X-Smtp2go-Api-Key: api-XXXX`  (also accepted in body as `api_key`)
 *   Base: https://api.smtp2go.com/v3
 *
 * Every SMTP2GO endpoint is POST (even read-only ones like /email/search,
 * /webhook/view, /domain/view) — quirk of their API design. We expose only
 * post()/postNoAuth() because GET endpoints don't exist.
 *
 * Error envelope (verified live):
 *   {data: {error: "...", error_code: 4xx, succeeded: 0, failed: 1}}
 * 2xx HTTP can still indicate a logical error when data.failed > 0 — caller
 * checks SendResult.raw if precise per-recipient diagnostics are needed.
 */
class Client
{
    public const BASE_URL = 'https://api.smtp2go.com/v3';

    public function __construct(
        private readonly string $apiKey,
    ) {
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     * @throws Error
     */
    public function post(string $path, array $body = []): array
    {
        return $this->request($path, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     * @throws Error
     */
    private function request(string $path, array $body): array
    {
        $url = self::BASE_URL . '/' . ltrim($path, '/');

        try {
            $response = (new Guzzle())->request('POST', $url, [
                'headers' => [
                    'X-Smtp2go-Api-Key' => $this->apiKey,
                    'Accept'            => 'application/json',
                    'Content-Type'      => 'application/json',
                ],
                'body'        => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'http_errors' => false,
                'timeout'     => 30,
            ]);
        } catch (GuzzleException $e) {
            throw new Error('SMTP2GO API request failed: ' . $e->getMessage(), 0, $e);
        }

        $status  = $response->getStatusCode();
        $rawBody = (string) $response->getBody();
        $json    = json_decode($rawBody, true);

        if ($status >= 200 && $status < 300) {
            return is_array($json) ? $json : [];
        }

        // SMTP2GO error shape: {data: {error: "...", error_code: int}}
        $msg = is_array($json)
            ? ($json['data']['error'] ?? $json['error'] ?? $rawBody)
            : ($rawBody !== '' ? $rawBody : 'HTTP ' . $status);
        $code = is_array($json) ? (int) ($json['data']['error_code'] ?? 0) : 0;

        throw new Error(sprintf('SMTP2GO API %d: %s', $status, $msg), $code ?: $status);
    }
}
