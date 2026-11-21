<?php

/**
 * RelativeTimeCalendarCheck: save-time refusal of a relative-time condition that cannot be evaluated.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

use Closure;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rules\NamedConditionLibrary;
use OCA\OpenRegister\Service\Rules\NamedConditionScope;
use OCA\OpenRegister\Service\Rules\RelativeTimeCondition;
use OCA\OpenRegister\Service\Rules\RelativeTimeEvaluation;

/**
 * Refuses a schema save carrying a `$age` condition that could not be
 * evaluated as written: a malformed body, an unknown unit, or a business unit
 * whose working calendar does not resolve, naming the calendar (REQ-RCT-003,
 * task 3.2b). The refusal names the rule, or the named condition, it sits in.
 *
 * The conditions read are the ones {@see NamedConditionCheck} reads, plus the
 * schema's own named conditions, since a `$age` is most useful as one.
 *
 * Runs only when the save can reach the calendar resolution: without it the
 * check is skipped, and evaluation still refuses what it cannot count — the
 * save-time check is the early answer, not the only guard.
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */
final class RelativeTimeCalendarCheck {

	/**
	 * Constructor.
	 *
	 * @param RelativeTimeEvaluation|Closure|null $time The `$age` evaluation; a Closure defers it to first use.
	 */
	public function __construct(
		private readonly RelativeTimeEvaluation|Closure|null $time,
	) {
	}//end __construct()

	/**
	 * Refuse the save when a relative-time condition cannot be evaluated.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 *
	 * @throws \Exception When a condition is refused.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public function check(Schema $schema): void {
		$nodes = self::nodesOf(schema: $schema);
		if ($nodes === [] || $this->time === null) {
			return;
		}

		$time = $this->time;
		if ($time instanceof Closure) {
			$time = $time();
		}

		foreach ($nodes as $where => $node) {
			$refusal = $this->firstRefusal(time: $time, node: $node);
			if ($refusal !== null) {
				(new AnnotationFaults(schema: $schema, key: RelativeTimeCondition::KEY))
					->refuseWith(messages: [sprintf('"%s": %s', $where, $refusal)]);
			}
		}
	}//end check()

	/**
	 * The conditions a schema declares, by the rule or named condition they belong to.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<string, mixed> Condition nodes, keyed by where they sit.
	 */
	private static function nodesOf(Schema $schema): array {
		$configuration = ($schema->getConfiguration() ?? []);
		if (is_array($configuration) === false) {
			return [];
		}

		$nodes = NamedConditionCheck::conditionNodes(schema: $schema, configuration: $configuration);
		foreach (NamedConditionScope::libraryFrom(configuration: $configuration) as $name => $entry) {
			$nodes[NamedConditionLibrary::REF.':'.$name] = $entry['expression'];
		}

		return $nodes;
	}//end nodesOf()

	/**
	 * The first `$age` inside a node that may not be saved, as its reason.
	 *
	 * @param RelativeTimeEvaluation $time The `$age` evaluation.
	 * @param mixed                  $node The condition node.
	 *
	 * @return string|null The reason.
	 */
	private function firstRefusal(RelativeTimeEvaluation $time, mixed $node): ?string {
		if (is_array($node) === false) {
			return null;
		}

		if (array_key_exists(RelativeTimeCondition::KEY, $node) === true) {
			return $time->refusalAtSave(node: $node);
		}

		foreach ($node as $child) {
			$refusal = $this->firstRefusal(time: $time, node: $child);
			if ($refusal !== null) {
				return $refusal;
			}
		}

		return null;
	}//end firstRefusal()
}//end class
