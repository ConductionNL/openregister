<?php

declare(strict_types=1);

namespace OCA\Mail\Events {
    if (!class_exists(\OCA\Mail\Events\BeforeMailTemplateRenderedEvent::class, false)) {
        class BeforeMailTemplateRenderedEvent extends \OCP\EventDispatcher\Event {}
    }
}

namespace Unit\Listener {

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Listener\MailAppScriptListener;
use OCP\App\IAppManager;
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
        // Create a mock event class that appears to be from the Mail app
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
     * Create an event that looks like it comes from the Mail app.
     *
     * Uses a real class in the OCA\Mail namespace so that get_class()
     * contains 'OCA\Mail\' as the listener checks.
     *
     * @return Event
     */
    private function createMailEvent(): Event
    {
        return new \OCA\Mail\Events\BeforeMailTemplateRenderedEvent();
    }
}
} // end namespace Unit\Listener
