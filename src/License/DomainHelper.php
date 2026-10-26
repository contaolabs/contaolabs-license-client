<?php

declare(strict_types=1);

/*
 * contaolabs License Client
 * (c) ZSTOV Projekt UG (haftungsbeschränkt) – MIT
 */

namespace ContaoLabs\LicenseClientBundle\License;

/**
 * Vereinfacht Domains und erkennt Entwicklungsumgebungen.
 *
 * Die Muster entsprechen denen der Lizenz-API auf contaolabs.de. Für diese
 * Domains wird keine Anfrage gesendet (AGB § 4: Entwicklungs-, Test- und
 * Staging-Umgebungen sind zusätzlich gestattet).
 *
 * contaolabs.de und alle Subdomains (z. B. demo.contaolabs.de) sind die
 * eigenen Seiten von contaolabs. Dort sind alle Erweiterungen ohne
 * Lizenzschlüssel freigeschaltet, es wird ebenfalls nichts gesendet.
 */
final class DomainHelper
{
    private const DEV_PATTERNS = [
        '/^localhost$/',
        '/^127\.0\.0\.1$/',
        '/^\[?::1\]?$/',
        '/\.(local|test|localhost|ddev\.site|lndo\.site)$/',
        '/^(dev|stage|staging|test)\./',
    ];

    private const HOUSE_PATTERN = '/(^|\.)contaolabs\.de$/';

    public static function normalize(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $domain);
        $domain = (string) preg_replace('#[/?\#].*$#', '', $domain);

        // Port entfernen, IPv6-Adressen in Klammern unverändert lassen
        if (str_starts_with($domain, '[')) {
            $domain = (string) preg_replace('#^(\[[^\]]*\]).*$#', '$1', $domain);
        } else {
            $domain = (string) preg_replace('#:\d+$#', '', $domain);
        }

        return (string) preg_replace('/^www\./', '', rtrim($domain, '.'));
    }

    public static function isDevelopment(string $domain): bool
    {
        $domain = self::normalize($domain);

        if ('' === $domain) {
            return true;
        }

        // Private und reservierte IP-Adressen (z. B. 192.168.x.x) sind lokale Systeme
        $ip = trim($domain, '[]');

        if (false !== filter_var($ip, FILTER_VALIDATE_IP)) {
            return false === filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        foreach (self::DEV_PATTERNS as $pattern) {
            if (preg_match($pattern, $domain)) {
                return true;
            }
        }

        return false;
    }

    /** Eigene Seite von contaolabs (contaolabs.de oder Subdomain)? */
    public static function isHouse(string $domain): bool
    {
        return 1 === preg_match(self::HOUSE_PATTERN, self::normalize($domain));
    }

    /**
     * @param iterable<string> $domains
     *
     * @return list<string> vereinfachte, eindeutige Produktiv-Domains, Hauptdomains zuerst
     */
    public static function productionDomains(iterable $domains): array
    {
        $result = [];

        foreach ($domains as $domain) {
            $domain = self::normalize((string) $domain);

            if ('' !== $domain && !self::isDevelopment($domain) && !self::isHouse($domain)) {
                $result[$domain] = true;
            }
        }

        $result = array_keys($result);

        // Hauptdomains zuerst: Die Lizenz-API trägt die erste Domain ein und
        // wertet Subdomains davon (z. B. Sprachversionen) als zugehörig.
        usort($result, static fn (string $a, string $b): int => [substr_count($a, '.'), $a] <=> [substr_count($b, '.'), $b]);

        return $result;
    }
}
