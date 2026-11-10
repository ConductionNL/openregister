<?php

/**
 * OpenRegister TermExplanationRequest
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
 * A request to explain one term: the calendar, anchor, SLA and ladder as the caller sent them, still unparsed.
 *
 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
 */
final class TermExplanationRequest {
	/**
	 * Hold the request.
	 *
	 * @param array<string, mixed> $calendar The `working-calendar` definition.
	 * @param string               $anchorAt The anchor instant.
	 * @param array<string, mixed> $sla      `{value, unit, rollToWorkingDay?}`.
	 * @param array<int, mixed>    $ladder   Optional rungs.
	 *
	 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
	 */
	public function __construct(
		public readonly array $calendar,
		public readonly string $anchorAt,
		public readonly array $sla,
		public readonly array $ladder,
	) {
	}//end __construct()
}//end class
