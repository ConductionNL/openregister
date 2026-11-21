<?php

/**
 * OpenRegister bulk save counts
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

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

/**
 * How many rows one bulk save was asked for, wrote, and accounted for.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class BulkSaveCounts {
	/**
	 * Bundle the counts of one bulk save.
	 *
	 * @param int $requested Rows the caller submitted.
	 * @param int $saved     Rows created or updated.
	 * @param int $accounted Rows written or left unchanged.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly int $requested,
		public readonly int $saved,
		public readonly int $accounted,
	) {
	}//end __construct()
}//end class
