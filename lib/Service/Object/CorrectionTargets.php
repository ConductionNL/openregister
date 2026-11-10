<?php

/**
 * OpenRegister correction target factory
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

/**
 * Builds the CorrectionTarget the corrections endpoint names a record with.
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
final class CorrectionTargets implements CorrectionTargetsInterface {

	/**
	 * The record a correction is made on.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object's id or uuid.
	 *
	 * @return CorrectionTarget
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function forRecord(string $register, string $schema, string $id): CorrectionTarget {
		return new CorrectionTarget(register: $register, schema: $schema, id: $id);
	}//end forRecord()
}//end class
