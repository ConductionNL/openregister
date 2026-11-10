<?php

/**
 * OpenRegister RuleReplayRun
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

use OCA\OpenRegister\BulkAction\ApplyRuleActionInterface;
use OCA\OpenRegister\Service\BulkJob\BulkJobActionCall;

/**
 * One replay asked for: the rule, the selection it runs over, who asks
 * and why.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final readonly class RuleReplayRun {

	/**
	 * One replay.
	 *
	 * @param RuleDescriptor      $rule          The rule to replay (only a calculation is replayable).
	 * @param RuleReplaySelection $selection     The objects it runs over.
	 * @param string              $actorUid      The actor's uid.
	 * @param string|null         $justification The reason the operator typed.
	 */
	public function __construct(
		public RuleDescriptor $rule,
		public RuleReplaySelection $selection,
		public string $actorUid,
		public ?string $justification,
	) {
	}//end __construct()

	/**
	 * The bulk action that applies this rule to each selected object.
	 *
	 * @return BulkJobActionCall The action and its parameters: the schema (slug, or id without one) and the rule id.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function actionCall(): BulkJobActionCall {
		$schema = $this->selection->schema;

		return new BulkJobActionCall(
			actionId: ApplyRuleActionInterface::ID,
			parameters: ['schema' => (string)($schema->getSlug() ?? (string)$schema->getId()), 'ruleId' => $this->rule->getId()]
		);
	}//end actionCall()
}//end class
