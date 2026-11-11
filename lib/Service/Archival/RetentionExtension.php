<?php

/**
 * OpenRegister RetentionExtension
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * One move of an object's archiefactiedatum: the ISO 8601 period it moves by
 * and the reason recorded in its exclusion history. Excluding an entry and
 * rejecting a list both extend with one, so {@see DestructionService} passes
 * the two together.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final readonly class RetentionExtension {

	/**
	 * Bundle the extension.
	 *
	 * @param string $period ISO 8601 duration to add.
	 * @param string $reason The reason for extension.
	 */
	public function __construct(
		public string $period,
		public string $reason,
	) {
	}//end __construct()
}//end class
