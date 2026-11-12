<?php

/**
 * OpenRegister ManifestController services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\ManifestServiceInterface;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * The collaborators of ManifestController, wired by DI autowiring.
 *
 * @spec openspec/specs/openregister-app-manifest/spec.md#req-or-man-012
 */
final class ManifestControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ManifestServiceInterface $manifestService Service that enriches manifests.
	 * @param IAppManager $appManager Nextcloud app manager (path resolution).
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @spec openspec/specs/openregister-app-manifest/spec.md#req-or-man-012
	 */
	public function __construct(
		public readonly ManifestServiceInterface $manifestService,
		public readonly IAppManager $appManager,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
