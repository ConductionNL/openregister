<?php

/**
 * OpenRegister draft expiry pair
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use DateTimeImmutable;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * One register and schema the draft sweep works on, with the cutoff its drafts are measured against.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class DraftExpiryPair {
	/**
	 * Name the pair and its cutoff.
	 *
	 * @param Register          $register The register.
	 * @param Schema            $schema   The schema.
	 * @param DateTimeImmutable $cutoff   Drafts last changed before this moment are expired.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly Register $register,
		public readonly Schema $schema,
		public readonly DateTimeImmutable $cutoff,
	) {
	}//end __construct()
}//end class
