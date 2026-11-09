<?php

/**
 * OpenRegister MigrationPacksController services
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

use OCA\OpenRegister\Service\MigrationPackServiceInterface;
use OCA\OpenRegister\Service\MigrationPack\MigrationPackErrorResponderInterface;
use OCA\OpenRegister\Service\MigrationPack\MigrationPackRequestReaderInterface;

/**
 * The collaborators of MigrationPacksController, wired by DI autowiring.
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */
final class MigrationPacksControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param MigrationPackServiceInterface $service Business logic.
	 * @param ControllerSession $session Session, group manager and logger.
	 * @param MigrationPackRequestReaderInterface $requestReader Reads definitions, uploads and preview input.
	 * @param MigrationPackErrorResponderInterface $errors Builds every error response.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md
	 */
	public function __construct(
		public readonly MigrationPackServiceInterface $service,
		public readonly ControllerSession $session,
		public readonly MigrationPackRequestReaderInterface $requestReader,
		public readonly MigrationPackErrorResponderInterface $errors,
	) {
	}//end __construct()
}//end class
