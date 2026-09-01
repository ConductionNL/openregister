<?php

/**
 * OpenRegister Temporal Calculation Sweep Service
 *
 * The clockwork that makes time-dependent declared behaviour LIVE without
 * object writes (dsar-escalation-and-dpia, closing the pipelinq
 * `consume-or-dsar` deadline-escalation gap): materialised calculations whose
 * expressions reference the evaluation clock (`now`) — e.g. the DSAR
 * `escalationTier` — go stale on objects nobody touches, so the schema's
 * declared `calculatedChange` notification rules (reminder / escalation /
 * breach) never fire for exactly the proactive case they exist for.
 *
 * The sweep periodically re-evaluates those calculations for objects in
 * non-terminal lifecycle states and persists ONLY changed values through the
 * normal `ObjectService` write path. The write re-runs the save-time
 * materialisation listener and emits the standard object-updated event with
 * old+new data, so `AnnotationNotificationListener` evaluates the declared
 * `calculatedChange` rules — zero new notification machinery (ADR-031:
 * behaviour stays declared on the schema; this job is generic clockwork any
 * future `now`-dependent schema inherits for free).
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Re-materialise `now`-dependent calculations for untouched objects.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The sweep composes the
 *   schema/register/object mappers, the calculation engine trio, and the
 *   object write path — each a distinct shipped collaborator.
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
 *   (Requirement: Time-dependent calculated fields re-evaluate without object writes)
 */
class TemporalCalculationSweepService {
	/**
	 * Constructor.
	 *
	 * @param SchemaMapper $schemaMapper Schema enumeration (system scope).
	 * @param RegisterMapper $registerMapper Register enumeration for schema→register resolution.
	 * @param MagicMapper $objectMapper Raw per-table object enumeration.
	 * @param CalculationEvaluator $evaluator Expression evaluator (exposes `now`).
	 * @param CalculationPayloadBuilder $payloadBuilder Shared @self/@ref/@aggregate payload prep.
	 * @param ObjectService $objectService The normal object write path (events + audit).
	 * @param LoggerInterface $logger Structured logging.
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly RegisterMapper $registerMapper,
		private readonly MagicMapper $objectMapper,
		private readonly CalculationEvaluator $evaluator,
		private readonly CalculationPayloadBuilder $payloadBuilder,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Run one sweep over every schema with `now`-dependent materialised
	 * calculations.
	 *
	 * @return array{schemasScanned: int, temporalSchemas: int, objectsEvaluated: int, objectsRewritten: int, errors: int} Sweep summary.
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
	 *   (Scenario: Untouched case crosses the reminder tier)
	 */
	public function runSweep(): array {
		$summary = [
			'schemasScanned' => 0,
			'temporalSchemas' => 0,
			'objectsEvaluated' => 0,
			'objectsRewritten' => 0,
			'errors' => 0,
		];

		$schemas = [];
		try {
			$schemas = $this->schemaMapper->findAll(_rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[TemporalCalculationSweep] schema enumeration failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			$summary['errors']++;
			return $summary;
		}

		$registers = $this->loadRegisters();

		foreach ($schemas as $schema) {
			$summary['schemasScanned']++;

			$calcs = $this->materialisedCalculations(schema: $schema);
			if ($calcs === [] || $this->hasTemporalCalculation(calculations: $calcs) === false) {
				// Schemas with no `now`-dependent materialised calculation are skipped entirely.
				continue;
			}

			$register = $this->registerOf(schema: $schema, registers: $registers);
			if ($register === null) {
				continue;
			}

			$summary['temporalSchemas']++;
			$this->sweepSchema(register: $register, schema: $schema, calcs: $calcs, summary: $summary);
		}//end foreach

		return $summary;
	}//end runSweep()

	/**
	 * Whether any materialised calculation references the evaluation clock.
	 *
	 * Detects both the `{"now": []}` operator anywhere in the expression tree
	 * and the literal `"now"` argument accepted by `dateDiff`-style
	 * operators.
	 *
	 * @param array<string, mixed> $calculations The schema's materialised calculations.
	 *
	 * @return bool True when at least one expression is time-dependent.
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
	 *   (Requirement: Time-dependent calculated fields re-evaluate without object writes)
	 */
	public function hasTemporalCalculation(array $calculations): bool {
		foreach ($calculations as $spec) {
			if (is_array($spec) === false) {
				continue;
			}

			if ($this->expressionReferencesNow(expression: ($spec['expression'] ?? null)) === true) {
				return true;
			}
		}

		return false;
	}//end hasTemporalCalculation()

	/**
	 * Sweep one register+schema pair: recompute the materialised calculations
	 * for every object in a non-terminal lifecycle state and rewrite only
	 * objects whose recomputed values changed.
	 *
	 * @param Register $register The owning register.
	 * @param Schema $schema The temporal schema.
	 * @param array<string, mixed> $calcs Materialised calculations.
	 * @param array<string, int> $summary Running summary counters (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
	 *   (Scenario: Tier crossing notifies exactly once)
	 */
	private function sweepSchema(Register $register, Schema $schema, array $calcs, array &$summary): void {
		try {
			$objects = $this->objectMapper->findAllInRegisterSchemaTable(register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[TemporalCalculationSweep] object enumeration failed for schema ' . (string)$schema->getSlug() . ': ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			$summary['errors']++;
			return;
		}

		[$lifecycleField, $terminalStates] = $this->lifecycleTerminals(schema: $schema);

		foreach ($objects as $object) {
			$data = ($object->getObject() ?? []);

			// Terminal cases are left alone (spec scenario) — their clock no
			// longer matters and rewriting them would churn finalised dossiers.
			if ($lifecycleField !== null) {
				$state = (string)($data[$lifecycleField] ?? '');
				if (in_array($state, $terminalStates, true) === true) {
					continue;
				}
			}

			$summary['objectsEvaluated']++;

			try {
				if ($this->recomputeChanges(object: $object, schema: $schema, calcs: $calcs) === false) {
					// Unchanged recomputation — no write, no duplicate notification.
					continue;
				}

				// Re-save the object UNCHANGED through the normal write path:
				// CalculationOnSaveListener re-materialises the calculations
				// (producing the same new values just detected) and the update
				// event carries old+new data, so the declared calculatedChange
				// rules fire exactly on the crossing. System sweep: RBAC and
				// tenancy scoping are bypassed deliberately (no user session);
				// the write is still audited through the normal path.
				$this->objectService->saveObject(
					object: $data,
					register: $register,
					schema: $schema,
					uuid: (string)$object->getUuid(),
					_rbac: false,
					_multitenancy: false
				);
				$summary['objectsRewritten']++;
			} catch (\Throwable $e) {
				$summary['errors']++;
				$this->logger->warning(
					message: '[TemporalCalculationSweep] recompute failed for object ' . (string)$object->getUuid() . ': ' . $e->getMessage(),
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
			}//end try
		}//end foreach

	}//end sweepSchema()

	/**
	 * Recompute the schema's materialised calculations for an object and
	 * report whether ANY value differs from the stored one.
	 *
	 * Evaluates sequentially in declaration order against the shared payload
	 * shape (`@self` / `@ref` / `@aggregate` injected), exactly like the
	 * save-time listener, so a calculation may reference an earlier one.
	 *
	 * @param ObjectEntity $object The object to probe.
	 * @param Schema $schema The schema declaring the calculations.
	 * @param array<string, mixed> $calcs Materialised calculations.
	 *
	 * @return bool True when at least one recomputed value changed.
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
	 *   (Scenario: Tier crossing notifies exactly once)
	 */
	private function recomputeChanges(ObjectEntity $object, Schema $schema, array $calcs): bool {
		$payload = $this->payloadBuilder->build(object: $object, schema: $schema);
		$changed = false;

		foreach ($calcs as $name => $spec) {
			// A `sequence` only resolves on the create path, where a
			// SequenceContext is active; the sweep never has one. Recomputing here
			// would render the node as "" and report a spurious change on every
			// pass (and, before openregister#3075, persist the truncated value).
			if ($this->evaluator->expressionUsesSequence($spec['expression'] ?? null) === true) {
				continue;
			}

			try {
				$value = $this->evaluator->evaluate($payload, $spec['expression'] ?? null);
			} catch (EvaluationException $e) {
				// Mirrors the save-time listener: a failing calculation is
				// logged and skipped, never fails the sweep.
				$this->logger->warning(
					message: sprintf(
						'[TemporalCalculationSweep] calculation "%s" failed on %s: %s',
						(string)$name,
						(string)$object->getUuid(),
						$e->getMessage()
					),
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
				continue;
			}

			if ($value instanceof \DateTimeInterface) {
				$value = $value->format(DATE_ATOM);
			}

			if (($payload[(string)$name] ?? null) !== $value) {
				$changed = true;
			}

			// Later calculations may reference this one's fresh value.
			$payload[(string)$name] = $value;
		}//end foreach

		return $changed;
	}//end recomputeChanges()

	/**
	 * The schema's materialised calculations (empty when none declared).
	 *
	 * @param Schema $schema The schema to inspect.
	 *
	 * @return array<string, mixed> Materialised calculation specs by name.
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
	 *   (Requirement: Time-dependent calculated fields re-evaluate without object writes)
	 */
	private function materialisedCalculations(Schema $schema): array {
		$config = ($schema->getConfiguration() ?? []);
		$calcs = ($config['x-openregister-calculations'] ?? null);
		if (is_array($calcs) === false) {
			return [];
		}

		$materialised = [];
		foreach ($calcs as $name => $spec) {
			if (is_array($spec) === true && ($spec['materialise'] ?? false) === true) {
				$materialised[(string)$name] = $spec;
			}
		}

		return $materialised;
	}//end materialisedCalculations()

	/**
	 * Recursively detect a reference to the evaluation clock in an
	 * expression tree: the `now` operator key or the literal string `"now"`
	 * (accepted by `dateDiff`'s from/to arguments).
	 *
	 * @param mixed $expression The (sub-)expression to scan.
	 *
	 * @return bool True when the expression references `now`.
	 */
	private function expressionReferencesNow(mixed $expression): bool {
		if (is_string($expression) === true) {
			return $expression === 'now';
		}

		if (is_array($expression) === false) {
			return false;
		}

		foreach ($expression as $key => $value) {
			if ($key === 'now') {
				return true;
			}

			if ($this->expressionReferencesNow(expression: $value) === true) {
				return true;
			}
		}

		return false;
	}//end expressionReferencesNow()

	/**
	 * The schema's lifecycle field + terminal (final) states, or [null, []]
	 * when the schema declares no usable lifecycle. Schemas without a
	 * lifecycle sweep ALL their objects (bounded by the unchanged-skip).
	 *
	 * @param Schema $schema The schema to inspect.
	 *
	 * @return array{0: string|null, 1: array<int, string>} Lifecycle field + terminal states.
	 */
	private function lifecycleTerminals(Schema $schema): array {
		$config = ($schema->getConfiguration() ?? []);
		$lifecycle = ($config['x-openregister-lifecycle'] ?? null);
		if (is_array($lifecycle) === false) {
			return [null, []];
		}

		$field = ($lifecycle['field'] ?? ($lifecycle['property'] ?? null));
		if (is_string($field) === false || $field === '') {
			return [null, []];
		}

		$final = ($lifecycle['final'] ?? []);
		if (is_array($final) === false) {
			$final = [];
		}

		return [$field, array_values(array_map('strval', $final))];
	}//end lifecycleTerminals()

	/**
	 * Load every register once (system scope), keyed for membership lookup.
	 *
	 * @return array<int, Register> All registers.
	 */
	private function loadRegisters(): array {
		try {
			return $this->registerMapper->findAll(_rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[TemporalCalculationSweep] register enumeration failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return [];
		}

	}//end loadRegisters()

	/**
	 * Find the register a schema belongs to (first register whose schema-id
	 * list contains it), or null for orphaned schemas.
	 *
	 * @param Schema $schema The schema.
	 * @param array<int, Register> $registers All registers.
	 *
	 * @return Register|null The owning register, or null.
	 */
	private function registerOf(Schema $schema, array $registers): ?Register {
		$schemaId = (int)$schema->getId();
		foreach ($registers as $register) {
			foreach (($register->getSchemas() ?? []) as $memberId) {
				if ((int)$memberId === $schemaId) {
					return $register;
				}
			}
		}

		return null;
	}//end registerOf()
}//end class
