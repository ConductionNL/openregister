<?php

/**
 * OpenRegister LifecycleInitialStateListener
 *
 * Subscribes to ObjectCreatingEvent and force-sets the lifecycle field
 * to the schema's declared `initial` value when the caller did not
 * supply a value. Apps don't need to remember to set it.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Force the lifecycle field to its declared initial state on create.
 *
 * Matches the principle that lifecycle is a declarative property of the
 * schema — apps shouldn't need to know the initial state.
 *
 * @template-implements IEventListener<ObjectCreatingEvent>
 */
class LifecycleInitialStateListener implements IEventListener
{
    /**
     * Wire collaborators used to look up schema lifecycle metadata.
     *
     * @param SchemaMapper    $schemaMapper Schema lookup mapper.
     * @param LoggerInterface $logger       PSR logger for warnings.
     *
     * @return void
     */
    public function __construct(
        private readonly SchemaMapper $schemaMapper,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Apply the schema-declared initial lifecycle value when missing.
     *
     * @param Event $event Inbound dispatcher event.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectCreatingEvent) === false) {
            return;
        }

        $object = $event->getObject();
        $schema = $this->loadSchema(object: $object);
        if ($schema === null) {
            return;
        }

        $annotation = $this->getLifecycleAnnotation(schema: $schema);
        if ($annotation === null) {
            return;
        }

        $field   = (string) ($annotation['field'] ?? '');
        $initial = (string) ($annotation['initial'] ?? '');
        if ($field === '' || $initial === '') {
            return;
        }

        $data = $object->getObject() ?? [];

        // Caller already set a value — leave it alone (validator covers it).
        if (isset($data[$field]) === true && $data[$field] !== null && $data[$field] !== '') {
            return;
        }

        $data[$field] = $initial;
        $object->setObject($data);
    }//end handle()

    /**
     * Look up the schema referenced by an object instance.
     *
     * @param ObjectEntity $object Object whose schema reference to resolve.
     *
     * @return Schema|null Resolved schema, or null on lookup failure.
     */
    private function loadSchema(ObjectEntity $object): ?Schema
    {
        $schemaRef = $object->getSchema();
        if ($schemaRef === null || $schemaRef === '') {
            return null;
        }

        try {
            return $this->schemaMapper->find($schemaRef, _multitenancy: false);
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf(
                    'Lifecycle initial-state listener could not load schema "%s": %s',
                    (string) $schemaRef,
                    $e->getMessage()
                )
            );
            return null;
        }
    }//end loadSchema()

    /**
     * Read the `x-openregister-lifecycle` configuration block.
     *
     * @param Schema $schema Schema to inspect.
     *
     * @return array<string, mixed>|null Lifecycle annotation, or null when missing.
     */
    private function getLifecycleAnnotation(Schema $schema): ?array
    {
        $config     = ($schema->getConfiguration() ?? []);
        $annotation = ($config['x-openregister-lifecycle'] ?? null);
        return is_array($annotation) === true ? $annotation : null;
    }//end getLifecycleAnnotation()
}//end class
