<?php

declare(strict_types=1);

/*
 * contaolabs License Client
 * (c) ZSTOV Projekt UG (haftungsbeschränkt) – MIT
 */

namespace ContaoLabs\LicenseClientBundle\License;

/**
 * Prüft die Lizenzen und fasst den Stand je Erweiterung zusammen.
 *
 * Grundsätze: höchstens einmal täglich je Erweiterung und Domain, bei nicht
 * erreichbarer API neuer Versuch nach 6 Stunden, Entwicklungsdomains werden
 * nie gesendet. Das Ergebnis führt nur zu einem Hinweis im Backend.
 */
class LicenseChecker
{
    public const INTERVAL = 86400;
    public const RETRY = 21600;

    public const STATUS_VALID = 'valid';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_MISSING = 'missing';
    public const STATUS_DEV = 'dev';
    public const STATUS_PENDING = 'pending';
    public const STATUS_UNREACHABLE = 'unreachable';

    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(
        private readonly ProductRegistry $products,
        private readonly EnvironmentInterface $environment,
        private readonly LicenseApiClient $api,
        private readonly StateStore $store,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public static function normalizeKey(string $key): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $key));
    }

    /** @return list<string> */
    public function getProductionDomains(): array
    {
        return DomainHelper::productionDomains($this->environment->getDomains());
    }

    /**
     * Prüft alle Erweiterungen, deren letzte Prüfung fällig ist.
     *
     * @param int $budget höchstens so viele Sekunden insgesamt
     */
    public function checkDue(int $budget = 10): void
    {
        $start = microtime(true);

        if (!$this->store->isWritable()) {
            // Ohne Zwischenspeicher würde bei jedem Aufruf geprüft. Dann lieber gar nicht.
            return;
        }

        foreach (array_keys($this->products->all()) as $product) {
            if (microtime(true) - $start > $budget) {
                return;
            }

            $this->checkProduct($product);
        }
    }

    /**
     * Prüft eine Erweiterung auf allen Produktiv-Domains.
     *
     * @param string|null $key    abweichender Schlüssel (z. B. gerade im Formular eingegeben)
     * @param bool        $force  auch prüfen, wenn die letzte Prüfung noch nicht 24 Stunden her ist
     * @param int         $minAge bei $force: denselben Schlüssel nicht öfter als alle so viele Sekunden prüfen
     */
    public function checkProduct(string $product, ?string $key = null, bool $force = false, int $minAge = 0): void
    {
        $key = self::normalizeKey($key ?? $this->environment->getLicenseKey($product));

        if ('' === $key || !$this->store->isWritable()) {
            return;
        }

        $hash = self::hash($key);

        foreach ($this->getProductionDomains() as $domain) {
            $entry = $this->store->get($product, $domain);

            if (!$force && !$this->isDue($entry, $hash)) {
                continue;
            }

            if ($force && $minAge > 0 && null !== $entry && ($entry['key_hash'] ?? null) === $hash && ($this->clock)() - (int) ($entry['attempt_at'] ?? 0) < $minAge) {
                continue;
            }

            $now = ($this->clock)();
            $sameKey = null !== $entry && ($entry['key_hash'] ?? null) === $hash;
            $entry = $sameKey ? $entry : ['key_hash' => $hash, 'checked_at' => 0, 'valid' => null, 'code' => '', 'license' => null];

            // Erst den Versuch vermerken, damit parallele Aufrufe nicht ebenfalls fragen
            $entry['attempt_at'] = $now;
            $this->store->set($product, $domain, $entry);

            $result = $this->api->verify($key, $product, $domain);

            if (null === $result) {
                $entry['failed'] = true;
            } else {
                $entry['failed'] = false;
                $entry['checked_at'] = ($this->clock)();
                $entry['valid'] = $result['valid'];
                $entry['code'] = $result['code'];
                $entry['license'] = $this->licenseInfo($result['license'] ?? null);
            }

            $this->store->set($product, $domain, $entry);
        }
    }

    /** @param array<string, mixed>|null $entry */
    public function isDue(?array $entry, string $keyHash): bool
    {
        if (null === $entry || ($entry['key_hash'] ?? null) !== $keyHash) {
            return true;
        }

        $interval = !empty($entry['failed']) ? self::RETRY : self::INTERVAL;

        return ($this->clock)() - (int) ($entry['attempt_at'] ?? 0) >= $interval;
    }

    /**
     * Stand einer Erweiterung.
     *
     * @return array{status: string, code: string, domain: string, license: array<string, mixed>|null, checked_at: int, domains: list<string>}
     */
    public function getStatus(string $product): array
    {
        $domains = $this->getProductionDomains();
        $status = ['status' => self::STATUS_PENDING, 'code' => '', 'domain' => '', 'license' => null, 'checked_at' => 0, 'domains' => $domains];

        if ([] === $domains) {
            return ['status' => self::STATUS_DEV] + $status;
        }

        $key = self::normalizeKey($this->environment->getLicenseKey($product));

        if ('' === $key) {
            return ['status' => self::STATUS_MISSING] + $status;
        }

        $hash = self::hash($key);
        $valid = null;
        $unreachable = null;

        foreach ($domains as $domain) {
            $entry = $this->store->get($product, $domain);

            if (null === $entry || ($entry['key_hash'] ?? null) !== $hash) {
                continue;
            }

            $info = [
                'code' => (string) ($entry['code'] ?? ''),
                'domain' => $domain,
                'license' => \is_array($entry['license'] ?? null) ? $entry['license'] : null,
                'checked_at' => (int) ($entry['checked_at'] ?? 0),
            ] + $status;

            if (false === ($entry['valid'] ?? null)) {
                return ['status' => self::STATUS_INVALID] + $info;
            }

            if (true === ($entry['valid'] ?? null)) {
                $valid ??= ['status' => self::STATUS_VALID] + $info;
            } elseif (!empty($entry['failed'])) {
                $unreachable ??= ['status' => self::STATUS_UNREACHABLE] + $info;
            }
        }

        return $valid ?? $unreachable ?? $status;
    }

    /**
     * Erweiterungen, zu denen im Backend ein Hinweis erscheinen soll.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getProblems(): array
    {
        $problems = [];

        foreach (array_keys($this->products->all()) as $product) {
            $status = $this->getStatus($product);

            if (\in_array($status['status'], [self::STATUS_MISSING, self::STATUS_INVALID], true)) {
                $problems[$product] = $status;
            }
        }

        return $problems;
    }

    private static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * Nur die Angaben, die für die Anzeige gebraucht werden.
     *
     * @return array<string, mixed>|null
     */
    private function licenseInfo(mixed $license): ?array
    {
        if (!\is_array($license)) {
            return null;
        }

        $updatesUntil = isset($license['updates_until']) && \is_string($license['updates_until']) ? strtotime($license['updates_until']) : false;

        return [
            'type' => \is_string($license['type'] ?? null) ? $license['type'] : '',
            'domains' => array_values(array_filter((array) ($license['domains'] ?? []), 'is_string')),
            'updates_until' => false !== $updatesUntil ? $updatesUntil : 0,
        ];
    }
}
