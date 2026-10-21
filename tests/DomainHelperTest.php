<?php

declare(strict_types=1);

namespace ContaoLabs\LicenseClientBundle\Tests;

use ContaoLabs\LicenseClientBundle\License\DomainHelper;
use PHPUnit\Framework\TestCase;

class DomainHelperTest extends TestCase
{
    /** @dataProvider normalizeProvider */
    public function testNormalize(string $input, string $expected): void
    {
        $this->assertSame($expected, DomainHelper::normalize($input));
    }

    public static function normalizeProvider(): iterable
    {
        yield ['Shop.Example.de', 'shop.example.de'];
        yield ['https://www.example.de/kasse?x=1', 'example.de'];
        yield ['example.de:8443', 'example.de'];
        yield ['example.de.', 'example.de'];
        yield ['[::1]:8080', '[::1]'];
    }

    /** @dataProvider devProvider */
    public function testIsDevelopment(string $domain, bool $expected): void
    {
        $this->assertSame($expected, DomainHelper::isDevelopment($domain));
    }

    public static function devProvider(): iterable
    {
        yield ['localhost', true];
        yield ['127.0.0.1', true];
        yield ['192.168.1.20', true];
        yield ['[::1]', true];
        yield ['shop.local', true];
        yield ['shop.test', true];
        yield ['shop.ddev.site', true];
        yield ['dev.example.de', true];
        yield ['staging.example.de', true];
        yield ['stage.example.de', true];
        yield ['test.example.de', true];
        yield ['', true];
        yield ['example.de', false];
        yield ['www.example.de', false];
        yield ['devshop.de', false];
        yield ['example.de.local.example.com', false];
        yield ['93.184.216.34', false];
    }

    public function testProductionDomains(): void
    {
        $this->assertSame(
            ['example.at', 'example.de', 'en.example.de'],
            DomainHelper::productionDomains(['en.example.de', 'www.example.de', 'example.de', 'staging.example.de', 'example.at', 'localhost', ''])
        );
    }
}
