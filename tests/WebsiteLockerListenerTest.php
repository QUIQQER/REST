<?php

declare(strict_types=1);

namespace QUI\REST\Tests;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Config;
use QUI\Events\Event;
use QUI\Events\Manager as EventsManager;
use QUI\Package\Manager as PackageManager;
use QUI\Package\Package;
use QUI\REST\EventHandler;
use QUI\Rewrite;
use Symfony\Component\HttpFoundation\Request;

final class WebsiteLockerListenerTest extends TestCase
{
    private ?PackageManager $previousPackageManager;
    private ?Request $previousRequest;
    private ?EventsManager $previousEvents;

    protected function setUp(): void
    {
        $this->previousPackageManager = QUI::$PackageManager;
        $this->previousRequest = QUI::$Request;
        $this->previousEvents = QUI::$Events;
    }

    protected function tearDown(): void
    {
        QUI::$PackageManager = $this->previousPackageManager;
        QUI::$Request = $this->previousRequest;
        QUI::$Events = $this->previousEvents;
    }

    #[DataProvider('requestProvider')]
    public function testListenerUsesConfiguredHostAndRouterPath(
        mixed $basePath,
        mixed $baseHost,
        string $uri,
        bool $expected
    ): void {
        $this->setRestContext($basePath, $baseHost);

        self::assertSame(
            $expected,
            EventHandler::onWebsiteLockerAllowRequest(
                Request::create($uri),
                $this->createStub(Rewrite::class),
                'unused-route'
            )
        );
    }

    /** @return iterable<string, array{mixed, mixed, string, bool}> */
    public static function requestProvider(): iterable
    {
        yield 'REST root' => ['/api/', '', 'https://api.example.test/api', true];
        yield 'REST child' => ['/api/', '', 'https://api.example.test/api/items', true];
        yield 'REST query' => ['/api/', '', 'https://api.example.test/api/items?limit=10', true];
        yield 'base path without trailing slash' => ['/api', '', 'https://api.example.test/api/items', true];
        yield 'base path without leading slash' => ['api/', '', 'https://api.example.test/api/items', true];
        yield 'ordinary page' => ['/api/', '', 'https://api.example.test/private', false];
        yield 'query string bypass' => ['/api/', '', 'https://api.example.test/private?target=/api/', false];
        yield 'encoded query' => ['/api/', '', 'https://api.example.test/private?target=%2Fapi%2F', false];
        yield 'prefix without boundary' => ['/api/', '', 'https://api.example.test/api-other', false];
        yield 'path in another directory' => ['/api/', '', 'https://api.example.test/directory/api/items', false];
        yield 'matching host' => ['/api/', 'api.example.test', 'https://api.example.test/api/items', true];
        yield 'different host' => ['/api/', 'api.example.test', 'https://other.example.test/api/items', false];
        yield 'host with scheme and slash' => ['/api/', 'https://api.example.test/', 'https://api.example.test/api', true];
        yield 'empty path follows router' => ['', '', 'https://api.example.test/private', true];
        yield 'root path follows router' => ['/', '', 'https://api.example.test/private', true];
        yield 'root path still checks host' => ['/', 'api.example.test', 'https://other.example.test/private', false];
        yield 'non-string path follows router' => [0, '', 'https://api.example.test/private', true];
        yield 'non-string host follows router' => ['/api/', 0, 'https://api.example.test/api', true];
    }

    public function testRejectedRequestIsAlsoIgnoredByRouter(): void
    {
        $this->setRestContext('/api/', 'api.example.test');
        $Request = Request::create('https://api.example.test/private?target=/api/');
        QUI::$Request = $Request;
        $Rewrite = $this->createStub(Rewrite::class);
        $Events = $this->createMock(EventsManager::class);
        $Events->expects(self::never())->method('fireEvent');
        QUI::$Events = $Events;

        self::assertFalse(EventHandler::onWebsiteLockerAllowRequest($Request, $Rewrite, 'api/items'));
        EventHandler::onRequest($Rewrite, 'api/items');
    }

    public function testEventRegistrationWorksWithCoreDispatcher(): void
    {
        $this->setRestContext('/api/', 'api.example.test');
        $Document = new DOMDocument();
        self::assertTrue($Document->load(dirname(__DIR__) . '/events.xml'));
        $nodes = (new DOMXPath($Document))->query(
            '/quiqqer/events/event[@on="onWebsiteLockerAllowRequest"]'
        );
        self::assertNotFalse($nodes);
        self::assertSame(1, $nodes->length);
        $callback = $nodes->item(0)->getAttribute('fire');
        self::assertSame(EventHandler::class . '::onWebsiteLockerAllowRequest', ltrim($callback, '\\'));

        $Dispatcher = new Event();
        $Dispatcher->addEvent('onWebsiteLockerAllowRequest', $callback);
        $results = $Dispatcher->fireEvent('onWebsiteLockerAllowRequest', [
            Request::create('https://api.example.test/api/items'),
            $this->createStub(Rewrite::class),
            'api/items'
        ]);

        self::assertSame([true], array_values($results));
    }

    public function testMissingConfigCannotGrantExemption(): void
    {
        $Package = $this->createStub(Package::class);
        $Package->method('getConfig')->willReturn(null);
        $Packages = $this->createStub(PackageManager::class);
        $Packages->method('getInstalledPackage')->willReturn($Package);
        QUI::$PackageManager = $Packages;

        $this->expectException(QUI\Exception::class);
        $this->expectExceptionMessage('REST configuration is not available.');

        EventHandler::onWebsiteLockerAllowRequest(
            Request::create('https://api.example.test/api/items'),
            $this->createStub(Rewrite::class),
            'api/items'
        );
    }

    private function setRestContext(mixed $basePath, mixed $baseHost): void
    {
        $Config = $this->createStub(Config::class);
        $Config->method('getValue')->willReturnCallback(
            static fn (string $section, string $key): mixed => $key === 'basePath' ? $basePath : $baseHost
        );
        $Package = $this->createStub(Package::class);
        $Package->method('getConfig')->willReturn($Config);
        $Packages = $this->createStub(PackageManager::class);
        $Packages->method('getInstalledPackage')->willReturn($Package);
        QUI::$PackageManager = $Packages;
    }
}
