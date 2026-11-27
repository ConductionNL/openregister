<?php

/**
 * Listener that loads the "Save chat to object" action into the Talk app.
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
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md#requirement-a-talk-action-saves-a-conversation-to-a-register-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCP\App\IAppManager;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\Util;

/**
 * Adds the Talk actions script when Talk renders its page for a signed-in user.
 *
 * Talk offers message actions to other apps through
 * `OCA.Talk.registerMessageAction`, which exists only on Talk's own page, so
 * the script is added there and nowhere else. The action itself attaches
 * through OpenRegister's own routes, which decide what the caller may write.
 *
 * @template-implements IEventListener<Event>
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md#requirement-a-talk-action-saves-a-conversation-to-a-register-object
 */
class TalkAppScriptListener implements IEventListener {

	/**
	 * Talk's app id, the app whose page carries the action.
	 *
	 * @var string
	 */
	public const TALK_APP_ID = 'spreed';

	/**
	 * The script that registers the action (the `talkActions` webpack entry).
	 *
	 * @var string
	 */
	public const SCRIPT = 'openregister-talk-actions';

	/**
	 * Constructor.
	 *
	 * @param IAppManager  $appManager  The app manager.
	 * @param IUserSession $userSession The user session.
	 *
	 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md#requirement-a-talk-action-saves-a-conversation-to-a-register-object
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Add the script when the event is Talk's page for a signed-in user with Talk enabled.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) \OCP\Util::addScript is Nextcloud's
	 *     static script helper; it has no injectable equivalent.
	 *
	 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md#requirement-a-talk-action-saves-a-conversation-to-a-register-object
	 */
	public function handle(Event $event): void {
		if ($this->shouldLoad(event: $event) === false) {
			return;
		}

		Util::addScript('openregister', self::SCRIPT);
	}//end handle()

	/**
	 * Whether this event is Talk's own page rendered for a signed-in user who has Talk.
	 *
	 * @param Event $event The event.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md#requirement-a-talk-action-saves-a-conversation-to-a-register-object
	 */
	public function shouldLoad(Event $event): bool {
		if (($event instanceof BeforeTemplateRenderedEvent) === false) {
			return false;
		}

		if ($event->getResponse()->getApp() !== self::TALK_APP_ID) {
			return false;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->appManager->isEnabledForUser(self::TALK_APP_ID, $user);
	}//end shouldLoad()
}//end class
