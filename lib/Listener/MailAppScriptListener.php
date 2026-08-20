<?php

/**
 * Listener that injects the mail sidebar script into the Nextcloud Mail app.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/mail-sidebar/spec.md#requirement-mail-app-script-injection-via-event-listener
 * @spec openspec/specs/mail-sidebar/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\ScriptManifestLoader;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Injects the OpenRegister mail sidebar script when the Mail app renders.
 *
 * Conditions for injection:
 * 1. The event is BeforeTemplateRenderedEvent from the Mail app.
 * 2. The Mail app is installed and enabled for the current user.
 * 3. The user has access to at least one OpenRegister register.
 *
 * @template-implements IEventListener<Event>
 *
 * @psalm-suppress UnusedClass
 */
class MailAppScriptListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager The app manager.
	 * @param IUserSession $userSession The user session.
	 * @param RegisterMapper $registerMapper The register mapper.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IUserSession $userSession,
		private readonly RegisterMapper $registerMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @spec openspec/specs/mail-sidebar/spec.md#requirement-mail-app-script-injection-via-event-listener
	 * @spec openspec/specs/mail-sidebar/spec.md
	 */
	public function handle(Event $event): void {
		// Only handle the core BeforeTemplateRenderedEvent rendered by the Mail app.
		if ($event instanceof BeforeTemplateRenderedEvent === false) {
			return;
		}

		$response = $event->getResponse();
		if ($response instanceof TemplateResponse === false) {
			return;
		}

		if ($response->getApp() !== 'mail') {
			return;
		}

		// Check Mail app is enabled.
		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}

		if ($this->appManager->isEnabledForUser('mail', $user) === false) {
			return;
		}

		// Check user has access to at least one register.
		if ($this->userHasRegisterAccess() === false) {
			return;
		}

		// Inject the sidebar script (only if compiled JS exists).
		$jsPath = __DIR__ . '/../../js/openregister-mail-sidebar.js';
		if (file_exists($jsPath) === true) {
			ScriptManifestLoader::addEntryScripts('openregister', 'mailSidebar', 'openregister-mail-sidebar');
			Util::addStyle('openregister', 'mail-sidebar');
		}

		$this->logger->debug(
			'Mail sidebar script injected for user {user}',
			[
				'user' => $user->getUID(),
			]
		);
	}//end handle()

	/**
	 * Check if the current user has access to any OpenRegister register.
	 *
	 * @return bool True if the user has register access.
	 *
	 * @spec openspec/specs/mail-sidebar/spec.md#requirement-mail-app-script-injection-via-event-listener
	 */
	private function userHasRegisterAccess(): bool {
		try {
			$registers = $this->registerMapper->findAll(1, 0);
			return count($registers) > 0;
		} catch (\Exception $e) {
			$this->logger->warning(
				'Could not check register access for mail sidebar: {error}',
				['error' => $e->getMessage()]
			);
			return false;
		}
	}//end userHasRegisterAccess()
}//end class
