<?php

declare(strict_types=1);

/**
 * ContactsMenuProvider Unit Tests
 *
 * Tests the contacts menu provider including action injection,
 * count badge, exception handling, and graceful degradation.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Contacts
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\Contacts;

use OCA\OpenRegister\Contacts\ContactsMenuProvider;
use OCA\OpenRegister\Service\ContactMatchingService;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCP\Contacts\ContactsMenu\IActionFactory;
use OCP\Contacts\ContactsMenu\IEntry;
use OCP\Contacts\ContactsMenu\ILinkAction;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for ContactsMenuProvider.
 */
class ContactsMenuProviderTest extends TestCase
{

    private ContactMatchingService&MockObject $matchingService;
    private DeepLinkRegistryService&MockObject $deepLinkRegistry;
    private IActionFactory&MockObject $actionFactory;
    private IURLGenerator&MockObject $urlGenerator;
    private IL10N&MockObject $l10n;
    private LoggerInterface&MockObject $logger;
    private ContactsMenuProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->matchingService  = $this->createMock(ContactMatchingService::class);
        $this->deepLinkRegistry = $this->createMock(DeepLinkRegistryService::class);
        $this->actionFactory    = $this->createMock(IActionFactory::class);
        $this->urlGenerator     = $this->createMock(IURLGenerator::class);
        $this->l10n             = $this->createMock(IL10N::class);
        $this->logger           = $this->createMock(LoggerInterface::class);

        $this->l10n->method('t')->willReturnCallback(
            static function (string $text): string {
                return $text;
            }
        );

        $this->urlGenerator->method('linkToRouteAbsolute')
            ->willReturn('http://localhost/apps/openregister/');
        $this->urlGenerator->method('imagePath')
            ->willReturn('/apps/openregister/img/app-dark.svg');

        $this->provider = new ContactsMenuProvider(
            $this->matchingService,
            $this->deepLinkRegistry,
            $this->actionFactory,
            $this->urlGenerator,
            $this->l10n,
            $this->logger
        );
    }

    /**
     * Create a mock IEntry with standard contact data.
     *
     * @param string      $email  Primary email
     * @param string      $name   Full name
     * @param string|null $org    Organization
     *
     * @return IEntry&MockObject
     */
    private function createMockEntry(
        string $email = 'jan@example.nl',
        string $name = 'Jan de Vries',
        ?string $org = null
    ): IEntry&MockObject {
        $entry = $this->createMock(IEntry::class);
        $entry->method('getEMailAddresses')->willReturn([$email]);
        $entry->method('getFullName')->willReturn($name);
        $entry->method('getProperty')->willReturnCallback(
            static function (string $key) use ($org) {
                if ($key === 'ORG') {
                    return $org;
                }
                return null;
            }
        );
        return $entry;
    }

    // -------------------------------------------------------------------------
    // Matched contact gets actions and count badge
    // -------------------------------------------------------------------------

    public function testProcessAddsActionsAndCountBadgeWhenMatchesFound(): void
    {
        $entry = $this->createMockEntry();

        $this->matchingService->method('matchContact')
            ->willReturn([
                [
                    'uuid'       => 'match-1',
                    'register'   => ['id' => 1, 'title' => 'Main'],
                    'schema'     => ['id' => 2, 'title' => 'Zaken'],
                    'title'      => 'Zaak 123',
                    'matchType'  => 'email',
                    'confidence' => 1.0,
                    'properties' => [],
                    'cached'     => false,
                ],
            ]);

        $this->matchingService->method('getRelatedObjectCounts')
            ->willReturn(['Zaken' => 1]);

        $this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
        $this->deepLinkRegistry->method('resolveIcon')->willReturn(null);

        $linkAction = $this->createMock(ILinkAction::class);
        $linkAction->method('setPriority')->willReturnSelf();

        $this->actionFactory->method('newLinkAction')
            ->willReturn($linkAction);

        // Should add at least 2 actions: count badge + entity action.
        $entry->expects($this->exactly(2))->method('addAction');

        $this->provider->process($entry);
    }

    // -------------------------------------------------------------------------
    // Unmatched contact gets no actions
    // -------------------------------------------------------------------------

    public function testProcessAddsNoActionsWhenNoMatchesFound(): void
    {
        $entry = $this->createMockEntry();

        $this->matchingService->method('matchContact')
            ->willReturn([]);

        $entry->expects($this->never())->method('addAction');

        $this->provider->process($entry);
    }

    // -------------------------------------------------------------------------
    // Exception is caught and logged
    // -------------------------------------------------------------------------

    public function testProcessCatchesExceptionsAndLogs(): void
    {
        $entry = $this->createMockEntry();

        $this->matchingService->method('matchContact')
            ->willThrowException(new \RuntimeException('DB connection lost'));

        // Should log at warning level.
        $this->logger->expects($this->once())->method('warning');

        // Should NOT throw.
        $entry->expects($this->never())->method('addAction');

        $this->provider->process($entry);
    }

    // -------------------------------------------------------------------------
    // Fallback to default action when deep link returns null
    // -------------------------------------------------------------------------

    public function testProcessFallsBackToDefaultActionWhenNoDeepLink(): void
    {
        $entry = $this->createMockEntry();

        $this->matchingService->method('matchContact')
            ->willReturn([
                [
                    'uuid'       => 'fallback-1',
                    'register'   => ['id' => 1, 'title' => 'Main'],
                    'schema'     => ['id' => 2, 'title' => 'Leads'],
                    'title'      => 'Lead 456',
                    'matchType'  => 'email',
                    'confidence' => 1.0,
                    'properties' => [],
                    'cached'     => false,
                ],
            ]);

        $this->matchingService->method('getRelatedObjectCounts')
            ->willReturn(['Leads' => 1]);

        // Deep link returns null = no registration.
        $this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
        $this->deepLinkRegistry->method('resolveIcon')->willReturn(null);

        $linkAction = $this->createMock(ILinkAction::class);
        $linkAction->method('setPriority')->willReturnSelf();

        // Capture the URL used for the entity action.
        $createdUrls = [];
        $this->actionFactory->method('newLinkAction')
            ->willReturnCallback(
                function ($icon, $label, $url, $appId) use ($linkAction, &$createdUrls) {
                    $createdUrls[] = $url;
                    return $linkAction;
                }
            );

        $entry->expects($this->exactly(2))->method('addAction');

        $this->provider->process($entry);

        // The entity action URL should be the fallback OpenRegister URL.
        $this->assertStringContainsString('fallback-1', $createdUrls[1] ?? '');
    }

    // -------------------------------------------------------------------------
    // Empty email and name skips processing
    // -------------------------------------------------------------------------

    public function testProcessSkipsWhenNoEmailAndNoName(): void
    {
        $entry = $this->createMock(IEntry::class);
        $entry->method('getEMailAddresses')->willReturn([]);
        $entry->method('getFullName')->willReturn('');
        $entry->method('getProperty')->willReturn(null);

        $this->matchingService->expects($this->never())->method('matchContact');
        $entry->expects($this->never())->method('addAction');

        $this->provider->process($entry);
    }
}
