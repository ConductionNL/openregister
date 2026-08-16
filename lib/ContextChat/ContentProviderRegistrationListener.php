<?php

/**
 * OpenRegister ContentProviderRegistrationListener
 *
 * Listens for `OCP\ContextChat\Events\ContentProviderRegisterEvent` and, only
 * when Context Chat is actually available, registers OpenRegister's
 * ContentProvider — mirroring the existing OcmResourceTypeListener pattern
 * (another event-advertised platform registration).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category ContextChat
 * @package  OCA\OpenRegister\ContextChat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @template-implements IEventListener<ContentProviderRegisterEvent>
 *
 * @spec openspec/specs/context-chat-provider/spec.md#openregister-registers-a-context-chat-content-provider-only-when-the-platform-is-available
 */

declare(strict_types=1);

namespace OCA\OpenRegister\ContextChat;

use OCP\ContextChat\Events\ContentProviderRegisterEvent;
use OCP\ContextChat\IContentManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Registers the OpenRegister Context Chat content provider, softly gated on
 * `IContentManager::isContextChatAvailable()` so a standalone instance
 * without the `context_chat` app installed is entirely unaffected.
 *
 * @spec openspec/specs/context-chat-provider/spec.md#openregister-registers-a-context-chat-content-provider-only-when-the-platform-is-available
 */
class ContentProviderRegistrationListener implements IEventListener {
	/**
	 * Wire the content manager used for the availability guard.
	 *
	 * @param IContentManager $contentManager Core Context Chat content manager (always resolvable via DI).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/context-chat-provider/spec.md#openregister-registers-a-context-chat-content-provider-only-when-the-platform-is-available
	 */
	public function __construct(
		private readonly IContentManager $contentManager,
	) {
	}//end __construct()

	/**
	 * Handle the provider-registration event, registering OpenRegister's
	 * ContentProvider only when the platform reports Context Chat as
	 * available.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/context-chat-provider/spec.md#openregister-registers-a-context-chat-content-provider-only-when-the-platform-is-available
	 */
	public function handle(Event $event): void {
		if (($event instanceof ContentProviderRegisterEvent) === false) {
			return;
		}

		// Belt-and-braces: the event only fires when `context_chat` dispatches
		// it (which already implies presence), but this guard protects
		// against any future platform change where the event fires
		// unconditionally — mirrors the class_exists guard already used for
		// the optional Tables integration.
		if ($this->contentManager->isContextChatAvailable() === false) {
			return;
		}

		$event->registerContentProvider(
			ContentProvider::APP_ID,
			ContentProvider::PROVIDER_ID,
			ContentProvider::class
		);
	}//end handle()
}//end class
