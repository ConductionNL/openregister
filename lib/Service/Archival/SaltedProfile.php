<?php

/**
 * A declared anonymisation profile together with the salt it pseudonymises under.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * The rules of one profile and the instance salt a pseudonym is derived with.
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
 */
final class SaltedProfile {
	/**
	 * Hold the profile and its salt.
	 *
	 * @param array<string, array<string, mixed>> $rules Property name to its rule.
	 * @param string                              $salt  Instance salt for the pseudonym.
	 */
	public function __construct(
		public readonly array $rules,
		public readonly string $salt,
	) {
	}//end __construct()
}//end class
