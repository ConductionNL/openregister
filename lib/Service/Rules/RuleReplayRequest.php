<?php

/**
 * OpenRegister RuleReplayRequest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

/**
 * What an operator asks to replay: the rule, who asks, and why.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RuleReplayRequest {

	/**
	 * Constructor.
	 *
	 * @param string      $ruleId        The derived rule id.
	 * @param string      $actorUid      The actor's uid.
	 * @param string|null $justification The reason the operator typed.
	 */
	public function __construct(
		public readonly string $ruleId,
		public readonly string $actorUid,
		public readonly ?string $justification = null,
	) {
	}//end __construct()
}//end class
