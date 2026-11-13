<?php

/**
 * OpenRegister ServiceAccountLoginListener
 *
 * A service account cannot sign in interactively (REQ-SAT-004, D-C40-2).
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-service-account-is-a-principal-owned-by-a-team-req-sat-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Service\Authorization\MachineCredentialService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\User\Events\PostLoginEvent;
use Psr\Log\LoggerInterface;

/**
 * Cancel any sign-in of a service account that is not made with a token.
 *
 * 🔴 WHY `PostLoginEvent` AND NOT `BeforeUserLoggedInEvent`. Every sign-in —
 * the login form, single sign-on, basic auth with a password and basic auth
 * with an app token — ends in `Session::completeLogin()`, which dispatches
 * `PostLoginEvent` saying whether a token was used, and then refuses the
 * sign-in ("Login canceled by app") when a listener has logged the session
 * out. That is Nextcloud's own way for an app to cancel a sign-in. The login
 * form's chain never dispatches `BeforeUserLoggedInEvent` (read in
 * nextcloud/server stable32), so a refusal hung there would let the form in.
 *
 * A token sign-in is let through: it is how the account's integration works.
 * Every other kind is interactive and is refused.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-service-account-is-a-principal-owned-by-a-team-req-sat-004
 */
class ServiceAccountLoginListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param MachineCredentialService $accounts    Says whether the user is a service account.
	 * @param IUserSession             $userSession The session the sign-in is cancelled on.
	 * @param LoggerInterface          $logger      Where a refused sign-in is reported.
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-service-account-is-a-principal-owned-by-a-team-req-sat-004
	 */
	public function __construct(
		private readonly MachineCredentialService $accounts,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Cancel an interactive sign-in of a service account.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-service-account-is-a-principal-owned-by-a-team-req-sat-004
	 */
	public function handle(Event $event): void {
		if (($event instanceof PostLoginEvent) === false || $event->isTokenLogin() === true) {
			return;
		}

		$uid = $event->getUser()->getUID();
		if ($this->accounts->isServiceAccount(uid: $uid) === false) {
			return;
		}

		$this->logger->warning(
			'[ServiceAccountLoginListener] Refused an interactive sign-in of service account {uid}; it signs in with its tokens only.',
			['uid' => $uid]
		);
		$this->userSession->logout();
	}//end handle()
}//end class
