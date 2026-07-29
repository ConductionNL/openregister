<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Config;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Config\Types\FlowShareableConfigType;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FlowShareableConfigTypeTest extends TestCase
{
    private ObjectService&MockObject $objects;

    private FlowShareableConfigType $type;

    protected function setUp(): void
    {
        $this->objects = $this->createMock(ObjectService::class);
        $config        = $this->createMock(IAppConfig::class);
        $config->method('getValueString')->willReturnCallback(fn ($a, $k, $d = '') => $d);

        $this->type = new FlowShareableConfigType($this->objects, $config);
    }

    private function flowObject(): ObjectEntity
    {
        $o = new ObjectEntity();
        $o->setUuid('flow-1');
        $o->setObject([
            'id'           => 99,
            'uuid'         => 'flow-1',
            'owner'        => 'admin',
            'organisation' => 'org-x',
            'name'         => 'My flow',
            'trigger'      => 'manual',
            'nodes'        => [['id' => 'a']],
            'edges'        => [['id' => 's1', 'from' => 'a', 'to' => 'b']],
        ]);
        return $o;
    }

    public function testTheTypeIdentity(): void
    {
        $this->assertSame('openregister.flows', $this->type->getId());
        $this->assertSame('openregister-flow', $this->type->getTopic());
    }

    public function testSerialiseKeepsPortableFieldsAndDropsInstanceFields(): void
    {
        $this->objects->method('find')->willReturn($this->flowObject());

        $bundle = $this->type->serialise(['flowIds' => ['flow-1']]);

        $this->assertSame('openregister.flows', $bundle['type']);
        $this->assertCount(1, $bundle['flows']);
        $flow = $bundle['flows'][0];
        // Portable fields kept …
        $this->assertSame('My flow', $flow['name']);
        $this->assertSame('manual', $flow['trigger']);
        $this->assertCount(1, $flow['edges']);
        // … instance-specific fields stripped.
        $this->assertArrayNotHasKey('id', $flow);
        $this->assertArrayNotHasKey('uuid', $flow);
        $this->assertArrayNotHasKey('owner', $flow);
        $this->assertArrayNotHasKey('organisation', $flow);
    }

    public function testDeserialiseWritesEachFlowIntoTheStore(): void
    {
        $saved = new ObjectEntity();
        $saved->setUuid('new-uuid');
        $this->objects->expects($this->once())->method('saveObject')->willReturn($saved);

        $result = $this->type->deserialise([
            'type'  => 'openregister.flows',
            'flows' => [['name' => 'Imported', 'trigger' => 'manual', 'nodes' => [], 'edges' => []]],
        ]);

        $this->assertSame(['new-uuid'], $result['installed']);
    }

    public function testARoundTripPreservesTheFlowShape(): void
    {
        // serialise → the same data deserialise would write back.
        $this->objects->method('find')->willReturn($this->flowObject());
        $bundle = $this->type->serialise(['flowIds' => ['flow-1']]);

        $captured = null;
        $saved = new ObjectEntity();
        $saved->setUuid('rt');
        $this->objects->method('saveObject')->willReturnCallback(function (array $object) use (&$captured, $saved): ObjectEntity {
            $captured = $object;
            return $saved;
        });

        $this->type->deserialise($bundle);
        $this->assertSame('My flow', $captured['name']);
        $this->assertSame('manual', $captured['trigger']);
        $this->assertArrayNotHasKey('uuid', $captured);
    }
}
