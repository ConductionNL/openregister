<?php

/**
 * Works out which token the current call was made with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\Db\Consumer;
use OCA\OpenRegister\Db\ConsumerMapper;
use OCP\Authentication\Token\IProvider as ITokenProvider;
use OCP\Authentication\Token\IToken;
use OCP\ISession;
use OCP\IUserSession;
use Throwable;

/**
 * Resolves the calling token from the session, and the consumer behind it.
 *
 * Nextcloud hands an API caller an app password, and the session remembers it
 * under `app_password`. That is the only thread back from a request deep in
 * the save path to the credential that opened it. An interactive browser login
 * has no `app_password`, which is exactly the distinction the requirement
 * rests on: an entry naming a token has to mean a machine wrote this.
 *
 * ⚠️ FAIL-SOFT THROUGHOUT, IN ONE DIRECTION. Every failure returns null, which
 * means "no token is named on this row". It never throws and it never guesses:
 * an audit trail that stops a save is worse than one that admits it does not
 * know who called, and a trail that names the WRONG consumer is worse than
 * both, because somebody will act on it.
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */
class TokenResolver {
	/**
	 * The session key Nextcloud stores an API caller's app password under.
	 *
	 * @var string
	 */
	public const SESSION_KEY = 'app_password';

	/**
	 * Constructor.
	 *
	 * @param ISession       $session       The current session.
	 * @param ITokenProvider $tokenProvider Resolves an app password to its token record.
	 * @param IUserSession   $userSession   The authenticated principal.
	 * @param ConsumerMapper $consumerMapper Registered API consumers.
	 */
	public function __construct(
		private readonly ISession $session,
		private readonly ITokenProvider $tokenProvider,
		private readonly IUserSession $userSession,
		private readonly ConsumerMapper $consumerMapper,
	) {
	}//end __construct()

	/**
	 * The token identity behind the current call, if a token made it.
	 *
	 * @return TokenIdentity|null The identity, or null for an interactive or unauthenticated call.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function resolve(): ?TokenIdentity {
		$token = $this->currentToken();
		if ($token === null) {
			return null;
		}

		$ownerUid = $this->ownerUid(token: $token);
		$identity = new TokenIdentity(
			mechanism: 'app-password',
			reference: (string)$token->getId(),
			name: $this->tokenName(token: $token),
			ownerUid: $ownerUid,
			ownerName: $this->ownerName(ownerUid: $ownerUid),
			consumerUuid: null,
			consumerName: null,
		);

		return $this->withConsumer(identity: $identity, ownerUid: $ownerUid);
	}//end resolve()

	/**
	 * The token record behind the session's app password.
	 *
	 * @return IToken|null The token, or null when this is not a token call.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	private function currentToken(): ?IToken {
		try {
			$password = $this->session->get(self::SESSION_KEY);
		} catch (Throwable $sessionUnavailable) {
			return null;
		}

		if (is_string($password) === false || $password === '') {
			return null;
		}

		try {
			return $this->tokenProvider->getToken($password);
		} catch (Throwable $tokenUnavailable) {
			return null;
		}
	}//end currentToken()

	/**
	 * The label the token carries.
	 *
	 * @param IToken $token The resolved token.
	 *
	 * @return string|null The name, or null when it is blank.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	private function tokenName(IToken $token): ?string {
		try {
			$name = trim($token->getName());
		} catch (Throwable $unnamed) {
			return null;
		}

		if ($name === '') {
			return null;
		}

		return $name;
	}//end tokenName()

	/**
	 * The uid of the principal the token belongs to.
	 *
	 * Taken from the TOKEN and not from the user session. They are the same in
	 * the ordinary case, and when they differ the token is the honest answer:
	 * an impersonation or a background continuation can move the session user,
	 * and "whose credential was used" is the question being asked.
	 *
	 * @param IToken $token The resolved token.
	 *
	 * @return string|null The uid, or null when the token does not name one.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	private function ownerUid(IToken $token): ?string {
		try {
			$uid = trim($token->getUID());
		} catch (Throwable $unknownOwner) {
			return null;
		}

		if ($uid === '') {
			return null;
		}

		return $uid;
	}//end ownerUid()

	/**
	 * The display name of the token's owner.
	 *
	 * @param string|null $ownerUid The owner's uid.
	 *
	 * @return string|null The display name, or null when it cannot be read.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	private function ownerName(?string $ownerUid): ?string {
		if ($ownerUid === null) {
			return null;
		}

		try {
			$user = $this->userSession->getUser();
		} catch (Throwable $sessionUnavailable) {
			return null;
		}

		if ($user === null || $user->getUID() !== $ownerUid) {
			return null;
		}

		$displayName = trim($user->getDisplayName());
		if ($displayName === '') {
			return null;
		}

		return $displayName;
	}//end ownerName()

	/**
	 * Add the registered consumer the token belongs to, when there is one.
	 *
	 * Matched on the consumer's own user, which is the binding OpenRegister
	 * already has: a consumer names the Nextcloud user its calls run as, and
	 * `AuthorizationService` sets that user on every authorised call. Matching
	 * on the token's NAME was the other candidate and is not used, because an
	 * app password's name is free text its owner can retype at any time, and a
	 * consumer attribution that a rename silently redirects is worse than none.
	 *
	 * An ambiguous match, where two consumers share one user, resolves to no
	 * consumer rather than to the first of them.
	 *
	 * @param TokenIdentity $identity The identity resolved so far.
	 * @param string|null   $ownerUid The token owner's uid.
	 *
	 * @return TokenIdentity The identity, with the consumer when one was found.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	private function withConsumer(TokenIdentity $identity, ?string $ownerUid): TokenIdentity {
		$consumer = $this->findConsumer(ownerUid: $ownerUid);
		if ($consumer === null) {
			return $identity;
		}

		return new TokenIdentity(
			mechanism: $identity->mechanism(),
			reference: $identity->reference(),
			name: $identity->name(),
			ownerUid: $identity->ownerUid(),
			ownerName: $identity->ownerName(),
			consumerUuid: $consumer->getUuid(),
			consumerName: $consumer->getName(),
		);
	}//end withConsumer()

	/**
	 * The single registered consumer running as this user, if exactly one does.
	 *
	 * @param string|null $ownerUid The token owner's uid.
	 *
	 * @return Consumer|null The consumer, or null when there is no unambiguous one.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	private function findConsumer(?string $ownerUid): ?Consumer {
		if ($ownerUid === null) {
			return null;
		}

		try {
			$consumers = $this->consumerMapper->findAll(filters: ['user_id' => $ownerUid]);
		} catch (Throwable $lookupFailed) {
			return null;
		}

		if (count($consumers) !== 1) {
			return null;
		}

		$consumer = $consumers[0];
		if (($consumer instanceof Consumer) === false) {
			return null;
		}

		return $consumer;
	}//end findConsumer()
}//end class
