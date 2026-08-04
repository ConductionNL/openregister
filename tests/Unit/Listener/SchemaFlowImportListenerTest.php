<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Listener\SchemaFlowImportListener;
use PHPUnit\Framework\TestCase;

/**
 * `x-openregister-flows` materialises into the flow store on schema save.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */
class SchemaFlowImportListenerTest extends TestCase
{
    /**
     * @var array<int, Flow>
     */
    private array $inserted = [];

    /**
     * @var array<int, Flow>
     */
    private array $updated = [];

    /**
     * Build the listener over a store pre-loaded with $existing.
     *
     * @param array<int, Flow> $existing Flows already in the store.
     *
     * @return SchemaFlowImportListener The listener.
     */
    private function listener(array $existing = []): SchemaFlowImportListener
    {
        $this->inserted = [];
        $this->updated  = [];

        $mapper = $this->createMock(FlowMapper::class);
        $mapper->method('findAllFlows')->willReturn($existing);
        $mapper->method('insert')->willReturnCallback(function (Flow $f): Flow {
            $this->inserted[] = $f;
            return $f;
        });
        $mapper->method('update')->willReturnCallback(function (Flow $f): Flow {
            $this->updated[] = $f;
            return $f;
        });

        return new SchemaFlowImportListener($mapper, new \Psr\Log\NullLogger());
    }

    /**
     * A schema carrying the given declarations.
     *
     * @param array<int, mixed> $declared The declared flows.
     *
     * @return Schema The schema.
     */
    private function schema(array $declared): Schema
    {
        $schema = new Schema();
        $schema->setSlug('case');
        $schema->setConfiguration(['x-openregister-flows' => $declared]);

        return $schema;
    }

    private function fire(SchemaFlowImportListener $listener, Schema $schema): void
    {
        $listener->handle(new SchemaCreatedEvent($schema));
    }

    public function testADeclaredFlowIsImported(): void
    {
        $listener = $this->listener();
        $this->fire($listener, $this->schema([
            ['name' => 'Triage', 'trigger' => 'object.created', 'nodes' => [['id' => 'a']], 'edges' => []],
        ]));

        $this->assertCount(1, $this->inserted);
        $this->assertSame('Triage', $this->inserted[0]->getName());
        $this->assertSame('case', $this->inserted[0]->getTriggerSchema());
        $this->assertSame('object.created', $this->inserted[0]->getTrigger());
    }

    /**
     * THE SAFETY PROPERTY. A schema save is not a person volunteering to run a
     * graph as themselves, so a declared flow arrives inert.
     */
    public function testADeclaredFlowArrivesDisabledAndOwnerless(): void
    {
        $listener = $this->listener();
        $this->fire($listener, $this->schema([
            ['name' => 'Triage', 'enabled' => true, 'owner' => 'admin', 'nodes' => [], 'edges' => []],
        ]));

        $flow = $this->inserted[0];
        $this->assertFalse((bool) $flow->getEnabled(), 'a declaration must not arrive enabled');
        $this->assertNull($flow->getOwner(), 'a declaration must not name its own owner');
        $this->assertFalse($flow->canDispatch());
    }

    /**
     * A re-import UPDATES rather than duplicating — a declaration carries no
     * uuid, so identity is (app, name, trigger schema).
     */
    public function testAReimportUpdatesRatherThanDuplicating(): void
    {
        $existing = new Flow();
        $existing->setUuid('u1');
        $existing->setApp('openregister');
        $existing->setName('Triage');
        $existing->setTriggerSchema('case');
        $existing->setEnabled(true);
        $existing->setOwner('alice');

        $listener = $this->listener([$existing]);
        $this->fire($listener, $this->schema([
            ['name' => 'Triage', 'nodes' => [['id' => 'b']], 'edges' => []],
        ]));

        $this->assertCount(0, $this->inserted, 'a second copy must not be created');
        $this->assertCount(1, $this->updated);
        $this->assertSame([['id' => 'b']], $this->updated[0]->getNodes(), 'the graph is refreshed');
    }

    /**
     * An app upgrade must not silently re-enable a flow an administrator turned
     * off, nor re-point whose identity it runs as.
     */
    public function testAReimportDoesNotResurrectEnabledOrOwner(): void
    {
        $existing = new Flow();
        $existing->setUuid('u1');
        $existing->setApp('openregister');
        $existing->setName('Triage');
        $existing->setTriggerSchema('case');
        $existing->setEnabled(false);
        $existing->setOwner('alice');

        $listener = $this->listener([$existing]);
        $this->fire($listener, $this->schema([
            ['name' => 'Triage', 'enabled' => true, 'owner' => 'mallory', 'nodes' => [], 'edges' => []],
        ]));

        $this->assertFalse((bool) $this->updated[0]->getEnabled(), 'an upgrade must not re-enable it');
        $this->assertSame('alice', $this->updated[0]->getOwner(), 'an upgrade must not re-point the owner');
    }

    public function testASchemaWithNoDeclarationImportsNothing(): void
    {
        $listener = $this->listener();
        $schema   = new Schema();
        $schema->setSlug('case');
        $schema->setConfiguration([]);

        $this->fire($listener, $schema);

        $this->assertSame([], $this->inserted);
    }

    /**
     * One malformed declaration must not stop its siblings arriving, nor fail
     * the schema save it rode in on.
     */
    public function testAMalformedDeclarationIsSkippedNotFatal(): void
    {
        $listener = $this->listener();
        $this->fire($listener, $this->schema([
            ['trigger' => 'object.created'],
            ['name' => 'Good', 'nodes' => [], 'edges' => []],
        ]));

        $this->assertCount(1, $this->inserted);
        $this->assertSame('Good', $this->inserted[0]->getName());
    }
}
