<?php

declare(strict_types=1);

/*
 * contaolabs License Client
 * (c) ZSTOV Projekt UG (haftungsbeschränkt) – MIT
 */

namespace ContaoLabs\LicenseClientBundle\License;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fragt die Lizenz-API von contaolabs.de.
 *
 * Übertragen werden nur Lizenzschlüssel, Produktkennung und Domain (AGB § 5,
 * Datenschutzerklärung Abschnitt 9), per POST, damit der Schlüssel nicht in
 * Server-Logs landet. Antwortet keine Adresse mit gültigem JSON, gibt die
 * Methode null zurück: Die Erweiterung läuft dann unverändert weiter.
 */
class LicenseApiClient
{
    public const VERSION = '1.1.0';

    public const DEFAULT_ENDPOINTS = [
        'https://contaolabs.de/api/license',
        'https://contaolabs.zstov.de/api/license',
    ];

    /** @var list<string> */
    private array $endpoints;

    /** @var array<string, true> Adressen, die in diesem Aufruf schon ausgefallen sind */
    private array $unreachable = [];

    /**
     * @param list<string>|string|null $endpoints Adressen der API; leer = Standard,
     *                                            sonst Umgebungsvariable CONTAOLABS_LICENSE_API (kommagetrennt)
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        array|string|null $endpoints = null,
        private readonly float $timeout = 3.0,
    ) {
        if (null === $endpoints || [] === $endpoints || '' === $endpoints) {
            $endpoints = (string) ($_SERVER['CONTAOLABS_LICENSE_API'] ?? $_ENV['CONTAOLABS_LICENSE_API'] ?? getenv('CONTAOLABS_LICENSE_API') ?: '');
        }

        if (\is_string($endpoints)) {
            $endpoints = array_filter(array_map('trim', explode(',', $endpoints)));
        }

        $this->endpoints = array_values($endpoints ?: self::DEFAULT_ENDPOINTS);
    }

    /**
     * @return array{valid: bool, code: string, message?: string, license?: array<string, mixed>}|null
     */
    public function verify(string $licenseKey, string $product, string $domain): ?array
    {
        foreach ($this->endpoints as $endpoint) {
            if (isset($this->unreachable[$endpoint])) {
                continue;
            }

            try {
                $response = $this->httpClient->request('POST', $endpoint, [
                    'body' => [
                        'action' => 'verify',
                        'license_key' => $licenseKey,
                        'product' => $product,
                        'domain' => $domain,
                    ],
                    'headers' => [
                        'Accept' => 'application/json',
                        'User-Agent' => 'contaolabs-license-client/'.self::VERSION,
                    ],
                    'timeout' => $this->timeout,
                    'max_duration' => $this->timeout + 1,
                    // Eine Umleitung würde aus dem POST ein GET machen. Dann lieber die nächste Adresse.
                    'max_redirects' => 0,
                ]);

                $data = json_decode($response->getContent(false), true);
            } catch (\Throwable) {
                // Nicht erreichbar oder Zeitüberschreitung: für den Rest dieses Aufrufs überspringen
                $this->unreachable[$endpoint] = true;

                continue;
            }

            if (\is_array($data) && isset($data['valid'], $data['code']) && \is_bool($data['valid']) && \is_string($data['code'])) {
                return $data;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function getEndpoints(): array
    {
        return $this->endpoints;
    }
}
