<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Config;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Config\Types\GenericObjectShareableConfigType;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GenericObjectShareableConfigTypeTest extends TestCase
{

    private ObjectService&MockObject $objects;

    private GenericObjectShareableConfigType $type;

    protected function setUp(): void
    {
        $this->objects = $this->createMock(ObjectService::class);
        $this->type    = new GenericObjectShareableConfigType(
            $this->objects,
            'procest.casetype',
            'Case type (Procest)',
            'procest-casetype',
            'procest',
            'casetype'
        );
    }//end setUp()

    private function obj(string $uuid, array $data): ObjectEntity
    {
        $o = new ObjectEntity();
        $o->setUuid($uuid);
        $o->setObject($data);
        return $o;
    }//end obj()

    public function testIdentity(): void
    {
        $this->assertSame('procest.casetype', $this->type->getId());
        $this->assertSame('procest-casetype', $this->type->getTopic());
        $this->assertSame('Case type (Procest)', $this->type->getDisplayName());
    }//end testIdentity()

    public function testSerialiseStripsInstanceFields(): void
    {
        $this->objects->method('findAll')->willReturn(
                [
                    $this->obj('a', ['uuid' => 'a', 'owner' => 'admin', 'organisation' => 'org', 'name' => 'Bezwaar', 'steps' => [1, 2]]),
                ]
                );

        $bundle = $this->type->serialise([]);
        $this->assertSame('procest.casetype', $bundle['type']);
        $this->assertSame('procest', $bundle['register']);
        $this->assertCount(1, $bundle['objects']);
        $o = $bundle['objects'][0];
        $this->assertSame('Bezwaar', $o['name']);
        $this->assertSame([1, 2], $o['steps']);
        $this->assertArrayNotHasKey('uuid', $o);
        $this->assertArrayNotHasKey('owner', $o);
        $this->assertArrayNotHasKey('organisation', $o);
    }//end testSerialiseStripsInstanceFields()

    public function testSerialiseCanFilterBySelectedIds(): void
    {
        $this->objects->method('findAll')->willReturn(
                [
                    $this->obj('a', ['name' => 'A']),
                    $this->obj('b', ['name' => 'B']),
                ]
                );

        $bundle = $this->type->serialise(['ids' => ['b']]);
        $this->assertCount(1, $bundle['objects']);
        $this->assertSame('B', $bundle['objects'][0]['name']);
    }//end testSerialiseCanFilterBySelectedIds()

    public function testDeserialiseWritesEachObject(): void
    {
        $saved = new ObjectEntity();
        $saved->setUuid('new');
        $this->objects->expects($this->once())->method('saveObject')
            ->with($this->isType('array'), [], 'procest', 'casetype')
            ->willReturn($saved);

        $result = $this->type->deserialise(['objects' => [['name' => 'Imported']]]);
        $this->assertSame(['new'], $result['installed']);
    }//end testDeserialiseWritesEachObject()
}//end class
