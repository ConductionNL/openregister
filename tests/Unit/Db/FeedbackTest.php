<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\Feedback;
use PHPUnit\Framework\TestCase;

class FeedbackTest extends TestCase
{
    private Feedback $feedback;

    protected function setUp(): void
    {
        $this->feedback = new Feedback();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->feedback->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('integer', $fieldTypes['messageId']);
        $this->assertSame('integer', $fieldTypes['conversationId']);
        $this->assertSame('integer', $fieldTypes['agentId']);
        $this->assertSame('string', $fieldTypes['userId']);
        $this->assertSame('string', $fieldTypes['organisation']);
        $this->assertSame('string', $fieldTypes['type']);
        $this->assertSame('string', $fieldTypes['comment']);
        $this->assertSame('datetime', $fieldTypes['created']);
        $this->assertSame('datetime', $fieldTypes['updated']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertSame('', $this->feedback->getUuid());
        $this->assertSame(0, $this->feedback->getMessageId());
        $this->assertSame(0, $this->feedback->getConversationId());
        $this->assertSame(0, $this->feedback->getAgentId());
        $this->assertSame('', $this->feedback->getUserId());
        $this->assertNull($this->feedback->getOrganisation());
        $this->assertSame('', $this->feedback->getType());
        $this->assertNull($this->feedback->getComment());
        $this->assertNull($this->feedback->getCreated());
        $this->assertNull($this->feedback->getUpdated());
    }

    public function testSetAndGetStringFields(): void
    {
        $this->feedback->setUuid('feedback-uuid');
        $this->feedback->setUserId('user1');
        $this->feedback->setOrganisation('org-uuid');
        $this->feedback->setType('positive');
        $this->feedback->setComment('Great response!');

        $this->assertSame('feedback-uuid', $this->feedback->getUuid());
        $this->assertSame('user1', $this->feedback->getUserId());
        $this->assertSame('org-uuid', $this->feedback->getOrganisation());
        $this->assertSame('positive', $this->feedback->getType());
        $this->assertSame('Great response!', $this->feedback->getComment());
    }

    public function testSetAndGetIntegerFields(): void
    {
        $this->feedback->setMessageId(10);
        $this->feedback->setConversationId(20);
        $this->feedback->setAgentId(30);

        $this->assertSame(10, $this->feedback->getMessageId());
        $this->assertSame(20, $this->feedback->getConversationId());
        $this->assertSame(30, $this->feedback->getAgentId());
    }

    public function testSetAndGetDateTimeFields(): void
    {
        $created = new DateTime('2024-01-01T00:00:00Z');
        $updated = new DateTime('2024-06-01T00:00:00Z');

        $this->feedback->setCreated($created);
        $this->feedback->setUpdated($updated);

        $this->assertSame($created, $this->feedback->getCreated());
        $this->assertSame($updated, $this->feedback->getUpdated());
    }

    public function testJsonSerialize(): void
    {
        $this->feedback->setUuid('json-uuid');
        $this->feedback->setMessageId(5);
        $this->feedback->setConversationId(10);
        $this->feedback->setAgentId(15);
        $this->feedback->setUserId('admin');
        $this->feedback->setType('negative');
        $this->feedback->setComment('Inaccurate');

        $json = $this->feedback->jsonSerialize();

        $expectedKeys = [
            'id', 'uuid', 'messageId', 'conversationId', 'agentId',
            'userId', 'organisation', 'type', 'comment', 'created', 'updated',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $json);
        }

        $this->assertSame('json-uuid', $json['uuid']);
        $this->assertSame(5, $json['messageId']);
        $this->assertSame(10, $json['conversationId']);
        $this->assertSame(15, $json['agentId']);
        $this->assertSame('admin', $json['userId']);
        $this->assertSame('negative', $json['type']);
        $this->assertSame('Inaccurate', $json['comment']);
    }

    public function testJsonSerializeDateTimeFormatting(): void
    {
        $created = new DateTime('2024-01-01T12:00:00+00:00');
        $this->feedback->setCreated($created);

        $json = $this->feedback->jsonSerialize();

        $this->assertSame($created->format('c'), $json['created']);
    }

    public function testJsonSerializeNullDates(): void
    {
        $json = $this->feedback->jsonSerialize();

        $this->assertNull($json['created']);
        $this->assertNull($json['updated']);
    }
}
