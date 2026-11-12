<?php

/**
 * The services an integration provider describes its Nextcloud app with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/integration-registry/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

use OCP\App\IAppManager;
use OCP\IL10N;

/**
 * The description bundle of an integration provider backed by one Nextcloud
 * app: the app manager that says whether the app is installed, and the
 * localisation of the provider's labels.
 *
 * @spec openspec/specs/integration-registry/spec.md
 */
final class AppProviderDescription {
	/**
	 * Bundle the services.
	 *
	 * @param IAppManager $appManager NC app manager.
	 * @param IL10N       $l10n       Localisation.
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function __construct(
		public readonly IAppManager $appManager,
		public readonly IL10N $l10n,
	) {
	}//end __construct()
}//end class
