<?php

/**
 * The save-time contract of a stored decision table, for the bulk writer.
 *
 * A bulk save dispatches no ObjectCreatingEvent, so {@see DecisionTableValidationListener} never sees its rows
 * and a table with a key another table already carries (or a table the engine cannot run) would be stored. This
 * guard asks the listener's own rule per row, and in addition refuses a key that two rows of the same batch both
 * claim, which no stored-table lookup can see yet.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Dmn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dmn;

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Listener\DecisionTableValidationListenerInterface;
use Psr\Container\ContainerInterface;

/**
 * Refuses the decision-table rows of a bulk save that the single save would have refused.
 *
 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
 */
class DecisionTableBulkGuard {

	/**
	 * The single-save rule, resolved on first use.
	 *
	 * @var DecisionTableValidationListenerInterface|null
	 */
	private ?DecisionTableValidationListenerInterface $listener = null;

	/**
	 * Constructor.
	 *
	 * The listener is not injected directly: it needs ObjectService, which
	 * needs SaveObjects, which needs this guard. Resolving it when the first
	 * row is checked breaks that cycle; ObjectService exists by then.
	 *
	 * @param ContainerInterface $container Hands out the single-save rule.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The single-save rule.
	 *
	 * @return DecisionTableValidationListenerInterface The listener.
	 *
	 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
	 */
	private function listener(): DecisionTableValidationListenerInterface {
		if ($this->listener === null) {
			$this->listener = $this->container->get(DecisionTableValidationListenerInterface::class);
		}

		return $this->listener;
	}//end listener()

	/**
	 * The rows that must not be stored, by their position in the batch.
	 *
	 * @param array<int|string, array<string, mixed>> $objects  The raw rows.
	 * @param Register|string|int|null                $register The batch's default register.
	 * @param Schema|string|int|null                  $schema   The batch's default schema.
	 *
	 * @return array<int|string, string> The refusal reason per refused row.
	 *
	 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
	 */
	public function refusals(array $objects, Register|string|int|null $register, Schema|string|int|null $schema): array {
		$run = new DecisionTableBatchRun();
		$run->defaultSchema   = $this->reference(value: $schema);
		$run->defaultRegister = $this->reference(value: $register);
		$refused = array_map(fn (mixed $row): ?string => $this->refusalFor(run: $run, row: $row), $objects);

		return array_filter($refused, static fn (?string $reason): bool => $reason !== null);
	}//end refusals()

	/**
	 * Why one row of the batch must not be stored, or null when it may.
	 *
	 * @param DecisionTableBatchRun $run The batch run.
	 * @param mixed                 $row The raw row.
	 *
	 * @return string|null The refusal reason, or null.
	 */
	private function refusalFor(DecisionTableBatchRun $run, mixed $row): ?string {
		$problems = $this->problemsOf(run: $run, row: $row);
		if ($problems === null || $problems === []) {
			return null;
		}

		return 'The decision table cannot be saved: ' . implode('; ', $problems) . '.';
	}//end refusalFor()

	/**
	 * What is wrong with one row, or null when the row is no decision table.
	 *
	 * Sets the run's row cursor (schema, register, uuid) before asking.
	 *
	 * @param DecisionTableBatchRun $run The batch run.
	 * @param mixed                 $row The raw row.
	 *
	 * @return array<int, string>|null The problems, or null.
	 */
	private function problemsOf(DecisionTableBatchRun $run, mixed $row): ?array {
		$self = $this->selfOf(row: $row);
		$this->locate(run: $run, self: $self);
		if ($this->isTable(run: $run) === false) {
			return null;
		}

		$data = $this->withoutSelf(row: $row);
		$run->uuid = $this->uuidOf(row: $row, self: $self);
		$problems  = $this->rowProblems(run: $run, data: $data);
		$clash     = $this->claim(run: $run, key: trim((string)($data['key'] ?? '')));
		if ($clash !== null) {
			$problems[] = $clash;
		}

		return $problems;
	}//end problemsOf()

	/**
	 * The row's `@self` block, or an empty one.
	 *
	 * @param mixed $row The submitted row.
	 *
	 * @return array<string, mixed> The block.
	 */
	private function selfOf(mixed $row): array {
		if (is_array($row['@self'] ?? null) === true) {
			return $row['@self'];
		}

		return [];
	}//end selfOf()

	/**
	 * The row's data: the row without its `@self` block.
	 *
	 * @param mixed $row The submitted row.
	 *
	 * @return mixed The data.
	 */
	private function withoutSelf(mixed $row): mixed {
		$data = $row;
		unset($data['@self']);

		return $data;
	}//end withoutSelf()

	/**
	 * Point the run at the row's schema and register, or the batch defaults.
	 *
	 * @param DecisionTableBatchRun $run  The batch in progress.
	 * @param array<string, mixed>  $self The row's `@self` block.
	 *
	 * @return void
	 */
	private function locate(DecisionTableBatchRun $run, array $self): void {
		$run->schema   = ($this->reference(value: ($self['schema'] ?? null)) ?? $run->defaultSchema);
		$run->register = ($this->reference(value: ($self['register'] ?? null)) ?? $run->defaultRegister);
	}//end locate()

	/**
	 * The problems the decision-table save check finds in one row's data.
	 *
	 * @param DecisionTableBatchRun $run  The batch in progress, at this row.
	 * @param array<string, mixed>  $data The row's data without `@self`.
	 *
	 * @return array<int, string> The problems.
	 */
	private function rowProblems(DecisionTableBatchRun $run, array $data): array {
		return ($this->listener()->problemsOf(
			row: new ObjectRef(register: (string)$run->register, schema: (string)$run->schema, id: $run->uuid),
			data: $data
		) ?? []);
	}//end rowProblems()

	/**
	 * Whether the run's current register/schema pair is a decision table, asked once per pair.
	 *
	 * @param DecisionTableBatchRun $run The batch run, its cursor on the row.
	 *
	 * @return bool|null The listener's answer.
	 */
	private function isTable(DecisionTableBatchRun $run): ?bool {
		$memo = ($run->schema . '|' . $run->register);
		if (array_key_exists($memo, $run->isTable) === false) {
			$run->isTable[$memo] = $this->listener()->isDecisionTableRef(schema: $run->schema, register: $run->register);
		}

		return $run->isTable[$memo];
	}//end isTable()

	/**
	 * Claim a key for the run's current row, or say that an earlier row of the batch holds it.
	 *
	 * @param DecisionTableBatchRun $run The batch run: the keys claimed so far, and the row's uuid ('' for a new row).
	 * @param string                $key The row's key.
	 *
	 * @return string|null The problem, or null when the key is free or the same row claims it again.
	 */
	private function claim(DecisionTableBatchRun $run, string $key): ?string {
		if ($key === '') {
			return null;
		}

		$other = ($run->claimed[$key] ?? null);
		$run->claimed[$key] = ($other ?? $run->uuid);
		if ($other !== null && ($other !== $run->uuid || $run->uuid === '')) {
			return sprintf('the key "%s" is claimed by another row of the same save', $key);
		}

		return null;
	}//end claim()

	/**
	 * A register or schema value as the reference string the listener resolves.
	 *
	 * @param mixed $value An entity, an id or a slug.
	 *
	 * @return string|null The reference, or null when there is none.
	 */
	private function reference(mixed $value): ?string {
		if ($value instanceof Schema === true || $value instanceof Register === true) {
			$value = $value->getId();
		}

		if ($value === null || $value === '' || $value === 0 || $value === '0') {
			return null;
		}

		return (string)$value;
	}//end reference()

	/**
	 * The uuid a row carries, '' for a new one.
	 *
	 * @param array<string, mixed> $row  The row.
	 * @param array<string, mixed> $self The row's `@self`.
	 *
	 * @return string
	 */
	private function uuidOf(array $row, array $self): string {
		$uuid = ($self['uuid'] ?? $self['id'] ?? $row['id'] ?? '');

		if (is_string($uuid) === true) {
			return $uuid;
		}

		return '';
	}//end uuidOf()
}//end class
