<?php

/**
 * OpenRegister DecisionTableStore
 *
 * Reads named decision tables from the `decision-tables` register, so a flow
 * step or an app can use a table by reference instead of carrying a copy.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Dmn
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

namespace OCA\OpenRegister\Service\Dmn;

use OCA\OpenRegister\Service\ObjectService;
use Throwable;
use UnexpectedValueException;

/**
 * One named decision table, stored once, used by every flow that names it.
 *
 * A reference is a table's `key` or its object uuid. A reference that names
 * no table is refused, never answered with an empty table: a decision step
 * that silently decided nothing would read as a decision.
 *
 * Reads run without RBAC, as the flow engine does for its other definitions
 * (working calendars, escalation ladders): a flow runs as a system actor, and
 * who may author a table is the register's RBAC, enforced when it is written.
 *
 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
 */
class DecisionTableStore {

	/**
	 * The register the tables live in.
	 */
	public const REGISTER = 'decision-tables';

	/**
	 * The schema of one table.
	 */
	public const SCHEMA = 'decision-table';

	/**
	 * Tables resolved in this request, by reference.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $resolved = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectService          $objects   Reads the register.
	 * @param DecisionTableValidator $validator Refuses a stored table the evaluator cannot execute.
	 * @param DecisionTableEvaluator $engine    Evaluates a resolved table.
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly DecisionTableValidator $validator,
		private readonly DecisionTableEvaluator $engine,
	) {
	}//end __construct()

	/**
	 * The table a reference names.
	 *
	 * @param string $reference The table's key or uuid.
	 *
	 * @return array<string, mixed> The table: key, name, hitPolicy, inputs, outputs, rules.
	 *
	 * @throws UnexpectedValueException When no table carries the reference, more than one does, or the stored table cannot be executed.
	 *
	 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
	 */
	public function find(string $reference): array {
		$reference = trim($reference);
		if ($reference === '') {
			throw new UnexpectedValueException('A decision table reference is empty.');
		}

		if (array_key_exists($reference, $this->resolved) === true) {
			return $this->resolved[$reference];
		}

		$table = $this->lookup(reference: $reference);
		if ($table === null) {
			throw new UnexpectedValueException(sprintf('No decision table has the key or id "%s".', $reference));
		}

		$problems = $this->validator->validate(table: $table);
		if ($problems !== []) {
			throw new UnexpectedValueException(
				sprintf('The decision table "%s" cannot be run: %s.', $reference, implode('; ', $problems))
			);
		}

		$this->resolved[$reference] = $table;

		return $table;
	}//end find()

	/**
	 * Evaluate the table a reference names against a set of inputs.
	 *
	 * The entry point for an app that decides outside a flow, for example a
	 * recommendation shown on a case. Same evaluator, same table, same answer.
	 *
	 * @param string               $reference The table's key or uuid.
	 * @param array<string, mixed> $inputs    Input values keyed by input name.
	 *
	 * @return array{outputs: array<string, mixed>, matchedRuleIds: array<int, string>, hitPolicy: string, tableKey: string}
	 *
	 * @throws UnexpectedValueException   When the reference names no executable table.
	 * @throws DecisionEvaluationException When the evaluation fails, including when no rule matches.
	 *
	 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
	 */
	public function evaluate(string $reference, array $inputs): array {
		$table  = $this->find(reference: $reference);
		$result = $this->engine->evaluate(decisionTable: $table, inputs: $inputs);

		return [
			'outputs' => $result['outputs'],
			'matchedRuleIds' => $result['matchedRuleIds'],
			'hitPolicy' => $result['hitPolicy'],
			'tableKey' => (string)($table['key'] ?? ''),
		];
	}//end evaluate()

	/**
	 * Find the stored row by key first, then by uuid.
	 *
	 * @param string $reference The table's key or uuid.
	 *
	 * @return array<string, mixed>|null The table, or null when none carries the reference.
	 *
	 * @throws UnexpectedValueException When the register cannot be read, or more than one table carries the key.
	 */
	private function lookup(string $reference): ?array {
		try {
			$rows = $this->objects->searchObjectsBySlug(
				registerSlug: self::REGISTER,
				schemaSlug: self::SCHEMA,
				filters: [],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $failure) {
			throw new UnexpectedValueException(
				sprintf('The decision-table register could not be read: %s', $failure->getMessage()),
				0,
				$failure
			);
		}

		$byKey  = [];
		$byUuid = null;
		foreach ((array)$rows as $entity) {
			$data = $entity->getObject();
			if (is_array($data) === false) {
				continue;
			}

			if (trim((string)($data['key'] ?? '')) === $reference) {
				$byKey[(string)$entity->getUuid()] = $data;
				continue;
			}

			if ($byUuid === null && (string)$entity->getUuid() === $reference) {
				$byUuid = $data;
			}
		}

		// Two rows with one key (a save that went around the guard, an import) are two tables, and the register
		// returns them in no fixed order: answering with the first would decide by a table nobody chose, and
		// which one could change between requests.
		if (count($byKey) > 1) {
			throw new UnexpectedValueException(
				sprintf('More than one decision table has the key "%s" (%s); the key must name exactly one.', $reference, implode(', ', array_keys($byKey)))
			);
		}

		if ($byKey !== []) {
			return array_values($byKey)[0];
		}

		return $byUuid;
	}//end lookup()
}//end class
