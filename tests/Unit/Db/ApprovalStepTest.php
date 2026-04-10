<?php

namespace Unit\Db;

use OCA\OpenRegister\Db\ApprovalStep;
use PHPUnit\Framework\TestCase;

class ApprovalStepTest extends TestCase
{
    private ApprovalStep $entity;

    protected function setUp(): void
    {
        $this->entity = new ApprovalStep();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->entity->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('integer', $fieldTypes['chainId']);
        $this->assertSame('string', $fieldTypes['objectUuid']);
        $this->assertSame('integer', $fieldTypes['stepOrder']);
        $this->assertSame('string', $fieldTypes['role']);
        $this->assertSame('string', $fieldTypes['status']);
        $this->assertSame('string', $fieldTypes['decidedBy']);
        $this->assertSame('string', $fieldTypes['comment']);
        $this->assertSame('datetime', $fieldTypes['decidedAt']);
        $this->assertSame('datetime', $fieldTypes['created']);
    }

    public function testDefaultValues(): void
    {
        $this->assertSame('pending', $this->entity->getStatus());
        $this->assertSame(0, $this->entity->getStepOrder());
        $this->assertNull($this->entity->getDecidedBy());
    }

    public function testHydrate(): void
    {
        $this->entity->hydrate([
            'uuid'       => 'step-1',
            'chainId'    => 1,
            'objectUuid' => 'obj-123',
            'stepOrder'  => 2,
            'role'       => 'teamleider',
            'status'     => 'approved',
            'decidedBy'  => 'admin',
            'comment'    => 'Akkoord',
        ]);

        $this->assertSame('step-1', $this->entity->getUuid());
        $this->assertSame(1, $this->entity->getChainId());
        $this->assertSame('obj-123', $this->entity->getObjectUuid());
        $this->assertSame(2, $this->entity->getStepOrder());
        $this->assertSame('teamleider', $this->entity->getRole());
        $this->assertSame('approved', $this->entity->getStatus());
        $this->assertSame('admin', $this->entity->getDecidedBy());
        $this->assertSame('Akkoord', $this->entity->getComment());
    }

    public function testJsonSerialize(): void
    {
        $this->entity->hydrate([
            'uuid'       => 'step-2',
            'chainId'    => 1,
            'objectUuid' => 'obj-456',
            'stepOrder'  => 1,
            'role'       => 'admin',
            'status'     => 'pending',
        ]);

        $json = $this->entity->jsonSerialize();

        $this->assertSame('step-2', $json['uuid']);
        $this->assertSame(1, $json['chainId']);
        $this->assertSame('pending', $json['status']);
        $this->assertNull($json['decidedBy']);
        $this->assertNull($json['decidedAt']);
    }
}
