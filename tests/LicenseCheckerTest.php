<?php

declare(strict_types=1);

namespace ContaoLabs\LicenseClientBundle\Tests;

use ContaoLabs\LicenseClientBundle\License\EnvironmentInterface;
use ContaoLabs\LicenseClientBundle\License\LicenseApiClient;
use ContaoLabs\LicenseClientBundle\License\LicenseChecker;
use ContaoLabs\LicenseClientBundle\License\ProductRegistry;
use ContaoLabs\LicenseClientBundle\License\StateStore;
use ContaoLabs\LicenseClientBundle\License\StatusFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class LicenseCheckerTest extends TestCase
{
    private string $file;
    private int $now = 1_800_000_000;
    /** @var list<array{method: string, url: string, body: string}> */
    private array $requests = [];
    /** @var list<callable|MockResponse> */
    private array $responses = [];

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/cl-license-'.bin2hex(random_bytes(4)).'/licenses.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        @rmdir(\dirname($this->file));
    }

    public function testNoKeyMeansNoRequestAndMissingStatus(): void
    {
        $checker = $this->checker(['example.de'], '');
        $checker->checkDue();

        $this->assertSame([], $this->requests);
        $this->assertSame(LicenseChecker::STATUS_MISSING, $checker->getStatus('isotope-onepage-checkout')['status']);
        $this->assertArrayHasKey('isotope-onepage-checkout', $checker->getProblems());
    }

    public function testDevelopmentDomainsAreNeverSent(): void
    {
        $checker = $this->checker(['localhost', 'shop.test', 'staging.example.de'], 'CL-IOC-AAAA');
        $checker->checkDue();
        $checker->checkProduct('isotope-onepage-checkout', null, true);

        $this->assertSame([], $this->requests);
        $this->assertSame(LicenseChecker::STATUS_DEV, $checker->getStatus('isotope-onepage-checkout')['status']);
        $this->assertSame([], $checker->getProblems());
    }

    public function testValidLicenseOnlySendsKeyProductAndDomainOncePerDay(): void
    {
        $this->responses[] = $this->json(['valid' => true, 'code' => 'valid', 'message' => 'Lizenz gültig.', 'license' => ['product' => 'isotope-onepage-checkout', 'type' => 'standard', 'domains' => ['example.de'], 'updates_until' => '2027-10-21T12:00:00+02:00']]);
        $checker = $this->checker(['www.example.de'], ' cl-ioc-aaaa ');

        $checker->checkDue();
        $checker->checkDue();

        $this->assertCount(1, $this->requests);
        $this->assertSame('POST', $this->requests[0]['method']);
        $this->assertSame('https://contaolabs.de/api/license', $this->requests[0]['url']);
        parse_str($this->requests[0]['body'], $body);
        $this->assertSame(['action' => 'verify', 'license_key' => 'CL-IOC-AAAA', 'product' => 'isotope-onepage-checkout', 'domain' => 'example.de'], $body);

        $status = $checker->getStatus('isotope-onepage-checkout');
        $this->assertSame(LicenseChecker::STATUS_VALID, $status['status']);
        $this->assertSame('standard', $status['license']['type']);
        $this->assertSame([], $checker->getProblems());

        // Der Schlüssel selbst wird nicht gespeichert
        $this->assertStringNotContainsString('CL-IOC-AAAA', (string) file_get_contents($this->file));

        // Nach 24 Stunden wird wieder geprüft
        $this->now += LicenseChecker::INTERVAL;
        $this->responses[] = $this->json(['valid' => true, 'code' => 'valid']);
        $checker->checkDue();
        $this->assertCount(2, $this->requests);
    }

    public function testUnreachableApiKeepsLastStateAndRetriesAfterSixHours(): void
    {
        $this->responses[] = $this->json(['valid' => true, 'code' => 'valid']);
        $checker = $this->checker(['example.de'], 'CL-IOC-AAAA');
        $checker->checkDue();

        $this->now += LicenseChecker::INTERVAL;
        // Die Lizenz-API fällt aus (nur eine Standardadresse seit 1.1.2)
        $this->responses[] = new MockResponse('<html>Wartung</html>', ['http_code' => 503]);
        $checker->checkDue();

        $this->assertCount(2, $this->requests);
        $this->assertSame('https://contaolabs.de/api/license', $this->requests[1]['url']);
        $this->assertSame(LicenseChecker::STATUS_VALID, $checker->getStatus('isotope-onepage-checkout')['status']);

        $this->now += LicenseChecker::RETRY - 1;
        $checker->checkDue();
        $this->assertCount(2, $this->requests);

        $this->now += 1;
        $this->responses[] = $this->json(['valid' => true, 'code' => 'valid']);
        $checker->checkDue();
        $this->assertCount(3, $this->requests);
    }

    public function testDefaultEndpointIsOnlyContaolabsDe(): void
    {
        $this->assertSame(['https://contaolabs.de/api/license'], LicenseApiClient::DEFAULT_ENDPOINTS);
    }

    public function testCustomEndpointsAreTriedInOrder(): void
    {
        // Eigene Adressen (z. B. über CONTAOLABS_LICENSE_API) werden weiter der Reihe nach probiert
        $this->responses[] = new MockResponse('', ['error' => 'Could not resolve host']);
        $this->responses[] = $this->json(['valid' => true, 'code' => 'valid']);
        $checker = $this->checker(['example.de'], 'CL-IOC-AAAA', ['https://eins.example/api', 'https://zwei.example/api']);
        $checker->checkDue();

        $this->assertSame(['https://eins.example/api', 'https://zwei.example/api'], array_column($this->requests, 'url'));
        $this->assertSame(LicenseChecker::STATUS_VALID, $checker->getStatus('isotope-onepage-checkout')['status']);
    }

    public function testNeverReachedShowsUnreachableWithoutNotice(): void
    {
        $this->responses[] = new MockResponse('', ['error' => 'timeout']);
        $this->responses[] = new MockResponse('', ['error' => 'timeout']);
        $checker = $this->checker(['example.de'], 'CL-IOC-AAAA');
        $checker->checkDue();

        $this->assertSame(LicenseChecker::STATUS_UNREACHABLE, $checker->getStatus('isotope-onepage-checkout')['status']);
        $this->assertSame([], $checker->getProblems());
    }

    public function testInvalidLicenseCreatesNotice(): void
    {
        $this->responses[] = $this->json(['valid' => false, 'code' => 'domain_limit', 'message' => '…', 'license' => ['type' => 'standard', 'domains' => ['other.de']]], 200);
        $checker = $this->checker(['example.de'], 'CL-IOC-AAAA');
        $checker->checkDue();

        $status = $checker->getStatus('isotope-onepage-checkout');
        $this->assertSame(LicenseChecker::STATUS_INVALID, $status['status']);
        $this->assertSame('domain_limit', $status['code']);

        $text = (new StatusFormatter(['code_domain_limit' => 'Schon aktiv für %s.', 'checked_at' => '']))->text($status);
        $this->assertStringStartsWith('Schon aktiv für other.de.', $text);
    }

    public function testChangedKeyIsCheckedImmediately(): void
    {
        $this->responses[] = $this->json(['valid' => false, 'code' => 'not_found']);
        $checker = $this->checker(['example.de'], 'CL-IOC-AAAA');
        $checker->checkDue();

        $this->responses[] = $this->json(['valid' => true, 'code' => 'valid']);
        $checker->checkProduct('isotope-onepage-checkout', 'CL-IOC-BBBB');
        $this->assertCount(2, $this->requests);
    }

    public function testRedirectIsNotFollowed(): void
    {
        // Erste Adresse leitet um: nicht folgen, sondern die nächste Adresse fragen
        $this->responses[] = new MockResponse('', ['http_code' => 301, 'response_headers' => ['Location' => 'https://evil.example/api']]);
        $this->responses[] = $this->json(['valid' => true, 'code' => 'valid']);
        $checker = $this->checker(['example.de'], 'CL-IOC-AAAA', ['https://eins.example/api', 'https://zwei.example/api']);
        $checker->checkDue();

        $this->assertSame(['https://eins.example/api', 'https://zwei.example/api'], array_column($this->requests, 'url'));
        $this->assertSame(LicenseChecker::STATUS_VALID, $checker->getStatus('isotope-onepage-checkout')['status']);
    }

    public function testFailedEndpointIsSkippedForTheRestOfTheRequest(): void
    {
        $this->responses[] = new MockResponse('', ['error' => 'timeout']);
        $this->responses[] = new MockResponse('', ['error' => 'timeout']);
        $checker = $this->checker(['example.de', 'example.at'], 'CL-IOC-AAAA', ['https://eins.example/api', 'https://zwei.example/api']);
        $checker->checkDue();

        // Zwei Domains, zwei Adressen, aber jede Adresse wurde nur einmal gefragt
        $this->assertCount(2, $this->requests);
    }

    public function testForcedRecheckRespectsMinimumAge(): void
    {
        $this->responses[] = $this->json(['valid' => false, 'code' => 'unpaid']);
        $checker = $this->checker(['example.de'], 'CL-IOC-AAAA');
        $checker->checkDue();

        $checker->checkProduct('isotope-onepage-checkout', null, true, 60);
        $this->assertCount(1, $this->requests);

        $this->now += 60;
        $this->responses[] = $this->json(['valid' => true, 'code' => 'valid']);
        $checker->checkProduct('isotope-onepage-checkout', null, true, 60);
        $this->assertCount(2, $this->requests);
        $this->assertSame(LicenseChecker::STATUS_VALID, $checker->getStatus('isotope-onepage-checkout')['status']);
    }

    public function testLockOnlyForMissingOrRejectedKeys(): void
    {
        $product = 'isotope-onepage-checkout';

        // Kein Schlüssel auf Produktiv-Domain: gesperrt
        $this->assertTrue($this->checker(['example.de'], '')->isLocked($product));

        // Entwicklungsdomain: nie gesperrt
        $this->assertFalse($this->checker(['shop.test'], '')->isLocked($product));

        // Schlüssel eingetragen, aber noch nie geprüft: nicht gesperrt
        $this->assertFalse($this->checker(['example.de'], 'CL-IOC-AAAA')->isLocked($product));

        // Lizenzserver nicht erreichbar: nicht gesperrt
        $this->responses[] = new MockResponse('', ['error' => 'timeout']);
        $checker = $this->checker(['example.de'], 'CL-IOC-AAAA');
        $checker->checkDue();
        $this->assertFalse($checker->isLocked($product));

        // Abgelehnt (nächster Seitenaufruf, 6 Stunden später): gesperrt
        $this->now += LicenseChecker::RETRY;
        $this->responses[] = $this->json(['valid' => false, 'code' => 'revoked']);
        $checker = $this->checker(['example.de'], 'CL-IOC-AAAA');
        $checker->checkDue();
        $this->assertTrue($checker->isLocked($product));

        // Gültig: frei
        $this->responses[] = $this->json(['valid' => true, 'code' => 'valid']);
        $checker->checkProduct($product, null, true);
        $this->assertFalse($checker->isLocked($product));
    }

    public function testUnwritableStoreMeansNoRequests(): void
    {
        $this->file = '/proc/nicht-schreibbar/licenses.json';
        $checker = $this->checker(['example.de'], 'CL-IOC-AAAA');
        $checker->checkDue();

        $this->assertSame([], $this->requests);
    }

    private function checker(array $domains, string $key, ?array $endpoints = null): LicenseChecker
    {
        $environment = new class($domains, $key) implements EnvironmentInterface {
            public function __construct(private array $domains, private string $key)
            {
            }

            public function getLicenseKey(string $product): string
            {
                return $this->key;
            }

            public function getDomains(): array
            {
                return $this->domains;
            }
        };

        $http = new MockHttpClient(function (string $method, string $url, array $options) {
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => \is_string($options['body'] ?? null) ? $options['body'] : ''];

            return array_shift($this->responses) ?? throw new TransportException('keine Antwort vorbereitet');
        });

        return new LicenseChecker(
            new ProductRegistry(['isotope-onepage-checkout' => 'Isotope OnePage-Checkout']),
            $environment,
            new LicenseApiClient($http, $endpoints),
            new StateStore($this->file),
            fn (): int => $this->now,
        );
    }

    private function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($data), ['http_code' => $status, 'response_headers' => ['Content-Type' => 'application/json']]);
    }
}
