<?php

declare(strict_types=1);

/**
 * ContentProviderRegistrationListener unit tests (context-chat-provider).
 *
 * Proves OpenRegister's Context Chat content provider registers only when
 * `IContentManager::isContextChatAvailable()` reports the platform as
 * available, and that a standalone instance without `context_chat`
 * (isContextChatAvailable() === false, the exact behaviour of NC core's
 * built-in no-op ContentManager) never registers.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\ContextChat
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\ContextChat;

use OCA\OpenRegister\ContextChat\ContentProvider;
use OCA\OpenRegister\ContextChat\ContentProviderRegistrationListener;
use OCP\ContextChat\Events\ContentProviderRegisterEvent;
use OCP\ContextChat\IContentManager;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\ContextChat\ContentProviderRegistrationListener
 */
class ContentProviderRegistrationListenerTest extends TestCase {
	/**
	 * 6.1: registration fires only when `isContextChatAvailable()` is true.
	 */
	public function testRegistersProviderWhenContextChatAvailable(): void {
		$contentManager = $this->createMock(IContentManager::class);
		$contentManager->method('isContextChatAvailable')->willReturn(true);

		$event = new ContentProviderRegisterEvent($contentManager);

		$contentManager->expects($this->once())
			->method('registerContentProvider')
			->with('openregister', 'openregister_objects', ContentProvider::class);

		$listener = new ContentProviderRegistrationListener($contentManager);
		$listener->handle($event);
	}

	/**
	 * 6.1: no registration call when Context Chat is unavailable (e.g. the
	 * `context_chat` app is not installed — NC core's ContentManager
	 * implementation reports `isContextChatAvailable() === false` in that
	 * case and this listener must not call registerContentProvider()).
	 */
	public function testDoesNotRegisterProviderWhenContextChatUnavailable(): void {
		$contentManager = $this->createMock(IContentManager::class);
		$contentManager->method('isContextChatAvailable')->willReturn(false);

		$event = new ContentProviderRegisterEvent($contentManager);

		$contentManager->expects($this->never())->method('registerContentProvider');

		$listener = new ContentProviderRegistrationListener($contentManager);
		$listener->handle($event);
	}

	/**
	 * Non-matching events are ignored without touching the content manager.
	 */
	public function testIgnoresUnrelatedEvents(): void {
		$contentManager = $this->createMock(IContentManager::class);
		$contentManager->expects($this->never())->method('isContextChatAvailable');
		$contentManager->expects($this->never())->method('registerContentProvider');

		$listener = new ContentProviderRegistrationListener($contentManager);
		$listener->handle($this->createMock(Event::class));
	}
}
