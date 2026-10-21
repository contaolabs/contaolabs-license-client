<?php

declare(strict_types=1);

/*
 * contaolabs License Client
 * (c) ZSTOV Projekt UG (haftungsbeschränkt) – MIT
 */

namespace ContaoLabs\LicenseClientBundle\License;

/**
 * Speichert das Ergebnis der letzten Prüfung je Erweiterung und Domain.
 *
 * Liegt in var/contaolabs/licenses.json und bleibt beim Leeren des Caches
 * erhalten. Der Lizenzschlüssel selbst wird nicht gespeichert, nur ein Hash,
 * damit ein geänderter Schlüssel sofort neu geprüft wird.
 */
class StateStore
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $data = null;

    public function __construct(private readonly string $file)
    {
    }

    public static function id(string $product, string $domain): string
    {
        return $product.'|'.$domain;
    }

    /** @return array<string, mixed>|null */
    public function get(string $product, string $domain): ?array
    {
        return $this->load()[self::id($product, $domain)] ?? null;
    }

    /** @param array<string, mixed> $entry */
    public function set(string $product, string $domain, array $entry): bool
    {
        $this->load();
        $this->data[self::id($product, $domain)] = $entry;

        return $this->write();
    }

    /** Kann der Speicher geschrieben werden? Ohne Speicher wird nie geprüft. */
    public function isWritable(): bool
    {
        $dir = \dirname($this->file);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        return is_file($this->file) ? is_writable($this->file) : is_writable($dir);
    }

    /** @return array<string, array<string, mixed>> */
    private function load(): array
    {
        if (null === $this->data) {
            $content = is_file($this->file) ? @file_get_contents($this->file) : false;
            $data = false !== $content ? json_decode($content, true) : null;
            $this->data = \is_array($data) ? $data : [];
        }

        return $this->data;
    }

    private function write(): bool
    {
        if (!$this->isWritable()) {
            return false;
        }

        $tmp = $this->file.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (false === @file_put_contents($tmp, json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))) {
            return false;
        }

        if (!@rename($tmp, $this->file)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }
}
