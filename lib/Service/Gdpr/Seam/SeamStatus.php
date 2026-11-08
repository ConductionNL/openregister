<?php

/**
 * OpenRegister Gdpr SeamStatus
 *
 * The closed-status check shared by the DSAR seam result value objects
 * ({@see \OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyResult},
 * {@see \OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateResult}): a
 * status outside the result's permitted set is refused, so a provider can
 * never hand back an ambiguous state (fail-closed, ADR-005 / CWE-863).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Seam
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Seam;

use InvalidArgumentException;

/**
 * Refuses a seam-result status outside its permitted set.
 *
 * @spec openspec/specs/dsar-identity-verify-seam/spec.md
 */
final class SeamStatus {

	/**
	 * Throw unless $status is one of $allowed.
	 *
	 * @param string             $resultName The result class's short name (for the message).
	 * @param string             $status     The status to check.
	 * @param array<int, string> $allowed    The permitted statuses.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When $status is not permitted.
	 *
	 * @spec openspec/specs/dsar-identity-verify-seam/spec.md
	 */
	public static function assertAllowed(string $resultName, string $status, array $allowed): void {
		if (in_array($status, $allowed, true) === true) {
			return;
		}

		throw new InvalidArgumentException(
			sprintf('%s status "%s" is invalid; expected one of: %s', $resultName, $status, implode(', ', $allowed))
		);

	}//end assertAllowed()
}//end class
