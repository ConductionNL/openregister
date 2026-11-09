<?php

/**
 * Every built-in integration provider factory builds its service from the right container entries.
 *
 * `IntegrationWiring::registerBuiltinIntegrationProviders()` builds these services by
 * hand, so autowiring does not cover them. This runs each registered factory
 * against a container double and pins which container entries it reads, in
 * which order, and which class it returns.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\AppInfo
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://www.OpenRegister.nl
 *
 * @spec openspec/specs/integration-registry/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppInfo;

use OCA\OpenRegister\Service\Integration\IntegrationWiring;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;

/**
 * Wiring of the built-in integration providers.
 */
class BuiltinIntegrationProviderRegistrationTest extends TestCase {

	/**
	 * The container entries each factory reads, in order, keyed by service.
	 *
	 * A factory that also hands over the container itself does so without a read.
	 *
	 * @var array<string, list<string>>
	 */
	private const EXPECTED = [
		'OCA\OpenRegister\Service\Integration\BuiltinProviders\FilesProvider' => [
			'OCA\OpenRegister\Service\FileService',
			'OCP\IL10N',
		],
		'OCA\OpenRegister\Service\Integration\BuiltinProviders\NotesProvider' => [
			'OCA\OpenRegister\Kernel\Contracts\NoteServiceInterface',
			'OCP\IL10N',
		],
		'OCA\OpenRegister\Service\Integration\BuiltinProviders\TasksProvider' => [
			'OCA\OpenRegister\Service\TaskService',
			'OCA\OpenRegister\Db\RegisterMapperInterface',
			'OCA\OpenRegister\Db\SchemaMapperInterface',
			'OCA\OpenRegister\Service\ObjectService',
			'OCP\IL10N',
		],
		'OCA\OpenRegister\Service\Integration\BuiltinProviders\TagsProvider' => [
			'OCP\SystemTag\ISystemTagManager',
			'OCP\SystemTag\ISystemTagObjectMapper',
			'OCP\IL10N',
		],
		'OCA\OpenRegister\Service\Integration\BuiltinProviders\AuditTrailProvider' => [
			'OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders',
			'OCP\IL10N',
		],
		'OCA\OpenRegister\Service\Integration\Providers\XwikiProvider' => [
			'OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter',
			'OCP\App\IAppManager',
			'OCP\IL10N',
			'OCA\OpenRegister\Db\XwikiLinkMapperInterface',
		],
		'OCA\OpenRegister\Service\XwikiLinkService' => [
			'OCA\OpenRegister\Db\XwikiLinkMapperInterface',
			'OCA\OpenRegister\Service\LinkServiceRuntime',
		],
		'OCA\OpenRegister\Service\Integration\Providers\KvkProvider' => [
			'OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter',
			'OCP\App\IAppManager',
			'OCP\IL10N',
			'Psr\Log\LoggerInterface',
		],
		'OCA\OpenRegister\Service\Integration\Providers\OpenCorporatesProvider' => [
			'OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter',
			'OCP\App\IAppManager',
			'OCP\IL10N',
			'Psr\Log\LoggerInterface',
		],
		'OCA\OpenRegister\Service\Integration\Providers\MessageDispatchProvider' => [
			'OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter',
			'OCP\App\IAppManager',
			'OCP\IL10N',
			'Psr\Log\LoggerInterface',
		],
		'OCA\OpenRegister\Service\Integration\Providers\OpenProjectProvider' => [
			'OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter',
			'OCP\App\IAppManager',
			'OCP\IL10N',
			'OCA\OpenRegister\Db\OpenProjectLinkMapperInterface',
		],
		'OCA\OpenRegister\Service\OpenProjectLinkService' => [
			'OCA\OpenRegister\Db\OpenProjectLinkMapperInterface',
			'OCA\OpenRegister\Service\Integration\Providers\OpenProjectProvider',
			'OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter',
			'OCA\OpenRegister\Service\LinkServiceRuntime',
		],
		'OCA\OpenRegister\Service\Integration\Providers\CalendarProvider' => [
			'OCA\OpenRegister\Service\CalendarEventService',
			'OCA\OpenRegister\Service\CalendarLinkService',
			'OCP\App\IAppManager',
			'OCP\IL10N',
			'Psr\Log\LoggerInterface',
		],
		'OCA\OpenRegister\Service\Integration\Providers\ContactsProvider' => [
			'OCA\OpenRegister\Service\ContactService',
			'OCP\App\IAppManager',
			'OCP\IL10N',
		],
		'OCA\OpenRegister\Service\Integration\Providers\DeckProvider' => [
			'OCA\OpenRegister\Service\DeckLinkService',
			'OCP\App\IAppManager',
			'OCP\IL10N',
		],
		'OCA\OpenRegister\Service\Integration\Providers\EmailProvider' => [
			'OCA\OpenRegister\Notifications\Service\EmailServiceInterface',
			'OCA\OpenRegister\Service\EmailLinkService',
			'OCP\App\IAppManager',
			'OCP\IL10N',
		],
		'OCA\OpenRegister\Service\Integration\Providers\ActivityProvider' => [
			'OCP\IDBConnection',
			'OCP\App\IAppManager',
			'OCP\IL10N',
			'Psr\Log\LoggerInterface',
		],
		'OCA\OpenRegister\Service\Integration\Providers\FormsProvider' => [
			'OCP\IDBConnection',
			'OCP\App\IAppManager',
			'OCP\IL10N',
			'OCA\OpenRegister\Db\FormLinkMapperInterface',
			'Psr\Log\LoggerInterface',
		],
		'OCA\OpenRegister\Service\Integration\Providers\SharesProvider' => [
			'OCP\IDBConnection',
			'OCP\App\IAppManager',
			'OCP\IL10N',
		],
		'OCA\OpenRegister\Service\ShareLinkService' => [
			'Psr\Log\LoggerInterface',
		],
		'OCA\OpenRegister\Service\CaseTokenService' => [
			'OCA\OpenRegister\Db\CaseTokenMapperInterface',
			'OCP\Security\ISecureRandom',
			'OCP\IUserSession',
			'OCP\IURLGenerator',
			'Psr\Log\LoggerInterface',
		],
		'OCA\OpenRegister\Service\AnalyticsSeriesService' => [
			'OCA\OpenRegister\Db\AnalyticsSeriesMapperInterface',
			'OCA\OpenRegister\Service\Integration\IntegrationRegistry',
			'OCP\IUserSession',
			'OCP\IGroupManager',
		],
		'OCA\OpenRegister\Service\Integration\Providers\BookmarksProvider' => [
			'OCA\OpenRegister\Db\BookmarkLinkMapperInterface',
			'OCP\App\IAppManager',
			'OCP\IL10N',
		],
		'OCA\OpenRegister\Service\Integration\Providers\TalkProvider' => [
			'OCP\App\IAppManager',
			'OCP\IUserSession',
			'OCP\IL10N',
			'OCA\OpenRegister\Db\TalkLinkMapperInterface',
		],
		'OCA\OpenRegister\Service\TalkLinkService' => [
			'OCA\OpenRegister\Db\TalkLinkMapperInterface',
			'OCP\IL10N',
			'OCA\OpenRegister\Db\SchemaMapperInterface',
			'OCA\OpenRegister\Service\LinkServiceRuntime',
		],
		'OCA\OpenRegister\Service\Integration\Providers\PollsProvider' => [
			'OCP\IDBConnection',
			'OCP\App\IAppManager',
			'OCP\IL10N',
			'OCA\OpenRegister\Db\PollLinkMapperInterface',
			'OCP\IUserSession',
		],
		'OCA\OpenRegister\Service\Integration\Providers\FlowProvider' => [
			'OCA\OpenRegister\Db\FlowLinkMapperInterface',
			'OCP\IDBConnection',
			'OCP\App\IAppManager',
			'OCP\IL10N',
		],
		'OCA\OpenRegister\Service\ActivityFilterService' => [
			'OCP\IDBConnection',
			'OCP\App\IAppManager',
			'Psr\Log\LoggerInterface',
		],
		'OCA\OpenRegister\Service\FlowLinkService' => [
			'OCA\OpenRegister\Db\FlowLinkMapperInterface',
			'OCP\IDBConnection',
			'OCP\App\IAppManager',
			'OCP\IUserSession',
			'OCP\IGroupManager',
			'Psr\Log\LoggerInterface',
		],
		'OCA\OpenRegister\Service\Integration\Providers\MapsProvider' => [
			'OCP\IDBConnection',
			'OCP\App\IAppManager',
			'OCP\IL10N',
			'OCA\OpenRegister\Db\MapLinkMapperInterface',
			'Psr\Log\LoggerInterface',
		],
		'OCA\OpenRegister\Service\MapLinkService' => [
			'OCA\OpenRegister\Db\MapLinkMapperInterface',
			'OCP\IURLGenerator',
			'OCA\OpenRegister\Service\LinkServiceRuntime',
		],
		'OCA\OpenRegister\Service\Integration\Providers\PhotosProvider' => [
			'OCP\IDBConnection',
			'OCP\App\IAppManager',
			'OCP\IL10N',
			'OCA\OpenRegister\Db\PhotoLinkMapperInterface',
			'Psr\Log\LoggerInterface',
		],
		'OCA\OpenRegister\Service\PhotoLinkService' => [
			'OCA\OpenRegister\Db\PhotoLinkMapperInterface',
			'OCP\IURLGenerator',
			'OCA\OpenRegister\Service\LinkServiceRuntime',
		],
		'OCA\OpenRegister\Service\Integration\Providers\CollectivesProvider' => [
			'OCP\IDBConnection',
			'OCP\App\IAppManager',
			'OCP\IL10N',
			'OCA\OpenRegister\Db\CollectiveLinkMapperInterface',
			'Psr\Log\LoggerInterface',
		],
		'OCA\OpenRegister\Service\Integration\Providers\AnalyticsProvider' => [
			'OCP\IDBConnection',
			'OCP\App\IAppManager',
			'OCP\IL10N',
			'OCA\OpenRegister\Db\AnalyticsLinkMapperInterface',
			'Psr\Log\LoggerInterface',
		],
		'OCA\OpenRegister\Service\Integration\Providers\CospendProvider' => [
			'OCP\IDBConnection',
			'OCP\App\IAppManager',
			'OCP\IL10N',
			'OCA\OpenRegister\Db\CospendLinkMapperInterface',
		],
		'OCA\OpenRegister\Service\CospendLinkService' => [
			'OCA\OpenRegister\Db\CospendLinkMapperInterface',
			'OCA\OpenRegister\Service\LinkServiceRuntime',
			'OCP\IDBConnection',
		],
		'OCA\OpenRegister\Service\CollectiveLinkService' => [
			'OCA\OpenRegister\Db\CollectiveLinkMapperInterface',
			'OCA\OpenRegister\Service\LinkServiceRuntime',
			'OCP\IURLGenerator',
		],
		'OCA\OpenRegister\Service\AnalyticsLinkService' => [
			'OCA\OpenRegister\Db\AnalyticsLinkMapperInterface',
			'OCA\OpenRegister\Service\LinkServiceRuntime',
		],
		'OCA\OpenRegister\Service\Integration\Providers\TimeProvider' => [
			'OCP\IDBConnection',
			'OCP\App\IAppManager',
			'OCP\IL10N',
			'OCA\OpenRegister\Db\TimeTrackerLinkMapperInterface',
			'OCP\IConfig',
			'Psr\Log\LoggerInterface',
		],
		'OCA\OpenRegister\Service\TimeTrackerLinkService' => [
			'OCA\OpenRegister\Db\TimeTrackerLinkMapperInterface',
			'OCP\IDBConnection',
			'OCA\OpenRegister\Service\LinkServiceRuntime',
		],
		'OCA\OpenRegister\Service\Schema\SchemaDiffService' => [
		],
		'OCA\OpenRegister\Service\Schema\SchemaMigrationPlanner' => [
		],
		'OCA\OpenRegister\Service\Schema\SchemaVersioningService' => [
			'OCA\OpenRegister\Service\Schema\SchemaDiffService',
			'OCA\OpenRegister\Db\SchemaChangelogMapperInterface',
			'OCA\OpenRegister\Db\SchemaRunMapperInterface',
			'OCA\OpenRegister\Db\SchemaRunEntryMapperInterface',
			'OCP\IUserSession',
			'Psr\Log\LoggerInterface',
		],
		'OCA\OpenRegister\Service\Schema\SchemaRevalidationService' => [
			'OCA\OpenRegister\Db\SchemaRunMapperInterface',
			'OCA\OpenRegister\Db\SchemaRunEntryMapperInterface',
			'OCA\OpenRegister\Db\SchemaMapperInterface',
			'OCA\OpenRegister\Service\ObjectService',
			'OCA\OpenRegister\Service\Object\ValidateObject',
			'Psr\Log\LoggerInterface',
		],
		'OCA\OpenRegister\Service\Schema\SchemaMigrationService' => [
			'OCA\OpenRegister\Service\Schema\SchemaMigrationPlanner',
			'OCA\OpenRegister\Db\SchemaRunMapperInterface',
			'OCA\OpenRegister\Db\SchemaRunEntryMapperInterface',
			'OCA\OpenRegister\Db\SchemaMapperInterface',
			'OCA\OpenRegister\Service\ObjectService',
			'Psr\Log\LoggerInterface',
		],
	];

	/**
	 * Collect the factories the method registers.
	 *
	 * @return array<string, callable>
	 */
	private function registeredFactories(): array {
		$factories = [];
		$context   = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			static function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			}
		);

		(new ReflectionMethod(IntegrationWiring::class, 'registerBuiltinIntegrationProviders'))->invoke(null, $context);

		return $factories;
	}//end registeredFactories()

	/**
	 * Run one factory and record the container entries it reads.
	 *
	 * @param callable $factory The registered factory.
	 *
	 * @return array{0: object, 1: list<string>}
	 */
	private function build(callable $factory): array {
		$reads     = [];
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$reads): object {
				$reads[] = $id;
				return $this->createMock(originalClassName: $id);
			}
		);

		$service = $factory($container);

		return [$service, $reads];
	}//end build()

	/**
	 * Each factory reads the expected container entries and returns its own class.
	 *
	 * @return void
	 */
	public function testEveryFactoryBuildsItsServiceFromTheExpectedEntries(): void {
		$factories = $this->registeredFactories();
		$actual    = [];
		foreach ($factories as $name => $factory) {
			[$service, $reads] = $this->build(factory: $factory);
			$this->assertInstanceOf(expected: $name, actual: $service);
			$actual[$name] = $reads;
		}

		$this->assertSame(expected: self::EXPECTED, actual: $actual);
	}//end testEveryFactoryBuildsItsServiceFromTheExpectedEntries()
}//end class
