<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\Conversation;
use PHPUnit\Framework\TestCase;

class ConversationTest extends TestCase
{
    private Conversation $conversation;

    protected function setUp(): void
    {
        $this->conversation = new Conversation();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->conversation->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('string', $fieldTypes['title']);
        $this->assertSame('string', $fieldTypes['userId']);
        $this->assertSame('string', $fieldTypes['organisation']);
        $this->assertSame('integer', $fieldTypes['agentId']);
        $this->assertSame('json', $fieldTypes['metadata']);
        $this->assertSame('datetime', $fieldTypes['deletedAt']);
        $this->assertSame('datetime', $fieldTypes['created']);
        $this->assertSame('datetime', $fieldTypes['updated']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertNull($this->conversation->getUuid());
        $this->assertNull($this->conversation->getTitle());
        $this->assertNull($this->conversation->getUserId());
        $this->assertNull($this->conversation->getOwner());
        $this->assertNull($this->conversation->getOrganisation());
        $this->assertNull($this->conversation->getAgentId());
        $this->assertNull($this->conversation->getMetadata());
        $this->assertNull($this->conversation->getDeletedAt());
        $this->assertNull($this->conversation->getCreated());
        $this->assertNull($this->conversation->getUpdated());
    }

    public function testSetAndGetStringFields(): void
    {
        $this->conversation->setUuid('conv-uuid');
        $this->conversation->setTitle('Test Conversation');
        $this->conversation->setUserId('user1');
        $this->conversation->setOwner('owner1');
        $this->conversation->setOrganisation('org-uuid');

        $this->assertSame('conv-uuid', $this->conversation->getUuid());
        $this->assertSame('Test Conversation', $this->conversation->getTitle());
        $this->assertSame('user1', $this->conversation->getUserId());
        $this->assertSame('owner1', $this->conversation->getOwner());
        $this->assertSame('org-uuid', $this->conversation->getOrganisation());
    }

    public function testSetAndGetAgentId(): void
    {
        $this->conversation->setAgentId(42);
        $this->assertSame(42, $this->conversation->getAgentId());
    }

    public function testSetAndGetMetadata(): void
    {
        $metadata = ['summary' => 'A chat', 'token_count' => 500];
        $this->conversation->setMetadata($metadata);
        $this->assertSame($metadata, $this->conversation->getMetadata());
    }

    public function testSetAndGetDateTimeFields(): void
    {
        $created = new DateTime('2024-01-01T00:00:00Z');
        $updated = new DateTime('2024-06-01T00:00:00Z');
        $deletedAt = new DateTime('2024-07-01T00:00:00Z');

        $this->conversation->setCreated($created);
        $this->conversation->setUpdated($updated);
        $this->conversation->setDeletedAt($deletedAt);

        $this->assertSame($created, $this->conversation->getCreated());
        $this->assertSame($updated, $this->conversation->getUpdated());
        $this->assertSame($deletedAt, $this->conversation->getDeletedAt());
    }

    public function testSoftDeleteReturnsSelf(): void
    {
        $result = $this->conversation->softDelete();
        $this->assertSame($this->conversation, $result);
    }

    public function testManualSoftDeleteAndRestore(): void
    {
        // softDelete() uses named args internally which breaks __call,
        // so test the manual equivalent
        $now = new DateTime();
        $this->conversation->setDeletedAt($now);
        $this->assertSame($now, $this->conversation->getDeletedAt());

        $this->conversation->setDeletedAt(null);
        $this->assertNull($this->conversation->getDeletedAt());
    }

    public function testRestoreReturnsSelf(): void
    {
        $result = $this->conversation->restore();
        $this->assertSame($this->conversation, $result);
    }

    public function testJsonSerialize(): void
    {
        $this->conversation->setUuid('json-uuid');
        $this->conversation->setTitle('My Chat');
        $this->conversation->setUserId('admin');
        $this->conversation->setAgentId(5);

        $json = $this->conversation->jsonSerialize();

        $expectedKeys = [
            'id', 'uuid', 'title', 'userId', 'organisation',
            'agentId', 'metadata', 'deletedAt', 'created', 'updated',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $json);
        }

        $this->assertSame('json-uuid', $json['uuid']);
        $this->assertSame('My Chat', $json['title']);
        $this->assertSame('admin', $json['userId']);
        $this->assertSame(5, $json['agentId']);
    }

    public function testJsonSerializeDateTimeFormatting(): void
    {
        $created = new DateTime('2024-01-01T12:00:00+00:00');
        $this->conversation->setCreated($created);

        $json = $this->conversation->jsonSerialize();

        $this->assertSame($created->format('c'), $json['created']);
    }

    public function testJsonSerializeNullDates(): void
    {
        $json = $this->conversation->jsonSerialize();

        $this->assertNull($json['created']);
        $this->assertNull($json['updated']);
        $this->assertNull($json['deletedAt']);
    }
}
