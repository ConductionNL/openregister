<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\ActionLog;
use PHPUnit\Framework\TestCase;

class ActionLogTest extends TestCase
{
    private ActionLog $log;

    protected function setUp(): void
    {
        $this->log = new ActionLog();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->log->getFieldTypes();

        $this->assertSame('integer', $fieldTypes['actionId']);
        $this->assertSame('string', $fieldTypes['actionUuid']);
        $this->assertSame('string', $fieldTypes['eventType']);
        $this->assertSame('string', $fieldTypes['objectUuid']);
        $this->assertSame('integer', $fieldTypes['schemaId']);
        $this->assertSame('integer', $fieldTypes['registerId']);
        $this->assertSame('string', $fieldTypes['engine']);
        $this->assertSame('string', $fieldTypes['workflowId']);
        $this->assertSame('string', $fieldTypes['status']);
        $this->assertSame('integer', $fieldTypes['durationMs']);
        $this->assertSame('string', $fieldTypes['requestPayload']);
        $this->assertSame('string', $fieldTypes['responsePayload']);
        $this->assertSame('string', $fieldTypes['errorMessage']);
        $this->assertSame('integer', $fieldTypes['attempt']);
        $this->assertSame('datetime', $fieldTypes['created']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertSame(0, $this->log->getActionId());
        $this->assertSame('', $this->log->getActionUuid());
        $this->assertSame('', $this->log->getEventType());
        $this->assertNull($this->log->getObjectUuid());
        $this->assertNull($this->log->getSchemaId());
        $this->assertNull($this->log->getRegisterId());
        $this->assertSame('', $this->log->getEngine());
        $this->assertSame('', $this->log->getWorkflowId());
        $this->assertSame('', $this->log->getStatus());
        $this->assertNull($this->log->getDurationMs());
        $this->assertNull($this->log->getRequestPayload());
        $this->assertNull($this->log->getResponsePayload());
        $this->assertNull($this->log->getErrorMessage());
        $this->assertSame(1, $this->log->getAttempt());
        $this->assertInstanceOf(DateTime::class, $this->log->getCreated());
    }

    public function testJsonSerialize(): void
    {
        $this->log->setActionId(5);
        $this->log->setActionUuid('abc-123');
        $this->log->setEventType('ObjectCreatingEvent');
        $this->log->setEngine('n8n');
        $this->log->setWorkflowId('wf-1');
        $this->log->setStatus('success');
        $this->log->setDurationMs(250);
        $this->log->setAttempt(1);

        $json = $this->log->jsonSerialize();

        $this->assertSame(5, $json['actionId']);
        $this->assertSame('abc-123', $json['actionUuid']);
        $this->assertSame('ObjectCreatingEvent', $json['eventType']);
        $this->assertSame('n8n', $json['engine']);
        $this->assertSame('wf-1', $json['workflowId']);
        $this->assertSame('success', $json['status']);
        $this->assertSame(250, $json['durationMs']);
        $this->assertSame(1, $json['attempt']);
    }

    public function testGetRequestPayloadArrayNull(): void
    {
        $this->assertSame([], $this->log->getRequestPayloadArray());
    }

    public function testGetRequestPayloadArrayValid(): void
    {
        $this->log->setRequestPayload(json_encode(['key' => 'value']));
        $this->assertSame(['key' => 'value'], $this->log->getRequestPayloadArray());
    }

    public function testGetResponsePayloadArrayNull(): void
    {
        $this->assertSame([], $this->log->getResponsePayloadArray());
    }

    public function testGetResponsePayloadArrayValid(): void
    {
        $this->log->setResponsePayload(json_encode(['status' => 'ok']));
        $this->assertSame(['status' => 'ok'], $this->log->getResponsePayloadArray());
    }
}
