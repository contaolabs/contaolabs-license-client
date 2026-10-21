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
 *   $GLOBALS['CONTAOLABS_LICENSES']['isotope-onepage-checkout'] = [
 *       'name' => 'Isotope OnePage-Checkout',
 *       'backend_modules' => [],                            // Backend-Menüpunkte (do=…)
 *       'module_types' => ['cl_iso_onepage_checkout'],      // Frontend-Modultypen (tl_module.type)
 *       'content_types' => [],                              // Inhaltselemente (tl_content.type)
 *   ];
 * Der Schlüssel ist die Produktkennung auf contaolabs.de. Ohne gültige Lizenz
 * sind diese Bereiche im Backend gesperrt, das Frontend läuft weiter.
 * Kurzform nur mit Namen: $GLOBALS['CONTAOLABS_LICENSES'][…] = 'Name';
 */
class ProductRegistry
{
    public const FIELD_PREFIX = 'contaolabs_license_';

    /** @param array<string, string|array<string, mixed>>|null $products nur für Tests, sonst $GLOBALS */
    public function __construct(private readonly ?array $products = null)
    {
    }

    /** @return array<string, string> Produktkennung => Name */
    public function all(): array
    {
        return array_map(static fn (array $config): string => $config['name'], $this->configs());
    }

    /**
     * @return array<string, array{name: string, backend_modules: list<string>, module_types: list<string>, content_types: list<string>}>
     */
    public function configs(): array
    {
        $products = $this->products ?? ($GLOBALS['CONTAOLABS_LICENSES'] ?? []);

        if (!\is_array($products)) {
            return [];
        }

        $result = [];

        foreach ($products as $code => $config) {
            if (!\is_string($code) || !preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $code)) {
                continue;
            }

            $config = \is_array($config) ? $config : ['name' => $config];
            $name = \is_string($config['name'] ?? null) && '' !== $config['name'] ? $config['name'] : $code;
            $list = static fn (mixed $value): array => array_values(array_filter((array) $value, static fn ($v): bool => \is_string($v) && '' !== $v));

            $result[$code] = [
                'name' => $name,
                'backend_modules' => $list($config['backend_modules'] ?? []),
                'module_types' => $list($config['module_types'] ?? []),
                'content_types' => $list($config['content_types'] ?? []),
            ];
        }

        ksort($result);

        return $result;
    }

    /** Erweiterung, zu der ein Backend-Menüpunkt, Modultyp oder Inhaltselement gehört */
    public function productFor(string $kind, string $value): ?string
    {
        foreach ($this->configs() as $product => $config) {
            if (\in_array($value, $config[$kind] ?? [], true)) {
                return $product;
            }
        }

        return null;
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
