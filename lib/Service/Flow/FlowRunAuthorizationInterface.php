<?php

/**
 * FlowRunAuthorizationInterface: what callers outside the component use of FlowRunAuthorization.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The authorization verdict the flow endpoints read from a refused run.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface FlowRunAuthorizationInterface {

	/**
	 * Refused: there is nobody to attribute the run to.
	 *
	 * @var string
	 */
	public const NO_SESSION = 'no-session';
}//end interface
