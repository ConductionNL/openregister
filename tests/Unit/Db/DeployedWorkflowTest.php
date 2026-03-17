<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\DeployedWorkflow;
use PHPUnit\Framework\TestCase;

class DeployedWorkflowTest extends TestCase
{
    private DeployedWorkflow $workflow;

    protected function setUp(): void
    {
        $this->workflow = new DeployedWorkflow();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->workflow->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('string', $fieldTypes['name']);
        $this->assertSame('string', $fieldTypes['engine']);
        $this->assertSame('string', $fieldTypes['engineWorkflowId']);
        $this->assertSame('string', $fieldTypes['sourceHash']);
        $this->assertSame('string', $fieldTypes['attachedSchema']);
        $this->assertSame('string', $fieldTypes['attachedEvent']);
        $this->assertSame('string', $fieldTypes['importSource']);
        $this->assertSame('integer', $fieldTypes['version']);
        $this->assertSame('datetime', $fieldTypes['created']);
        $this->assertSame('datetime', $fieldTypes['updated']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertNull($this->workflow->getUuid());
        $this->assertNull($this->workflow->getName());
        $this->assertNull($this->workflow->getEngine());
        $this->assertNull($this->workflow->getEngineWorkflowId());
        $this->assertNull($this->workflow->getSourceHash());
        $this->assertNull($this->workflow->getAttachedSchema());
        $this->assertNull($this->workflow->getAttachedEvent());
        $this->assertNull($this->workflow->getImportSource());
        $this->assertSame(1, $this->workflow->getVersion());
        $this->assertNull($this->workflow->getCreated());
        $this->assertNull($this->workflow->getUpdated());
    }

    public function testSetAndGetStringFields(): void
    {
        $this->workflow->setUuid('wf-uuid');
        $this->workflow->setName('My Workflow');
        $this->workflow->setEngine('n8n');
        $this->workflow->setEngineWorkflowId('n8n-123');
        $this->workflow->setSourceHash('sha256-hash');
        $this->workflow->setAttachedSchema('my-schema');
        $this->workflow->setAttachedEvent('created');
        $this->workflow->setImportSource('workflows/my-workflow.json');

        $this->assertSame('wf-uuid', $this->workflow->getUuid());
        $this->assertSame('My Workflow', $this->workflow->getName());
        $this->assertSame('n8n', $this->workflow->getEngine());
        $this->assertSame('n8n-123', $this->workflow->getEngineWorkflowId());
        $this->assertSame('sha256-hash', $this->workflow->getSourceHash());
        $this->assertSame('my-schema', $this->workflow->getAttachedSchema());
        $this->assertSame('created', $this->workflow->getAttachedEvent());
        $this->assertSame('workflows/my-workflow.json', $this->workflow->getImportSource());
    }

    public function testSetAndGetVersion(): void
    {
        $this->workflow->setVersion(3);
        $this->assertSame(3, $this->workflow->getVersion());
    }

    public function testSetAndGetDateTimeFields(): void
    {
        $created = new DateTime('2024-01-01T00:00:00Z');
        $updated = new DateTime('2024-06-01T00:00:00Z');

        $this->workflow->setCreated($created);
        $this->workflow->setUpdated($updated);

        $this->assertSame($created, $this->workflow->getCreated());
        $this->assertSame($updated, $this->workflow->getUpdated());
    }

    public function testHydrate(): void
    {
        $data = [
            'uuid'   => 'hydrate-uuid',
            'name'   => 'Hydrated Workflow',
            'engine' => 'windmill',
            'version' => 5,
        ];

        $result = $this->workflow->hydrate($data);

        $this->assertSame($this->workflow, $result);
        $this->assertSame('hydrate-uuid', $this->workflow->getUuid());
        $this->assertSame('Hydrated Workflow', $this->workflow->getName());
        $this->assertSame('windmill', $this->workflow->getEngine());
        $this->assertSame(5, $this->workflow->getVersion());
    }

    public function testJsonSerialize(): void
    {
        $this->workflow->setUuid('json-uuid');
        $this->workflow->setName('JSON Workflow');
        $this->workflow->setEngine('n8n');
        $this->workflow->setVersion(2);

        $json = $this->workflow->jsonSerialize();

        $expectedKeys = [
            'id', 'uuid', 'name', 'engine', 'engineWorkflowId',
            'sourceHash', 'attachedSchema', 'attachedEvent',
            'importSource', 'version', 'created', 'updated',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $json);
        }

        $this->assertSame('json-uuid', $json['uuid']);
        $this->assertSame('JSON Workflow', $json['name']);
        $this->assertSame('n8n', $json['engine']);
        $this->assertSame(2, $json['version']);
    }

    public function testJsonSerializeDateTimeFormatting(): void
    {
        $created = new DateTime('2024-01-01T12:00:00+00:00');
        $this->workflow->setCreated($created);

        $json = $this->workflow->jsonSerialize();

        $this->assertSame($created->format('c'), $json['created']);
    }

    public function testJsonSerializeNullDates(): void
    {
        $json = $this->workflow->jsonSerialize();
        $this->assertNull($json['created']);
        $this->assertNull($json['updated']);
    }
}
