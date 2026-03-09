<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\Message;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase
{
    private Message $message;

    protected function setUp(): void
    {
        $this->message = new Message();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->message->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('integer', $fieldTypes['conversationId']);
        $this->assertSame('string', $fieldTypes['role']);
        $this->assertSame('string', $fieldTypes['content']);
        $this->assertSame('json', $fieldTypes['sources']);
        $this->assertSame('datetime', $fieldTypes['created']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertNull($this->message->getUuid());
        $this->assertNull($this->message->getConversationId());
        $this->assertNull($this->message->getRole());
        $this->assertNull($this->message->getContent());
        $this->assertNull($this->message->getSources());
        $this->assertNull($this->message->getCreated());
    }

    public function testConstants(): void
    {
        $this->assertSame('user', Message::ROLE_USER);
        $this->assertSame('assistant', Message::ROLE_ASSISTANT);
    }

    public function testSetAndGetUuid(): void
    {
        $this->message->setUuid('msg-uuid-123');
        $this->assertSame('msg-uuid-123', $this->message->getUuid());
    }

    public function testSetAndGetConversationId(): void
    {
        $this->message->setConversationId(42);
        $this->assertSame(42, $this->message->getConversationId());
    }

    public function testSetAndGetRole(): void
    {
        $this->message->setRole('user');
        $this->assertSame('user', $this->message->getRole());

        $this->message->setRole('assistant');
        $this->assertSame('assistant', $this->message->getRole());
    }

    public function testSetAndGetContent(): void
    {
        $this->message->setContent('Hello, world!');
        $this->assertSame('Hello, world!', $this->message->getContent());
    }

    public function testSetAndGetSources(): void
    {
        $sources = [
            ['id' => 'uuid-1', 'type' => 'file', 'name' => 'doc.pdf', 'similarity' => 0.95],
            ['id' => 'uuid-2', 'type' => 'object', 'name' => 'record', 'similarity' => 0.88],
        ];
        $this->message->setSources($sources);
        $this->assertSame($sources, $this->message->getSources());
    }

    public function testSetAndGetSourcesNull(): void
    {
        $this->message->setSources([['id' => '1']]);
        $this->message->setSources(null);
        $this->assertNull($this->message->getSources());
    }

    public function testSetAndGetCreated(): void
    {
        $dt = new DateTime('2024-06-01 12:00:00');
        $this->message->setCreated($dt);
        $this->assertSame($dt, $this->message->getCreated());
    }

    public function testJsonSerializeAllFieldsPresent(): void
    {
        $json = $this->message->jsonSerialize();

        $expectedKeys = ['id', 'uuid', 'conversationId', 'role', 'content', 'sources', 'created'];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $json);
        }
    }

    public function testJsonSerializeDefaultValues(): void
    {
        $json = $this->message->jsonSerialize();

        $this->assertNull($json['id']);
        $this->assertNull($json['uuid']);
        $this->assertNull($json['conversationId']);
        $this->assertNull($json['role']);
        $this->assertNull($json['content']);
        $this->assertNull($json['sources']);
        $this->assertNull($json['created']);
    }

    public function testJsonSerializeWithValues(): void
    {
        $created = new DateTime('2024-01-15 10:30:00');
        $sources = [['id' => 'src-1', 'type' => 'file']];

        $this->message->setUuid('msg-uuid');
        $this->message->setConversationId(5);
        $this->message->setRole('assistant');
        $this->message->setContent('Test response');
        $this->message->setSources($sources);
        $this->message->setCreated($created);

        $json = $this->message->jsonSerialize();

        $this->assertSame('msg-uuid', $json['uuid']);
        $this->assertSame(5, $json['conversationId']);
        $this->assertSame('assistant', $json['role']);
        $this->assertSame('Test response', $json['content']);
        $this->assertSame($sources, $json['sources']);
        $this->assertSame($created->format('c'), $json['created']);
    }

    public function testJsonSerializeCreatedFormattedAsIso8601(): void
    {
        $dt = new DateTime('2024-03-20 15:45:00');
        $this->message->setCreated($dt);
        $json = $this->message->jsonSerialize();

        $this->assertSame($dt->format('c'), $json['created']);
    }

    public function testJsonSerializeCreatedNullWhenNotSet(): void
    {
        $json = $this->message->jsonSerialize();
        $this->assertNull($json['created']);
    }
}
