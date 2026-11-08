<?php

/**
 * OpenRegister correction target
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
 * The record a correction is made on, as the route names it.
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
final class CorrectionTarget {
	/**
	 * Name the record.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object's id or uuid.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function __construct(
		public readonly string $register,
		public readonly string $schema,
		public readonly string $id,
	) {
	}//end __construct()
}//end class
