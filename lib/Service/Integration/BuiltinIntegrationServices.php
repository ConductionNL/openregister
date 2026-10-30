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
 * argument is a container id, or one of the markers below. Arguments are
 * resolved in the listed order.
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
	 * Named constructor arguments per service class.
	 *
	 * @var array<class-string, array<string, string>>
	 */
	public const FACTORIES = [
		\OCA\OpenRegister\Service\Integration\BuiltinProviders\FilesProvider::class => [
			'fileService' => \OCA\OpenRegister\Service\FileService::class,
			'container'   => self::CONTAINER,
			'l10n'        => \OCP\IL10N::class,
		],
		\OCA\OpenRegister\Service\Integration\BuiltinProviders\NotesProvider::class => [
			'noteService' => \OCA\OpenRegister\Service\NoteService::class,
			'l10n'        => \OCP\IL10N::class,
		],
		\OCA\OpenRegister\Service\Integration\BuiltinProviders\TasksProvider::class => [
			'taskService'    => \OCA\OpenRegister\Service\TaskService::class,
			'registerMapper' => \OCA\OpenRegister\Db\RegisterMapper::class,
			'schemaMapper'   => \OCA\OpenRegister\Db\SchemaMapper::class,
			'objectService'  => \OCA\OpenRegister\Service\ObjectService::class,
			'l10n'           => \OCP\IL10N::class,
		],
		\OCA\OpenRegister\Service\Integration\BuiltinProviders\TagsProvider::class => [
			'tagManager'   => \OCP\SystemTag\ISystemTagManager::class,
			'objectMapper' => \OCP\SystemTag\ISystemTagObjectMapper::class,
			'l10n'         => \OCP\IL10N::class,
		],
		\OCA\OpenRegister\Service\Integration\BuiltinProviders\AuditTrailProvider::class => [
			'mapper' => \OCA\OpenRegister\Db\AuditTrailMapper::class,
			'l10n'   => \OCP\IL10N::class,
		],
		// @spec openspec/specs/integration-xwiki/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\XwikiProvider::class => [
			'router'          => \OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter::class,
			'appManager'      => \OCP\App\IAppManager::class,
			'l10n'            => \OCP\IL10N::class,
			'xwikiLinkMapper' => \OCA\OpenRegister\Db\XwikiLinkMapper::class,
		],
		// @spec openspec/specs/integration-xwiki/spec.md
		\OCA\OpenRegister\Service\XwikiLinkService::class => [
			'xwikiLinkMapper' => \OCA\OpenRegister\Db\XwikiLinkMapper::class,
			'container'       => self::CONTAINER,
			'appManager'      => \OCP\App\IAppManager::class,
			'userSession'     => \OCP\IUserSession::class,
			'logger'          => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-registry/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\KvkProvider::class => [
			'router'     => \OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter::class,
			'appManager' => \OCP\App\IAppManager::class,
			'l10n'       => \OCP\IL10N::class,
			'logger'     => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-registry/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\OpenCorporatesProvider::class => [
			'router'     => \OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter::class,
			'appManager' => \OCP\App\IAppManager::class,
			'l10n'       => \OCP\IL10N::class,
			'logger'     => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-registry/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\MessageDispatchProvider::class => [
			'router'     => \OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter::class,
			'appManager' => \OCP\App\IAppManager::class,
			'l10n'       => \OCP\IL10N::class,
			'logger'     => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-openproject/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\OpenProjectProvider::class => [
			'router'     => \OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter::class,
			'appManager' => \OCP\App\IAppManager::class,
			'l10n'       => \OCP\IL10N::class,
			'linkMapper' => \OCA\OpenRegister\Db\OpenProjectLinkMapper::class,
		],
		// @spec openspec/specs/integration-openproject/spec.md
		\OCA\OpenRegister\Service\OpenProjectLinkService::class => [
			'openProjectLinkMapper' => \OCA\OpenRegister\Db\OpenProjectLinkMapper::class,
			'provider'              => \OCA\OpenRegister\Service\Integration\Providers\OpenProjectProvider::class,
			'router'                => \OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter::class,
			'appManager'            => \OCP\App\IAppManager::class,
			'userSession'           => \OCP\IUserSession::class,
			'logger'                => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-calendar/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\CalendarProvider::class => [
			'calendarEventService' => \OCA\OpenRegister\Service\CalendarEventService::class,
			'calendarLinkService'  => \OCA\OpenRegister\Service\CalendarLinkService::class,
			'appManager'           => \OCP\App\IAppManager::class,
			'l10n'                 => \OCP\IL10N::class,
			'logger'               => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-contacts/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\ContactsProvider::class => [
			'contactService' => \OCA\OpenRegister\Service\ContactService::class,
			'appManager'     => \OCP\App\IAppManager::class,
			'l10n'           => \OCP\IL10N::class,
		],
		// @spec openspec/specs/integration-deck/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\DeckProvider::class => [
			'deckLinkService' => \OCA\OpenRegister\Service\DeckLinkService::class,
			'appManager'      => \OCP\App\IAppManager::class,
			'l10n'            => \OCP\IL10N::class,
		],
		// @spec openspec/specs/integration-email/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\EmailProvider::class => [
			'emailService'     => \OCA\OpenRegister\Service\EmailService::class,
			'emailLinkService' => \OCA\OpenRegister\Service\EmailLinkService::class,
			'appManager'       => \OCP\App\IAppManager::class,
			'l10n'             => \OCP\IL10N::class,
		],
		// @spec openspec/specs/integration-activity/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\ActivityProvider::class => [
			'db'         => \OCP\IDBConnection::class,
			'appManager' => \OCP\App\IAppManager::class,
			'l10n'       => \OCP\IL10N::class,
			'logger'     => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-forms/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\FormsProvider::class => [
			'db'             => \OCP\IDBConnection::class,
			'appManager'     => \OCP\App\IAppManager::class,
			'l10n'           => \OCP\IL10N::class,
			'formLinkMapper' => \OCA\OpenRegister\Db\FormLinkMapper::class,
			'logger'         => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-shares/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\SharesProvider::class => [
			'db'         => \OCP\IDBConnection::class,
			'appManager' => \OCP\App\IAppManager::class,
			'l10n'       => \OCP\IL10N::class,
		],
		// @spec openspec/specs/integration-shares/spec.md
		\OCA\OpenRegister\Service\ShareLinkService::class => [
			'l10n'   => \OCP\IL10N::class,
			'logger' => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-leaf-foundation/spec.md
		\OCA\OpenRegister\Service\CaseTokenService::class => [
			'mapper'       => \OCA\OpenRegister\Db\CaseTokenMapper::class,
			'secureRandom' => \OCP\Security\ISecureRandom::class,
			'userSession'  => \OCP\IUserSession::class,
			'urlGenerator' => \OCP\IURLGenerator::class,
			'logger'       => \Psr\Log\LoggerInterface::class,
			'container'    => self::CONTAINER,
		],
		\OCA\OpenRegister\Controller\CaseTokenController::class => [
			'appName'      => self::APP_NAME,
			'request'      => \OCP\IRequest::class,
			'tokenService' => \OCA\OpenRegister\Service\CaseTokenService::class,
			'throttler'    => \OCP\Security\Bruteforce\IThrottler::class,
			'logger'       => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-leaf-foundation/spec.md
		\OCA\OpenRegister\Service\AnalyticsSeriesService::class => [
			'mapper'       => \OCA\OpenRegister\Db\AnalyticsSeriesMapper::class,
			'registry'     => \OCA\OpenRegister\Service\Integration\IntegrationRegistry::class,
			'userSession'  => \OCP\IUserSession::class,
			'groupManager' => \OCP\IGroupManager::class,
		],
		\OCA\OpenRegister\Controller\AnalyticsSeriesController::class => [
			'appName'       => self::APP_NAME,
			'request'       => \OCP\IRequest::class,
			'seriesService' => \OCA\OpenRegister\Service\AnalyticsSeriesService::class,
		],
		// @spec openspec/specs/integration-bookmarks/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\BookmarksProvider::class => [
			'bookmarkLinkMapper' => \OCA\OpenRegister\Db\BookmarkLinkMapper::class,
			'appManager'         => \OCP\App\IAppManager::class,
			'l10n'               => \OCP\IL10N::class,
		],
		// @spec openspec/specs/integration-talk/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\TalkProvider::class => [
			'container'      => self::CONTAINER,
			'appManager'     => \OCP\App\IAppManager::class,
			'userSession'    => \OCP\IUserSession::class,
			'l10n'           => \OCP\IL10N::class,
			'talkLinkMapper' => \OCA\OpenRegister\Db\TalkLinkMapper::class,
		],
		// @spec openspec/specs/integration-talk/spec.md
		\OCA\OpenRegister\Service\TalkLinkService::class => [
			'talkLinkMapper' => \OCA\OpenRegister\Db\TalkLinkMapper::class,
			'container'      => self::CONTAINER,
			'appManager'     => \OCP\App\IAppManager::class,
			'userSession'    => \OCP\IUserSession::class,
			'l10n'           => \OCP\IL10N::class,
			'logger'         => \Psr\Log\LoggerInterface::class,
			'schemaMapper'   => \OCA\OpenRegister\Db\SchemaMapper::class,
		],
		// @spec openspec/specs/integration-polls/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\PollsProvider::class => [
			'pollLinkMapper' => \OCA\OpenRegister\Db\PollLinkMapper::class,
			'db'             => \OCP\IDBConnection::class,
			'appManager'     => \OCP\App\IAppManager::class,
			'userSession'    => \OCP\IUserSession::class,
			'l10n'           => \OCP\IL10N::class,
		],
		// @spec openspec/specs/integration-flow/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\FlowProvider::class => [
			'flowLinkMapper' => \OCA\OpenRegister\Db\FlowLinkMapper::class,
			'db'             => \OCP\IDBConnection::class,
			'appManager'     => \OCP\App\IAppManager::class,
			'l10n'           => \OCP\IL10N::class,
		],
		// @spec openspec/specs/integration-activity/spec.md
		\OCA\OpenRegister\Service\ActivityFilterService::class => [
			'db'         => \OCP\IDBConnection::class,
			'appManager' => \OCP\App\IAppManager::class,
			'logger'     => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-flow/spec.md
		\OCA\OpenRegister\Service\FlowLinkService::class => [
			'flowLinkMapper' => \OCA\OpenRegister\Db\FlowLinkMapper::class,
			'db'             => \OCP\IDBConnection::class,
			'container'      => self::CONTAINER,
			'appManager'     => \OCP\App\IAppManager::class,
			'userSession'    => \OCP\IUserSession::class,
			'groupManager'   => \OCP\IGroupManager::class,
			'logger'         => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-maps/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\MapsProvider::class => [
			'db'            => \OCP\IDBConnection::class,
			'appManager'    => \OCP\App\IAppManager::class,
			'l10n'          => \OCP\IL10N::class,
			'mapLinkMapper' => \OCA\OpenRegister\Db\MapLinkMapper::class,
			'logger'        => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-maps/spec.md
		\OCA\OpenRegister\Service\MapLinkService::class => [
			'mapLinkMapper' => \OCA\OpenRegister\Db\MapLinkMapper::class,
			'container'     => self::CONTAINER,
			'appManager'    => \OCP\App\IAppManager::class,
			'userSession'   => \OCP\IUserSession::class,
			'urlGenerator'  => \OCP\IURLGenerator::class,
			'logger'        => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-photos/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\PhotosProvider::class => [
			'db'              => \OCP\IDBConnection::class,
			'appManager'      => \OCP\App\IAppManager::class,
			'l10n'            => \OCP\IL10N::class,
			'photoLinkMapper' => \OCA\OpenRegister\Db\PhotoLinkMapper::class,
			'logger'          => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-photos/spec.md
		\OCA\OpenRegister\Service\PhotoLinkService::class => [
			'photoLinkMapper' => \OCA\OpenRegister\Db\PhotoLinkMapper::class,
			'container'       => self::CONTAINER,
			'appManager'      => \OCP\App\IAppManager::class,
			'userSession'     => \OCP\IUserSession::class,
			'urlGenerator'    => \OCP\IURLGenerator::class,
			'logger'          => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-collectives/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\CollectivesProvider::class => [
			'db'                   => \OCP\IDBConnection::class,
			'appManager'           => \OCP\App\IAppManager::class,
			'l10n'                 => \OCP\IL10N::class,
			'collectiveLinkMapper' => \OCA\OpenRegister\Db\CollectiveLinkMapper::class,
			'logger'               => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-analytics/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\AnalyticsProvider::class => [
			'db'                  => \OCP\IDBConnection::class,
			'appManager'          => \OCP\App\IAppManager::class,
			'l10n'                => \OCP\IL10N::class,
			'analyticsLinkMapper' => \OCA\OpenRegister\Db\AnalyticsLinkMapper::class,
			'logger'              => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-cospend/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\CospendProvider::class => [
			'db'                => \OCP\IDBConnection::class,
			'appManager'        => \OCP\App\IAppManager::class,
			'l10n'              => \OCP\IL10N::class,
			'cospendLinkMapper' => \OCA\OpenRegister\Db\CospendLinkMapper::class,
		],
		// @spec openspec/specs/integration-cospend/spec.md
		\OCA\OpenRegister\Service\CospendLinkService::class => [
			'cospendLinkMapper' => \OCA\OpenRegister\Db\CospendLinkMapper::class,
			'container'         => self::CONTAINER,
			'appManager'        => \OCP\App\IAppManager::class,
			'userSession'       => \OCP\IUserSession::class,
			'db'                => \OCP\IDBConnection::class,
			'logger'            => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-collectives/spec.md
		\OCA\OpenRegister\Service\CollectiveLinkService::class => [
			'collectiveLinkMapper' => \OCA\OpenRegister\Db\CollectiveLinkMapper::class,
			'container'            => self::CONTAINER,
			'appManager'           => \OCP\App\IAppManager::class,
			'userSession'          => \OCP\IUserSession::class,
			'urlGenerator'         => \OCP\IURLGenerator::class,
			'logger'               => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-analytics/spec.md
		\OCA\OpenRegister\Service\AnalyticsLinkService::class => [
			'analyticsLinkMapper' => \OCA\OpenRegister\Db\AnalyticsLinkMapper::class,
			'appManager'          => \OCP\App\IAppManager::class,
			'userSession'         => \OCP\IUserSession::class,
			'logger'              => \Psr\Log\LoggerInterface::class,
			'container'           => self::CONTAINER,
		],
		// @spec openspec/specs/integration-time-tracker/spec.md
		\OCA\OpenRegister\Service\Integration\Providers\TimeProvider::class => [
			'db'         => \OCP\IDBConnection::class,
			'appManager' => \OCP\App\IAppManager::class,
			'l10n'       => \OCP\IL10N::class,
			'linkMapper' => \OCA\OpenRegister\Db\TimeTrackerLinkMapper::class,
			'config'     => \OCP\IConfig::class,
			'logger'     => \Psr\Log\LoggerInterface::class,
		],
		// @spec openspec/specs/integration-time-tracker/spec.md
		\OCA\OpenRegister\Service\TimeTrackerLinkService::class => [
			'timeTrackerLinkMapper' => \OCA\OpenRegister\Db\TimeTrackerLinkMapper::class,
			'db'                    => \OCP\IDBConnection::class,
			'container'             => self::CONTAINER,
			'appManager'            => \OCP\App\IAppManager::class,
			'userSession'           => \OCP\IUserSession::class,
			'logger'                => \Psr\Log\LoggerInterface::class,
		],
		\OCA\OpenRegister\Service\Schema\SchemaDiffService::class => [],
		\OCA\OpenRegister\Service\Schema\SchemaMigrationPlanner::class => [],
		\OCA\OpenRegister\Service\Schema\SchemaVersioningService::class => [
			'diffService'     => \OCA\OpenRegister\Service\Schema\SchemaDiffService::class,
			'changelogMapper' => \OCA\OpenRegister\Db\SchemaChangelogMapper::class,
			'runMapper'       => \OCA\OpenRegister\Db\SchemaRunMapper::class,
			'runEntryMapper'  => \OCA\OpenRegister\Db\SchemaRunEntryMapper::class,
			'userSession'     => \OCP\IUserSession::class,
			'logger'          => \Psr\Log\LoggerInterface::class,
		],
		\OCA\OpenRegister\Service\Schema\SchemaRevalidationService::class => [
			'runMapper'      => \OCA\OpenRegister\Db\SchemaRunMapper::class,
			'runEntryMapper' => \OCA\OpenRegister\Db\SchemaRunEntryMapper::class,
			'schemaMapper'   => \OCA\OpenRegister\Db\SchemaMapper::class,
			'objectService'  => \OCA\OpenRegister\Service\ObjectService::class,
			'validateObject' => \OCA\OpenRegister\Service\Object\ValidateObject::class,
			'logger'         => \Psr\Log\LoggerInterface::class,
		],
		\OCA\OpenRegister\Service\Schema\SchemaMigrationService::class => [
			'planner'        => \OCA\OpenRegister\Service\Schema\SchemaMigrationPlanner::class,
			'runMapper'      => \OCA\OpenRegister\Db\SchemaRunMapper::class,
			'runEntryMapper' => \OCA\OpenRegister\Db\SchemaRunEntryMapper::class,
			'schemaMapper'   => \OCA\OpenRegister\Db\SchemaMapper::class,
			'objectService'  => \OCA\OpenRegister\Service\ObjectService::class,
			'logger'         => \Psr\Log\LoggerInterface::class,
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
		$arguments = [];
		foreach (self::FACTORIES[$class] as $name => $source) {
			$arguments[$name] = self::resolve(container: $container, source: $source);
		}

		return new $class(...$arguments);
	}//end build()

	/**
	 * Resolve one constructor argument.
	 *
	 * @param ContainerInterface $container The app container.
	 * @param string             $source    A container id or an argument marker.
	 *
	 * @return mixed The argument value.
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	private static function resolve(ContainerInterface $container, string $source): mixed {
		return match ($source) {
			self::CONTAINER => $container,
			self::APP_NAME  => self::OWN_APP_ID,
			default         => $container->get($source),
		};
	}//end resolve()
}//end class
