<?php

/**
 * CasePlanAuthorizationServiceInterface: what callers outside the component use of CasePlanAuthorizationService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
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

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Exception\CaseAccessDeniedException;

/**
 * The caller identification check the case endpoints run first.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface CasePlanAuthorizationServiceInterface {

	/**
	 * Assert an acting identity exists at all. No verb is anonymous.
	 *
	 * @param string|null $uid The acting identity.
	 * @param string $verb The verb, for the message.
	 *
	 * @return string The trimmed identity.
	 *
	 * @throws CaseAccessDeniedException Without one.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	public function assertIdentified(?string $uid, string $verb): string;
}//end interface
