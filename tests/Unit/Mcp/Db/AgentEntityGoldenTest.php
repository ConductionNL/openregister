<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp\Db;

use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Db\Conversation;
use PHPUnit\Framework\TestCase;

/**
 * Pins the column types, hydration and serialisation of the Agent and
 * Conversation entities, so moving their maps into collaborators keeps them.
 *
 * @covers \OCA\OpenRegister\Mcp\Db\Agent
 * @covers \OCA\OpenRegister\Mcp\Db\Conversation
 */
class AgentEntityGoldenTest extends TestCase
{
    private const AGENT_TYPES = [
        'id' => 'integer', 'uuid' => 'string', 'name' => 'string', 'description' => 'string',
        'type' => 'string', 'provider' => 'string', 'model' => 'string', 'prompt' => 'string',
        'temperature' => 'float', 'maxTokens' => 'integer', 'configuration' => 'json',
        'organisation' => 'string', 'owner' => 'string', 'active' => 'boolean',
        'enableRag' => 'boolean', 'ragSearchMode' => 'string', 'ragNumSources' => 'integer',
        'ragIncludeFiles' => 'boolean', 'ragIncludeObjects' => 'boolean',
        'requestQuota' => 'integer', 'tokenQuota' => 'integer', 'views' => 'json',
        'searchFiles' => 'boolean', 'searchObjects' => 'boolean', 'isPrivate' => 'boolean',
        'invitedUsers' => 'json', 'groups' => 'json', 'tools' => 'json', 'user' => 'string',
        'created' => 'datetime', 'updated' => 'datetime',
    ];

    /**
     * Agent column types.
     *
     * @return void
     */
    public function testAgentColumnTypes(): void
    {
        $this->assertSame(self::AGENT_TYPES, (new Agent())->getFieldTypes());
    }//end testAgentColumnTypes()

    /**
     * Conversation column types.
     *
     * @return void
     */
    public function testConversationColumnTypes(): void
    {
        $expected = [
            'id' => 'integer', 'uuid' => 'string', 'title' => 'string', 'userId' => 'string',
            'organisation' => 'string', 'agentId' => 'integer', 'metadata' => 'json',
            'deletedAt' => 'datetime', 'created' => 'datetime', 'updated' => 'datetime',
        ];
        $this->assertSame($expected, (new Conversation())->getFieldTypes());
    }//end testConversationColumnTypes()

    /**
     * Hydrate from empty array applies defaults.
     *
     * @return void
     */
    public function testHydrateFromEmptyArrayAppliesDefaults(): void
    {
        $agent = (new Agent())->hydrate([]);
        $json  = $agent->jsonSerialize();

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $json['uuid']);
        unset($json['uuid']);
        $this->assertSame(
            [
                'id' => null, 'name' => null, 'description' => null, 'type' => null,
                'provider' => null, 'model' => null, 'prompt' => null, 'temperature' => null,
                'maxTokens' => null, 'configuration' => null, 'organisation' => null,
                'owner' => null, 'active' => true, 'enableRag' => false, 'ragSearchMode' => null,
                'ragNumSources' => null, 'ragIncludeFiles' => false, 'ragIncludeObjects' => false,
                'requestQuota' => null, 'tokenQuota' => null, 'views' => null,
                'searchFiles' => true, 'searchObjects' => true, 'isPrivate' => true,
                'invitedUsers' => null, 'groups' => null, 'tools' => null, 'user' => null,
                'created' => null, 'updated' => null, 'managedByConfiguration' => null,
            ],
            $json
        );
    }//end testHydrateFromEmptyArrayAppliesDefaults()

    /**
     * Hydrate reads snake case fallbacks.
     *
     * @return void
     */
    public function testHydrateReadsSnakeCaseFallbacks(): void
    {
        $agent = (new Agent())->hydrate(
            [
                'uuid' => 'u-1', 'max_tokens' => 7, 'enable_rag' => true, 'rag_search_mode' => 'hybrid',
                'rag_num_sources' => 3, 'rag_include_files' => true, 'rag_include_objects' => true,
                'request_quota' => 11, 'token_quota' => 12, 'search_files' => false,
                'search_objects' => false, 'is_private' => false, 'invited_users' => ['bob'],
            ]
        );

        $this->assertSame('u-1', $agent->getUuid());
        $this->assertSame(7, $agent->getMaxTokens());
        $this->assertTrue($agent->getEnableRag());
        $this->assertSame('hybrid', $agent->getRagSearchMode());
        $this->assertSame(3, $agent->getRagNumSources());
        $this->assertTrue($agent->getRagIncludeFiles());
        $this->assertTrue($agent->getRagIncludeObjects());
        $this->assertSame(11, $agent->getRequestQuota());
        $this->assertSame(12, $agent->getTokenQuota());
        $this->assertFalse($agent->getSearchFiles());
        $this->assertFalse($agent->getSearchObjects());
        $this->assertFalse($agent->getIsPrivate());
        $this->assertSame(['bob'], $agent->getInvitedUsers());
        $this->assertTrue($agent->hasInvitedUser('bob'));
        $this->assertFalse($agent->hasInvitedUser('eve'));
    }//end testHydrateReadsSnakeCaseFallbacks()

    /**
     * Camel case wins over snake case.
     *
     * @return void
     */
    public function testCamelCaseWinsOverSnakeCase(): void
    {
        $agent = (new Agent())->hydrate(['maxTokens' => 1, 'max_tokens' => 2, 'isPrivate' => false, 'is_private' => true, 'name' => 'n']);

        $this->assertSame(1, $agent->getMaxTokens());
        $this->assertFalse($agent->getIsPrivate());
        $this->assertSame('n', $agent->getName());
        $this->assertSame(['uuid', 'name', 'maxTokens', 'searchFiles', 'searchObjects', 'isPrivate'], array_keys($agent->getUpdatedFields()));
    }//end testCamelCaseWinsOverSnakeCase()

    /**
     * Empty uuid is replaced.
     *
     * @return void
     */
    public function testEmptyUuidIsReplaced(): void
    {
        $agent = (new Agent())->hydrate(['uuid' => '']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $agent->getUuid());
    }//end testEmptyUuidIsReplaced()

    /**
     * Uninvited when no invite list.
     *
     * @return void
     */
    public function testUninvitedWhenNoInviteList(): void
    {
        $this->assertFalse((new Agent())->hasInvitedUser('bob'));
    }//end testUninvitedWhenNoInviteList()
}//end class
