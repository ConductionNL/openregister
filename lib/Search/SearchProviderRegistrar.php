<?php

/**
 * The search provider registrations of OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers OpenRegister's providers for Nextcloud's unified search.
 *
 * Application::register() calls register() once.
 *
 * @spec openspec/changes/flow-task-search-provider/tasks.md#task-2.1
 */
final class SearchProviderRegistrar {

	/**
	 * Register the unified search providers.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-search-provider/tasks.md#task-2.1
	 *
	 * @spec openspec/changes/flow-task-search-provider/specs/unified-search-provider/spec.md#requirement-nextcloud-search-finds-the-flow-tasks-a-user-may-see
	 */
	public static function register(IRegistrationContext $context): void {
		$context->registerSearchProvider(ObjectsProvider::class);

		// The timeline entries beside the objects and the flow tasks in the
		// searcher's inbox have their own providers, registered by the
		// Notifications and Processes components that own them.
	}//end register()
}//end class
