<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\Action;
use PHPUnit\Framework\TestCase;

class ActionTest extends TestCase
{
    private Action $action;

    protected function setUp(): void
    {
        $this->action = new Action();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->action->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('string', $fieldTypes['name']);
        $this->assertSame('string', $fieldTypes['slug']);
        $this->assertSame('string', $fieldTypes['description']);
        $this->assertSame('string', $fieldTypes['version']);
        $this->assertSame('string', $fieldTypes['status']);
        $this->assertSame('string', $fieldTypes['eventType']);
        $this->assertSame('string', $fieldTypes['engine']);
        $this->assertSame('string', $fieldTypes['workflowId']);
        $this->assertSame('string', $fieldTypes['mode']);
        $this->assertSame('integer', $fieldTypes['executionOrder']);
        $this->assertSame('integer', $fieldTypes['timeout']);
        $this->assertSame('string', $fieldTypes['onFailure']);
        $this->assertSame('string', $fieldTypes['onTimeout']);
        $this->assertSame('string', $fieldTypes['onEngineDown']);
        $this->assertSame('string', $fieldTypes['filterCondition']);
        $this->assertSame('string', $fieldTypes['configuration']);
        $this->assertSame('integer', $fieldTypes['mapping']);
        $this->assertSame('string', $fieldTypes['schemas']);
        $this->assertSame('string', $fieldTypes['registers']);
        $this->assertSame('string', $fieldTypes['schedule']);
        $this->assertSame('integer', $fieldTypes['maxRetries']);
        $this->assertSame('string', $fieldTypes['retryPolicy']);
        $this->assertSame('boolean', $fieldTypes['enabled']);
        $this->assertSame('string', $fieldTypes['owner']);
        $this->assertSame('string', $fieldTypes['application']);
        $this->assertSame('string', $fieldTypes['organisation']);
        $this->assertSame('datetime', $fieldTypes['lastExecutedAt']);
        $this->assertSame('integer', $fieldTypes['executionCount']);
        $this->assertSame('integer', $fieldTypes['successCount']);
        $this->assertSame('integer', $fieldTypes['failureCount']);
        $this->assertSame('datetime', $fieldTypes['created']);
        $this->assertSame('datetime', $fieldTypes['updated']);
        $this->assertSame('datetime', $fieldTypes['deleted']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertSame('', $this->action->getUuid());
        $this->assertSame('', $this->action->getName());
        $this->assertNull($this->action->getSlug());
        $this->assertNull($this->action->getDescription());
        $this->assertSame('1.0.0', $this->action->getVersion());
        $this->assertSame('draft', $this->action->getStatus());
        $this->assertSame('sync', $this->action->getMode());
        $this->assertSame(0, $this->action->getExecutionOrder());
        $this->assertSame(30, $this->action->getTimeout());
        $this->assertSame('reject', $this->action->getOnFailure());
        $this->assertSame('reject', $this->action->getOnTimeout());
        $this->assertSame('allow', $this->action->getOnEngineDown());
        $this->assertSame(3, $this->action->getMaxRetries());
        $this->assertSame('exponential', $this->action->getRetryPolicy());
        $this->assertTrue($this->action->getEnabled());
        $this->assertSame(0, $this->action->getExecutionCount());
        $this->assertSame(0, $this->action->getSuccessCount());
        $this->assertSame(0, $this->action->getFailureCount());
    }

    public function testJsonSerializeReturnsAllFields(): void
    {
        $this->action->setUuid('test-uuid');
        $this->action->setName('Test Action');
        $this->action->setSlug('test-action');
        $this->action->setStatus('active');
        $this->action->setEventType('ObjectCreatingEvent');
        $this->action->setEngine('n8n');
        $this->action->setWorkflowId('wf-123');

        $json = $this->action->jsonSerialize();

        $this->assertSame('test-uuid', $json['uuid']);
        $this->assertSame('Test Action', $json['name']);
        $this->assertSame('test-action', $json['slug']);
        $this->assertSame('active', $json['status']);
        $this->assertSame(['ObjectCreatingEvent'], $json['eventType']);
        $this->assertSame('n8n', $json['engine']);
        $this->assertSame('wf-123', $json['workflowId']);
        $this->assertSame('sync', $json['mode']);
        $this->assertSame(0, $json['executionOrder']);
        $this->assertSame(30, $json['timeout']);
        $this->assertSame('reject', $json['onFailure']);
        $this->assertSame('reject', $json['onTimeout']);
        $this->assertSame('allow', $json['onEngineDown']);
        $this->assertSame(3, $json['maxRetries']);
        $this->assertSame('exponential', $json['retryPolicy']);
        $this->assertTrue($json['enabled']);
    }

    public function testMatchesEventExactMatch(): void
    {
        $this->action->setEventType('ObjectCreatingEvent');

        $this->assertTrue($this->action->matchesEvent('ObjectCreatingEvent'));
        $this->assertFalse($this->action->matchesEvent('ObjectUpdatingEvent'));
    }

    public function testMatchesEventWildcardMatch(): void
    {
        $this->action->setEventType('Object*Event');

        $this->assertTrue($this->action->matchesEvent('ObjectCreatingEvent'));
        $this->assertTrue($this->action->matchesEvent('ObjectUpdatedEvent'));
        $this->assertTrue($this->action->matchesEvent('ObjectDeletedEvent'));
        $this->assertFalse($this->action->matchesEvent('RegisterCreatedEvent'));
    }

    public function testMatchesEventJsonArrayMatch(): void
    {
        $this->action->setEventType(json_encode(['ObjectCreatedEvent', 'ObjectUpdatedEvent']));

        $this->assertTrue($this->action->matchesEvent('ObjectCreatedEvent'));
        $this->assertTrue($this->action->matchesEvent('ObjectUpdatedEvent'));
        $this->assertFalse($this->action->matchesEvent('ObjectDeletedEvent'));
    }

    public function testMatchesSchemaEmptyMatchesAll(): void
    {
        // No schemas set = match all.
        $this->assertTrue($this->action->matchesSchema('any-uuid'));
        $this->assertTrue($this->action->matchesSchema(null));
    }

    public function testMatchesSchemaSpecificBinding(): void
    {
        $this->action->setSchemasArray(['schema-uuid-1', 'schema-uuid-2']);

        $this->assertTrue($this->action->matchesSchema('schema-uuid-1'));
        $this->assertTrue($this->action->matchesSchema('schema-uuid-2'));
        $this->assertFalse($this->action->matchesSchema('schema-uuid-3'));
        $this->assertFalse($this->action->matchesSchema(null));
    }

    public function testMatchesRegisterEmptyMatchesAll(): void
    {
        $this->assertTrue($this->action->matchesRegister('any-uuid'));
        $this->assertTrue($this->action->matchesRegister(null));
    }

    public function testMatchesRegisterSpecificBinding(): void
    {
        $this->action->setRegistersArray(['register-uuid-1']);

        $this->assertTrue($this->action->matchesRegister('register-uuid-1'));
        $this->assertFalse($this->action->matchesRegister('register-uuid-2'));
        $this->assertFalse($this->action->matchesRegister(null));
    }

    public function testHydrate(): void
    {
        $data = [
            'name'           => 'Hydrated Action',
            'eventType'      => ['ObjectCreatingEvent', 'ObjectUpdatingEvent'],
            'engine'         => 'windmill',
            'workflowId'     => 'wf-456',
            'mode'           => 'async',
            'executionOrder' => 5,
            'timeout'        => 60,
            'onFailure'      => 'allow',
            'schemas'        => ['s1', 's2'],
            'registers'      => ['r1'],
            'filterCondition' => ['data.status' => 'active'],
            'configuration'  => ['key' => 'value'],
            'enabled'        => false,
        ];

        $action = new Action();
        $action->hydrate($data);

        $this->assertSame('Hydrated Action', $action->getName());
        $this->assertSame('windmill', $action->getEngine());
        $this->assertSame('wf-456', $action->getWorkflowId());
        $this->assertSame('async', $action->getMode());
        $this->assertSame(5, $action->getExecutionOrder());
        $this->assertSame(60, $action->getTimeout());
        $this->assertSame('allow', $action->getOnFailure());
        $this->assertSame(['s1', 's2'], $action->getSchemasArray());
        $this->assertSame(['r1'], $action->getRegistersArray());
        $this->assertSame(['data.status' => 'active'], $action->getFilterConditionArray());
        $this->assertSame(['key' => 'value'], $action->getConfigurationArray());
        $this->assertFalse($action->getEnabled());
    }

    public function testGetEventTypeArraySingleString(): void
    {
        $this->action->setEventType('ObjectCreatingEvent');
        $this->assertSame(['ObjectCreatingEvent'], $this->action->getEventTypeArray());
    }

    public function testGetEventTypeArrayJsonArray(): void
    {
        $this->action->setEventType(json_encode(['A', 'B']));
        $this->assertSame(['A', 'B'], $this->action->getEventTypeArray());
    }

    public function testGetFilterConditionArrayNull(): void
    {
        $this->assertSame([], $this->action->getFilterConditionArray());
    }

    public function testGetConfigurationArrayNull(): void
    {
        $this->assertSame([], $this->action->getConfigurationArray());
    }
}
