<?php

declare(strict_types=1);

/*
 * contaolabs License Client
 * (c) ZSTOV Projekt UG (haftungsbeschränkt) – MIT
 */

namespace ContaoLabs\LicenseClientBundle\EventListener;

use Contao\CoreBundle\Routing\ScopeMatcher;
use ContaoLabs\LicenseClientBundle\License\LicenseChecker;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Prüft fällige Lizenzen nach dem Ausliefern einer Backend-Seite.
 *
 * Beim Erzeugen der Antwort wird vermerkt, ob es eine Backend-Seite war. Beim
 * Abschluss (kernel.terminate) wird nur dann geprüft. So funktioniert es auch
 * hinter dem HTTP-Cache von Contao, der beim Abschluss die ursprüngliche
 * Anfrage ohne Routing-Angaben übergibt.
 *
 * Frontend-Aufrufe und die Konsole lösen nie eine Prüfung aus. Fehler werden
 * verschluckt: Die Lizenzprüfung darf eine Installation nie stören.
 */
class BackendCheckListener
{
    private bool $backendResponse = false;

    public function __construct(
        private readonly LicenseChecker $checker,
        private readonly ScopeMatcher $scopeMatcher,
    ) {
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && $event->getResponse()->getStatusCode() < 400 && $this->scopeMatcher->isBackendRequest($event->getRequest())) {
            $this->backendResponse = true;
        }
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        if (!$this->backendResponse) {
            return;
        }

        $this->backendResponse = false;

        try {
            $this->checker->checkDue();
        } catch (\Throwable) {
            // bewusst ignoriert
        }
    }
}
