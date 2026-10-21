<?php

declare(strict_types=1);

/*
 * contaolabs License Client
 * (c) ZSTOV Projekt UG (haftungsbeschränkt) – MIT
 */

namespace ContaoLabs\LicenseClientBundle\EventListener;

use Contao\BackendUser;
use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\DataContainer;
use Contao\Input;
use Contao\Message;
use Contao\StringUtil;
use ContaoLabs\LicenseClientBundle\License\LicenseChecker;
use ContaoLabs\LicenseClientBundle\License\ProductRegistry;
use ContaoLabs\LicenseClientBundle\License\StatusFormatter;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Sperrt die Backend-Bereiche einer Erweiterung ohne gültige Lizenz.
 *
 * - Backend-Menüpunkte der Erweiterung leiten zu System → Einstellungen um
 * - Frontend-Module und Inhaltselemente der Erweiterung lassen sich nicht bearbeiten
 * - Auf jeder Backend-Seite steht ein roter Hinweis
 *
 * Das Frontend ist nie betroffen. Gesperrt wird nur bei fehlendem oder
 * abgelehntem Schlüssel auf einer Produktiv-Domain (LicenseChecker::isLocked).
 */
class BackendLockListener
{
    public function __construct(
        private readonly LicenseChecker $checker,
        private readonly ProductRegistry $products,
        private readonly ContaoFramework $framework,
        private readonly ScopeMatcher $scopeMatcher,
        private readonly RequestStack $requestStack,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $router,
        private readonly Connection $connection,
    ) {
    }

    /** kernel.request: gesperrte Backend-Menüpunkte zu den Einstellungen umleiten */
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $do = $request->query->get('do');

        if (!$event->isMainRequest() || !\is_string($do) || '' === $do || !$this->scopeMatcher->isBackendRequest($request)) {
            return;
        }

        try {
            if (!$this->tokenStorage->getToken()?->getUser() instanceof BackendUser) {
                return;
            }

            $product = $this->products->productFor('backend_modules', $do);

            if (null === $product || !$this->checker->isLocked($product)) {
                return;
            }

            $this->framework->initialize();
            $this->deny($product);
            $event->setResponse(new RedirectResponse($this->router->generate('contao_backend', ['do' => 'settings'], UrlGeneratorInterface::ABSOLUTE_URL)));
        } catch (\Throwable) {
            // Im Zweifel nicht sperren
        }
    }

    /** Hook loadDataContainer: Bearbeiten gesperrter Module und Inhaltselemente verhindern */
    public function onLoadDataContainer(string $table): void
    {
        if (!\in_array($table, ['tl_module', 'tl_content'], true) || !$this->isBackend()) {
            return;
        }

        $GLOBALS['TL_DCA'][$table]['config']['onload_callback'][] = [self::class, 'onLoadRecord'];
    }

    /** onload_callback für tl_module und tl_content */
    public function onLoadRecord(?DataContainer $dc = null): void
    {
        if (null === $dc || !\in_array(Input::get('act'), ['edit', 'copy', 'paste'], true) || !$dc->id) {
            return;
        }

        $table = (string) $dc->table;

        try {
            $record = $this->connection->fetchAssociative('SELECT pid, type FROM '.$table.' WHERE id=?', [(int) $dc->id]);
        } catch (\Throwable) {
            return;
        }

        if (false === $record) {
            return;
        }

        $product = $this->products->productFor('tl_module' === $table ? 'module_types' : 'content_types', (string) $record['type']);

        if (null === $product || !$this->checker->isLocked($product)) {
            return;
        }

        $this->deny($product);

        // Zurück zur Liste: act entfernen, id auf das übergeordnete Element (Theme bzw. Artikel)
        $request = $this->requestStack->getCurrentRequest();
        $query = $request ? $request->query->all() : [];
        unset($query['act'], $query['mode'], $query['field']);
        $query['id'] = (int) $record['pid'];

        Controller::redirect($this->router->generate('contao_backend', $query));
    }

    /** Hook parseBackendTemplate: roter Hinweis auf jeder Backend-Seite */
    public function onParseBackendTemplate(string $buffer, string $template): string
    {
        if ('be_main' !== $template) {
            return $buffer;
        }

        try {
            $html = $this->banner();
        } catch (\Throwable) {
            return $buffer;
        }

        if ('' === $html) {
            return $buffer;
        }

        $main = strpos($buffer, '<main id="main"');
        $content = false !== $main ? strpos($buffer, '<div class="content">', $main) : false;

        if (false === $content) {
            return $buffer;
        }

        $pos = $content + \strlen('<div class="content">');

        return substr($buffer, 0, $pos).$html.substr($buffer, $pos);
    }

    private function banner(): string
    {
        $formatter = new StatusFormatter();
        $settingsUrl = $this->router->generate('contao_backend', ['do' => 'settings']);
        $html = '';

        foreach ($this->products->all() as $product => $name) {
            if (!$this->checker->isLocked($product)) {
                continue;
            }

            $html .= sprintf(
                '<p class="tl_error"><strong>%s:</strong> %s %s <a href="%s">%s</a></p>',
                StringUtil::specialchars($name),
                StringUtil::specialchars($formatter->text(array_merge($this->checker->getStatus($product), ['checked_at' => 0]))),
                StringUtil::specialchars($formatter->label('locked')),
                StringUtil::specialchars($settingsUrl),
                StringUtil::specialchars($formatter->label('to_settings'))
            );
        }

        return '' === $html ? '' : '<div class="tl_message" style="margin:0 0 18px">'.$html.'</div>';
    }

    private function deny(string $product): void
    {
        $formatter = new StatusFormatter();
        Message::addError(sprintf($formatter->label('locked_area'), $this->products->name($product)));
    }

    private function isBackend(): bool
    {
        $request = $this->requestStack->getCurrentRequest();

        return null !== $request && $this->scopeMatcher->isBackendRequest($request);
    }
}
