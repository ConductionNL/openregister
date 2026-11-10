<?php

/**
 * OpenRegister hardening fact
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
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

namespace OCA\OpenRegister\Service\Hardening;

/**
 * One hardening fact as the audit trail records it: what happened, and the state before and after.
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
 */
final class HardeningFact {
	/**
	 * Hold the fact.
	 *
	 * @param string           $fact   What happened, as `area.event`.
	 * @param int|string|array $before The state before.
	 * @param int|string|array $after  The state after, or the state asked for.
	 */
	public function __construct(
		public readonly string $fact,
		public readonly int|string|array $before,
		public readonly int|string|array $after,
	) {
	}//end __construct()
}//end class
