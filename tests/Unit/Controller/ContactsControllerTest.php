<?php

declare(strict_types=1);

/**
 * ContactsController Unit Tests
 *
 * Tests the contacts match API endpoint including success, validation,
 * and error handling.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ContactsController;
use OCA\OpenRegister\Service\ContactMatchingService;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for ContactsController.
 */
class ContactsControllerTest extends TestCase
{

    private IRequest&MockObject $request;
    private ContactMatchingService&MockObject $matchingService;
    private DeepLinkRegistryService&MockObject $deepLinkRegistry;
    private IL10N&MockObject $l10n;
    private LoggerInterface&MockObject $logger;
    private ContactsController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request          = $this->createMock(IRequest::class);
        $this->matchingService  = $this->createMock(ContactMatchingService::class);
        $this->deepLinkRegistry = $this->createMock(DeepLinkRegistryService::class);
        $this->l10n             = $this->createMock(IL10N::class);
        $this->logger           = $this->createMock(LoggerInterface::class);

        $this->l10n->method('t')->willReturnCallback(
            static function (string $text): string {
                return $text;
            }
        );

        $this->controller = new ContactsController(
            'openregister',
            $this->request,
            $this->matchingService,
            $this->deepLinkRegistry,
            $this->l10n,
            $this->logger
        );
    }

    // -------------------------------------------------------------------------
    // Successful match
    // -------------------------------------------------------------------------

    public function testMatchReturns200WithCorrectJsonStructure(): void
    {
        $this->request->method('getParam')
            ->willReturnCallback(static function (string $key, $default = '') {
                return match ($key) {
                    'email' => 'jan@example.nl',
                    'name' => 'Jan de Vries',
                    'organization' => '',
                    default => $default,
                };
            });

        $this->matchingService->method('matchContact')
            ->willReturn([
                [
                    'uuid'       => 'result-1',
                    'register'   => ['id' => 1, 'title' => 'Main'],
                    'schema'     => ['id' => 2, 'title' => 'Medewerkers'],
                    'title'      => 'Jan de Vries',
                    'matchType'  => 'email',
                    'confidence' => 1.0,
                    'properties' => ['email' => 'jan@example.nl'],
                    'cached'     => false,
                ],
            ]);

        $this->deepLinkRegistry->method('resolveUrl')->willReturn('/apps/procest/#/cases/result-1');
        $this->deepLinkRegistry->method('resolveIcon')->willReturn('/apps/procest/img/app.svg');

        $response = $this->controller->match();

        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('matches', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('cached', $data);
        $this->assertSame(1, $data['total']);
        $this->assertSame('/apps/procest/#/cases/result-1', $data['matches'][0]['url']);
        $this->assertSame('/apps/procest/img/app.svg', $data['matches'][0]['icon']);
    }

    // -------------------------------------------------------------------------
    // Missing parameters
    // -------------------------------------------------------------------------

    public function testMatchReturns400WhenNoEmailOrNameProvided(): void
    {
        $this->request->method('getParam')
            ->willReturnCallback(static function (string $key, $default = '') {
                return match ($key) {
                    'email' => '',
                    'name' => '',
                    'organization' => 'Gemeente Tilburg',
                    default => $default,
                };
            });

        $response = $this->controller->match();

        $this->assertSame(400, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('error', $data);
        $this->assertSame(0, $data['total']);
    }

    // -------------------------------------------------------------------------
    // Internal server error
    // -------------------------------------------------------------------------

    public function testMatchReturns500OnInternalError(): void
    {
        $this->request->method('getParam')
            ->willReturnCallback(static function (string $key, $default = '') {
                return match ($key) {
                    'email' => 'jan@example.nl',
                    default => $default,
                };
            });

        $this->matchingService->method('matchContact')
            ->willThrowException(new \RuntimeException('Database error'));

        $response = $this->controller->match();

        $this->assertSame(500, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('error', $data);
    }

    // -------------------------------------------------------------------------
    // Email-only match
    // -------------------------------------------------------------------------

    public function testMatchWorksWithEmailOnly(): void
    {
        $this->request->method('getParam')
            ->willReturnCallback(static function (string $key, $default = '') {
                return match ($key) {
                    'email' => 'test@example.nl',
                    'name' => '',
                    'organization' => '',
                    default => $default,
                };
            });

        $this->matchingService->method('matchContact')
            ->with('test@example.nl', null, null)
            ->willReturn([]);

        $this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
        $this->deepLinkRegistry->method('resolveIcon')->willReturn(null);

        $response = $this->controller->match();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(0, $response->getData()['total']);
    }
}
