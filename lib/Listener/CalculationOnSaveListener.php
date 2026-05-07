<?php

/**
 * OpenRegister CalculationOnSaveListener
 *
 * Subscribes to ObjectCreatingEvent + ObjectUpdatingEvent. For each
 * `materialise: true` calculation declared on the schema, runs the
 * evaluator and patches the field into the object payload before
 * persistence.
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
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\EvaluationException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Materialises declared calculations into the object payload on create/update.
 *
 * Iteration order: declaration order in the annotation. A calculation
 * may reference another calculation declared earlier. The validator's
 * cycle check guarantees the graph is acyclic.
 *
 * @template-implements IEventListener<ObjectCreatingEvent|ObjectUpdatingEvent>
 */
class CalculationOnSaveListener implements IEventListener
{
    /**
     * Wire collaborators used to look up schema calculations.
     *
     * @param SchemaMapper         $schemaMapper Schema lookup mapper.
     * @param CalculationEvaluator $evaluator    Expression evaluator.
     * @param LoggerInterface      $logger       PSR logger for warnings.
     *
     * @return void
     */
    public function __construct(
        private readonly SchemaMapper $schemaMapper,
        private readonly CalculationEvaluator $evaluator,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Run materialised calculations before the object is persisted.
     *
     * @param Event $event Inbound dispatcher event.
     *
     * @return void
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
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
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

        $data    = $object->getObject() ?? [];
        $changed = false;

        // Inject `@self` system metadata so calculations can reference
        // `@self.created`, `@self.updated`, etc. via the CalculationEvaluator's
        // dotted prop path. ObjectEntity carries these on the entity itself,
        // not in the data array.
        $created       = $object->getCreated();
        $updated       = $object->getUpdated();
        $data['@self'] = [
            'id'       => $object->getUuid(),
            'uuid'     => $object->getUuid(),
            'register' => $object->getRegister(),
            'schema'   => $object->getSchema(),
            'owner'    => $object->getOwner(),
            'created'  => $created !== null ? $created->format(\DateTimeInterface::ATOM) : null,
            'updated'  => $updated !== null ? $updated->format(\DateTimeInterface::ATOM) : null,
        ];

        foreach ($calcs as $name => $spec) {
            if (is_array($spec) === false) {
                continue;
            }

            $materialise = ($spec['materialise'] ?? false);
            if ($materialise !== true) {
                continue;
            }

            try {
                $value = $this->evaluator->evaluate($data, $spec['expression'] ?? null);
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

        // Strip the synthetic @self before persisting; it's a runtime aid
        // for the evaluator, not user data.
        unset($data['@self']);

        if ($changed === true) {
            $object->setObject($data);
        }
    }//end process()

    /**
     * Render a calculation result into a JSON-friendly value.
     *
     * @param mixed $value Raw value returned by the evaluator.
     *
     * @return mixed JSON-serialisable representation of the value.
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
     */
    private function getCalculations(Schema $schema): ?array
    {
        $config = ($schema->getConfiguration() ?? []);
        $value  = ($config['x-openregister-calculations'] ?? null);
        return is_array($value) === true ? $value : null;
    }//end getCalculations()
}//end class
