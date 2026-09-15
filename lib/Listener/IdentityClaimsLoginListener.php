<?php

/**
 * Derive access at sign-in, from what the identity provider asserted.
 *
 * One sign-in, one derivation, and nothing between them. The alternative is a
 * matrix somebody maintains by hand, and the row that stays in it after an
 * employee changes department is the finding an auditor eventually makes.
 *
 * COSTS NOTHING WHERE NOTHING IS DECLARED. An instance with no rules exits on
 * one app-config read, before any event is dispatched, so a sign-in on an
 * instance that never wanted this is unchanged.
 *
 * NEVER FAILS A SIGN-IN. Everything here is wrapped: a listener that throws, a
 * provider that answers nothing, an unreadable rule set. Derived access is an
 * addition to what an administrator already granted, and losing it must never
 * be the reason somebody cannot log in.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\IdentityClaimsCollectingEvent;
use OCA\OpenRegister\Service\Rbac\DerivedGrantStore;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserLoggedInEvent;
use Psr\Log\LoggerInterface;

/**
 * Turns one sign-in into the derived grants the rules declare.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */
class IdentityClaimsLoginListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param DerivedGrantStore $store      The rules, the claims and the grants.
	 * @param IEventDispatcher  $dispatcher Where the claims are asked for.
	 * @param LoggerInterface   $logger     Where a failed derivation is reported.
	 */
	public function __construct(
		private readonly DerivedGrantStore $store,
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Derive this account's grants, or leave it with none.
	 *
	 * @param Event $event The sign-in.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof UserLoggedInEvent) === false) {
			return;
		}

		$userId = $event->getUser()->getUID();

		try {
			// The cheap exit. An instance that declares no rule does no work and
			// dispatches nothing.
			if ($this->store->rules() === []) {
				return;
			}

			$collecting = new IdentityClaimsCollectingEvent(userId: $userId);
			$this->dispatcher->dispatchTyped($collecting);
			$claims = $collecting->getClaims();

			// A sign-in that asserts nothing leaves the account with no derived
			// access, rather than with the access it had yesterday. Carrying the
			// old set forward would keep somebody in a department they left,
			// which is the failure this exists to end rather than to automate.
			if ($claims === []) {
				$this->store->forget(userId: $userId);
				return;
			}

			$granted = $this->store->apply(userId: $userId, claims: $claims);

			$this->logger->info(
				message: '[IdentityClaimsLoginListener] Derived access from the claims of this sign-in',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'userId' => $userId,
					'claims' => array_keys($claims),
					'grants' => count($granted),
				]
			);
		} catch (\Throwable $e) {
			// See the class docblock: derived access is an addition, and losing
			// it must never be the reason somebody cannot sign in.
			$this->logger->error(
				message: '[IdentityClaimsLoginListener] Could not derive access for this sign-in',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'userId' => $userId,
					'error' => $e->getMessage(),
				]
			);
		}//end try
	}//end handle()
}//end class
