<?php

/**
 * OpenRegister CalculationOnSaveListener
 *
 * Subscribes to ObjectCreatingEvent + ObjectUpdatingEvent. For each
 * `materialise: true` calculation declared on the schema, runs the
 * evaluator and patches the field into the object payload before
 * persistence.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
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

namespace OCA\OpenRegister\Listener;

use DateTimeInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\Support\CalculationOnSaveEngine;
use OCA\OpenRegister\Listener\Support\CalculationOnSaveLookups;
use OCA\OpenRegister\Listener\Support\CalculationSaveRun;
use OCA\OpenRegister\Service\Calculation\EvaluationException;
use OCA\OpenRegister\Service\Calculation\SequenceContext;
use OCA\OpenRegister\Service\Rules\RuleDescriptor;
use OCA\OpenRegister\Service\Rules\RuleRunRecorderInterface;
use OCA\OpenRegister\Service\Rules\RuleRunSubject;
use OCA\OpenRegister\Service\Rules\RuleTrace;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use OCA\OpenRegister\Service\Schemas\SchemaDeclarationValidators;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Materialises declared calculations into the object payload on create/update.
 *
 * Iteration order: declaration order in the annotation. A calculation
 * may reference another calculation declared earlier. The validator's
 * cycle check guarantees the graph is acyclic.
 *
 * @spec openspec/specs/computed-fields/spec.md
 *
 * @template-implements IEventListener<ObjectCreatingEvent|ObjectUpdatingEvent>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The listener legitimately wires the
 *   schema mapper, the pure evaluator, the cross-object reference resolver, the logger,
 *   and the two object/schema/event DB + event types it bridges; each is a distinct
 *   collaborator in the save-time materialisation pipeline and none can be folded away.
 */
class CalculationOnSaveListener implements IEventListener {
	/**
	 * Wire collaborators used to look up schema calculations.
	 *
	 * @param CalculationOnSaveLookups $lookups  Schema, register, counter and log.
	 * @param CalculationOnSaveEngine  $engine   Evaluator and payload builder.
	 * @param RuleRunRecorderInterface $ruleRuns Records each calculation's verdict for the rule inventory.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-2
	 * @spec openspec/changes/calc-engine-aggregate-reference/tasks.md#task-2
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function __construct(
		private readonly CalculationOnSaveLookups $lookups,
		private readonly CalculationOnSaveEngine $engine,
		private readonly RuleRunRecorderInterface $ruleRuns,
	) {
	}//end __construct()

	/**
	 * Run materialised calculations before the object is persisted.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->process(object: $event->getObject(), isUpdate: false);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->process(object: $event->getNewObject(), isUpdate: true);
			return;
		}
	}//end handle()

	/**
	 * Apply each materialised calculation to the object data.
	 *
	 * The evaluation payload is the object's data enriched with the synthetic
	 * `@self` system metadata, pre-resolved `@ref.<name>` cross-object
	 * references and pre-resolved `@aggregate.<name>` aggregate references,
	 * shared with the temporal re-evaluation sweep via CalculationPayloadBuilder
	 * so both paths evaluate against one payload shape. References are read as
	 * the system, bounded by the saving object's tenant (ReferenceTenantGuard),
	 * and never fail the save.
	 *
	 * The sequence-consumption context is built ONLY on create. Passing it to
	 * the evaluator lets `{ "sequence": … }` nodes reserve exactly one running
	 * number per object; on update the context is null so a re-materialise
	 * never burns a fresh number.
	 *
	 * @param ObjectEntity $object Object being created or updated.
	 * @param bool $isUpdate True when the object is being updated.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 * @spec openspec/changes/calculations-resolve-references-regardless-of-saver/specs/computed-fields/spec.md
	 */
	private function process(ObjectEntity $object, bool $isUpdate): void {
		$schema = $this->loadSchema(object: $object);
		$calcs = $this->getCalculations(schema: $schema);
		if ($calcs === null) {
			return;
		}

		$data = $this->engine->payloadBuilder->build(object: $object, schema: $schema);
		$sequenceContext = null;
		if ($isUpdate === false) {
			$sequenceContext = $this->buildSequenceContext(object: $object, schema: $schema);
		}

		$slug = (string)($schema->getSlug() ?? '');
		$run = new CalculationSaveRun(object: $object, slug: $slug, sequenceContext: $sequenceContext, payload: $data);
		$this->applyAll(run: $run, calcs: $calcs);
	}//end process()

	/**
	 * Apply every calculation in declaration order, then write the payload back.
	 *
	 * The synthetic @self, @ref and @aggregate are stripped before persisting;
	 * they are a runtime aid for the evaluator, not user data.
	 *
	 * @param CalculationSaveRun   $run   The pass.
	 * @param array<string, mixed> $calcs The declared calculations.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	private function applyAll(CalculationSaveRun $run, array $calcs): void {
		foreach ($calcs as $name => $spec) {
			$this->applyOne(run: $run, name: (string)$name, spec: $spec);
		}

		$data = $this->engine->payloadBuilder->stripSyntheticKeys(data: $run->payload());
		if ($run->changed() === true) {
			$run->object->setObject($data);
		}
	}//end applyAll()

	/**
	 * Apply one declared calculation, when it is due.
	 *
	 * @param CalculationSaveRun $run  The pass.
	 * @param string             $name The calculation's name.
	 * @param mixed              $spec Its declaration.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	private function applyOne(CalculationSaveRun $run, string $name, mixed $spec): void {
		if ($this->isDue(spec: $spec, sequenceContext: $run->sequenceContext) === false) {
			return;
		}

		if ($this->keptForUnresolvedReference(run: $run, name: $name, expression: ($spec['expression'] ?? null)) === true) {
			return;
		}

		$this->evaluateInto(run: $run, name: $name, expression: ($spec['expression'] ?? null));
	}//end applyOne()

	/**
	 * Whether a declaration is a materialised calculation this save evaluates.
	 *
	 * A calculation switched off from the rule inventory is not evaluated and
	 * writes nothing: the switch has to bite HERE, at the one place a
	 * materialised calculation is applied, or it is a control that reports a
	 * state it does not cause.
	 *
	 * @param mixed                $spec            The declaration.
	 * @param SequenceContext|null $sequenceContext The create-only context.
	 *
	 * @return bool True when it is evaluated.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	private function isDue(mixed $spec, ?SequenceContext $sequenceContext): bool {
		if (is_array($spec) === false || ($spec['enabled'] ?? true) === false) {
			return false;
		}

		if (($spec['materialise'] ?? false) !== true) {
			return false;
		}

		return $this->keepsStoredNumber(expression: ($spec['expression'] ?? null), sequenceContext: $sequenceContext) === false;
	}//end isDue()

	/**
	 * Whether an update must leave a sequence-bearing calculation alone.
	 *
	 * A `sequence` reserves its number exactly once, on create, through the
	 * SequenceContext. On update the context is deliberately null, but the
	 * evaluator then resolves the `sequence` node to null and `concat` renders
	 * that as "", so re-materialising would OVERWRITE the number assigned at
	 * create ("2026-0013" -> "2026-"). The field is skipped and the stored
	 * value left untouched. openregister#3075.
	 *
	 * @param mixed                $expression      The calculation's expression.
	 * @param SequenceContext|null $sequenceContext The create-only context.
	 *
	 * @return bool True when the stored value is kept.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	private function keepsStoredNumber(mixed $expression, ?SequenceContext $sequenceContext): bool {
		return $sequenceContext === null
			&& $this->engine->evaluator->expressionUsesSequence($expression) === true;
	}//end keepsStoredNumber()

	/**
	 * Keep the stored value when a reference the expression reads came back empty.
	 *
	 * A calculation that reads a reference which had something to resolve and
	 * still came back empty (target missing, outside the tenant, read failed)
	 * would evaluate against nothing and write null over a good stored value.
	 * The stored value is kept and the rule run log says why.
	 *
	 * @param CalculationSaveRun $run        The pass.
	 * @param string             $name       The calculation's name.
	 * @param mixed              $expression Its expression.
	 *
	 * @return bool True when the calculation is skipped.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	private function keptForUnresolvedReference(CalculationSaveRun $run, string $name, mixed $expression): bool {
		$unresolved = $this->engine->payloadBuilder->unresolvedReferencesUsedBy(
			payload: $run->payload(),
			expression: $expression
		);
		if ($unresolved === []) {
			return false;
		}

		$message = sprintf('Reference %s could not be resolved; the stored value was kept.', implode(', ', $unresolved));
		$this->recordRun(run: $run, name: $name, trace: new RuleTrace(verdict: RuleVocabulary::VERDICT_ERROR, message: $message));
		return true;
	}//end keptForUnresolvedReference()

	/**
	 * Evaluate one calculation and write its value into the payload.
	 *
	 * @param CalculationSaveRun $run        The pass.
	 * @param string             $name       The calculation's name.
	 * @param mixed              $expression Its expression.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	private function evaluateInto(CalculationSaveRun $run, string $name, mixed $expression): void {
		try {
			$value = $this->engine->evaluator->evaluate($run->payload(), $expression, $run->sequenceContext);
		} catch (EvaluationException $e) {
			$this->lookups->logger->warning(
				sprintf('Calculation "%s" failed on %s: %s', $name, (string)$run->object->getUuid(), $e->getMessage())
			);
			$this->recordRun(run: $run, name: $name, trace: new RuleTrace(verdict: RuleVocabulary::VERDICT_ERROR, message: $e->getMessage()));
			return;
		}

		$this->recordRun(run: $run, name: $name, trace: new RuleTrace(verdict: RuleVocabulary::VERDICT_FIRED));
		$run->write(name: $name, value: $this->serialise(value: $value));
	}//end evaluateInto()

	/**
	 * Record one calculation's verdict against the rule run log.
	 *
	 * A calculation has no condition, so it reaches only two of the four
	 * verdicts: it evaluated, or it could not be evaluated. There is no
	 * `no_match` to reach and inventing one would put a verdict in the log
	 * that the engine can never mean.
	 *
	 * @param CalculationSaveRun $run   The pass (object and schema slug).
	 * @param string             $name  The calculation's name.
	 * @param RuleTrace          $trace The verdict this evaluation reached.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) RuleDescriptor::idFor is the published
	 *   derivation of a rule id; a descriptor built here would carry a declaration this
	 *   path has no use for.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function recordRun(CalculationSaveRun $run, string $name, RuleTrace $trace): void {
		if ($run->slug === '') {
			return;
		}

		$this->ruleRuns->record(
			subject: new RuleRunSubject(
				ruleId: RuleDescriptor::idFor(kind: RuleVocabulary::KIND_CALCULATION, schemaSlug: $run->slug, key: $name),
				schemaSlug: $run->slug,
				objectUuid: ($run->object->getUuid() ?? null),
				registerSlug: ($run->object->getRegister() ?? null)
			),
			trace: $trace
		);
	}//end recordRun()

	/**
	 * Build the per-create SequenceContext binding the object's register + schema scope.
	 *
	 * Returns null when the numeric register/schema ids cannot be resolved, in
	 * which case a `sequence` node simply yields null rather than failing the
	 * save. The schema is already resolved (it carries the numeric PK); the
	 * register reference is resolved through the RegisterMapper.
	 *
	 * @param ObjectEntity $object The object being created.
	 * @param Schema $schema The resolved schema (carries the numeric id).
	 *
	 * @return SequenceContext|null The bound context, or null when the scope ids are unresolvable.
	 */
	private function buildSequenceContext(ObjectEntity $object, Schema $schema): ?SequenceContext {
		$schemaId = (int)$schema->getId();
		$registerId = 0;
		if ($schemaId > 0) {
			$registerId = $this->registerIdOf(object: $object);
		}

		if ($registerId <= 0) {
			return null;
		}

		return new SequenceContext(service: $this->lookups->sequences, registerId: $registerId, schemaId: $schemaId);
	}//end buildSequenceContext()

	/**
	 * The numeric id of the object's register, or 0 when it cannot be resolved.
	 *
	 * RBAC and multitenancy are bypassed: the create event fires in a context
	 * that may have no active organisation, so the default tenant-scoped find()
	 * would not resolve a register referenced purely by its numeric id and the
	 * `sequence` node would silently yield null.
	 *
	 * @param ObjectEntity $object The object being created.
	 *
	 * @return int The register id, or 0.
	 */
	private function registerIdOf(ObjectEntity $object): int {
		$registerRef = $object->getRegister();
		if ($registerRef === null || $registerRef === '') {
			return 0;
		}

		try {
			return (int)$this->lookups->registerMapper->find($registerRef, false, false)->getId();
		} catch (Throwable $e) {
			$this->lookups->logger->warning(
				sprintf('Sequence context: could not resolve register "%s": %s', (string)$registerRef, $e->getMessage())
			);
			return 0;
		}
	}//end registerIdOf()

	/**
	 * Render a calculation result into a JSON-friendly value.
	 *
	 * @param mixed $value Raw value returned by the evaluator.
	 *
	 * @return mixed JSON-serialisable representation of the value.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	private function serialise(mixed $value): mixed {
		if ($value instanceof DateTimeInterface) {
			return $value->format(DATE_ATOM);
		}

		return $value;
	}//end serialise()

	/**
	 * Look up the schema referenced by an object instance.
	 *
	 * @param ObjectEntity $object Object whose schema reference to resolve.
	 *
	 * @return Schema|null Resolved schema, or null on lookup failure.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	private function loadSchema(ObjectEntity $object): ?Schema {
		$ref = $object->getSchema();
		if ($ref === null || $ref === '') {
			return null;
		}

		try {
			return $this->lookups->schemaMapper->find($ref, _multitenancy: false);
		} catch (Throwable $e) {
			return null;
		}
	}//end loadSchema()

	/**
	 * Read every declared calculation: the `x-openregister-calculations`
	 * configuration block, plus the `calculation` key on each property.
	 *
	 * A `calculation` key a property form forwarded materialises through
	 * exactly this path, so an authored expression and a hand-written
	 * annotation produce the same stored value.
	 *
	 * @param Schema|null $schema Schema to inspect, or null when none was found.
	 *
	 * @return array<string, mixed>|null Calculations map, or null when nothing is declared.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	private function getCalculations(?Schema $schema): ?array {
		if ($schema === null) {
			return null;
		}

		$config = ($schema->getConfiguration() ?? []);
		$merged = SchemaDeclarationValidators::propertyCalculations()->merge(
			annotation: $this->arrayOrEmpty(value: ($config['x-openregister-calculations'] ?? [])),
			properties: $this->arrayOrEmpty(value: ($schema->getProperties() ?? []))
		);
		if ($merged === []) {
			return null;
		}

		return $merged;
	}//end getCalculations()

	/**
	 * The value when it is an array, else the empty array.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<mixed>
	 */
	private function arrayOrEmpty(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end arrayOrEmpty()
}//end class
