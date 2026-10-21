<?php

declare(strict_types=1);

/*
 * contaolabs License Client
 * (c) ZSTOV Projekt UG (haftungsbeschränkt) – MIT
 */

namespace ContaoLabs\LicenseClientBundle\License;

interface EnvironmentInterface
{
    /** Eingetragener Lizenzschlüssel (leer, wenn keiner eingetragen ist) */
    public function getLicenseKey(string $product): string;

    /** @return list<string> Domains dieser Installation (ungefiltert) */
    public function getDomains(): array;
}
