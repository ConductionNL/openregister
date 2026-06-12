<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Listener\MailAppScriptListener;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for MailAppScriptListener.
 */
class MailAppScriptListenerTest extends TestCase
{
    private IAppManager&MockObject $appManager;
    private IUserSession&MockObject $userSession;
    private RegisterMapper&MockObject $registerMapper;
    private LoggerInterface&MockObject $logger;
    private MailAppScriptListener $listener;

    protected function setUp(): void
    {
        $this->appManager = $this->createMock(IAppManager::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->listener = new MailAppScriptListener(
            $this->appManager,
            $this->userSession,
            $this->registerMapper,
            $this->logger
        );
    }

    public function testIgnoresNonMailEvents(): void
    {
        $event = $this->createMock(Event::class);

        // Should not throw or call any services
        $this->appManager->expects($this->never())->method('isEnabledForUser');

        $this->listener->handle($event);
        $this->assertTrue(true);
    }

    public function testIgnoresWhenNoUserIsLoggedIn(): void
    {
        $event = $this->createMailEvent();

        $this->userSession->method('getUser')->willReturn(null);
        $this->appManager->expects($this->never())->method('isEnabledForUser');

        $this->listener->handle($event);
        $this->assertTrue(true);
    }

    public function testIgnoresWhenMailAppNotEnabled(): void
    {
        $event = $this->createMailEvent();

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);

        $this->appManager->expects($this->once())
            ->method('isEnabledForUser')
            ->with('mail', $user)
            ->willReturn(false);

        $this->registerMapper->expects($this->never())->method('findAll');

        $this->listener->handle($event);
        $this->assertTrue(true);
    }

    public function testIgnoresWhenUserHasNoRegisters(): void
    {
        $event = $this->createMailEvent();

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);

        $this->appManager->method('isEnabledForUser')
            ->with('mail', $user)
            ->willReturn(true);

        $this->registerMapper->expects($this->once())
            ->method('findAll')
            ->with(1, 0)
            ->willReturn([]);

        $this->listener->handle($event);
        $this->assertTrue(true);
    }

    /**
     * Create a mock BeforeTemplateRenderedEvent that looks like a Mail app event.
     *
     * The listener checks for BeforeTemplateRenderedEvent and then
     * verifies $event->getResponse()->getApp() === 'mail'.
     *
     * @return BeforeTemplateRenderedEvent&MockObject
     */
    private function createMailEvent(): BeforeTemplateRenderedEvent&MockObject
    {
        $response = $this->createMock(TemplateResponse::class);
        $response->method('getApp')->willReturn('mail');

        $event = $this->createMock(BeforeTemplateRenderedEvent::class);
        $event->method('getResponse')->willReturn($response);

        return $event;
    }
}
