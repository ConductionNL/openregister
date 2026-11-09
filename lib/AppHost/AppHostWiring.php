<?php

/**
 * AppHostRegistrar: container bindings of the AppHost component.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppHost
 * @package  OCA\OpenRegister\AppHost
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost;

use OCA\OpenRegister\AppHost\Scheduling\ScheduleFlowStore;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleFlowStoreInterface;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleManifestLoader;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleManifestLoaderInterface;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleRefusalReportInterface;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleRefusals;
use OCA\OpenRegister\AppHost\Service\RegisterDocumentLoader;
use OCA\OpenRegister\AppHost\Service\RegisterDocumentLoaderInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The wiring behind AppHostRegistrar.
 *
 * AppHostRegistrar keeps the public entry points and delegates each of them here.
 *
 * Binds the interfaces other components type against to the AppHost classes.
 *
 * FeatureToggleRegistrar and ObservabilityRegistrar keep their own wiring;
 * this one only holds the interface bindings.
 */
final class AppHostWiring {

	/**
	 * The interfaces callers outside the component type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		ScheduleFlowStoreInterface::class => ScheduleFlowStore::class,
		ScheduleManifestLoaderInterface::class => ScheduleManifestLoader::class,
		ScheduleRefusalReportInterface::class => ScheduleRefusals::class,
		RegisterDocumentLoaderInterface::class => RegisterDocumentLoader::class,
	];

	/**
	 * Register the AppHost component's bindings.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; no behaviour of its own.
	 */
	public static function register(IRegistrationContext $context): void {
		foreach (self::BINDINGS as $contract => $service) {
			$context->registerServiceAlias($contract, $service);
		}
	}//end register()
}//end class
