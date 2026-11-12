<?php

/**
 * OpenRegister CorrectionsController services
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

use OCA\OpenRegister\Service\Object\CorrectionServiceInterface;
use OCA\OpenRegister\Service\Object\CorrectionTargetsInterface;
use OCP\IUserSession;

/**
 * The collaborators of CorrectionsController, wired by DI autowiring.
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
final class CorrectionsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param CorrectionServiceInterface $corrections The correction act.
	 * @param CorrectionTargetsInterface $targets     Names the record a correction is made on.
	 * @param IUserSession               $userSession The user session.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function __construct(
		public readonly CorrectionServiceInterface $corrections,
		public readonly CorrectionTargetsInterface $targets,
		public readonly IUserSession $userSession,
	) {
	}//end __construct()
}//end class
