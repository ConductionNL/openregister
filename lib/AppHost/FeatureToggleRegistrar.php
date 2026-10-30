<?php

/**
 * The feature-toggle registration of OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppHost
 * @package  OCA\OpenRegister\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost;

use OCA\OpenRegister\AppHost\Service\FeatureToggleService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the feature-toggle reader as a shared service.
 *
 * Application::register() calls register() once.
 */
final class FeatureToggleRegistrar {

	/**
	 * Register the shared feature-toggle reader.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	public static function register(IRegistrationContext $context): void {
		// The feature-toggle reader MUST be shared, for the same reason the
		// hierarchy descent below it must: it memoises the merged toggle map
		// FOR THE LIFETIME OF ONE REQUEST, and a container that built it fresh
		// at each injection point would turn a per-request memo into a
		// per-injection one — every `isEnabled()` in a loop paying a config
		// read. Registered explicitly rather than autowired so that a wiring
		// failure is loud here rather than showing up as every toggle reading
		// off (ledger row 11.15).
		$context->registerService(
			FeatureToggleService::class,
			static function ($c) {
				return new FeatureToggleService(
					appConfig: $c->get(\OCP\IAppConfig::class),
					container: $c,
					logger: $c->get(\Psr\Log\LoggerInterface::class),
				);
			}
		);
	}//end register()
}//end class
