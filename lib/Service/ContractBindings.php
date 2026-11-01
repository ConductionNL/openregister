<?php

/**
 * Binds the contracts in lib/Contract to the services that implement them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\AppHost\AppContainerLocator;
use OCA\OpenRegister\Kernel\Contracts\AppContainerFinderInterface;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCA\OpenRegister\Kernel\Contracts\ContactMatcherInterface;
use OCA\OpenRegister\Kernel\Contracts\EmbeddingGeneratorInterface;
use OCA\OpenRegister\Kernel\Contracts\ExampleSetInstallerInterface;
use OCA\OpenRegister\Kernel\Contracts\FlowTimerControlInterface;
use OCA\OpenRegister\Kernel\Contracts\GitHubClientInterface;
use OCA\OpenRegister\Kernel\Contracts\McpCatalogueInterface;
use OCA\OpenRegister\Kernel\Contracts\NotificationPreferencesInterface;
use OCA\OpenRegister\Kernel\Contracts\NotificationTemplatesInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarEventsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarTasksInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectExporterInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectMergerInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectNameCacheInterface;
use OCA\OpenRegister\Kernel\Contracts\ScheduledReportsInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaExplorationInterface;
use OCA\OpenRegister\Kernel\Contracts\TaskManagerInterface;
use OCA\OpenRegister\Kernel\Contracts\VocabularyImporterInterface;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCA\OpenRegister\Service\Merge\MergeService;
use OCA\OpenRegister\Service\Notification\NotificationPreferenceService;
use OCA\OpenRegister\Service\Notification\NotificationTemplateRegistry;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Task\TaskService;
use OCA\OpenRegister\Service\Vectorization\VectorEmbeddings;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers each contract as an alias of the service that implements it.
 *
 * An alias resolves to the service's own shared instance, so a class that
 * injects the contract and one that injects the service by its class name
 * get the same object. Other apps keep resolving the services by class name.
 */
final class ContractBindings {

	/**
	 * Contract => the service that implements it.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		AppContainerFinderInterface::class => AppContainerLocator::class,
		AppSettingsInterface::class => SettingsService::class,
		ContactMatcherInterface::class => ContactMatchingService::class,
		EmbeddingGeneratorInterface::class => VectorEmbeddings::class,
		ExampleSetInstallerInterface::class => ExampleSetInstaller::class,
		FlowTimerControlInterface::class => FlowTimerService::class,
		GitHubClientInterface::class => GitHubHandler::class,
		McpCatalogueInterface::class => McpDiscoveryService::class,
		NotificationPreferencesInterface::class => NotificationPreferenceService::class,
		NotificationTemplatesInterface::class => NotificationTemplateRegistry::class,
		ObjectCalendarEventsInterface::class => CalendarEventService::class,
		ObjectCalendarTasksInterface::class => \OCA\OpenRegister\Service\TaskService::class,
		ObjectExporterInterface::class => ExportService::class,
		ObjectImporterInterface::class => ImportService::class,
		ObjectMergerInterface::class => MergeService::class,
		ObjectNameCacheInterface::class => CacheHandler::class,
		ScheduledReportsInterface::class => ScheduledReportService::class,
		SchemaExplorationInterface::class => SchemaService::class,
		TaskManagerInterface::class => TaskService::class,
		VocabularyImporterInterface::class => VocabularyImportService::class,
	];

	/**
	 * Register every binding with the app container.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 */
	public static function register(IRegistrationContext $context): void {
		foreach (self::BINDINGS as $contract => $service) {
			$context->registerServiceAlias($contract, $service);
		}
	}//end register()
}//end class
