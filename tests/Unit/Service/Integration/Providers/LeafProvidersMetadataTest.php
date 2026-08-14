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
use OCA\OpenRegister\Service\CalendarLinkService;
use OCA\OpenRegister\Service\ContactService;
use OCA\OpenRegister\Service\DeckLinkService;
use OCA\OpenRegister\Service\EmailLinkService;
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
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Contract + delegation tests for all 16 leaf integration providers.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class LeafProvidersMetadataTest extends TestCase {
	/**
	 * Build a mocked IL10N that passes strings through unchanged so
	 * label assertions stay readable.
	 *
	 * @return IL10N
	 */
	private function buildL10n(): IL10N {
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
	private function buildAppManager(array $installed = []): IAppManager {
		$mock = $this->createMock(IAppManager::class);
		$mock->method('isInstalled')->willReturnCallback(
			static fn (string $appId): bool => in_array($appId, $installed, true)
		);
		return $mock;
	}//end buildAppManager()

	/**
	 * Build a PSR-3 logger mock — providers log failure paths through
	 * this; in unit tests we only care that the type contract is met.
	 *
	 * @return LoggerInterface
	 */
	private function buildLogger(): LoggerInterface {
		return $this->createMock(LoggerInterface::class);
	}//end buildLogger()

	/**
	 * Build a greenfield provider instance. The provider's constructor
	 * signature differs by backing-store flavour (db-backed vs.
	 * container/session-backed), so this helper picks the right shape.
	 *
	 * @param string $class Provider class FQN.
	 * @param string $requiredApp Required NC app id (treated as installed).
	 *
	 * @return object Provider instance.
	 */
	private function buildGreenfieldProvider(string $class, string $requiredApp): object {
		return $this->instantiateGreenfieldProvider($class, [$requiredApp]);
	}//end buildGreenfieldProvider()

	/**
	 * Build a mocked link mapper whose `findByObjectUuid()` returns an
	 * empty array — the stub providers query it from `list()` and we
	 * assert an empty result.
	 *
	 * @param string $mapperClass Link-mapper FQN.
	 *
	 * @return object Mock mapper.
	 */
	private function buildLinkMapper(string $mapperClass): object {
		$mapper = $this->getMockBuilder($mapperClass)
			->disableOriginalConstructor()
			->onlyMethods(['findByObjectUuid'])
			->getMock();
		$mapper->method('findByObjectUuid')->willReturn([]);
		return $mapper;
	}//end buildLinkMapper()

	/**
	 * Instantiate a greenfield provider with the right constructor shape.
	 *
	 * The Tier-2 work gave most leaf providers a per-integration link
	 * mapper. Constructor arg order varies by provider, so each shape is
	 * handled explicitly. `$installed` controls which NC apps the mocked
	 * IAppManager reports installed (drives isEnabled()/health()).
	 *
	 * @param string $class Provider class FQN.
	 * @param array<int,string> $installed App ids to treat as installed.
	 *
	 * @return object Provider instance.
	 */
	private function instantiateGreenfieldProvider(string $class, array $installed): object {
		$appManager = $this->buildAppManager($installed);
		$l10n = $this->buildL10n();
		$db = $this->createMock(IDBConnection::class);

		// Container/session-backed provider (Talk).
		if ($class === TalkProvider::class) {
			return new $class(
				container: $this->createMock(ContainerInterface::class),
				appManager: $appManager,
				userSession: $this->createMock(IUserSession::class),
				l10n: $l10n,
			);
		}

		// PollsProvider — (mapper, db, appManager, userSession, l10n).
		if ($class === PollsProvider::class) {
			return new $class(
				pollLinkMapper: $this->buildLinkMapper(\OCA\OpenRegister\Db\PollLinkMapper::class),
				db: $db,
				appManager: $appManager,
				userSession: $this->createMock(IUserSession::class),
				l10n: $l10n,
			);
		}

		// BookmarksProvider — (mapper, appManager, l10n).
		if ($class === BookmarksProvider::class) {
			return new $class(
				bookmarkLinkMapper: $this->buildLinkMapper(\OCA\OpenRegister\Db\BookmarkLinkMapper::class),
				appManager: $appManager,
				l10n: $l10n,
			);
		}

		// FlowProvider — (mapper, db, appManager, l10n).
		if ($class === FlowProvider::class) {
			return new $class(
				flowLinkMapper: $this->buildLinkMapper(\OCA\OpenRegister\Db\FlowLinkMapper::class),
				db: $db,
				appManager: $appManager,
				l10n: $l10n,
			);
		}

		// CospendProvider — (db, appManager, l10n, mapper, ?container).
		if ($class === CospendProvider::class) {
			return new $class(
				db: $db,
				appManager: $appManager,
				l10n: $l10n,
				cospendLinkMapper: $this->buildLinkMapper(\OCA\OpenRegister\Db\CospendLinkMapper::class),
			);
		}

		// TimeProvider — (db, appManager, l10n, mapper, config).
		if ($class === TimeProvider::class) {
			$config = $this->createMock(\OCP\IConfig::class);
			$config->method('getAppValue')->willReturnCallback(
				static function (string $app, string $key, string $default = '') {
					return $default;
				}
			);
			return new TimeProvider(
				db: $db,
				appManager: $appManager,
				l10n: $l10n,
				linkMapper: $this->buildLinkMapper(\OCA\OpenRegister\Db\TimeTrackerLinkMapper::class),
				config: $config,
			);
		}

		// Providers shaped (db, appManager, l10n, <mapper>).
		$trailingMapper = [
			AnalyticsProvider::class => \OCA\OpenRegister\Db\AnalyticsLinkMapper::class,
			CollectivesProvider::class => \OCA\OpenRegister\Db\CollectiveLinkMapper::class,
			MapsProvider::class => \OCA\OpenRegister\Db\MapLinkMapper::class,
			PhotosProvider::class => \OCA\OpenRegister\Db\PhotoLinkMapper::class,
			FormsProvider::class => \OCA\OpenRegister\Db\FormLinkMapper::class,
		];
		if (isset($trailingMapper[$class]) === true) {
			return new $class(
				$db,
				$appManager,
				$l10n,
				$this->buildLinkMapper($trailingMapper[$class]),
			);
		}

		// Default: plain (db, appManager, l10n) providers (Activity, ...).
		return new $class(
			db: $db,
			appManager: $appManager,
			l10n: $l10n,
		);
	}//end instantiateGreenfieldProvider()

	/**
	 * Build a greenfield provider instance with the required NC app
	 * reported missing.
	 *
	 * @param string $class Provider class FQN.
	 *
	 * @return object Provider instance.
	 */
	private function buildGreenfieldProviderMissingApp(string $class): object {
		return $this->instantiateGreenfieldProvider($class, []);
	}//end buildGreenfieldProviderMissingApp()

	/**
	 * Calendar provider exposes the contract metadata declared in
	 * the integration-calendar leaf spec.
	 *
	 * @return void
	 */
	public function testCalendarProviderMetadata(): void {
		$provider = new CalendarProvider(
			calendarEventService: $this->createMock(CalendarEventService::class),
			calendarLinkService: $this->createMock(CalendarLinkService::class),
			appManager: $this->buildAppManager(['calendar']),
			l10n: $this->buildL10n(),
			logger: $this->buildLogger(),
		);

		$this->assertSame('calendar', $provider->getId());
		$this->assertSame('Meetings', $provider->getLabel());
		$this->assertSame('Calendar', $provider->getIcon());
		$this->assertSame('comms', $provider->getGroup());
		$this->assertSame('calendar', $provider->getRequiredApp());
		$this->assertSame('link-table', $provider->getStorageStrategy());
		$this->assertTrue($provider->isEnabled());
	}//end testCalendarProviderMetadata()

	public function testCalendarProviderListDelegatesToService(): void {
		// Tier-2: list() now goes through CalendarLinkService::getLinkedEvents
		// (UNION over the link table + the legacy X-OR-* scan).
		$link = $this->createMock(CalendarLinkService::class);
		$link->expects($this->once())
			->method('getLinkedEvents')
			->with('obj-uuid')
			->willReturn([['id' => 'cal1/event.ics', 'source' => 'both']]);

		$provider = new CalendarProvider(
			calendarEventService: $this->createMock(CalendarEventService::class),
			calendarLinkService: $link,
			appManager: $this->buildAppManager(['calendar']),
			l10n: $this->buildL10n(),
			logger: $this->buildLogger(),
		);

		$this->assertSame([['id' => 'cal1/event.ics', 'source' => 'both']], $provider->list('reg', 'sch', 'obj-uuid'));
	}//end testCalendarProviderListDelegatesToService()

	public function testCalendarProviderListSurfacesEmptyOnFailure(): void {
		$link = $this->createMock(CalendarLinkService::class);
		$link->method('getLinkedEvents')->willThrowException(new \RuntimeException('no calendar'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('CalendarProvider::list()'),
				$this->callback(static function (array $ctx): bool {
					return ($ctx['provider'] ?? null) === CalendarProvider::class
						&& ($ctx['method'] ?? null) === 'list'
						&& ($ctx['objectId'] ?? null) === 'obj-uuid'
						&& isset($ctx['exception'])
						&& $ctx['exception'] instanceof \Throwable;
				})
			);

		$provider = new CalendarProvider(
			calendarEventService: $this->createMock(CalendarEventService::class),
			calendarLinkService: $link,
			appManager: $this->buildAppManager(['calendar']),
			l10n: $this->buildL10n(),
			logger: $logger,
		);

		$this->assertSame([], $provider->list('reg', 'sch', 'obj-uuid'));
	}//end testCalendarProviderListSurfacesEmptyOnFailure()

	public function testCalendarProviderDeleteHandlesLegacyAndBareUidShapes(): void {
		$eventSvc = $this->createMock(CalendarEventService::class);
		$eventSvc->expects($this->once())->method('unlinkEvent')->with('7', 'event.ics');
		$linkSvc = $this->createMock(CalendarLinkService::class);
		$linkSvc->expects($this->once())->method('unlinkEvent')->with('o', 'ev-uid');

		$provider = new CalendarProvider(
			calendarEventService: $eventSvc,
			calendarLinkService: $linkSvc,
			appManager: $this->buildAppManager(['calendar']),
			l10n: $this->buildL10n(),
			logger: $this->buildLogger(),
		);

		// Legacy composite shape — strips X-OR-* via CalendarEventService.
		$provider->delete('r', 's', 'o', '7/event.ics');
		// Bare uid shape — Tier-2 link-only removal.
		$provider->delete('r', 's', 'o', 'ev-uid');
	}//end testCalendarProviderDeleteHandlesLegacyAndBareUidShapes()

	public function testCalendarProviderHealthReportsUnavailableWhenAppMissing(): void {
		$provider = new CalendarProvider(
			calendarEventService: $this->createMock(CalendarEventService::class),
			calendarLinkService: $this->createMock(CalendarLinkService::class),
			appManager: $this->buildAppManager([]),
			l10n: $this->buildL10n(),
			logger: $this->buildLogger(),
		);

		$health = $provider->health();
		$this->assertSame('unavailable', $health['status']);
	}//end testCalendarProviderHealthReportsUnavailableWhenAppMissing()

	public function testContactsProviderMetadata(): void {
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

	public function testContactsProviderListReturnsResultsArray(): void {
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

	public function testContactsProviderCreateThrowsNotImplemented(): void {
		$provider = new ContactsProvider(
			contactService: $this->createMock(ContactService::class),
			appManager: $this->buildAppManager(['contacts']),
			l10n: $this->buildL10n(),
		);

		$this->expectException(NotImplementedException::class);
		$provider->create('r', 's', 'obj', []);
	}//end testContactsProviderCreateThrowsNotImplemented()

	public function testDeckProviderMetadataAndEnableGate(): void {
		$service = $this->createMock(DeckLinkService::class);
		$service->method('isDeckAvailable')->willReturn(true);

		$provider = new DeckProvider(
			deckLinkService: $service,
			appManager: $this->buildAppManager(['deck']),
			l10n: $this->buildL10n(),
		);

		$this->assertSame('deck', $provider->getId());
		$this->assertSame('Cards', $provider->getLabel());
		$this->assertSame('workflow', $provider->getGroup());
		$this->assertSame('link-table', $provider->getStorageStrategy());
		$this->assertTrue($provider->isEnabled());
	}//end testDeckProviderMetadataAndEnableGate()

	public function testEmailProviderMetadataAndDelegation(): void {
		$service = $this->createMock(EmailService::class);
		$service->method('getEmailsForObject')->willReturn(['results' => [['id' => 9]], 'total' => 1]);

		// Tier-2: isEnabled()/health() gate through EmailLinkService.
		$linkService = $this->createMock(EmailLinkService::class);
		$linkService->method('isMailAvailable')->willReturn(true);

		$provider = new EmailProvider(
			emailService: $service,
			emailLinkService: $linkService,
			appManager: $this->buildAppManager(['mail']),
			l10n: $this->buildL10n(),
		);

		$this->assertSame('email', $provider->getId());
		$this->assertSame('Email', $provider->getIcon());
		$this->assertTrue($provider->isEnabled());
		$this->assertSame(
			['items' => [['id' => 9]], 'total' => 1, 'nextCursor' => null],
			$provider->list('r', 's', 'obj')
		);
	}//end testEmailProviderMetadataAndDelegation()

	/**
	 * OpenProject provider exposes the external/OpenConnector-routed
	 * contract declared in the integration-openproject leaf spec.
	 *
	 * @return void
	 */
	public function testOpenProjectProviderMetadata(): void {
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

	public function testOpenProjectProviderListRoutesThroughExternalRouter(): void {
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
	public function greenfieldStubProvider(): array {
		// [class, id, label, icon, group, requiredApp, storage]
		return [
			'activity' => [ActivityProvider::class, 'activity', 'Activity', 'Timeline', 'workflow', 'activity', 'query-time'],
			'analytics' => [AnalyticsProvider::class, 'analytics', 'Analytics', 'ChartBar', 'workflow', 'analytics', 'link-table'],
			'bookmarks' => [BookmarksProvider::class, 'bookmarks', 'Bookmarks', 'Bookmark', 'docs', 'bookmarks', 'link-table'],
			'collectives' => [CollectivesProvider::class, 'collectives', 'Knowledge', 'BookOpenPageVariant', 'docs', 'collectives', 'link-table'],
			'cospend' => [CospendProvider::class, 'cospend', 'Costs', 'CurrencyEur', 'workflow', 'cospend', 'link-table'],
			'flow' => [FlowProvider::class, 'flow', 'Automation', 'RobotOutline', 'workflow', 'workflowengine', 'link-table'],
			'forms' => [FormsProvider::class, 'forms', 'Forms', 'ClipboardText', 'workflow', 'forms', 'link-table'],
			'maps' => [MapsProvider::class, 'maps', 'Locations', 'MapMarker', 'docs', 'maps', 'link-table'],
			'photos' => [PhotosProvider::class, 'photos', 'Photos', 'Image', 'docs', 'photos', 'link-table'],
			'polls' => [PollsProvider::class, 'polls', 'Polls', 'Poll', 'workflow', 'polls', 'link-table'],
			'talk' => [TalkProvider::class, 'talk', 'Chat', 'ChatOutline', 'comms', 'spreed', 'link-table'],
			'time-tracker' => [TimeProvider::class, 'time-tracker', 'Time tracker', 'Clock', 'workflow', 'timemanager', 'link-table'],
		];
	}//end greenfieldStubProvider()

	/**
	 * Every greenfield provider declares the registry metadata and
	 * returns an empty list from `list()` while gated on its app.
	 *
	 * @param string $class Provider class.
	 * @param string $id Expected id.
	 * @param string $label Expected label.
	 * @param string $icon Expected icon.
	 * @param string $group Expected group.
	 * @param string $requiredApp Expected required app.
	 * @param string $storage Expected storage strategy.
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
		$provider = $this->buildGreenfieldProvider($class, $requiredApp);

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
	 * @param string $class Provider class.
	 * @param string $id Expected id (unused).
	 * @param string $label Expected label (unused).
	 * @param string $icon Expected icon (unused).
	 * @param string $group Expected group (unused).
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
		$provider = $this->buildGreenfieldProviderMissingApp($class);

		$this->assertFalse($provider->isEnabled());
		$this->assertSame('unavailable', $provider->health()['status']);
	}//end testGreenfieldProviderHidesWhenRequiredAppMissing()

	/**
	 * Shares provider is NC-core (no required app), query-time
	 * storage, with list() driven by an IDBConnection LIKE query.
	 *
	 * @return void
	 */
	public function testSharesProviderMetadata(): void {
		$provider = new SharesProvider(
			db: $this->createMock(IDBConnection::class),
			appManager: $this->buildAppManager([]),
			l10n: $this->buildL10n(),
		);

		$this->assertSame('shares', $provider->getId());
		$this->assertSame('Shares', $provider->getLabel());
		$this->assertSame('Share', $provider->getIcon());
		$this->assertSame('core', $provider->getGroup());
		$this->assertNull($provider->getRequiredApp());
		$this->assertSame('query-time', $provider->getStorageStrategy());
		$this->assertTrue($provider->isEnabled());
	}//end testSharesProviderMetadata()

	public function testSharesProviderCreateThrowsNotImplemented(): void {
		// Inject a mock container that returns null for every service lookup
		// so that SharesProvider::lookup() cannot resolve CaseTokenService
		// from the real NC container. Without the container override,
		// \OCP\Server::get(CaseTokenService) succeeds in the docker environment
		// but then throws InvalidArgumentException (no logged-in user) rather
		// than falling through to parent::create() → NotImplementedException.
		$mockContainer = $this->createMock(ContainerInterface::class);
		$mockContainer->method('get')->willThrowException(new \RuntimeException('not found'));
		$mockContainer->method('has')->willReturn(false);

		$provider = new SharesProvider(
			db: $this->createMock(IDBConnection::class),
			appManager: $this->buildAppManager([]),
			l10n: $this->buildL10n(),
			container: $mockContainer,
		);

		$this->expectException(NotImplementedException::class);
		$provider->create('r', 's', 'obj', []);
	}//end testSharesProviderCreateThrowsNotImplemented()
}//end class
