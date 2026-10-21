<?php

declare(strict_types=1);

/*
 * contaolabs License Client
 * (c) ZSTOV Projekt UG (haftungsbeschränkt) – MIT
 */

namespace ContaoLabs\LicenseClientBundle\License;

/**
 * Macht aus dem Stand einer Erweiterung einen lesbaren Text (TL_LANG.MSC.contaolabs_license).
 */
class StatusFormatter
{
    /** @param array<string, mixed>|null $lang nur für Tests, sonst $GLOBALS['TL_LANG']['MSC']['contaolabs_license'] */
    public function __construct(private readonly ?array $lang = null)
    {
    }

    /** @param array<string, mixed> $status aus LicenseChecker::getStatus() */
    public function text(array $status, string $dateFormat = 'd.m.Y', string $datimFormat = 'd.m.Y H:i'): string
    {
        $license = \is_array($status['license'] ?? null) ? $status['license'] : [];

        switch ($status['status']) {
            case LicenseChecker::STATUS_VALID:
                $type = $this->label('type_'.($license['type'] ?? ''), $this->label('type_unknown'));
                $text = sprintf($this->label('valid'), $type);
                $until = (int) ($license['updates_until'] ?? 0);

                if ($until > 0) {
                    $text .= ' '.sprintf($this->label($until >= time() ? 'updates_until' : 'updates_expired'), date($dateFormat, $until));
                }

                break;

            case LicenseChecker::STATUS_INVALID:
                $code = (string) ($status['code'] ?? '');
                $domains = implode(', ', (array) ($license['domains'] ?? []));
                $text = isset($this->lang()['code_'.$code])
                    ? sprintf($this->label('code_'.$code), $domains ?: '–')
                    : sprintf($this->label('code_other'), $code);
                break;

            default:
                $text = $this->label((string) $status['status']);
        }

        if (!empty($status['checked_at'])) {
            $text .= ' '.sprintf($this->label('checked_at'), date($datimFormat, (int) $status['checked_at']));
        }

        return $text;
    }

    public function label(string $key, ?string $fallback = null): string
    {
        $value = $this->lang()[$key] ?? null;

        return \is_string($value) ? $value : ($fallback ?? $key);
    }

    /** @return array<string, mixed> */
    private function lang(): array
    {
        $lang = $this->lang ?? ($GLOBALS['TL_LANG']['MSC']['contaolabs_license'] ?? []);

        return \is_array($lang) ? $lang : [];
    }
}
