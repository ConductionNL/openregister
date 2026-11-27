<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Listener\TalkAppScriptListener;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * When the "Save chat to object" script is added: on Talk's page, for a signed-in user who has Talk.
 *
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md#requirement-a-talk-action-saves-a-conversation-to-a-register-object
 */
#[CoversClass(TalkAppScriptListener::class)]
class TalkAppScriptListenerTest extends TestCase {

	private IAppManager&MockObject $appManager;

	private IUserSession&MockObject $userSession;

	private TalkAppScriptListener $listener;

	protected function setUp(): void {
		$this->appManager = $this->createMock(IAppManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->listener = new TalkAppScriptListener(appManager: $this->appManager, userSession: $this->userSession);
	}

	public function testLoadsOnTalksPageForASignedInUserWithTalk(): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->appManager->expects($this->once())
			->method('isEnabledForUser')
			->with('spreed', $user)
			->willReturn(true);

		$this->assertTrue($this->listener->shouldLoad(event: $this->pageOf(app: 'spreed')));
	}

	public function testDoesNotLoadOnAnotherAppsPage(): void {
		$this->userSession->expects($this->never())->method('getUser');
		$this->appManager->expects($this->never())->method('isEnabledForUser');

		$this->assertFalse($this->listener->shouldLoad(event: $this->pageOf(app: 'mail')));
		$this->assertFalse($this->listener->shouldLoad(event: $this->pageOf(app: 'openregister')));
	}

	public function testDoesNotLoadForAnEventThatIsNotAPageRender(): void {
		$this->appManager->expects($this->never())->method('isEnabledForUser');

		$this->assertFalse($this->listener->shouldLoad(event: new Event()));
	}

	public function testDoesNotLoadWithoutASignedInUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->appManager->expects($this->never())->method('isEnabledForUser');

		$this->assertFalse($this->listener->shouldLoad(event: $this->pageOf(app: 'spreed')));
	}

	public function testDoesNotLoadForAUserTalkIsNotEnabledFor(): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->appManager->method('isEnabledForUser')->with('spreed', $user)->willReturn(false);

		$this->assertFalse($this->listener->shouldLoad(event: $this->pageOf(app: 'spreed')));
	}

	public function testHandleAddsNothingWhenTheEventIsNotTalks(): void {
		$this->userSession->expects($this->never())->method('getUser');

		$this->listener->handle(event: $this->pageOf(app: 'files'));

		$this->assertSame('openregister-talk-actions', TalkAppScriptListener::SCRIPT);
	}

	/**
	 * A page-render event for an app.
	 *
	 * @param string $app The app whose page renders.
	 *
	 * @return BeforeTemplateRenderedEvent&MockObject
	 */
	private function pageOf(string $app): BeforeTemplateRenderedEvent&MockObject {
		$response = $this->createMock(TemplateResponse::class);
		$response->method('getApp')->willReturn($app);

		$event = $this->createMock(BeforeTemplateRenderedEvent::class);
		$event->method('getResponse')->willReturn($response);

		return $event;
	}
}
