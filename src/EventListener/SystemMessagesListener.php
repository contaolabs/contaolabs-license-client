<?php

declare(strict_types=1);

/*
 * contaolabs License Client
 * (c) ZSTOV Projekt UG (haftungsbeschränkt) – MIT
 */

namespace ContaoLabs\LicenseClientBundle\EventListener;

use Contao\BackendUser;
use Contao\Config;
use Contao\StringUtil;
use ContaoLabs\LicenseClientBundle\License\LicenseChecker;
use ContaoLabs\LicenseClientBundle\License\ProductRegistry;
use ContaoLabs\LicenseClientBundle\License\StatusFormatter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Hook getSystemMessages: Hinweis auf der Startseite des Backends, wenn eine
 * Lizenz fehlt oder ungültig ist. Nur für Administratoren.
 */
class SystemMessagesListener
{
    public function __construct(
        private readonly LicenseChecker $checker,
        private readonly ProductRegistry $products,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    public function __invoke(): string
    {
        try {
            $user = BackendUser::getInstance();

            if (!$user->isAdmin) {
                return '';
            }

            $problems = $this->checker->getProblems();
        } catch (\Throwable) {
            return '';
        }

        if ([] === $problems) {
            return '';
        }

        $formatter = new StatusFormatter();
        $settingsUrl = $this->router->generate('contao_backend', ['do' => 'settings']);
        $html = '';

        foreach ($problems as $product => $status) {
            $html .= sprintf(
                '<p class="%s"><strong>%s:</strong> %s %s <a href="%s">%s</a></p>',
                LicenseChecker::STATUS_INVALID === $status['status'] ? 'tl_error' : 'tl_info',
                StringUtil::specialchars($this->products->name($product)),
                StringUtil::specialchars($formatter->text($status, (string) Config::get('dateFormat'), (string) Config::get('datimFormat'))),
                StringUtil::specialchars($formatter->label('still_works')),
                StringUtil::specialchars($settingsUrl),
                StringUtil::specialchars($formatter->label('to_settings'))
            );
        }

        return $html;
    }
}
