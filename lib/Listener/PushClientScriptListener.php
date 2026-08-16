<?php

/**
 * Listener that loads the always-on Web Push subscribe client on every page.
 *
 * The subscribe client must work regardless of which Nextcloud app the user
 * is viewing (push notifications are cross-app), so this listener injects the
 * static `js/openregister-push-client.js` on EVERY full-page render via
 * \OCP\Util::addScript. The client is opt-in only — it NEVER prompts for push
 * permission on load; it merely exposes a global the settings toggle / a user
 * gesture calls to subscribe (see src docs in the script). It also reads the
 * `_suppressForegroundPopup` flag the dispatcher stamps so an open tab with an
 * active subscription suppresses the duplicate stock popup.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\Util;

/**
 * Injects the opt-in Web Push subscribe client on every authenticated page.
 *
 * @template-implements IEventListener<Event>
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
 */
class PushClientScriptListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession The current user session.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Handle the event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof BeforeTemplateRenderedEvent === false) {
			return;
		}

		// Only for an authenticated user (subscriptions are user-scoped).
		if ($this->userSession->getUser() === null) {
			return;
		}

		// Load the static, opt-in subscribe client (no prompt on load).
		if (file_exists(__DIR__ . '/../../js/openregister-push-client.js') === true) {
			Util::addScript('openregister', '../js/openregister-push-client');
		}
	}//end handle()
}//end class
