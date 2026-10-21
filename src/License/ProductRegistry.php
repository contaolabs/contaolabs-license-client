<?php

declare(strict_types=1);

/*
 * contaolabs License Client
 * (c) ZSTOV Projekt UG (haftungsbeschränkt) – MIT
 */

namespace ContaoLabs\LicenseClientBundle\License;

/**
 * Liste der installierten contaolabs-Erweiterungen.
 *
 * Jede Erweiterung meldet sich in ihrer config.php an:
 *   $GLOBALS['CONTAOLABS_LICENSES']['isotope-onepage-checkout'] = 'Isotope OnePage-Checkout';
 * Der Schlüssel ist die Produktkennung auf contaolabs.de.
 */
class ProductRegistry
{
    public const FIELD_PREFIX = 'contaolabs_license_';

    /** @param array<string, string>|null $products nur für Tests, sonst $GLOBALS */
    public function __construct(private readonly ?array $products = null)
    {
    }

    /** @return array<string, string> Produktkennung => Name */
    public function all(): array
    {
        $products = $this->products ?? ($GLOBALS['CONTAOLABS_LICENSES'] ?? []);

        if (!\is_array($products)) {
            return [];
        }

        $result = [];

        foreach ($products as $code => $name) {
            if (\is_string($code) && preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $code)) {
                $result[$code] = \is_string($name) && '' !== $name ? $name : $code;
            }
        }

        ksort($result);

        return $result;
    }

    public function has(string $product): bool
    {
        return isset($this->all()[$product]);
    }

    public function name(string $product): string
    {
        return $this->all()[$product] ?? $product;
    }

    /** Name des Felds in den Contao-Einstellungen (localconfig) */
    public static function field(string $product): string
    {
        return self::FIELD_PREFIX.str_replace('-', '_', $product);
    }

    public function productForField(string $field): ?string
    {
        foreach (array_keys($this->all()) as $product) {
            if (self::field($product) === $field) {
                return $product;
            }
        }

        return null;
    }
}
