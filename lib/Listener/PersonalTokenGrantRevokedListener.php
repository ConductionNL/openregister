<?php

/**
 * A revoked personal token takes its grant with it.
 *
 * When Nextcloud invalidates an app password, the grant kept for it in its
 * owner's store has nothing left to narrow. Removing it keeps the lapse sweep
 * from warning about a token that no longer exists, and keeps the store
 * holding only live grants.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Service\Rbac\PersonalTokenGrantStore;
use OCP\Authentication\Events\TokenInvalidatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Removes the grant of an invalidated personal token.
 *
 * @template-implements IEventListener<TokenInvalidatedEvent>
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */
class PersonalTokenGrantRevokedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param PersonalTokenGrantStore $store  The personal grants.
	 * @param LoggerInterface         $logger Diagnostics.
	 */
	public function __construct(
		private readonly PersonalTokenGrantStore $store,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Remove the invalidated token's grant.
	 *
	 * Never throws: revoking a token is the user's act, and bookkeeping here
	 * must not stand in its way.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof TokenInvalidatedEvent) === false) {
			return;
		}

		try {
			$token   = $event->getToken();
			$uid     = $token->getUID();
			$tokenId = (string) $token->getId();
			// Every sign-out invalidates a session token; only a token that
			// carries a grant has anything to remove.
			if ($this->store->read(uid: $uid, tokenId: $tokenId) === null) {
				return;
			}

			$this->store->remove(uid: $uid, tokenId: $tokenId);
		} catch (Throwable $e) {
			$this->logger->warning('[PersonalTokenGrantRevokedListener] Could not remove a revoked token\'s grant: {error}', ['error' => $e->getMessage()]);
		}
	}//end handle()
}//end class
