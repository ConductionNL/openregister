<?php

/**
 * OpenRegister safeguard rejection
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

namespace OCA\OpenRegister\Service\Object;

/**
 * One row a bulk safeguard refused to write: the row, why, and where it stood in the batch.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class SafeguardRejection {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $object The refused row.
	 * @param string               $reason The rejection reason.
	 * @param int|null             $index  Position of the row in the submitted batch, when known.
	 */
	public function __construct(
		public readonly array $object,
		public readonly string $reason,
		public readonly int|null $index = null,
	) {
	}//end __construct()
}//end class
