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
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\CalculationPayloadBuilder;
use OCA\OpenRegister\Service\Calculation\EvaluationException;
use OCA\OpenRegister\Service\Calculation\SequenceContext;
use OCA\OpenRegister\Service\SequenceService;
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
 * @template-implements IEventListener<ObjectCreatingEvent|ObjectUpdatingEvent>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The listener legitimately wires the
 *   schema mapper, the pure evaluator, the cross-object reference resolver, the logger,
 *   and the two object/schema/event DB + event types it bridges; each is a distinct
 *   collaborator in the save-time materialisation pipeline and none can be folded away.
 */
class CalculationOnSaveListener implements IEventListener
{
    /**
     * Wire collaborators used to look up schema calculations.
     *
     * @param SchemaMapper              $schemaMapper   Schema lookup mapper.
     * @param RegisterMapper            $registerMapper Register lookup mapper (for sequence scope id).
     * @param CalculationEvaluator      $evaluator      Expression evaluator.
     * @param CalculationPayloadBuilder $payloadBuilder Shared @self/@ref/@aggregate payload prep.
     * @param SequenceService           $sequences      Atomic running-number reservation service.
     * @param LoggerInterface           $logger         PSR logger for warnings.
     *
     * @return void
     *
     * @spec openspec/specs/computed-fields/spec.md
     * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-2
     * @spec openspec/changes/calc-engine-aggregate-reference/tasks.md#task-2
     */
    public function __construct(
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly CalculationEvaluator $evaluator,
        private readonly CalculationPayloadBuilder $payloadBuilder,
        private readonly SequenceService $sequences,
        private readonly LoggerInterface $logger
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
    public function handle(Event $event): void
    {
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
     * @param ObjectEntity $object   Object being created or updated.
     * @param bool         $isUpdate True when the object is being updated.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) The method runs the linear save-time
     *   materialisation pipeline (inject @self, @ref, @aggregate, then evaluate each calc and
     *   strip the synthetic keys); the steps share one payload and must stay in order, so
     *   extracting them would only scatter a strictly-sequential flow across helpers.
     *
     * @spec openspec/specs/computed-fields/spec.md
     */
    private function process(ObjectEntity $object, bool $isUpdate): void
    {
        $schema = $this->loadSchema(object: $object);
        if ($schema === null) {
            return;
        }

        $calcs = $this->getCalculations(schema: $schema);
        if ($calcs === null) {
            return;
        }

        $changed = false;

        // Build the evaluation payload — the object's data enriched with the
        // synthetic `@self` system metadata, pre-resolved `@ref.<name>`
        // cross-object references, and pre-resolved `@aggregate.<name>`
        // aggregate references. Shared with the temporal re-evaluation sweep
        // via CalculationPayloadBuilder so both paths evaluate against one
        // payload shape. Resolution is RBAC + tenant scoped and never fails
        // the save.
        $data = $this->payloadBuilder->build(object: $object, schema: $schema);

        // Build the sequence-consumption context ONLY on create. Passing it to
        // the evaluator lets `{ "sequence": … }` nodes reserve exactly one
        // running number per object; on update the context is null so a
        // re-materialise never burns a fresh number.
        $sequenceContext = null;
        if ($isUpdate === false) {
            $sequenceContext = $this->buildSequenceContext(object: $object, schema: $schema);
        }

        foreach ($calcs as $name => $spec) {
            if (is_array($spec) === false) {
                continue;
            }

            $materialise = ($spec['materialise'] ?? false);
            if ($materialise !== true) {
                continue;
            }

            try {
                $value = $this->evaluator->evaluate($data, $spec['expression'] ?? null, $sequenceContext);
            } catch (EvaluationException $e) {
                $this->logger->warning(
                    sprintf(
                        'Calculation "%s" failed on %s: %s',
                        (string) $name,
                        (string) $object->getUuid(),
                        $e->getMessage()
                    )
                );
                continue;
            }

            $serialised = $this->serialise(value: $value);
            if (($data[(string) $name] ?? null) !== $serialised) {
                $data[(string) $name] = $serialised;
                $changed = true;
            }
        }//end foreach

        // Strip the synthetic @self, @ref and @aggregate before persisting;
        // they're a runtime aid for the evaluator, not user data.
        $data = $this->payloadBuilder->stripSyntheticKeys(data: $data);

        if ($changed === true) {
            $object->setObject($data);
        }
    }//end process()

    /**
     * Build the per-create SequenceContext binding the object's register + schema scope.
     *
     * Returns null when the numeric register/schema ids cannot be resolved, in
     * which case a `sequence` node simply yields null rather than failing the
     * save. The schema is already resolved (it carries the numeric PK); the
     * register reference is resolved through the RegisterMapper.
     *
     * @param ObjectEntity $object The object being created.
     * @param Schema       $schema The resolved schema (carries the numeric id).
     *
     * @return SequenceContext|null The bound context, or null when the scope ids are unresolvable.
     */
    private function buildSequenceContext(ObjectEntity $object, Schema $schema): ?SequenceContext
    {
        $schemaId = (int) $schema->getId();
        if ($schemaId <= 0) {
            return null;
        }

        $registerRef = $object->getRegister();
        if ($registerRef === null || $registerRef === '') {
            return null;
        }

        try {
            // Bypass RBAC + multitenancy: the create event fires in a context
            // that may have no active organisation, so the default tenant-scoped
            // find() would not resolve a register referenced purely by its
            // numeric id and the `sequence` node would silently yield null.
            $register   = $this->registerMapper->find($registerRef, false, false);
            $registerId = (int) $register->getId();
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Sequence context: could not resolve register "%s": %s', (string) $registerRef, $e->getMessage())
            );
            return null;
        }

        if ($registerId <= 0) {
            return null;
        }

        return new SequenceContext(service: $this->sequences, registerId: $registerId, schemaId: $schemaId);
    }//end buildSequenceContext()

    /**
     * Render a calculation result into a JSON-friendly value.
     *
     * @param mixed $value Raw value returned by the evaluator.
     *
     * @return mixed JSON-serialisable representation of the value.
     *
     * @spec openspec/specs/computed-fields/spec.md
     */
    private function serialise(mixed $value): mixed
    {
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
    private function loadSchema(ObjectEntity $object): ?Schema
    {
        $ref = $object->getSchema();
        if ($ref === null || $ref === '') {
            return null;
        }

        try {
            return $this->schemaMapper->find($ref, _multitenancy: false);
        } catch (\Throwable $e) {
            return null;
        }
    }//end loadSchema()

    /**
     * Read the `x-openregister-calculations` configuration block.
     *
     * @param Schema $schema Schema to inspect.
     *
     * @return array<string, mixed>|null Calculations map, or null when absent.
     *
     * @spec openspec/specs/computed-fields/spec.md
     */
    private function getCalculations(Schema $schema): ?array
    {
        $config = ($schema->getConfiguration() ?? []);
        $value  = ($config['x-openregister-calculations'] ?? null);
        $result = null;
        if (is_array($value) === true) {
            $result = $value;
        }

        return $result;
    }//end getCalculations()
}//end class
