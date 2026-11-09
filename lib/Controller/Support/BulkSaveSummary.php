<?php

/**
 * OpenRegister bulk save summary
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
 * What one bulk-save path wrote, as the bulk answer reports it.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class BulkSaveSummary {
	/**
	 * Bundle the figures of one bulk save.
	 *
	 * @param string $successMessage Message used when nothing failed.
	 * @param int    $savedCount     Rows created or updated.
	 * @param int    $requestedCount Rows the caller submitted.
	 * @param array  $extra          Path-specific payload merged into the response body.
	 * @param int    $accountedCount Rows written or left unchanged.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $successMessage,
		public readonly int $savedCount,
		public readonly int $requestedCount,
		public readonly array $extra,
		public readonly int $accountedCount,
	) {
	}//end __construct()
}//end class
