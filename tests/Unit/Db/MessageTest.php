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
    }//end setUp()

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->message->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('integer', $fieldTypes['conversationId']);
        $this->assertSame('string', $fieldTypes['role']);
        $this->assertSame('string', $fieldTypes['content']);
        $this->assertSame('json', $fieldTypes['sources']);
        $this->assertSame('json', $fieldTypes['context']);
        $this->assertSame('datetime', $fieldTypes['created']);
    }//end testConstructorRegistersFieldTypes()

    public function testConstructorDefaultValues(): void
    {
        $this->assertNull($this->message->getUuid());
        $this->assertNull($this->message->getConversationId());
        $this->assertNull($this->message->getRole());
        $this->assertNull($this->message->getContent());
        $this->assertNull($this->message->getSources());
        $this->assertNull($this->message->getCreated());

        // getContext() shadows the magic getter to normalise null -> [].
        $this->assertSame([], $this->message->getContext());
    }//end testConstructorDefaultValues()

    public function testConstants(): void
    {
        $this->assertSame('user', Message::ROLE_USER);
        $this->assertSame('assistant', Message::ROLE_ASSISTANT);
    }//end testConstants()

    public function testSetAndGetUuid(): void
    {
        $this->message->setUuid('msg-uuid-123');
        $this->assertSame('msg-uuid-123', $this->message->getUuid());
    }//end testSetAndGetUuid()

    public function testSetAndGetConversationId(): void
    {
        $this->message->setConversationId(42);
        $this->assertSame(42, $this->message->getConversationId());
    }//end testSetAndGetConversationId()

    public function testSetAndGetRole(): void
    {
        $this->message->setRole('user');
        $this->assertSame('user', $this->message->getRole());

        $this->message->setRole('assistant');
        $this->assertSame('assistant', $this->message->getRole());
    }//end testSetAndGetRole()

    public function testSetAndGetContent(): void
    {
        $this->message->setContent('Hello, world!');
        $this->assertSame('Hello, world!', $this->message->getContent());
    }//end testSetAndGetContent()

    public function testSetAndGetSources(): void
    {
        $sources = [
            ['id' => 'uuid-1', 'type' => 'file', 'name' => 'doc.pdf', 'similarity' => 0.95],
            ['id' => 'uuid-2', 'type' => 'object', 'name' => 'record', 'similarity' => 0.88],
        ];
        $this->message->setSources($sources);
        $this->assertSame($sources, $this->message->getSources());
    }//end testSetAndGetSources()

    public function testSetAndGetSourcesNull(): void
    {
        $this->message->setSources([['id' => '1']]);
        $this->message->setSources(null);
        $this->assertNull($this->message->getSources());
    }//end testSetAndGetSourcesNull()

    public function testSetAndGetCreated(): void
    {
        $dt = new DateTime('2024-06-01 12:00:00');
        $this->message->setCreated($dt);
        $this->assertSame($dt, $this->message->getCreated());
    }//end testSetAndGetCreated()

    public function testJsonSerializeAllFieldsPresent(): void
    {
        $json = $this->message->jsonSerialize();

        $expectedKeys = ['id', 'uuid', 'conversationId', 'role', 'content', 'sources', 'context', 'created'];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $json);
        }
    }//end testJsonSerializeAllFieldsPresent()

    public function testJsonSerializeDefaultValues(): void
    {
        $json = $this->message->jsonSerialize();

        $this->assertNull($json['id']);
        $this->assertNull($json['uuid']);
        $this->assertNull($json['conversationId']);
        $this->assertNull($json['role']);
        $this->assertNull($json['content']);
        $this->assertNull($json['sources']);
        $this->assertSame([], $json['context']);
        $this->assertNull($json['created']);
    }//end testJsonSerializeDefaultValues()

    /**
     * §9.5 — getContext() normalises null -> [] for callers; setContext
     * shadows the magic setter with non-null array semantics and the
     * `json` addType binding handles serialisation on persist.
     */
    public function testGetContextReturnsEmptyArrayWhenUnset(): void
    {
        $this->assertSame([], $this->message->getContext());
    }//end testGetContextReturnsEmptyArrayWhenUnset()

    public function testSetAndGetContext(): void
    {
        $context = ['app' => 'openbuild', 'slug' => 'my-app', 'view' => 'detail'];
        $this->message->setContext($context);
        $this->assertSame($context, $this->message->getContext());
    }//end testSetAndGetContext()

    public function testSetContextOverwritesPreviousValue(): void
    {
        $this->message->setContext(['stale' => true]);
        $this->message->setContext(['fresh' => 'value']);
        $this->assertSame(['fresh' => 'value'], $this->message->getContext());
    }//end testSetContextOverwritesPreviousValue()

    public function testSetContextWithEmptyArrayKeepsEmpty(): void
    {
        // Calling setContext([]) is legal and getContext() reflects it.
        $this->message->setContext([]);
        $this->assertSame([], $this->message->getContext());
    }//end testSetContextWithEmptyArrayKeepsEmpty()

    public function testSetContextWithNestedStructure(): void
    {
        // Frontend may send nested objects + arrays — must round-trip.
        $context = [
            'app'  => 'openbuild',
            'page' => ['id' => 'Dashboard', 'route' => '/'],
            'tags' => ['draft', 'wip'],
        ];
        $this->message->setContext($context);
        $this->assertSame($context, $this->message->getContext());
    }//end testSetContextWithNestedStructure()

    public function testJsonSerializeIncludesContext(): void
    {
        $context = ['register' => 'decidesk', 'schema' => 'meeting'];
        $this->message->setContext($context);
        $json = $this->message->jsonSerialize();
        $this->assertSame($context, $json['context']);
    }//end testJsonSerializeIncludesContext()

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
        $this->assertSame([], $json['context']);
        // default when unset
        $this->assertSame($created->format('c'), $json['created']);
    }//end testJsonSerializeWithValues()

    public function testJsonSerializeCreatedFormattedAsIso8601(): void
    {
        $dt = new DateTime('2024-03-20 15:45:00');
        $this->message->setCreated($dt);
        $json = $this->message->jsonSerialize();

        $this->assertSame($dt->format('c'), $json['created']);
    }//end testJsonSerializeCreatedFormattedAsIso8601()

    public function testJsonSerializeCreatedNullWhenNotSet(): void
    {
        $json = $this->message->jsonSerialize();
        $this->assertNull($json['created']);
    }//end testJsonSerializeCreatedNullWhenNotSet()
}//end class
