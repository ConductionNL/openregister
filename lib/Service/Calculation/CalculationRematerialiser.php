<?php

/**
 * Re-derives the materialised calculations of a schema's objects, over a selection.
 *
 * The logic `occ openregister:rematerialise-calculations` ran on its own, moved
 * here so the command and the recompute endpoint produce the same counts
 * through the same code.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/computed-fields/spec.md
 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

use Closure;
use DateTimeInterface;
use OCA\OpenRegister\Command\RematerialiseRun;
use OCA\OpenRegister\Command\Support\RematerialiseEvaluation;
use OCA\OpenRegister\Command\Support\RematerialiseStorage;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Object\SchemaTypeConverterInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Re-evaluates every materialised calculation on the selected objects and saves the ones that changed.
 *
 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */
class CalculationRematerialiser {
	/**
	 * The most rows one recompute reads, as the command always did.
	 */
	public const READ_LIMIT = 100000;

	/**
	 * Wire the stores, the evaluator and the type converter.
	 *
	 * @param RematerialiseStorage         $storage    Register, schema and object stores.
	 * @param RematerialiseEvaluation      $evaluation Evaluator and reference pre-resolvers.
	 * @param SchemaTypeConverterInterface $types      Restores the stored form of `type: string` values the read decoded.
	 */
	public function __construct(
		private readonly RematerialiseStorage $storage,
		private readonly RematerialiseEvaluation $evaluation,
		private readonly SchemaTypeConverterInterface $types,
	) {
	}//end __construct()

	/**
	 * Re-derive the selected objects' calculations and say what changed.
	 *
	 * @param RegisterSchema       $table     The register and the schema that declares the calculations.
	 * @param RecomputeSelection   $selection Which objects.
	 * @param bool                 $dryRun    Report only; write nothing.
	 * @param Closure|null         $mayUpdate Whether the caller may update an object; null runs as the system.
	 *
	 * @return RecomputeResult
	 *
	 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
	 */
	public function run(RegisterSchema $table, RecomputeSelection $selection, bool $dryRun, ?Closure $mayUpdate=null): RecomputeResult {
		$run = new RematerialiseRun(table: $table, output: new NullOutput());
		$run->dryRun    = $dryRun;
		$run->mayUpdate = $mayUpdate;

		$this->rematerialise(run: $run, entities: $this->select(table: $table, selection: $selection));

		return RecomputeResult::of(run: $run);
	}//end run()

	/**
	 * The selected objects of a schema.
	 *
	 * @param RegisterSchema     $table     The register and the schema.
	 * @param RecomputeSelection $selection Which objects.
	 *
	 * @return list<ObjectEntity>
	 *
	 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
	 */
	public function select(RegisterSchema $table, RecomputeSelection $selection): array {
		$entities = $this->storage->magicMapper->findAllInRegisterSchemaTable(
			table: $table,
			listing: new ListQuery(limit: self::READ_LIMIT, filters: $selection->filters)
		);

		return array_values(
			array_filter(
				$entities,
				static fn (ObjectEntity $entity): bool => $selection->holds(id: (string)$entity->getId(), uuid: (string)$entity->getUuid())
			)
		);
	}//end select()

	/**
	 * Re-derive every given object in a run; nothing when the schema materialises no calculation.
	 *
	 * @param RematerialiseRun   $run      The run, which keeps the counts.
	 * @param list<ObjectEntity> $entities The objects.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
	 */
	public function rematerialise(RematerialiseRun $run, array $entities): void {
		if ($run->materialised === []) {
			return;
		}

		foreach ($entities as $entity) {
			$this->rematerialiseRow(run: $run, entity: $entity);
		}
	}//end rematerialise()

	/**
	 * Re-evaluate one row's materialised calculations and save it when a value changed.
	 *
	 * @param RematerialiseRun $run    The run.
	 * @param ObjectEntity $entity The row.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function rematerialiseRow(RematerialiseRun $run, ObjectEntity $entity): void {
		if ($run->mayUpdate !== null && ($run->mayUpdate)($entity) === false) {
			// Never written: the caller may not update this object (REQ-RRD-001).
			$run->fail(id: (string)$entity->getUuid(), reason: 'forbidden');
			return;
		}

		$payload = $this->evaluationPayload(run: $run, entity: $entity);
		if ($payload === null) {
			return;
		}

		$row = ['entity' => $entity, 'data' => ($entity->getObject() ?? []), 'payload' => $payload];
		$row['expected'] = $this->changedValues(run: $run, row: $row);
		if ($row['expected'] === []) {
			$run->unchanged++;
			return;
		}

		if ($run->dryRun === true) {
			$run->touched++;
			return;
		}

		$this->saveChangedRow(run: $run, row: $row);
	}//end rematerialiseRow()

	/**
	 * Build the payload the calculations evaluate: the row with `@self`, `@ref` and `@aggregate`.
	 *
	 * Declared cross-object references and aggregate-references are pre-resolved
	 * per object so the recompute path refreshes them the same way the save path does.
	 * An aggregate that could not be resolved is not a null to compare: the row's
	 * value is unknown, so the row FAILED and no payload is returned.
	 *
	 * @param RematerialiseRun $run    The run.
	 * @param ObjectEntity $entity The row.
	 *
	 * @return array<string, mixed>|null The payload, or null when an aggregate failed.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	private function evaluationPayload(RematerialiseRun $run, ObjectEntity $entity): ?array {
		$payload = $this->payloadWithReferences(run: $run, entity: $entity);
		if ($run->aggregateSpecs === null) {
			return $payload;
		}

		return $this->payloadWithAggregates(run: $run, row: ['entity' => $entity, 'payload' => $payload]);
	}//end evaluationPayload()

	/**
	 * The row with `@self`, and `@ref` when the schema declares cross-object references.
	 *
	 * @param RematerialiseRun $run    The run.
	 * @param ObjectEntity $entity The row.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function payloadWithReferences(RematerialiseRun $run, ObjectEntity $entity): array {
		$payload = (new CalculationPayload())->withSelf(data: ($entity->getObject() ?? []), entity: $entity);
		if ($run->referenceSpecs !== null) {
			$payload['@ref'] = $this->evaluation->references->resolveAll(
				payload: $payload,
				references: $run->referenceSpecs,
				register: $entity->getRegister(),
				organisation: $entity->getOrganisation()
			);
		}

		return $payload;
	}//end payloadWithReferences()

	/**
	 * The payload with `@aggregate`, or null (and the row FAILED) when an aggregate could not be resolved.
	 *
	 * @param RematerialiseRun     $run The run.
	 * @param array<string, mixed> $row The row: entity and payload.
	 *
	 * @return array<string, mixed>|null The payload, or null when an aggregate failed.
	 */
	private function payloadWithAggregates(RematerialiseRun $run, array $row): ?array {
		$outcome = $this->evaluation->aggregates->resolveOutcome(
			referrer: new ReferringObject(payload: $row['payload'], register: $row['entity']->getRegister()),
			aggregates: $run->aggregateSpecs
		);
		if ($outcome['failed'] !== []) {
			$messages = $this->aggregateFailureMessages(failed: $outcome['failed'], uuid: (string)$row['entity']->getUuid());
			$this->reportFailedAggregates(
				run: $run,
				messages: $messages,
				failure: ['id' => (string)$row['entity']->getUuid(), 'failed' => $outcome['failed']]
			);
			return null;
		}

		$payload = $row['payload'];
		$payload['@aggregate'] = $outcome['values'];
		return $payload;
	}//end payloadWithAggregates()

	/**
	 * One error line per aggregate that could not be resolved.
	 *
	 * @param array<string, string> $failed The failure reason per aggregate name.
	 * @param string                $uuid   The row's uuid.
	 *
	 * @return string[] The lines.
	 */
	private function aggregateFailureMessages(array $failed, string $uuid): array {
		$messages = [];
		foreach ($failed as $name => $reason) {
			$messages[] = sprintf('  <error>! aggregate %s on %s: %s</error>', $name, $uuid, $reason);
		}

		return $messages;
	}//end aggregateFailureMessages()

	/**
	 * Count the row as FAILED and write its aggregate error lines.
	 *
	 * @param RematerialiseRun     $run      The run.
	 * @param string[]             $messages The error lines.
	 * @param array<string, mixed> $failure  The row's id and the failure reason per aggregate.
	 *
	 * @return void
	 */
	private function reportFailedAggregates(RematerialiseRun $run, array $messages, array $failure): void {
		$reasons = [];
		foreach ($failure['failed'] as $name => $reason) {
			$reasons[] = 'aggregate '.$name.': '.$reason;
		}

		$run->fail(id: $failure['id'], reason: implode('; ', $reasons));
		foreach ($messages as $message) {
			$run->output->writeln($message);
		}
	}//end reportFailedAggregates()

	/**
	 * Evaluate the materialised calculations and keep the values that differ from the stored ones.
	 *
	 * A `sequence` reserves exactly once, on create. This command has no
	 * SequenceContext, so re-evaluating would resolve the node to null and
	 * `concat` would render it as "", replacing every assigned number with
	 * a truncated stub ("2026-0013" -> "2026-"). Those are never rewritten.
	 *
	 * @param RematerialiseRun     $run The run.
	 * @param array<string, mixed> $row The row: entity, stored data and payload.
	 *
	 * @return array<string, mixed> The changed values, by calculation name.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	private function changedValues(RematerialiseRun $run, array $row): array {
		$expected = [];
		foreach ($this->recomputedValues(run: $run, row: $row) as $name => $value) {
			if (($row['data'][$name] ?? null) !== $value) {
				$expected[$name] = $value;
			}
		}

		return $expected;
	}//end changedValues()

	/**
	 * Evaluate every materialised calculation that does not use a sequence; a failing one is reported.
	 *
	 * @param RematerialiseRun     $run The run.
	 * @param array<string, mixed> $row The row: entity, stored data and payload.
	 *
	 * @return array<string, mixed> The evaluated values, by calculation name.
	 */
	private function recomputedValues(RematerialiseRun $run, array $row): array {
		$values = [];
		foreach ($this->recomputableCalculations(run: $run) as $name => $spec) {
			try {
				$values[(string)$name] = $this->evaluatedValue(payload: $row['payload'], spec: $spec);
			} catch (\Throwable $e) {
				$this->reportFailedCalculation(
					run: $run,
					message: sprintf('  <error>! %s on %s: %s</error>', (string)$name, (string)$row['entity']->getUuid(), $e->getMessage()),
					failure: ['id' => (string)$row['entity']->getUuid(), 'reason' => (string)$name.': '.$e->getMessage()]
				);
			}
		}

		return $values;
	}//end recomputedValues()

	/**
	 * The materialised calculations whose expression uses no sequence.
	 *
	 * @param RematerialiseRun $run The run.
	 *
	 * @return array<string, array> The calculations, by name.
	 */
	private function recomputableCalculations(RematerialiseRun $run): array {
		$recomputable = [];
		foreach ($run->materialised as $name => $spec) {
			if ($this->evaluation->evaluator->expressionUsesSequence($spec['expression'] ?? null) === false) {
				$recomputable[$name] = $spec;
			}
		}

		return $recomputable;
	}//end recomputableCalculations()

	/**
	 * A calculation's value for a payload, a date written as ATOM.
	 *
	 * @param array<string, mixed> $payload The evaluation payload.
	 * @param array                $spec    The calculation.
	 *
	 * @return mixed The value.
	 */
	private function evaluatedValue(array $payload, array $spec): mixed {
		$value = $this->evaluation->evaluator->evaluate($payload, $spec['expression'] ?? null);
		if ($value instanceof DateTimeInterface) {
			return $value->format(DateTimeInterface::ATOM);
		}

		return $value;
	}//end evaluatedValue()

	/**
	 * Count a calculation failure and write its error line.
	 *
	 * @param RematerialiseRun      $run     The run.
	 * @param string                $message The error line.
	 * @param array<string, string> $failure The row's id and the reason.
	 *
	 * @return void
	 */
	private function reportFailedCalculation(RematerialiseRun $run, string $message, array $failure): void {
		$run->fail(id: $failure['id'], reason: $failure['reason']);
		$run->output->writeln($message);
	}//end reportFailedCalculation()

	/**
	 * Save a row whose calculations changed, unless an earlier save in this run already materialised it.
	 *
	 * The read decodes JSON held in a `type: string` property; the re-save hands
	 * it back as stored, or validation refuses the row. A `fallback` calculation
	 * gives the value only until an app's own save listener sets it, so a value
	 * the save kept is not a failure.
	 *
	 * @param RematerialiseRun     $run The run.
	 * @param array<string, mixed> $row The row: entity, stored data and the changed values.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	private function saveChangedRow(RematerialiseRun $run, array $row): void {
		$row = $this->withCurrentState(run: $run, row: $row);
		if ($row['expected'] === []) {
			$run->output->writeln(
				sprintf('  <comment>%s already materialised by an earlier save in this run</comment>', (string)$row['entity']->getUuid()),
				OutputInterface::VERBOSITY_VERBOSE
			);
			$run->touched++;
			return;
		}

		$row['data'] = $this->types->restoreStringTypedValues(data: $row['data'], properties: ($run->schema->getProperties() ?? []));
		$row['expected'] = array_diff_key($row['expected'], $run->fallbacks());
		$failure = $this->saveForMaterialisation(run: $run, row: $row);
		if ($failure === null) {
			$run->touched++;
			return;
		}

		$run->fail(id: (string)$row['entity']->getUuid(), reason: $failure);
	}//end saveChangedRow()

	/**
	 * Replace the row's data with what is stored now, and drop the values it already holds.
	 *
	 * An earlier save in this run can already have materialised the row (saving
	 * one enrolment re-saves its siblings), and saving the snapshot taken at the
	 * start would then try to change the readOnly value back. When the row cannot
	 * be read again, the snapshot stays.
	 *
	 * @param RematerialiseRun     $run The run.
	 * @param array<string, mixed> $row The row: entity, stored data and the changed values.
	 *
	 * @return array<string, mixed> The row with current data and the values still to change.
	 *
	 * @spec openspec/changes/rematerialise-rereads-before-save/specs/computed-fields/spec.md
	 */
	private function withCurrentState(RematerialiseRun $run, array $row): array {
		$current = $this->reread(run: $run, entity: $row['entity']);
		if ($current === null) {
			return $row;
		}

		$row['data'] = $current;
		$row['expected'] = array_filter(
			$row['expected'],
			static fn ($value, $name): bool => ($current[$name] ?? null) !== $value,
			ARRAY_FILTER_USE_BOTH
		);
		return $row;
	}//end withCurrentState()

	/**
	 * Read a row's stored data as it is now, not as the run's first read saw it.
	 *
	 * @param RematerialiseRun $run    The run, for its register and schema.
	 * @param ObjectEntity $entity The row from the run's first read.
	 *
	 * @return array<string, mixed>|null The stored data, or null when the row cannot be read again.
	 *
	 * @spec openspec/changes/rematerialise-rereads-before-save/specs/computed-fields/spec.md
	 */
	private function reread(RematerialiseRun $run, ObjectEntity $entity): ?array {
		try {
			$fresh = $this->storage->magicMapper->find(
				identifier: (string)$entity->getUuid(),
				register: $run->register,
				schema: $run->schema,
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			return null;
		}

		return ($fresh->getObject() ?? []);
	}//end reread()

	/**
	 * Re-save a row UNCHANGED so the save path materialises it, and check it did.
	 *
	 * The command does not write the values itself: materialised calculations
	 * are declared readOnly ("never edit by hand"), and ObjectService refuses a
	 * payload that changes them (live pass O8: "Cannot modify readOnly
	 * properties"). CalculationOnSaveListener writes them on every save, as the
	 * temporal sweep relies on; the command re-saves the stored data, as the
	 * sweep does, and then reads back that each changed value arrived.
	 *
	 * @param RematerialiseRun     $run The run, for its output.
	 * @param array<string, mixed> $row The row: entity, its stored data and the values the calculations now give, by name.
	 *
	 * @return string|null Null when every expected value is on the saved row, else why not.
	 *
	 * @spec openspec/changes/rematerialise-writes-calculated-values/specs/computed-fields/spec.md
	 */
	private function saveForMaterialisation(RematerialiseRun $run, array $row): ?string {
		$entity = $row['entity'];
		try {
			// No user session under occ: write as the system (live pass O7).
			$saved = $this->storage->objectService->saveObject(
				object: $row['data'],
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $entity->getRegister(), schema: $entity->getSchema()),
					uuid: $entity->getUuid()
				),
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$run->output->writeln(sprintf('  <error>save failed on %s: %s</error>', (string)$entity->getUuid(), $e->getMessage()));
			return 'save failed: '.$e->getMessage();
		}

		$missing = self::notMaterialised(after: ($saved->getObject() ?? []), row: $row);
		if ($missing === []) {
			return null;
		}

		$run->output->writeln(
			sprintf(
				'  <error>saved %s but the save did not materialise: %s</error>',
				(string)$entity->getUuid(),
				implode(', ', $missing)
			)
		);
		return 'the save did not materialise: '.implode(', ', $missing);
	}//end saveForMaterialisation()

	/**
	 * The expected values the save left as they were stored.
	 *
	 * @param array<string, mixed> $after The saved row's data.
	 * @param array<string, mixed> $row   The row: its stored data and the expected values, by name.
	 *
	 * @return array<int, string> The names that did not materialise.
	 */
	private static function notMaterialised(array $after, array $row): array {
		$missing = [];
		foreach (array_keys($row['expected']) as $name) {
			if (($after[$name] ?? null) === ($row['data'][$name] ?? null)) {
				$missing[] = $name;
			}
		}

		return $missing;
	}//end notMaterialised()
}//end class
