<?php

namespace Unit\Db;

use OCA\OpenRegister\Db\ApprovalChain;
use PHPUnit\Framework\TestCase;

class ApprovalChainTest extends TestCase
{
    private ApprovalChain $entity;

    protected function setUp(): void
    {
        $this->entity = new ApprovalChain();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->entity->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('string', $fieldTypes['name']);
        $this->assertSame('integer', $fieldTypes['schemaId']);
        $this->assertSame('string', $fieldTypes['statusField']);
        $this->assertSame('string', $fieldTypes['steps']);
        $this->assertSame('boolean', $fieldTypes['enabled']);
    }

    public function testDefaultValues(): void
    {
        $this->assertSame('status', $this->entity->getStatusField());
        $this->assertTrue($this->entity->getEnabled());
    }

    public function testHydrateEncodesStepsArray(): void
    {
        $steps = [
            ['order' => 1, 'role' => 'teamleider', 'statusOnApprove' => 'wacht', 'statusOnReject' => 'afgewezen'],
            ['order' => 2, 'role' => 'afdelingshoofd', 'statusOnApprove' => 'goedgekeurd', 'statusOnReject' => 'afgewezen'],
        ];

        $this->entity->hydrate([
            'name'     => 'Vergunning goedkeuring',
            'schemaId' => 12,
            'steps'    => $steps,
        ]);

        // Steps should be encoded to JSON string internally.
        $this->assertIsString($this->entity->getSteps());

        // getStepsArray should decode back.
        $decoded = $this->entity->getStepsArray();
        $this->assertCount(2, $decoded);
        $this->assertSame('teamleider', $decoded[0]['role']);
        $this->assertSame('afdelingshoofd', $decoded[1]['role']);
    }

    public function testJsonSerializeReturnsStepsAsArray(): void
    {
        $steps = [
            ['order' => 1, 'role' => 'admin', 'statusOnApprove' => 'ok', 'statusOnReject' => 'no'],
        ];

        $this->entity->hydrate([
            'uuid'     => 'chain-1',
            'name'     => 'Test Chain',
            'schemaId' => 5,
            'steps'    => $steps,
        ]);

        $json = $this->entity->jsonSerialize();

        $this->assertIsArray($json['steps']);
        $this->assertSame('admin', $json['steps'][0]['role']);
    }

    public function testGetStepsArrayReturnsEmptyForNull(): void
    {
        $this->assertSame([], $this->entity->getStepsArray());
    }
}
