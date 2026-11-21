<?php

/**
 * ExportRefusal — why an export was refused, as the audit trail records it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

/**
 * One refused export: the profile that was asked for, the rule that refused
 * and the sentence the caller was given.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ExportRefusal {

	/**
	 * Describe the refused export.
	 *
	 * @param string $profile The profile name, or `ad-hoc` when the caller named no profile.
	 * @param string $rule    The rule that refused.
	 * @param string $reason  The sentence the caller was given.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $profile,
		public readonly string $rule,
		public readonly string $reason,
	) {
	}//end __construct()
}//end class
