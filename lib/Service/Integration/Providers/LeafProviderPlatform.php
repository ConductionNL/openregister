<?php

/**
 * The Nextcloud platform services a leaf integration provider reads its app's tables through.
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
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * The platform bundle of a leaf integration provider: the database it scans
 * the leaf app's tables in, the app manager that says whether the leaf app
 * is installed, and the localisation of its labels.
 *
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */
final class LeafProviderPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IDBConnection $db         NC DB connection.
	 * @param IAppManager   $appManager NC app manager.
	 * @param IL10N         $l10n       Localisation.
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function __construct(
		public readonly IDBConnection $db,
		public readonly IAppManager $appManager,
		public readonly IL10N $l10n,
	) {
	}//end __construct()
}//end class
