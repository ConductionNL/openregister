<?php

/**
 * OpenRegister OasController services
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

use OCA\OpenRegister\Service\Oas\OasETagComputerInterface;
use OCA\OpenRegister\Service\OasServiceInterface;

/**
 * The collaborators of OasController, wired by DI autowiring.
 *
 * @spec openspec/specs/oas-generation/spec.md
 */
final class OasControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param OasServiceInterface       $oasService   OAS service instance
	 * @param ?OasETagComputerInterface $etagComputer Optional ETag computer for If-None-Match short-circuit
	 */
	public function __construct(
		public readonly OasServiceInterface $oasService,
		public readonly ?OasETagComputerInterface $etagComputer = null,
	) {
	}//end __construct()
}//end class
