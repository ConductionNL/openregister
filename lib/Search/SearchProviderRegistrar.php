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
 */
final class SearchProviderRegistrar {

	/**
	 * Register the unified search providers.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	public static function register(IRegistrationContext $context): void {
		$context->registerSearchProvider(ObjectsProvider::class);

		// The entries beside the objects. Two providers, because a Woo
		// request asks for every mention of a subject and the answer is a
		// list of entries, each naming the case it sits on.
		$context->registerSearchProvider(\OCA\OpenRegister\Search\TimelineEntriesProvider::class);
	}//end register()
}//end class
