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

use OCA\OpenRegister\Contract\ExampleSetInstallerInterface;
use OCA\OpenRegister\Contract\FlowTimerControlInterface;
use OCA\OpenRegister\Contract\McpCatalogueInterface;
use OCA\OpenRegister\Contract\ObjectExporterInterface;
use OCA\OpenRegister\Contract\ObjectImporterInterface;
use OCA\OpenRegister\Contract\ObjectMergerInterface;
use OCA\OpenRegister\Contract\SchemaExplorationInterface;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCA\OpenRegister\Service\Merge\MergeService;
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
		ExampleSetInstallerInterface::class => ExampleSetInstaller::class,
		FlowTimerControlInterface::class    => FlowTimerService::class,
		McpCatalogueInterface::class        => McpDiscoveryService::class,
		ObjectExporterInterface::class      => ExportService::class,
		ObjectImporterInterface::class      => ImportService::class,
		ObjectMergerInterface::class        => MergeService::class,
		SchemaExplorationInterface::class   => SchemaService::class,
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
