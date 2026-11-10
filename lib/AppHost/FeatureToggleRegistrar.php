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

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The body lives in FeatureToggleWiring, in this component;
 * this class keeps the entry points its callers use.
 *
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
		FeatureToggleWiring::register(context: $context);
	}//end register()
}//end class
