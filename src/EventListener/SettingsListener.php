<?php

declare(strict_types=1);

/*
 * contaolabs License Client
 * (c) ZSTOV Projekt UG (haftungsbeschränkt) – MIT
 */

namespace ContaoLabs\LicenseClientBundle\EventListener;

use Contao\Config;
use Contao\DataContainer;
use Contao\Message;
use Contao\StringUtil;
use Contao\System;
use ContaoLabs\LicenseClientBundle\License\LicenseChecker;
use ContaoLabs\LicenseClientBundle\License\ProductRegistry;
use ContaoLabs\LicenseClientBundle\License\StatusFormatter;

/**
 * System → Einstellungen: Bereich „contaolabs-Lizenzen“ mit einem Feld je
 * installierter Erweiterung und dem Stand der letzten Prüfung.
 */
class SettingsListener
{
    public const STATUS_FIELD = 'contaolabs_license_status';

    public function __construct(
        private readonly LicenseChecker $checker,
        private readonly ProductRegistry $products,
    ) {
    }

    /** Hook loadDataContainer */
    public function onLoadDataContainer(string $table): void
    {
        if ('tl_settings' !== $table || [] === ($products = $this->products->all())) {
            return;
        }

        System::loadLanguageFile('tl_settings');
        $lang = $GLOBALS['TL_LANG']['tl_settings'] ?? [];
        $label = \is_array($lang['contaolabs_license_key'] ?? null) ? $lang['contaolabs_license_key'] : ['%s', ''];
        $fields = [];

        foreach ($products as $product => $name) {
            $field = ProductRegistry::field($product);
            $fields[] = $field;

            $GLOBALS['TL_DCA']['tl_settings']['fields'][$field] = [
                'label' => [sprintf((string) $label[0], $name), (string) ($label[1] ?? '')],
                'inputType' => 'text',
                'eval' => ['maxlength' => 64, 'nospace' => true, 'decodeEntities' => true, 'tl_class' => 'w50'],
                'save_callback' => [[self::class, 'onSaveKey']],
            ];
        }

        $GLOBALS['TL_DCA']['tl_settings']['fields'][self::STATUS_FIELD] = [
            'input_field_callback' => [self::class, 'renderStatus'],
        ];

        $palette = &$GLOBALS['TL_DCA']['tl_settings']['palettes']['default'];
        $palette = rtrim((string) $palette, ';').';{contaolabs_license_legend},'.implode(',', $fields).','.self::STATUS_FIELD;
    }

    /** save_callback: Schlüssel vereinheitlichen und sofort prüfen */
    public function onSaveKey(mixed $value, DataContainer $dc): string
    {
        $value = LicenseChecker::normalizeKey((string) $value);
        $product = $this->products->productForField((string) $dc->field);

        if (null === $product || '' === $value) {
            return $value;
        }

        try {
            $status = $this->checker->getStatus($product);
            $changed = $value !== LicenseChecker::normalizeKey((string) Config::get((string) $dc->field));

            // Neuer Schlüssel: sofort prüfen. Gleicher, noch nicht gültiger Schlüssel
            // (z. B. inzwischen bezahlt): erneut prüfen, aber höchstens einmal pro Minute.
            if ($changed) {
                $this->checker->checkProduct($product, $value, true);
            } elseif (LicenseChecker::STATUS_VALID !== $status['status']) {
                $this->checker->checkProduct($product, $value, true, 60);
            }
        } catch (\Throwable) {
            // Die Einstellungen werden in jedem Fall gespeichert
        }

        return $value;
    }

    /** input_field_callback: Stand der Prüfung je Erweiterung */
    public function renderStatus(DataContainer $dc): string
    {
        $formatter = new StatusFormatter();
        $rows = '';

        try {
            foreach ($this->products->all() as $product => $name) {
                $status = $this->checker->getStatus($product);
                $class = match ($status['status']) {
                    LicenseChecker::STATUS_VALID => 'tl_green',
                    LicenseChecker::STATUS_INVALID => 'tl_red',
                    LicenseChecker::STATUS_MISSING => 'tl_orange',
                    default => 'tl_gray',
                };

                $rows .= sprintf(
                    '<tr><td class="tl_file_list" style="width:30%%"><strong>%s</strong></td><td class="tl_file_list %s">%s</td></tr>',
                    StringUtil::specialchars($name),
                    $class,
                    StringUtil::specialchars($formatter->text($status, (string) Config::get('dateFormat'), (string) Config::get('datimFormat')))
                );
            }

            $domains = $this->checker->getProductionDomains();
        } catch (\Throwable) {
            return '';
        }

        $info = [] === $domains
            ? $formatter->label('info_dev')
            : sprintf($formatter->label('info_domains'), implode(', ', $domains));

        return sprintf(
            '<div class="widget clr"><h3><label>%s</label></h3><table class="tl_listing" style="margin-top:6px"><tbody>%s</tbody></table><p class="tl_help tl_tip">%s</p></div>',
            StringUtil::specialchars($GLOBALS['TL_LANG']['tl_settings'][self::STATUS_FIELD][0] ?? 'Stand'),
            $rows,
            StringUtil::specialchars($info)
        );
    }
}
