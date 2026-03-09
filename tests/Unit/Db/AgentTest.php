<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\Agent;
use PHPUnit\Framework\TestCase;

class AgentTest extends TestCase
{
    private Agent $agent;

    protected function setUp(): void
    {
        $this->agent = new Agent();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->agent->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('string', $fieldTypes['name']);
        $this->assertSame('string', $fieldTypes['description']);
        $this->assertSame('string', $fieldTypes['type']);
        $this->assertSame('string', $fieldTypes['provider']);
        $this->assertSame('string', $fieldTypes['model']);
        $this->assertSame('string', $fieldTypes['prompt']);
        $this->assertSame('float', $fieldTypes['temperature']);
        $this->assertSame('integer', $fieldTypes['maxTokens']);
        $this->assertSame('json', $fieldTypes['configuration']);
        $this->assertSame('string', $fieldTypes['organisation']);
        $this->assertSame('string', $fieldTypes['owner']);
        $this->assertSame('boolean', $fieldTypes['active']);
        $this->assertSame('boolean', $fieldTypes['enableRag']);
        $this->assertSame('string', $fieldTypes['ragSearchMode']);
        $this->assertSame('integer', $fieldTypes['ragNumSources']);
        $this->assertSame('boolean', $fieldTypes['ragIncludeFiles']);
        $this->assertSame('boolean', $fieldTypes['ragIncludeObjects']);
        $this->assertSame('integer', $fieldTypes['requestQuota']);
        $this->assertSame('integer', $fieldTypes['tokenQuota']);
        $this->assertSame('json', $fieldTypes['views']);
        $this->assertSame('boolean', $fieldTypes['searchFiles']);
        $this->assertSame('boolean', $fieldTypes['searchObjects']);
        $this->assertSame('boolean', $fieldTypes['isPrivate']);
        $this->assertSame('json', $fieldTypes['invitedUsers']);
        $this->assertSame('json', $fieldTypes['groups']);
        $this->assertSame('json', $fieldTypes['tools']);
        $this->assertSame('string', $fieldTypes['user']);
        $this->assertSame('datetime', $fieldTypes['created']);
        $this->assertSame('datetime', $fieldTypes['updated']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertNull($this->agent->getUuid());
        $this->assertNull($this->agent->getName());
        $this->assertNull($this->agent->getDescription());
        $this->assertNull($this->agent->getType());
        $this->assertNull($this->agent->getProvider());
        $this->assertNull($this->agent->getModel());
        $this->assertNull($this->agent->getPrompt());
        $this->assertNull($this->agent->getTemperature());
        $this->assertNull($this->agent->getMaxTokens());
        $this->assertNull($this->agent->getConfiguration());
        $this->assertNull($this->agent->getOrganisation());
        $this->assertNull($this->agent->getOwner());
        $this->assertTrue($this->agent->getActive());
        $this->assertFalse($this->agent->getEnableRag());
        $this->assertNull($this->agent->getRagSearchMode());
        $this->assertNull($this->agent->getRagNumSources());
        $this->assertFalse($this->agent->getRagIncludeFiles());
        $this->assertFalse($this->agent->getRagIncludeObjects());
        $this->assertNull($this->agent->getRequestQuota());
        $this->assertNull($this->agent->getTokenQuota());
        $this->assertNull($this->agent->getViews());
        $this->assertNull($this->agent->getSearchFiles());
        $this->assertNull($this->agent->getSearchObjects());
        $this->assertNull($this->agent->getIsPrivate());
        $this->assertNull($this->agent->getInvitedUsers());
        $this->assertNull($this->agent->getGroups());
        $this->assertNull($this->agent->getTools());
        $this->assertNull($this->agent->getUser());
        $this->assertNull($this->agent->getCreated());
        $this->assertNull($this->agent->getUpdated());
    }

    public function testSetAndGetStringFields(): void
    {
        $this->agent->setUuid('550e8400-e29b-41d4-a716-446655440000');
        $this->agent->setName('Test Agent');
        $this->agent->setDescription('A test agent');
        $this->agent->setType('chat');
        $this->agent->setProvider('openai');
        $this->agent->setModel('gpt-4o-mini');
        $this->agent->setPrompt('You are a helpful assistant');
        $this->agent->setOrganisation('org-uuid');
        $this->agent->setOwner('admin');
        $this->agent->setRagSearchMode('hybrid');
        $this->agent->setUser('testuser');

        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $this->agent->getUuid());
        $this->assertSame('Test Agent', $this->agent->getName());
        $this->assertSame('A test agent', $this->agent->getDescription());
        $this->assertSame('chat', $this->agent->getType());
        $this->assertSame('openai', $this->agent->getProvider());
        $this->assertSame('gpt-4o-mini', $this->agent->getModel());
        $this->assertSame('You are a helpful assistant', $this->agent->getPrompt());
        $this->assertSame('org-uuid', $this->agent->getOrganisation());
        $this->assertSame('admin', $this->agent->getOwner());
        $this->assertSame('hybrid', $this->agent->getRagSearchMode());
        $this->assertSame('testuser', $this->agent->getUser());
    }

    public function testSetAndGetNumericFields(): void
    {
        $this->agent->setTemperature(0.7);
        $this->agent->setMaxTokens(4096);
        $this->agent->setRagNumSources(5);
        $this->agent->setRequestQuota(100);
        $this->agent->setTokenQuota(50000);

        $this->assertSame(0.7, $this->agent->getTemperature());
        $this->assertSame(4096, $this->agent->getMaxTokens());
        $this->assertSame(5, $this->agent->getRagNumSources());
        $this->assertSame(100, $this->agent->getRequestQuota());
        $this->assertSame(50000, $this->agent->getTokenQuota());
    }

    public function testSetAndGetBooleanFields(): void
    {
        $this->agent->setActive(false);
        $this->agent->setEnableRag(true);
        $this->agent->setRagIncludeFiles(true);
        $this->agent->setRagIncludeObjects(true);
        $this->agent->setSearchFiles(true);
        $this->agent->setSearchObjects(false);
        $this->agent->setIsPrivate(true);

        $this->assertFalse($this->agent->getActive());
        $this->assertTrue($this->agent->getEnableRag());
        $this->assertTrue($this->agent->getRagIncludeFiles());
        $this->assertTrue($this->agent->getRagIncludeObjects());
        $this->assertTrue($this->agent->getSearchFiles());
        $this->assertFalse($this->agent->getSearchObjects());
        $this->assertTrue($this->agent->getIsPrivate());
    }

    public function testSetAndGetJsonFields(): void
    {
        $config = ['key' => 'value', 'nested' => ['a' => 1]];
        $views = ['view-uuid-1', 'view-uuid-2'];
        $invitedUsers = ['user1', 'user2'];
        $groups = ['group1'];
        $tools = ['register', 'objects'];

        $this->agent->setConfiguration($config);
        $this->agent->setViews($views);
        $this->agent->setInvitedUsers($invitedUsers);
        $this->agent->setGroups($groups);
        $this->agent->setTools($tools);

        $this->assertSame($config, $this->agent->getConfiguration());
        $this->assertSame($views, $this->agent->getViews());
        $this->assertSame($invitedUsers, $this->agent->getInvitedUsers());
        $this->assertSame($groups, $this->agent->getGroups());
        $this->assertSame($tools, $this->agent->getTools());
    }

    public function testSetAndGetDateTimeFields(): void
    {
        $created = new DateTime('2024-01-01T12:00:00Z');
        $updated = new DateTime('2024-06-15T18:30:00Z');

        $this->agent->setCreated($created);
        $this->agent->setUpdated($updated);

        $this->assertSame($created, $this->agent->getCreated());
        $this->assertSame($updated, $this->agent->getUpdated());
    }

    public function testHasInvitedUserReturnsTrue(): void
    {
        $this->agent->setInvitedUsers(['user1', 'user2', 'user3']);
        $this->assertTrue($this->agent->hasInvitedUser('user2'));
    }

    public function testHasInvitedUserReturnsFalse(): void
    {
        $this->agent->setInvitedUsers(['user1', 'user2']);
        $this->assertFalse($this->agent->hasInvitedUser('user3'));
    }

    public function testHasInvitedUserReturnsFalseWhenNull(): void
    {
        $this->assertFalse($this->agent->hasInvitedUser('user1'));
    }

    public function testHydrateFromArray(): void
    {
        $data = [
            'name'        => 'Hydrated Agent',
            'type'        => 'assistant',
            'provider'    => 'ollama',
            'model'       => 'llama3',
            'active'      => false,
            'enableRag'   => true,
            'temperature' => 0.5,
        ];

        $result = $this->agent->hydrate($data);

        $this->assertSame($this->agent, $result);
        $this->assertSame('Hydrated Agent', $this->agent->getName());
        $this->assertSame('assistant', $this->agent->getType());
        $this->assertSame('ollama', $this->agent->getProvider());
        $this->assertSame('llama3', $this->agent->getModel());
        $this->assertFalse($this->agent->getActive());
        $this->assertTrue($this->agent->getEnableRag());
        $this->assertSame(0.5, $this->agent->getTemperature());
        // UUID should be auto-generated
        $this->assertNotNull($this->agent->getUuid());
    }

    public function testHydrateWithSnakeCaseFallbacks(): void
    {
        $data = [
            'max_tokens'         => 2048,
            'enable_rag'         => true,
            'rag_search_mode'    => 'semantic',
            'rag_num_sources'    => 10,
            'rag_include_files'  => true,
            'rag_include_objects' => true,
            'request_quota'      => 50,
            'token_quota'        => 10000,
            'search_files'       => false,
            'search_objects'     => false,
            'is_private'         => false,
            'invited_users'      => ['u1'],
        ];

        $this->agent->hydrate($data);

        $this->assertSame(2048, $this->agent->getMaxTokens());
        $this->assertTrue($this->agent->getEnableRag());
        $this->assertSame('semantic', $this->agent->getRagSearchMode());
        $this->assertSame(10, $this->agent->getRagNumSources());
        $this->assertTrue($this->agent->getRagIncludeFiles());
        $this->assertTrue($this->agent->getRagIncludeObjects());
        $this->assertSame(50, $this->agent->getRequestQuota());
        $this->assertSame(10000, $this->agent->getTokenQuota());
    }

    public function testJsonSerialize(): void
    {
        $this->agent->setUuid('test-uuid');
        $this->agent->setName('Test Agent');
        $this->agent->setType('chat');
        $this->agent->setActive(true);
        $this->agent->setEnableRag(false);

        $json = $this->agent->jsonSerialize();

        $this->assertArrayHasKey('id', $json);
        $this->assertArrayHasKey('uuid', $json);
        $this->assertArrayHasKey('name', $json);
        $this->assertArrayHasKey('description', $json);
        $this->assertArrayHasKey('type', $json);
        $this->assertArrayHasKey('provider', $json);
        $this->assertArrayHasKey('model', $json);
        $this->assertArrayHasKey('prompt', $json);
        $this->assertArrayHasKey('temperature', $json);
        $this->assertArrayHasKey('maxTokens', $json);
        $this->assertArrayHasKey('configuration', $json);
        $this->assertArrayHasKey('organisation', $json);
        $this->assertArrayHasKey('owner', $json);
        $this->assertArrayHasKey('active', $json);
        $this->assertArrayHasKey('enableRag', $json);
        $this->assertArrayHasKey('ragSearchMode', $json);
        $this->assertArrayHasKey('ragNumSources', $json);
        $this->assertArrayHasKey('ragIncludeFiles', $json);
        $this->assertArrayHasKey('ragIncludeObjects', $json);
        $this->assertArrayHasKey('requestQuota', $json);
        $this->assertArrayHasKey('tokenQuota', $json);
        $this->assertArrayHasKey('views', $json);
        $this->assertArrayHasKey('searchFiles', $json);
        $this->assertArrayHasKey('searchObjects', $json);
        $this->assertArrayHasKey('isPrivate', $json);
        $this->assertArrayHasKey('invitedUsers', $json);
        $this->assertArrayHasKey('groups', $json);
        $this->assertArrayHasKey('tools', $json);
        $this->assertArrayHasKey('user', $json);
        $this->assertArrayHasKey('created', $json);
        $this->assertArrayHasKey('updated', $json);
        $this->assertArrayHasKey('managedByConfiguration', $json);

        $this->assertSame('test-uuid', $json['uuid']);
        $this->assertSame('Test Agent', $json['name']);
        $this->assertSame('chat', $json['type']);
        $this->assertTrue($json['active']);
        $this->assertFalse($json['enableRag']);
    }

    public function testJsonSerializeDateTimeFormatting(): void
    {
        $created = new DateTime('2024-01-01T12:00:00Z');
        $this->agent->setCreated($created);

        $json = $this->agent->jsonSerialize();

        $this->assertSame('2024-01-01T12:00:00Z', $json['created']);
    }

    public function testJsonSerializeNullDates(): void
    {
        $json = $this->agent->jsonSerialize();

        $this->assertNull($json['created']);
        $this->assertNull($json['updated']);
    }

    public function testJsonSerializeManagedByConfigurationNull(): void
    {
        $json = $this->agent->jsonSerialize();

        $this->assertNull($json['managedByConfiguration']);
    }
}
