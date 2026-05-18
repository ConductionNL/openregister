<?php

/**
 * Metadata + contract tests for the 16 leaf IntegrationProvider
 * implementations under `Service/Integration/Providers/`.
 *
 * The tests follow the same shape as
 * {@see \OCA\OpenRegister\Tests\Unit\Service\Integration\BuiltinProvidersMetadataTest}
 * — they exercise the provider contract (id, label, icon, group,
 * storage, requiredApp, isEnabled) and a representative behavioural
 * path per provider (delegation for backend-shipped ones, empty list
 * for greenfield stubs, NotImplementedException for mutation methods
 * the leaf doesn't yet support). Wrapped-service tests live with the
 * wrapped services (EmailServiceTest, CalendarEventServiceTest, ...);
 * duplicating them here would just re-test the wrap.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-calendar/tasks.md
 * @spec openspec/changes/integration-contacts/tasks.md
 * @spec openspec/changes/integration-deck/tasks.md
 * @spec openspec/changes/integration-email/tasks.md
 * @spec openspec/changes/integration-openproject/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- test method names + arrange/act/assert structure make intent obvious; matches BuiltinProvidersMetadataTest convention.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers (assertSame, assertNull, ...) take positional args by convention; mirroring BuiltinProvidersMetadataTest in this repo.

use OCA\OpenRegister\Exception\NotImplementedException;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\ContactService;
use OCA\OpenRegister\Service\DeckCardService;
use OCA\OpenRegister\Service\EmailService;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\Providers\ActivityProvider;
use OCA\OpenRegister\Service\Integration\Providers\AnalyticsProvider;
use OCA\OpenRegister\Service\Integration\Providers\BookmarksProvider;
use OCA\OpenRegister\Service\Integration\Providers\CalendarProvider;
use OCA\OpenRegister\Service\Integration\Providers\CollectivesProvider;
use OCA\OpenRegister\Service\Integration\Providers\ContactsProvider;
use OCA\OpenRegister\Service\Integration\Providers\CospendProvider;
use OCA\OpenRegister\Service\Integration\Providers\DeckProvider;
use OCA\OpenRegister\Service\Integration\Providers\EmailProvider;
use OCA\OpenRegister\Service\Integration\Providers\FlowProvider;
use OCA\OpenRegister\Service\Integration\Providers\FormsProvider;
use OCA\OpenRegister\Service\Integration\Providers\MapsProvider;
use OCA\OpenRegister\Service\Integration\Providers\OpenProjectProvider;
use OCA\OpenRegister\Service\Integration\Providers\PhotosProvider;
use OCA\OpenRegister\Service\Integration\Providers\PollsProvider;
use OCA\OpenRegister\Service\Integration\Providers\SharesProvider;
use OCA\OpenRegister\Service\Integration\Providers\TalkProvider;
use OCA\OpenRegister\Service\Integration\Providers\TimeProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\TestCase;

/**
 * Contract + delegation tests for all 16 leaf integration providers.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class LeafProvidersMetadataTest extends TestCase
{
    /**
     * Build a mocked IL10N that passes strings through unchanged so
     * label assertions stay readable.
     *
     * @return IL10N
     */
    private function buildL10n(): IL10N
    {
        $mock = $this->createMock(IL10N::class);
        $mock->method('t')->willReturnArgument(0);
        return $mock;
    }//end buildL10n()

    /**
     * Build a mocked IAppManager that reports the given apps as installed.
     *
     * @param array<int,string> $installed App ids to treat as installed.
     *
     * @return IAppManager
     */
    private function buildAppManager(array $installed=[]): IAppManager
    {
        $mock = $this->createMock(IAppManager::class);
        $mock->method('isInstalled')->willReturnCallback(
            static fn(string $appId): bool => in_array($appId, $installed, true)
        );
        return $mock;
    }//end buildAppManager()

    /**
     * Calendar provider exposes the contract metadata declared in
     * the integration-calendar leaf spec.
     *
     * @return void
     */
    public function testCalendarProviderMetadata(): void
    {
        $provider = new CalendarProvider(
            calendarEventService: $this->createMock(CalendarEventService::class),
            appManager: $this->buildAppManager(['calendar']),
            l10n: $this->buildL10n(),
        );

        $this->assertSame('calendar', $provider->getId());
        $this->assertSame('Meetings', $provider->getLabel());
        $this->assertSame('Calendar', $provider->getIcon());
        $this->assertSame('comms', $provider->getGroup());
        $this->assertSame('calendar', $provider->getRequiredApp());
        $this->assertSame('link-table', $provider->getStorageStrategy());
        $this->assertTrue($provider->isEnabled());
    }//end testCalendarProviderMetadata()

    public function testCalendarProviderListDelegatesToService(): void
    {
        $service = $this->createMock(CalendarEventService::class);
        $service->expects($this->once())
            ->method('getEventsForObject')
            ->with('obj-uuid')
            ->willReturn([['id' => 'cal1/event.ics']]);

        $provider = new CalendarProvider(
            calendarEventService: $service,
            appManager: $this->buildAppManager(['calendar']),
            l10n: $this->buildL10n(),
        );

        $this->assertSame([['id' => 'cal1/event.ics']], $provider->list('reg', 'sch', 'obj-uuid'));
    }//end testCalendarProviderListDelegatesToService()

    public function testCalendarProviderListSurfacesEmptyOnFailure(): void
    {
        $service = $this->createMock(CalendarEventService::class);
        $service->method('getEventsForObject')->willThrowException(new \RuntimeException('no calendar'));

        $provider = new CalendarProvider(
            calendarEventService: $service,
            appManager: $this->buildAppManager(['calendar']),
            l10n: $this->buildL10n(),
        );

        $this->assertSame([], $provider->list('reg', 'sch', 'obj-uuid'));
    }//end testCalendarProviderListSurfacesEmptyOnFailure()

    public function testCalendarProviderDeleteRejectsMalformedEntityId(): void
    {
        $provider = new CalendarProvider(
            calendarEventService: $this->createMock(CalendarEventService::class),
            appManager: $this->buildAppManager(['calendar']),
            l10n: $this->buildL10n(),
        );
        $this->expectException(\InvalidArgumentException::class);
        $provider->delete('r', 's', 'o', 'not-a-composite');
    }//end testCalendarProviderDeleteRejectsMalformedEntityId()

    public function testCalendarProviderHealthReportsUnavailableWhenAppMissing(): void
    {
        $provider = new CalendarProvider(
            calendarEventService: $this->createMock(CalendarEventService::class),
            appManager: $this->buildAppManager([]),
            l10n: $this->buildL10n(),
        );

        $health = $provider->health();
        $this->assertSame('unavailable', $health['status']);
    }//end testCalendarProviderHealthReportsUnavailableWhenAppMissing()

    public function testContactsProviderMetadata(): void
    {
        $provider = new ContactsProvider(
            contactService: $this->createMock(ContactService::class),
            appManager: $this->buildAppManager(['contacts']),
            l10n: $this->buildL10n(),
        );

        $this->assertSame('contacts', $provider->getId());
        $this->assertSame('Contacts', $provider->getLabel());
        $this->assertSame('AccountBox', $provider->getIcon());
        $this->assertSame('comms', $provider->getGroup());
        $this->assertSame('contacts', $provider->getRequiredApp());
        $this->assertSame('link-table', $provider->getStorageStrategy());
    }//end testContactsProviderMetadata()

    public function testContactsProviderListReturnsResultsArray(): void
    {
        $service = $this->createMock(ContactService::class);
        $service->method('getContactsForObject')->willReturn(
            ['results' => [['id' => 1]], 'total' => 1]
        );

        $provider = new ContactsProvider(
            contactService: $service,
            appManager: $this->buildAppManager(['contacts']),
            l10n: $this->buildL10n(),
        );

        $this->assertSame([['id' => 1]], $provider->list('r', 's', 'obj'));
    }//end testContactsProviderListReturnsResultsArray()

    public function testContactsProviderCreateThrowsNotImplemented(): void
    {
        $provider = new ContactsProvider(
            contactService: $this->createMock(ContactService::class),
            appManager: $this->buildAppManager(['contacts']),
            l10n: $this->buildL10n(),
        );

        $this->expectException(NotImplementedException::class);
        $provider->create('r', 's', 'obj', []);
    }//end testContactsProviderCreateThrowsNotImplemented()

    public function testDeckProviderMetadataAndEnableGate(): void
    {
        $service = $this->createMock(DeckCardService::class);
        $service->method('isDeckAvailable')->willReturn(true);

        $provider = new DeckProvider(
            deckCardService: $service,
            appManager: $this->buildAppManager(['deck']),
            l10n: $this->buildL10n(),
        );

        $this->assertSame('deck', $provider->getId());
        $this->assertSame('Cards', $provider->getLabel());
        $this->assertSame('workflow', $provider->getGroup());
        $this->assertSame('link-table', $provider->getStorageStrategy());
        $this->assertTrue($provider->isEnabled());
    }//end testDeckProviderMetadataAndEnableGate()

    public function testEmailProviderMetadataAndDelegation(): void
    {
        $service = $this->createMock(EmailService::class);
        $service->method('isMailAvailable')->willReturn(true);
        $service->method('getEmailsForObject')->willReturn(['results' => [['id' => 9]], 'total' => 1]);

        $provider = new EmailProvider(
            emailService: $service,
            appManager: $this->buildAppManager(['mail']),
            l10n: $this->buildL10n(),
        );

        $this->assertSame('email', $provider->getId());
        $this->assertSame('Email', $provider->getIcon());
        $this->assertTrue($provider->isEnabled());
        $this->assertSame([['id' => 9]], $provider->list('r', 's', 'obj'));
    }//end testEmailProviderMetadataAndDelegation()

    /**
     * OpenProject provider exposes the external/OpenConnector-routed
     * contract declared in the integration-openproject leaf spec.
     *
     * @return void
     */
    public function testOpenProjectProviderMetadata(): void
    {
        $provider = new OpenProjectProvider(
            router: $this->createMock(ExternalIntegrationRouter::class),
            appManager: $this->buildAppManager(['openconnector']),
            l10n: $this->buildL10n(),
        );

        $this->assertSame('openproject', $provider->getId());
        $this->assertSame('Projects', $provider->getLabel());
        $this->assertSame('Briefcase', $provider->getIcon());
        $this->assertSame('external', $provider->getGroup());
        $this->assertSame('openconnector', $provider->getRequiredApp());
        $this->assertSame('external', $provider->getStorageStrategy());
        $this->assertSame('openproject', $provider->getOpenConnectorSource());
        $this->assertTrue($provider->isEnabled());

        $auth = $provider->authRequirements();
        $this->assertSame('external', $auth['type']);
        $this->assertSame('openproject', $auth['source']);
    }//end testOpenProjectProviderMetadata()

    public function testOpenProjectProviderListRoutesThroughExternalRouter(): void
    {
        $router = $this->createMock(ExternalIntegrationRouter::class);
        $router->expects($this->once())
            ->method('call')
            ->willReturn(['_embedded' => ['elements' => [['id' => 42, 'subject' => 'Wp']]]]);

        $provider = new OpenProjectProvider(
            router: $router,
            appManager: $this->buildAppManager(['openconnector']),
            l10n: $this->buildL10n(),
        );

        $rows = $provider->list('r', 's', 'obj');
        $this->assertCount(1, $rows);
        $this->assertSame('42', $rows[0]['id']);
        $this->assertSame('Wp', $rows[0]['title']);
    }//end testOpenProjectProviderListRoutesThroughExternalRouter()

    // -------------------------------------------------------------------
    // Greenfield NC-app-backed (registry-surface stubs)
    // -------------------------------------------------------------------

    /**
     * Provides every greenfield stub provider class + its expected
     * registry metadata. Used as a single data provider over the
     * stub-shape contract test below.
     *
     * @return array<string,array{0: string, 1: string, 2: string, 3: string, 4: ?string, 5: string}>
     */
    public function greenfieldStubProvider(): array
    {
        // [class, id, label, icon, group, requiredApp, storage]
        return [
            'activity'     => [ActivityProvider::class, 'activity', 'Activity', 'Timeline', 'workflow', 'activity', 'query-time'],
            'analytics'    => [AnalyticsProvider::class, 'analytics', 'Analytics', 'ChartBar', 'workflow', 'analytics', 'link-table'],
            'bookmarks'    => [BookmarksProvider::class, 'bookmarks', 'Bookmarks', 'Bookmark', 'docs', 'bookmarks', 'link-table'],
            'collectives'  => [CollectivesProvider::class, 'collectives', 'Knowledge', 'BookOpenPageVariant', 'docs', 'collectives', 'link-table'],
            'cospend'      => [CospendProvider::class, 'cospend', 'Costs', 'CurrencyEur', 'workflow', 'cospend', 'link-table'],
            'flow'         => [FlowProvider::class, 'flow', 'Automation', 'RobotOutline', 'workflow', 'workflowengine', 'link-table'],
            'forms'        => [FormsProvider::class, 'forms', 'Forms', 'ClipboardText', 'workflow', 'forms', 'link-table'],
            'maps'         => [MapsProvider::class, 'maps', 'Location', 'MapMarker', 'docs', 'maps', 'link-table'],
            'photos'       => [PhotosProvider::class, 'photos', 'Photos', 'Image', 'docs', 'photos', 'link-table'],
            'polls'        => [PollsProvider::class, 'polls', 'Polls', 'Poll', 'workflow', 'polls', 'link-table'],
            'talk'         => [TalkProvider::class, 'talk', 'Chat', 'ChatOutline', 'comms', 'spreed', 'link-table'],
            'time-tracker' => [TimeProvider::class, 'time-tracker', 'Time', 'Clock', 'workflow', 'timemanager', 'link-table'],
        ];
    }//end greenfieldStubProvider()

    /**
     * Every greenfield provider declares the registry metadata and
     * returns an empty list from `list()` while gated on its app.
     *
     * @param string $class       Provider class.
     * @param string $id          Expected id.
     * @param string $label       Expected label.
     * @param string $icon        Expected icon.
     * @param string $group       Expected group.
     * @param string $requiredApp Expected required app.
     * @param string $storage     Expected storage strategy.
     *
     * @return void
     *
     * @dataProvider greenfieldStubProvider
     */
    public function testGreenfieldProviderContract(
        string $class,
        string $id,
        string $label,
        string $icon,
        string $group,
        string $requiredApp,
        string $storage,
    ): void {
        $provider = new $class(
            appManager: $this->buildAppManager([$requiredApp]),
            l10n: $this->buildL10n(),
        );

        $this->assertSame($id, $provider->getId());
        $this->assertSame($label, $provider->getLabel());
        $this->assertSame($icon, $provider->getIcon());
        $this->assertSame($group, $provider->getGroup());
        $this->assertSame($requiredApp, $provider->getRequiredApp());
        $this->assertSame($storage, $provider->getStorageStrategy());
        $this->assertNull($provider->getOpenConnectorSource());
        $this->assertTrue($provider->isEnabled());
        // Stubs return an empty list; mutation methods inherit
        // NotImplementedException from AbstractIntegrationProvider.
        $this->assertSame([], $provider->list('r', 's', 'obj'));
    }//end testGreenfieldProviderContract()

    /**
     * Every greenfield provider's `isEnabled()` returns false when
     * its required NC app isn't installed, and `health()` reports
     * unavailable.
     *
     * @param string $class       Provider class.
     * @param string $id          Expected id (unused).
     * @param string $label       Expected label (unused).
     * @param string $icon        Expected icon (unused).
     * @param string $group       Expected group (unused).
     * @param string $requiredApp Expected required app.
     *
     * @return void
     *
     * @dataProvider greenfieldStubProvider
     */
    public function testGreenfieldProviderHidesWhenRequiredAppMissing(
        string $class,
        string $id,
        string $label,
        string $icon,
        string $group,
        string $requiredApp,
    ): void {
        $provider = new $class(
            appManager: $this->buildAppManager([]),
            l10n: $this->buildL10n(),
        );

        $this->assertFalse($provider->isEnabled());
        $this->assertSame('unavailable', $provider->health()['status']);
    }//end testGreenfieldProviderHidesWhenRequiredAppMissing()

    /**
     * Shares provider is NC-core (no required app), query-time
     * storage, with delete() delegating to IShareManager.
     *
     * @return void
     */
    public function testSharesProviderMetadata(): void
    {
        $provider = new SharesProvider(
            shareManager: $this->createMock(IShareManager::class),
            l10n: $this->buildL10n(),
        );

        $this->assertSame('shares', $provider->getId());
        $this->assertSame('Shares', $provider->getLabel());
        $this->assertSame('Share', $provider->getIcon());
        $this->assertSame('core', $provider->getGroup());
        $this->assertNull($provider->getRequiredApp());
        $this->assertSame('query-time', $provider->getStorageStrategy());
        $this->assertTrue($provider->isEnabled());
        $this->assertSame([], $provider->list('r', 's', 'obj'));
    }//end testSharesProviderMetadata()

    public function testSharesProviderCreateThrowsNotImplemented(): void
    {
        $provider = new SharesProvider(
            shareManager: $this->createMock(IShareManager::class),
            l10n: $this->buildL10n(),
        );

        $this->expectException(NotImplementedException::class);
        $provider->create('r', 's', 'obj', []);
    }//end testSharesProviderCreateThrowsNotImplemented()
}//end class
