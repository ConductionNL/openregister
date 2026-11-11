<?php

/**
 * OpenRegister Seam Status Set
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Seam
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

namespace OCA\OpenRegister\Service\Gdpr\Seam;

use InvalidArgumentException;

/**
 * The statuses one seam result class permits.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Seam
 */
final class SeamStatusSet {

	/**
	 * Constructor.
	 *
	 * @param string             $resultName The result class's short name (for the message).
	 * @param array<int, string> $allowed    The permitted statuses.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $resultName,
		public readonly array $allowed,
	) {

	}//end __construct()

	/**
	 * Throw unless the status is one of the permitted ones.
	 *
	 * @param string $status The status to check.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When $status is not permitted.
	 *
	 * @spec openspec/specs/dsar-identity-verify-seam/spec.md
	 */
	public function assert(string $status): void {
		if (in_array($status, $this->allowed, true) === true) {
			return;
		}

		throw new InvalidArgumentException(
			sprintf('%s status "%s" is invalid; expected one of: %s', $this->resultName, $status, implode(', ', $this->allowed))
		);

	}//end assert()
}//end class
