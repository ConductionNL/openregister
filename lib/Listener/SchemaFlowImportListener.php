<?php

/**
 * Materialises a schema's declared flows into the flow store.
 *
 * `x-openregister-flows` is how an app SHIPS a flow in its register file, the
 * same way it ships a lifecycle or a notification (ADR-031). Every other
 * `x-openregister-*` extension is read from the schema at runtime, so declaring
 * it is enough. Flows are different: they live in their own table, because that
 * is what gives them run history, retention, oversight and a builder. So a
 * declaration has to be IMPORTED rather than merely read.
 *
 * Importing on schema save — rather than only on register import — means a flow
 * declared by an app and a flow authored in the builder end up in exactly the
 * same place, and a register re-import updates rather than duplicates.
 *
 * A declared flow arrives DISABLED and OWNERLESS. A schema save is not a person
 * volunteering to run a graph as themselves: the acting user may be an admin
 * doing an upgrade, and a flow's owner is the identity its runs execute as.
 * Adopting a flow is a deliberate act, so it is stored, visible and inert until
 * somebody makes it theirs.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use InvalidArgumentException;
use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Imports `x-openregister-flows` declarations into the flow store.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */
class SchemaFlowImportListener implements IEventListener
{
    /**
     * The configuration key carrying declared flows.
     *
     * @var string
     */
    public const ANNOTATION_KEY = 'x-openregister-flows';

    /**
     * Constructor.
     *
     * @param FlowMapper      $flows  The flow store.
     * @param LoggerInterface $logger Records what was imported, and what could not be.
     */
    public function __construct(
        private readonly FlowMapper $flows,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * Import on schema create and update.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
     */
    public function handle(Event $event): void
    {
        $schema = null;
        if ($event instanceof SchemaCreatedEvent) {
            $schema = $event->getSchema();
        }

        if ($event instanceof SchemaUpdatedEvent) {
            $schema = $event->getNewSchema();
        }

        if ($schema === null) {
            return;
        }

        $this->importFor(schema: $schema);

    }//end handle()

    /**
     * Import every flow this schema declares.
     *
     * A declaration that fails is logged and SKIPPED rather than aborting the
     * rest: a schema save must not fail because one of its flows is malformed,
     * and one bad flow must not stop its siblings from arriving.
     *
     * @param Schema $schema The schema that was saved.
     *
     * @return void
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
     */
    private function importFor(Schema $schema): void
    {
        $declared = (($schema->getConfiguration() ?? [])[self::ANNOTATION_KEY] ?? null);
        if (is_array($declared) === false || $declared === []) {
            return;
        }

        $schemaSlug = (string) $schema->getSlug();

        foreach ($declared as $declaration) {
            if (is_array($declaration) === false) {
                continue;
            }

            try {
                $this->upsert(declaration: $declaration, schemaSlug: $schemaSlug);
            } catch (Throwable $e) {
                $this->logger->warning(
                    message: '[SchemaFlowImport] Could not import a declared flow on schema "'
                        .$schemaSlug.'": '.$e->getMessage(),
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
            }
        }

    }//end importFor()

    /**
     * Create or update the stored flow for one declaration.
     *
     * Identity is (app, name, trigger schema). A register re-import must UPDATE
     * the flow it shipped last time rather than adding a second copy — the
     * declaration has no uuid to match on, because the app author does not mint
     * one and two instances importing the same register must not collide on it.
     *
     * `enabled` and `owner` are NOT taken from the declaration on update, so an
     * app upgrade cannot silently re-enable a flow an administrator switched
     * off, nor re-point whose identity it runs as.
     *
     * @param array<string, mixed> $declaration The declared flow.
     * @param string               $schemaSlug  The declaring schema's slug.
     *
     * @return void
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
     */
    private function upsert(array $declaration, string $schemaSlug): void
    {
        $name = trim((string) ($declaration['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('a declared flow needs a name');
        }

        $app = (string) ($declaration['app'] ?? 'openregister');

        $existing = null;
        foreach ($this->flows->findAllFlows(app: $app, limit: 500) as $candidate) {
            if ($candidate->getName() === $name && (string) $candidate->getTriggerSchema() === $schemaSlug) {
                $existing = $candidate;
                break;
            }
        }

        $flow = ($existing ?? new Flow());

        if ($existing === null) {
            $flow->setUuid($this->newUuid());
            $flow->setApp($app);
            $flow->setName($name);
            $flow->setTriggerSchema($schemaSlug);
            $flow->setCreated(new DateTime());

            // Inert until adopted. See the class docblock.
            $flow->setEnabled(false);
            $flow->setOwner(null);
        }

        $flow->setDescription(($declaration['description'] ?? null));
        $flow->setTrigger(($declaration['trigger'] ?? null));
        $flow->setTriggerRegister(($declaration['triggerRegister'] ?? null));
        $flow->setCron(($declaration['cron'] ?? null));
        $flow->setExecutionMode((string) ($declaration['executionMode'] ?? Flow::MODE_ASYNC));
        $flow->setNodes((array) ($declaration['nodes'] ?? []));
        $flow->setEdges((array) ($declaration['edges'] ?? []));
        $flow->setLimits((array) ($declaration['limits'] ?? []));
        $flow->setUpdated(new DateTime());

        if ($existing === null) {
            $this->flows->insert($flow);
            $this->logger->info(
                message: '[SchemaFlowImport] Imported declared flow "'.$name.'" for schema "'.$schemaSlug
                    .'" (disabled until adopted).'
            );
            return;
        }

        $this->flows->update($flow);

    }//end upsert()

    /**
     * Mint a v4 uuid.
     *
     * @return string The uuid.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
     */
    private function newUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

    }//end newUuid()
}//end class
