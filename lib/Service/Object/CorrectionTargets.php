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

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;

/**
 * Builds the CorrectionTarget the corrections endpoint names a record with.
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
final class CorrectionTargets implements CorrectionTargetsInterface {

	/**
	 * The record a correction is made on.
	 *
	 * @param ObjectRef $ref The register and schema (slug or id) and the object's id or uuid.
	 *
	 * @return CorrectionTarget
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function forRecord(ObjectRef $ref): CorrectionTarget {
		return new CorrectionTarget(register: (string)$ref->register, schema: (string)$ref->schema, id: $ref->id);
	}//end forRecord()
}//end class
