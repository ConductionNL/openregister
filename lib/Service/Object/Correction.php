<?php

/**
 * OpenRegister correction
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
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * One correction, checked: the record it is made on, the values it corrects
 * and the reason it is recorded under.
 *
 * Built by CorrectionService::correct() after the reason and the values have
 * passed their checks, so a Correction is never empty and never unexplained.
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
final class Correction {
	/**
	 * Constructor.
	 *
	 * @param CorrectionTarget $target The register, schema and object the correction is made on.
	 * @param string           $reason Why the values were wrong, trimmed and non-empty.
	 * @param array            $values The correctable properties, mapped to their corrected values.
	 */
	public function __construct(
		public readonly CorrectionTarget $target,
		public readonly string $reason,
		public readonly array $values,
	) {
	}//end __construct()
}//end class
