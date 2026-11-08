<?php

/**
 * OpenRegister bulk save batch
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

namespace OCA\OpenRegister\Controller\Support;

/**
 * One accepted bulk-save batch, as every write path receives it.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final readonly class BulkSaveBatch {
	/**
	 * Bundle the rows with where they go and how a shortfall is answered.
	 *
	 * @param array    $objects  The rows.
	 * @param int      $register The resolved register id.
	 * @param int|null $schema   The resolved schema id, or null for a mixed-schema batch.
	 * @param bool     $partial  Whether the caller opted into best-effort semantics.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public array $objects,
		public int $register,
		public ?int $schema,
		public bool $partial,
	) {
	}//end __construct()
}//end class
