<?php

/**
 * What caused an archival nomination, and who asked for it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * The trigger of a nomination — the record's closure, or a recomputation —
 * and, for a recomputation, who asked and why.
 *
 * Built inside {@see ArchivalNominationService} by `nominate()` and
 * `recompute()`; callers outside the component never name it.
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */
final class NominationCause {
	/**
	 * Hold the cause.
	 *
	 * @param string      $trigger What caused this: `closure` or `recompute`.
	 * @param string|null $actor   Who asked, for a recomputation.
	 * @param string|null $reason  Why they asked, for a recomputation.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function __construct(
		public readonly string $trigger,
		public readonly ?string $actor = null,
		public readonly ?string $reason = null,
	) {
	}//end __construct()
}//end class
