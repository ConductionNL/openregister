<?php

namespace Unit\Service\Integration\Providers;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\TalkLink;
use OCA\OpenRegister\Db\TalkLinkMapper;
use OCA\OpenRegister\Service\ChatService;
use OCA\OpenRegister\Service\Integration\Providers\TalkProvider;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for TalkProvider covering both service-delegation paths.
 *
 * Validates:
 * - Metadata contract (id, label, icon, group, requiredApp, storageStrategy)
 * - Conversation-listing path (delegates to TalkLinkMapper)
 * - Chat/link-management path (link + unlink operations)
 * - Graceful degradation when spreed is unavailable
 */
class TalkProviderTest extends TestCase
{

    private TalkLinkMapper&MockObject $talkLinkMapper;
    private ChatService&MockObject    $chatService;
    private IAppManager&MockObject    $appManager;
    private IUserSession&MockObject   $userSession;
    private LoggerInterface&MockObject $logger;
    private TalkProvider $provider;

    protected function setUp(): void
    {
        $this->talkLinkMapper = $this->createMock(TalkLinkMapper::class);
        $this->chatService    = $this->createMock(ChatService::class);
        $this->appManager     = $this->createMock(IAppManager::class);
        $this->userSession    = $this->createMock(IUserSession::class);
        $this->logger         = $this->createMock(LoggerInterface::class);

        $this->provider = new TalkProvider(
            talkLinkMapper: $this->talkLinkMapper,
            chatService: $this->chatService,
            appManager: $this->appManager,
            userSession: $this->userSession,
            logger: $this->logger
        );
    }//end setUp()

    // ---------------------------------------------------------------------------
    // Metadata contract
    // ---------------------------------------------------------------------------

    public function testGetIdReturnsTalk(): void
    {
        $this->assertSame('talk', $this->provider->getId());
    }//end testGetIdReturnsTalk()

    public function testGetLabelReturnsChat(): void
    {
        $this->assertSame('Chat', $this->provider->getLabel());
    }//end testGetLabelReturnsChat()

    public function testGetIconReturnsChatOutline(): void
    {
        $this->assertSame('ChatOutline', $this->provider->getIcon());
    }//end testGetIconReturnsChatOutline()

    public function testGetGroupReturnsComms(): void
    {
        $this->assertSame('comms', $this->provider->getGroup());
    }//end testGetGroupReturnsComms()

    public function testGetRequiredAppReturnsSpreed(): void
    {
        $this->assertSame('spreed', $this->provider->getRequiredApp());
    }//end testGetRequiredAppReturnsSpreed()

    public function testGetStorageStrategyReturnsLinkTable(): void
    {
        $this->assertSame('link-table', $this->provider->getStorageStrategy());
    }//end testGetStorageStrategyReturnsLinkTable()

    public function testRequiresPermissionReturnsNull(): void
    {
        $this->assertNull($this->provider->requiresPermission());
    }//end testRequiresPermissionReturnsNull()

    // ---------------------------------------------------------------------------
    // Conversation-listing path (delegate to TalkLinkMapper)
    // ---------------------------------------------------------------------------

    public function testListForObjectReturnsMappedLinks(): void
    {
        $this->appManager->method('isInstalled')->with('spreed')->willReturn(true);

        $link = new TalkLink();
        $link->setObjectUuid(objectUuid: 'obj-uuid-1');
        $link->setConversationToken(conversationToken: 'abc123');
        $link->setConversationName(conversationName: 'Bug discussion');
        $link->setLinkedBy(linkedBy: 'admin');
        $link->setLinkedAt(linkedAt: new DateTime('2026-01-01T00:00:00+00:00'));

        $this->talkLinkMapper->method('findByObjectUuid')->willReturn([$link]);

        $result = $this->provider->listForObject(objectUuid: 'obj-uuid-1');

        $this->assertCount(1, $result);
        $this->assertSame('abc123', $result[0]['conversationToken']);
        $this->assertSame('Bug discussion', $result[0]['conversationName']);
    }//end testListForObjectReturnsMappedLinks()

    public function testListForObjectReturnsEmptyWhenSpreedMissing(): void
    {
        $this->appManager->method('isInstalled')->with('spreed')->willReturn(false);

        $result = $this->provider->listForObject(objectUuid: 'obj-uuid-1');

        $this->assertSame([], $result);
    }//end testListForObjectReturnsEmptyWhenSpreedMissing()

    public function testListForObjectReturnsEmptyOnMapperException(): void
    {
        $this->appManager->method('isInstalled')->willReturn(true);
        $this->talkLinkMapper->method('findByObjectUuid')->willThrowException(new \Exception('DB error'));
        $this->logger->expects($this->once())->method('warning');

        $result = $this->provider->listForObject(objectUuid: 'obj-uuid-1');

        $this->assertSame([], $result);
    }//end testListForObjectReturnsEmptyOnMapperException()

    // ---------------------------------------------------------------------------
    // Chat/link-management path (linkToObject + unlinkFromObject)
    // ---------------------------------------------------------------------------

    public function testLinkToObjectCreatesNewLink(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);

        $this->talkLinkMapper->method('findByObjectAndToken')->willReturn(null);

        $stored = new TalkLink();
        $stored->setObjectUuid(objectUuid: 'obj-1');
        $stored->setConversationToken(conversationToken: 'tok-abc');
        $stored->setConversationName(conversationName: 'Release planning');
        $stored->setLinkedBy(linkedBy: 'admin');
        $stored->setLinkedAt(linkedAt: new DateTime());

        $this->talkLinkMapper->expects($this->once())
            ->method('insert')
            ->willReturn($stored);

        $result = $this->provider->linkToObject(
            objectUuid: 'obj-1',
            data: ['conversationToken' => 'tok-abc', 'conversationName' => 'Release planning']
        );

        $this->assertSame('tok-abc', $result['conversationToken']);
    }//end testLinkToObjectCreatesNewLink()

    public function testLinkToObjectIsIdempotentWhenLinkExists(): void
    {
        $existing = new TalkLink();
        $existing->setConversationToken(conversationToken: 'tok-xyz');
        $existing->setObjectUuid(objectUuid: 'obj-1');
        $existing->setLinkedBy(linkedBy: 'user1');
        $existing->setLinkedAt(linkedAt: new DateTime());

        $this->talkLinkMapper->method('findByObjectAndToken')->willReturn($existing);
        $this->talkLinkMapper->expects($this->never())->method('insert');

        $result = $this->provider->linkToObject(
            objectUuid: 'obj-1',
            data: ['conversationToken' => 'tok-xyz']
        );

        $this->assertSame('tok-xyz', $result['conversationToken']);
    }//end testLinkToObjectIsIdempotentWhenLinkExists()

    public function testLinkToObjectThrowsWhenTokenMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->provider->linkToObject(objectUuid: 'obj-1', data: []);
    }//end testLinkToObjectThrowsWhenTokenMissing()

    public function testUnlinkFromObjectReturnsTrueOnSuccess(): void
    {
        $link = new TalkLink();
        $link->setObjectUuid(objectUuid: 'obj-1');
        $link->setConversationToken(conversationToken: 'tok-abc');
        $link->setLinkedBy(linkedBy: 'admin');
        $link->setLinkedAt(linkedAt: new DateTime());

        $this->talkLinkMapper->method('find')->with(42)->willReturn($link);
        $this->talkLinkMapper->expects($this->once())->method('delete')->with($link);

        $result = $this->provider->unlinkFromObject(objectUuid: 'obj-1', linkId: '42');

        $this->assertTrue($result);
    }//end testUnlinkFromObjectReturnsTrueOnSuccess()

    public function testUnlinkFromObjectReturnsFalseWhenNotFound(): void
    {
        $this->talkLinkMapper->method('find')->willThrowException(new DoesNotExistException(''));

        $result = $this->provider->unlinkFromObject(objectUuid: 'obj-1', linkId: '99');

        $this->assertFalse($result);
    }//end testUnlinkFromObjectReturnsFalseWhenNotFound()

    public function testUnlinkFromObjectReturnsFalseWhenObjectMismatch(): void
    {
        $link = new TalkLink();
        $link->setObjectUuid(objectUuid: 'other-obj');
        $link->setLinkedBy(linkedBy: 'admin');
        $link->setLinkedAt(linkedAt: new DateTime());

        $this->talkLinkMapper->method('find')->willReturn($link);
        $this->talkLinkMapper->expects($this->never())->method('delete');

        $result = $this->provider->unlinkFromObject(objectUuid: 'obj-1', linkId: '10');

        $this->assertFalse($result);
    }//end testUnlinkFromObjectReturnsFalseWhenObjectMismatch()

    public function testIsTalkAvailableReturnsTrueWhenSpreedInstalled(): void
    {
        $this->appManager->method('isInstalled')->with('spreed')->willReturn(true);

        $this->assertTrue($this->provider->isTalkAvailable());
    }//end testIsTalkAvailableReturnsTrueWhenSpreedInstalled()

    public function testIsTalkAvailableReturnsFalseWhenSpreedMissing(): void
    {
        $this->appManager->method('isInstalled')->with('spreed')->willReturn(false);

        $this->assertFalse($this->provider->isTalkAvailable());
    }//end testIsTalkAvailableReturnsFalseWhenSpreedMissing()

}//end class
