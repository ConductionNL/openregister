<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\Message;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase
{
<<<<<<< HEAD

=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
    private Message $message;

    protected function setUp(): void
    {
        $this->message = new Message();
<<<<<<< HEAD
    }//end setUp()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->message->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('integer', $fieldTypes['conversationId']);
        $this->assertSame('string', $fieldTypes['role']);
        $this->assertSame('string', $fieldTypes['content']);
        $this->assertSame('json', $fieldTypes['sources']);
<<<<<<< HEAD
        $this->assertSame('json', $fieldTypes['context']);
        $this->assertSame('datetime', $fieldTypes['created']);
    }//end testConstructorRegistersFieldTypes()
=======
        $this->assertSame('datetime', $fieldTypes['created']);
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testConstructorDefaultValues(): void
    {
        $this->assertNull($this->message->getUuid());
        $this->assertNull($this->message->getConversationId());
        $this->assertNull($this->message->getRole());
        $this->assertNull($this->message->getContent());
        $this->assertNull($this->message->getSources());
        $this->assertNull($this->message->getCreated());
<<<<<<< HEAD

        // getContext() shadows the magic getter to normalise null -> [].
        $this->assertSame([], $this->message->getContext());
    }//end testConstructorDefaultValues()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testConstants(): void
    {
        $this->assertSame('user', Message::ROLE_USER);
        $this->assertSame('assistant', Message::ROLE_ASSISTANT);
<<<<<<< HEAD
    }//end testConstants()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testSetAndGetUuid(): void
    {
        $this->message->setUuid('msg-uuid-123');
        $this->assertSame('msg-uuid-123', $this->message->getUuid());
<<<<<<< HEAD
    }//end testSetAndGetUuid()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testSetAndGetConversationId(): void
    {
        $this->message->setConversationId(42);
        $this->assertSame(42, $this->message->getConversationId());
<<<<<<< HEAD
    }//end testSetAndGetConversationId()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testSetAndGetRole(): void
    {
        $this->message->setRole('user');
        $this->assertSame('user', $this->message->getRole());

        $this->message->setRole('assistant');
        $this->assertSame('assistant', $this->message->getRole());
<<<<<<< HEAD
    }//end testSetAndGetRole()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testSetAndGetContent(): void
    {
        $this->message->setContent('Hello, world!');
        $this->assertSame('Hello, world!', $this->message->getContent());
<<<<<<< HEAD
    }//end testSetAndGetContent()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testSetAndGetSources(): void
    {
        $sources = [
            ['id' => 'uuid-1', 'type' => 'file', 'name' => 'doc.pdf', 'similarity' => 0.95],
            ['id' => 'uuid-2', 'type' => 'object', 'name' => 'record', 'similarity' => 0.88],
        ];
        $this->message->setSources($sources);
        $this->assertSame($sources, $this->message->getSources());
<<<<<<< HEAD
    }//end testSetAndGetSources()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testSetAndGetSourcesNull(): void
    {
        $this->message->setSources([['id' => '1']]);
        $this->message->setSources(null);
        $this->assertNull($this->message->getSources());
<<<<<<< HEAD
    }//end testSetAndGetSourcesNull()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testSetAndGetCreated(): void
    {
        $dt = new DateTime('2024-06-01 12:00:00');
        $this->message->setCreated($dt);
        $this->assertSame($dt, $this->message->getCreated());
<<<<<<< HEAD
    }//end testSetAndGetCreated()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testJsonSerializeAllFieldsPresent(): void
    {
        $json = $this->message->jsonSerialize();

<<<<<<< HEAD
        $expectedKeys = ['id', 'uuid', 'conversationId', 'role', 'content', 'sources', 'context', 'created'];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $json);
        }
    }//end testJsonSerializeAllFieldsPresent()
=======
        $expectedKeys = ['id', 'uuid', 'conversationId', 'role', 'content', 'sources', 'created'];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $json);
        }
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testJsonSerializeDefaultValues(): void
    {
        $json = $this->message->jsonSerialize();

        $this->assertNull($json['id']);
        $this->assertNull($json['uuid']);
        $this->assertNull($json['conversationId']);
        $this->assertNull($json['role']);
        $this->assertNull($json['content']);
        $this->assertNull($json['sources']);
<<<<<<< HEAD
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
=======
        $this->assertNull($json['created']);
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

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
<<<<<<< HEAD
        $this->assertSame([], $json['context']);
        // default when unset
        $this->assertSame($created->format('c'), $json['created']);
    }//end testJsonSerializeWithValues()
=======
        $this->assertSame($created->format('c'), $json['created']);
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testJsonSerializeCreatedFormattedAsIso8601(): void
    {
        $dt = new DateTime('2024-03-20 15:45:00');
        $this->message->setCreated($dt);
        $json = $this->message->jsonSerialize();

        $this->assertSame($dt->format('c'), $json['created']);
<<<<<<< HEAD
    }//end testJsonSerializeCreatedFormattedAsIso8601()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testJsonSerializeCreatedNullWhenNotSet(): void
    {
        $json = $this->message->jsonSerialize();
        $this->assertNull($json['created']);
<<<<<<< HEAD
    }//end testJsonSerializeCreatedNullWhenNotSet()
}//end class
=======
    }
}
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
