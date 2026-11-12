<?php

/**
 * The services a leaf integration provider scans its app's tables for legacy markers with.
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
use Psr\Log\LoggerInterface;

/**
 * The marker-scanning bundle of a leaf integration provider: the database it
 * scans the leaf app's tables in, the app manager that says whether the leaf
 * app is installed, the localisation of its labels, and the logger of the
 * degraded marker-scan path (MarkerLookupTrait).
 *
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */
final class LeafMarkerScanning {
	/**
	 * Bundle the services.
	 *
	 * @param IDBConnection   $db         NC DB connection.
	 * @param IAppManager     $appManager NC app manager.
	 * @param IL10N           $l10n       Localisation.
	 * @param LoggerInterface $logger     Logger for the degraded marker-scan path.
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function __construct(
		public readonly IDBConnection $db,
		public readonly IAppManager $appManager,
		public readonly IL10N $l10n,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
