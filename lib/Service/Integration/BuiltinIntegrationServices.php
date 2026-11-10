<?php

/**
 * Constructor wiring of the built-in integration providers and their link services.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use Psr\Container\ContainerInterface;

/**
 * Builds the integration providers and link services that the app wires by hand.
 *
 * Each entry maps a service class to its named constructor arguments. An
 * argument is a container id, one of the markers below, or a dependency
 * bundle: a list of the bundle class and its own named arguments. Arguments
 * are resolved in the listed order.
 *
 * @spec openspec/specs/integration-registry/spec.md
 */
final class BuiltinIntegrationServices {

	/**
	 * Argument marker: pass the container itself.
	 */
	public const CONTAINER = '@container';

	/**
	 * Argument marker: pass the app id.
	 */
	public const APP_NAME = '@appName';

	/**
	 * The id of the app these services belong to, handed to a constructor that asks for APP_NAME.
	 *
	 * @var string
	 */
	private const OWN_APP_ID = 'openregister';

	/**
	 * The routing bundle every external-connector provider takes.
	 *
	 * @var array{0: class-string, 1: array<string, string>}
	 */
	private const CONNECTOR_ROUTING = [
		\OCA\OpenRegister\Service\Integration\Providers\ConnectorRouting::class,
		[
			'router'     => \OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter::class,
			'appManager' => \OCP\App\IAppManager::class,
			'l10n'       => \OCP\IL10N::class,
		],
	];

	/**
	 * The description bundle every app-backed provider takes.
	 *
	 * @var array{0: class-string, 1: array<string, string>}
	 */
	private const APP_PROVIDER_DESCRIPTION = [
		\OCA\OpenRegister\Service\Integration\Providers\AppProviderDescription::class,
		[
			'appManager' => \OCP\App\IAppManager::class,
			'l10n'       => \OCP\IL10N::class,
		],
	];

	/**
	 * The marker-scanning bundle every leaf provider that scans markers takes.
	 *
	 * @var array{0: class-string, 1: array<string, string>}
	 */
	private const LEAF_MARKER_SCANNING = [
		\OCA\OpenRegister\Service\Integration\Providers\LeafMarkerScanning::class,
		[
			'db'         => \OCP\IDBConnection::class,
			'appManager' => \OCP\App\IAppManager::class,
			'l10n'       => \OCP\IL10N::class,
			'logger'     => \Psr\Log\LoggerInterface::class,
		],
	];

	/**
	 * The platform bundle every leaf provider over a link table takes.
	 *
	 * @var array{0: class-string, 1: array<string, string>}
	 */
	private const LEAF_PROVIDER_PLATFORM = [
		\OCA\OpenRegister\Service\Integration\Providers\LeafProviderPlatform::class,
		[
			'db'         => \OCP\IDBConnection::class,
			'appManager' => \OCP\App\IAppManager::class,
			'l10n'       => \OCP\IL10N::class,
		],
	];

	/**
	 * The population-run bundle every schema run service takes.
	 *
	 * @var array{0: class-string, 1: array<string, string>}
	 */
	private const SCHEMA_POPULATION_RUNS = [
		\OCA\OpenRegister\Service\Schema\SchemaPopulationRuns::class,
		[
			'runMapper'      => \OCA\OpenRegister\Db\SchemaRunMapperInterface::class,
			'runEntryMapper' => \OCA\OpenRegister\Db\SchemaRunEntryMapperInterface::class,
			'schemaMapper'   => \OCA\OpenRegister\Db\SchemaMapperInterface::class,
			'objectService'  => \OCA\OpenRegister\Service\ObjectService::class,
		],
	];

	/**
	 * Named constructor arguments per service class.
	 *
	 * @var array<class-string, array<string, string|array{0: class-string, 1: array<string, string>}>>
	 */
	public const FACTORIES = [
		\OCA\OpenRegister\Service\Integration\BuiltinProviders\FilesProvider::class => [
			'fileService' => \OCA\OpenRegister\Service\FileService::class,
			'container'   => self::CONTAINER,
			'l10n'        => \OCP\IL10N::class,
		],
		\OCA\OpenRegister\Service\Integration\BuiltinProviders\NotesProvider::class => [
			'noteService' => \OCA\OpenRegister\Kernel\Contracts\NoteServiceInterface::class,
			'l10n'        => \OCP\IL10N::class,
		],
		\OCA\OpenRegister\Service\Integration\BuiltinProviders\TasksProvider::class => [
			'services' => [
				\OCA\OpenRegister\Service\Integration\BuiltinProviders\TasksProviderServices::class,
				[
					'taskService'    => \OCA\OpenRegister\Service\TaskService::class,
					'registerMapper' => \OCA\OpenRegister\Db\RegisterMapperInterface::class,
					'schemaMapper'   => \OCA\OpenRegister\Db\SchemaMapperInterface::class,
					'objectService'  => \OCA\OpenRegister\Service\ObjectService::class,
				],
			],
			'l10n'     => \OCP\IL10N::class,
		],
		\OCA\OpenRegister\Service\Integration\BuiltinProviders\TagsProvider::class => [
			'tagManager'   => \OCP\SystemTag\ISystemTagManager::class,
			'objectMapper' => \OCP\SystemTag\ISystemTagObjectMapper::class,
			'l10n'         => \OCP\IL10N::class,
		],
		\OCA\OpenRegister\Service\Integration\BuiltinProviders\AuditTrailProvider::class => [
			'history' => \OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders::class,
			'l10n'    => \OCP\IL10N::class,
		],
		// @spec openspec/specs/integration-xwiki/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\XwikiProvider::class => [
			'routing' => self::CONNECTOR_ROUTING,
			'xwikiLinkMapper' => \OCA\OpenRegister\Db\XwikiLinkMapperInterface::class,
		],
		// @spec openspec/specs/integration-xwiki/spec.md
		\OCA\OpenRegister\Service\XwikiLinkService::class => [
			'xwikiLinkMapper' => \OCA\OpenRegister\Db\XwikiLinkMapperInterface::class,
			'container'       => self::CONTAINER,
			'runtime'         => \OCA\OpenRegister\Service\LinkServiceRuntime::class,
		],
		// @spec openspec/specs/integration-registry/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\KvkProvider::class => [
			'routing' => self::CONNECTOR_ROUTING,
			'logger'  => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-registry/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\OpenCorporatesProvider::class => [
			'routing' => self::CONNECTOR_ROUTING,
			'logger'  => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-registry/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\MessageDispatchProvider::class => [
			'routing' => self::CONNECTOR_ROUTING,
			'logger'  => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-openproject/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\OpenProjectProvider::class => [
			'routing'    => self::CONNECTOR_ROUTING,
			'linkMapper' => \OCA\OpenRegister\Db\OpenProjectLinkMapperInterface::class,
		],
		// @spec openspec/specs/integration-openproject/spec.md
		\OCA\OpenRegister\Service\OpenProjectLinkService::class => [
			'openProjectLinkMapper' => \OCA\OpenRegister\Db\OpenProjectLinkMapperInterface::class,
			'provider'              => \OCA\OpenRegister\Service\Integration\Providers\OpenProjectProvider::class,
			'router'                => \OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter::class,
			'runtime'               => \OCA\OpenRegister\Service\LinkServiceRuntime::class,
		],
		// @spec openspec/specs/integration-calendar/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\CalendarProvider::class => [
			'calendar'   => [
				\OCA\OpenRegister\Service\Integration\Providers\CalendarProviderServices::class,
				[
					'calendarEventService' => \OCA\OpenRegister\Service\CalendarEventService::class,
					'calendarLinkService'  => \OCA\OpenRegister\Service\CalendarLinkService::class,
				],
			],
			'appManager' => \OCP\App\IAppManager::class,
			'l10n'       => \OCP\IL10N::class,
			'logger'     => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-contacts/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\ContactsProvider::class => [
			'description'    => self::APP_PROVIDER_DESCRIPTION,
			'contactService' => \OCA\OpenRegister\Service\ContactService::class,
		],
		// @spec openspec/specs/integration-deck/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\DeckProvider::class => [
			'description'     => self::APP_PROVIDER_DESCRIPTION,
			'deckLinkService' => \OCA\OpenRegister\Service\DeckLinkService::class,
		],
		// @spec openspec/specs/integration-email/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\EmailProvider::class => [
			'emailService'     => \OCA\OpenRegister\Notifications\Service\EmailServiceInterface::class,
			'emailLinkService' => \OCA\OpenRegister\Service\EmailLinkService::class,
			'appManager'       => \OCP\App\IAppManager::class,
			'l10n'             => \OCP\IL10N::class,
		],
		// @spec openspec/specs/integration-activity/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\ActivityProvider::class => [
			'leaf' => self::LEAF_MARKER_SCANNING,
		],
		// @spec openspec/specs/integration-forms/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\FormsProvider::class => [
			'leaf'           => self::LEAF_MARKER_SCANNING,
			'formLinkMapper' => \OCA\OpenRegister\Db\FormLinkMapperInterface::class,
		],
		// @spec openspec/specs/integration-shares/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\SharesProvider::class => [
			'l10n' => \OCP\IL10N::class,
		],
		// @spec openspec/specs/integration-shares/spec.md
		\OCA\OpenRegister\Service\ShareLinkService::class => [
			'logger' => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-leaf-foundation/spec.md
		\OCA\OpenRegister\Service\CaseTokenService::class => [
			'issuance'    => [
				\OCA\OpenRegister\Service\CaseTokenIssuance::class,
				[
					'mapper'       => \OCA\OpenRegister\Db\CaseTokenMapperInterface::class,
					'secureRandom' => \OCP\Security\ISecureRandom::class,
					'urlGenerator' => \OCP\IURLGenerator::class,
				],
			],
			'userSession' => \OCP\IUserSession::class,
			'logger'      => \Psr\Log\LoggerInterface::class,
			'container'   => self::CONTAINER,
		],
		// @spec openspec/specs/integration-leaf-foundation/spec.md
		\OCA\OpenRegister\Service\AnalyticsSeriesService::class => [
			'mapper'       => \OCA\OpenRegister\Db\AnalyticsSeriesMapperInterface::class,
			'registry'     => \OCA\OpenRegister\Service\Integration\IntegrationRegistry::class,
			'userSession'  => \OCP\IUserSession::class,
			'groupManager' => \OCP\IGroupManager::class,
		],
		// @spec openspec/specs/integration-bookmarks/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\BookmarksProvider::class => [
			'description'        => self::APP_PROVIDER_DESCRIPTION,
			'bookmarkLinkMapper' => \OCA\OpenRegister\Db\BookmarkLinkMapperInterface::class,
		],
		// @spec openspec/specs/integration-talk/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\TalkProvider::class => [
			'container'      => self::CONTAINER,
			'description'    => self::APP_PROVIDER_DESCRIPTION,
			'userSession'    => \OCP\IUserSession::class,
			'talkLinkMapper' => \OCA\OpenRegister\Db\TalkLinkMapperInterface::class,
		],
		// @spec openspec/specs/integration-talk/spec.md
		\OCA\OpenRegister\Service\TalkLinkService::class => [
			'talkLinkMapper' => \OCA\OpenRegister\Db\TalkLinkMapperInterface::class,
			'container'      => self::CONTAINER,
			'l10n'           => \OCP\IL10N::class,
			'schemaMapper'   => \OCA\OpenRegister\Db\SchemaMapperInterface::class,
			'runtime'        => \OCA\OpenRegister\Service\LinkServiceRuntime::class,
		],
		// @spec openspec/specs/integration-polls/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\PollsProvider::class => [
			'platform'       => self::LEAF_PROVIDER_PLATFORM,
			'pollLinkMapper' => \OCA\OpenRegister\Db\PollLinkMapperInterface::class,
		],
		// @spec openspec/specs/integration-flow/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\FlowProvider::class => [
			'platform'       => self::LEAF_PROVIDER_PLATFORM,
			'flowLinkMapper' => \OCA\OpenRegister\Db\FlowLinkMapperInterface::class,
		],
		// @spec openspec/specs/integration-activity/spec.md
		\OCA\OpenRegister\Service\ActivityFilterService::class => [
			'db'      => \OCP\IDBConnection::class,
			'runtime' => \OCA\OpenRegister\Service\LinkServiceRuntime::class,
		],
		// @spec openspec/specs/integration-flow/spec.md
		\OCA\OpenRegister\Service\FlowLinkService::class => [
			'platform'     => [
				\OCA\OpenRegister\Service\FlowLinkPlatform::class,
				[
					'flowLinkMapper' => \OCA\OpenRegister\Db\FlowLinkMapperInterface::class,
					'db'             => \OCP\IDBConnection::class,
					'appManager'     => \OCP\App\IAppManager::class,
				],
			],
			'userSession'  => \OCP\IUserSession::class,
			'groupManager' => \OCP\IGroupManager::class,
			'logger'       => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-maps/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\MapsProvider::class => [
			'leaf'          => self::LEAF_MARKER_SCANNING,
			'mapLinkMapper' => \OCA\OpenRegister\Db\MapLinkMapperInterface::class,
		],
		// @spec openspec/specs/integration-maps/spec.md
		\OCA\OpenRegister\Service\MapLinkService::class => [
			'mapLinkMapper' => \OCA\OpenRegister\Db\MapLinkMapperInterface::class,
			'container'     => self::CONTAINER,
			'urlGenerator'  => \OCP\IURLGenerator::class,
			'runtime'       => \OCA\OpenRegister\Service\LinkServiceRuntime::class,
		],
		// @spec openspec/specs/integration-photos/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\PhotosProvider::class => [
			'leaf'            => self::LEAF_MARKER_SCANNING,
			'photoLinkMapper' => \OCA\OpenRegister\Db\PhotoLinkMapperInterface::class,
		],
		// @spec openspec/specs/integration-photos/spec.md
		\OCA\OpenRegister\Service\PhotoLinkService::class => [
			'photoLinkMapper' => \OCA\OpenRegister\Db\PhotoLinkMapperInterface::class,
			'container'       => self::CONTAINER,
			'urlGenerator'    => \OCP\IURLGenerator::class,
			'runtime'         => \OCA\OpenRegister\Service\LinkServiceRuntime::class,
		],
		// @spec openspec/specs/integration-collectives/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\CollectivesProvider::class => [
			'leaf'                 => self::LEAF_MARKER_SCANNING,
			'collectiveLinkMapper' => \OCA\OpenRegister\Db\CollectiveLinkMapperInterface::class,
		],
		// @spec openspec/specs/integration-analytics/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\AnalyticsProvider::class => [
			'leaf'                => self::LEAF_MARKER_SCANNING,
			'analyticsLinkMapper' => \OCA\OpenRegister\Db\AnalyticsLinkMapperInterface::class,
		],
		// @spec openspec/specs/integration-cospend/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\CospendProvider::class => [
			'platform'          => self::LEAF_PROVIDER_PLATFORM,
			'cospendLinkMapper' => \OCA\OpenRegister\Db\CospendLinkMapperInterface::class,
		],
		// @spec openspec/specs/integration-cospend/spec.md
		\OCA\OpenRegister\Service\CospendLinkService::class => [
			'cospendLinkMapper' => \OCA\OpenRegister\Db\CospendLinkMapperInterface::class,
			'container'         => self::CONTAINER,
			'runtime'           => \OCA\OpenRegister\Service\LinkServiceRuntime::class,
			'db'                => \OCP\IDBConnection::class,
		],
		// @spec openspec/specs/integration-collectives/spec.md
		\OCA\OpenRegister\Service\CollectiveLinkService::class => [
			'collectiveLinkMapper' => \OCA\OpenRegister\Db\CollectiveLinkMapperInterface::class,
			'container'            => self::CONTAINER,
			'runtime'              => \OCA\OpenRegister\Service\LinkServiceRuntime::class,
			'urlGenerator'         => \OCP\IURLGenerator::class,
		],
		// @spec openspec/specs/integration-analytics/spec.md
		\OCA\OpenRegister\Service\AnalyticsLinkService::class => [
			'analyticsLinkMapper' => \OCA\OpenRegister\Db\AnalyticsLinkMapperInterface::class,
			'runtime'             => \OCA\OpenRegister\Service\LinkServiceRuntime::class,
			'container'           => self::CONTAINER,
		],
		// @spec openspec/specs/integration-time-tracker/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\TimeProvider::class => [
			'leaf'       => self::LEAF_MARKER_SCANNING,
			'linkMapper' => \OCA\OpenRegister\Db\TimeTrackerLinkMapperInterface::class,
			'config'     => \OCP\IConfig::class,
		],
		// @spec openspec/specs/integration-time-tracker/spec.md
		\OCA\OpenRegister\Service\TimeTrackerLinkService::class => [
			'timeTrackerLinkMapper' => \OCA\OpenRegister\Db\TimeTrackerLinkMapperInterface::class,
			'db'                    => \OCP\IDBConnection::class,
			'container'             => self::CONTAINER,
			'runtime'               => \OCA\OpenRegister\Service\LinkServiceRuntime::class,
		],
		\OCA\OpenRegister\Service\Schema\SchemaDiffService::class => [],
		\OCA\OpenRegister\Service\Schema\SchemaMigrationPlanner::class => [],
		\OCA\OpenRegister\Service\Schema\SchemaVersioningService::class => [
			'changelogMapper' => \OCA\OpenRegister\Db\SchemaChangelogMapperInterface::class,
			'runs'            => self::SCHEMA_POPULATION_RUNS,
			'userSession'     => \OCP\IUserSession::class,
			'logger'          => \Psr\Log\LoggerInterface::class,
		],
		\OCA\OpenRegister\Service\Schema\SchemaRevalidationService::class => [
			'runs'           => self::SCHEMA_POPULATION_RUNS,
			'validateObject' => \OCA\OpenRegister\Service\Object\ValidateObject::class,
		],
		\OCA\OpenRegister\Service\Schema\SchemaMigrationService::class => [
			'planner' => \OCA\OpenRegister\Service\Schema\SchemaMigrationPlanner::class,
			'runs'    => self::SCHEMA_POPULATION_RUNS,
		],
	];

	/**
	 * Build one service from its table entry.
	 *
	 * @param ContainerInterface $container The app container.
	 * @param string             $class     A key of FACTORIES.
	 *
	 * @return object The constructed service.
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public static function build(ContainerInterface $container, string $class): object {
		return new $class(...self::arguments(container: $container, sources: self::FACTORIES[$class]));
	}//end build()

	/**
	 * Resolve a list of named constructor arguments, in the listed order.
	 *
	 * @param ContainerInterface    $container The app container.
	 * @param array<string, string|array{0: class-string, 1: array<string, string>}> $sources Argument name to source.
	 *
	 * @return array<string, mixed> Argument name to value.
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	private static function arguments(ContainerInterface $container, array $sources): array {
		$arguments = [];
		foreach ($sources as $name => $source) {
			$arguments[$name] = self::resolve(container: $container, source: $source);
		}

		return $arguments;
	}//end arguments()

	/**
	 * Resolve one constructor argument.
	 *
	 * A list source is a dependency bundle: its class, then its own named
	 * arguments, resolved the same way.
	 *
	 * @param ContainerInterface $container The app container.
	 * @param string|array{0: class-string, 1: array<string, string>} $source A container id, an argument marker or a bundle.
	 *
	 * @return mixed The argument value.
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	private static function resolve(ContainerInterface $container, string|array $source): mixed {
		if (is_array($source) === true) {
			return new $source[0](...self::arguments(container: $container, sources: $source[1]));
		}

		return match ($source) {
			self::CONTAINER => $container,
			self::APP_NAME  => self::OWN_APP_ID,
			default         => $container->get($source),
		};
	}//end resolve()
}//end class
