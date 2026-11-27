<?php

/**
 * The app shell's own boot reads, carried as initial state on the SPA render.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/shell-reads-from-initial-state/specs/apphost-boilerplate/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Kernel\Contracts\ShellBootReadsInterface;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Puts the answers of three reads every OpenRegister page made at boot into
 * the page itself, so nextcloud-vue's app shell starts with them instead of
 * asking: `walkthrough_preferences` (the two walkthrough preferences) for
 * every signed-in user, and `setup_status` (the setup status document) for
 * administrators only. The setup endpoint is admin-only; it is read here
 * in-process, past its middleware, so for anyone else the key is left out
 * and the shell asks the endpoint as before.
 *
 * @spec openspec/changes/shell-reads-from-initial-state/specs/apphost-boilerplate/spec.md
 */
final class ShellBootReads implements ShellBootReadsInterface {
	/**
	 * The manifest's `walkthrough.completionConfigKey`.
	 */
	public const WALKTHROUGH_KEY = 'walkthrough_seen_version';

	/**
	 * Constructor.
	 *
	 * @param IInitialState     $initialState The page's initial state.
	 * @param IUserSession      $userSession  The current session.
	 * @param IGroupManager     $groupManager Admin check.
	 * @param IConfig           $config       User preference storage.
	 * @param SetupStatusReader $setupStatus  The setup status document.
	 */
	public function __construct(
		private readonly IInitialState $initialState,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IConfig $config,
		private readonly SetupStatusReader $setupStatus,
	) {
	}//end __construct()

	/**
	 * Provide the carried reads for the signed-in user. Nothing for a guest.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/shell-reads-from-initial-state/specs/apphost-boilerplate/spec.md
	 */
	public function provide(): void {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}

		$this->initialState->provideInitialState('walkthrough_preferences', $this->walkthroughPreferences(user: $user));

		if ($this->groupManager->isAdmin($user->getUID()) === true) {
			$this->initialState->provideInitialState('setup_status', $this->setupStatus->read());
		}
	}//end provide()

	/**
	 * The two values `GET /api/preferences/{key}` answers for the walkthrough:
	 * the last-seen version and the tour progress, each a string or null.
	 *
	 * @param IUser $user The signed-in user.
	 *
	 * @return array{seen: string|null, progress: string|null}
	 */
	private function walkthroughPreferences(IUser $user): array {
		$values = new PreferencesUserValues(config: $this->config, appName: AppIdentity::APP_ID);

		$read = static function (string $key) use ($values, $user): ?string {
			$slot = $values->slotFor(user: $user, key: $key);
			if ($slot instanceof PreferenceSlot) {
				return $values->read(slot: $slot);
			}

			return null;
		};

		return [
			'seen'     => $read(self::WALKTHROUGH_KEY),
			'progress' => $read(self::WALKTHROUGH_KEY . '-progress'),
		];
	}//end walkthroughPreferences()
}//end class
