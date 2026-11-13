<?php

/**
 * OpenRegister DecisionQuestion
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Decision
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Decision;

/**
 * Who asks about a decision, under which policy, on which subject
 * (ADR-011 Rule 2 value object). Shared by the ownership and visibility rules.
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */
final class DecisionQuestion {

	/**
	 * Constructor.
	 *
	 * @param string               $actorId The user who asks.
	 * @param ReviewPolicy         $policy  The policy that applies.
	 * @param array<string, mixed> $subject What the decision is on, for listeners.
	 */
	public function __construct(
		public readonly string $actorId,
		public readonly ReviewPolicy $policy,
		public readonly array $subject=[],
	) {
	}//end __construct()
}//end class
