<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp;

use OCA\OpenRegister\Mcp\Db\Conversation;
use OCA\OpenRegister\Mcp\Db\ConversationMapper;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Only a conversation's owner reads or changes it.
 *
 * @covers \OCA\OpenRegister\Mcp\Db\ConversationMapper
 */
class ConversationMapperOwnerRulesTest extends TestCase
{
    private ConversationMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ConversationMapper(
            db: $this->createMock(IDBConnection::class),
            eventDispatcher: $this->createMock(IEventDispatcher::class)
        );
    }//end setUp()

    private function conversation(?string $owner, ?string $organisation = null): Conversation
    {
        $conversation = new Conversation();
        $conversation->setUserId($owner);
        $conversation->setOrganisation($organisation);

        return $conversation;
    }//end conversation()

    public function testTheOwnerMayModify(): void
    {
        $this->assertTrue($this->mapper->canUserModifyConversation(conversation: $this->conversation('alice'), userId: 'alice'));
    }//end testTheOwnerMayModify()

    public function testAnotherUserMayNotModify(): void
    {
        $this->assertFalse($this->mapper->canUserModifyConversation(conversation: $this->conversation('alice'), userId: 'bob'));
    }//end testAnotherUserMayNotModify()

    public function testAConversationWithoutOwnerCannotBeModified(): void
    {
        $this->assertFalse($this->mapper->canUserModifyConversation(conversation: $this->conversation(null), userId: 'bob'));
    }//end testAConversationWithoutOwnerCannotBeModified()

    public function testAnotherUserMayNotRead(): void
    {
        $this->assertFalse($this->mapper->canUserAccessConversation(conversation: $this->conversation('alice'), userId: 'bob'));
    }//end testAnotherUserMayNotRead()

    public function testTheOwnerReadsOnlyInsideTheConversationsOrganisation(): void
    {
        $conversation = $this->conversation('alice', 'org-a');

        $this->assertTrue($this->mapper->canUserAccessConversation(conversation: $conversation, userId: 'alice', organisationUuid: 'org-a'));
        $this->assertFalse($this->mapper->canUserAccessConversation(conversation: $conversation, userId: 'alice', organisationUuid: 'org-b'));
        $this->assertTrue($this->mapper->canUserAccessConversation(conversation: $conversation, userId: 'alice'));
    }//end testTheOwnerReadsOnlyInsideTheConversationsOrganisation()
}//end class
