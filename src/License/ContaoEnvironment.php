<?php

declare(strict_types=1);

/*
 * contaolabs License Client
 * (c) ZSTOV Projekt UG (haftungsbeschränkt) – MIT
 */

namespace ContaoLabs\LicenseClientBundle\License;

use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Liest Lizenzschlüssel (System → Einstellungen) und Domains
 * (Startpunkte, sonst der Host der aktuellen Anfrage).
 */
class ContaoEnvironment implements EnvironmentInterface
{
    /** @var list<string>|null */
    private ?array $domains = null;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getLicenseKey(string $product): string
    {
        $this->framework->initialize();

        return trim((string) $this->framework->getAdapter(Config::class)->get(ProductRegistry::field($product)));
    }

    public function getDomains(): array
    {
        if (null !== $this->domains) {
            return $this->domains;
        }

        $domains = [];

        try {
            $domains = $this->connection->fetchFirstColumn(
                "SELECT DISTINCT dns FROM tl_page WHERE type='root' AND published='1' AND dns!=''"
            );
        } catch (\Throwable) {
            // Datenbank nicht erreichbar oder noch nicht eingerichtet
        }

        $domains = array_values(array_filter(array_map('strval', $domains)));

        if ([] === $domains && null !== ($request = $this->requestStack->getMainRequest())) {
            $domains = [$request->getHost()];
        }

        return $this->domains = $domains;
    }
}
