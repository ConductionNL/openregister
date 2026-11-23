<?php

/**
 * Binds the grant of the personal API token that opened this request.
 *
 * A Consumer's grant is bound in AuthorizationService, where its credential is
 * accepted. A personal token is a Nextcloud app password, which Nextcloud
 * itself accepts before any OpenRegister code runs; the only thread back to it
 * is the session's `app_password`. This class follows that thread to the token,
 * reads the token's grant from its owner's store and binds it, then counts the
 * call against the grant's own rate limit.
 *
 * An interactive session, a token without a grant, and a request a Consumer
 * already bound are left exactly as they were.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
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

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Exception\TokenRateLimitExceededException;
use OCP\Authentication\Token\IProvider as ITokenProvider;
use OCP\ICacheFactory;
use OCP\ISession;
use Throwable;
use OCA\OpenRegister\Kernel\Contracts\PersonalTokenGrantBinderInterface;

/**
 * Binds a personal token's grant to the request.
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */
class PersonalTokenGrantBinder implements PersonalTokenGrantBinderInterface {

	/**
	 * The session key Nextcloud keeps an API caller's app password under.
	 *
	 * @var string
	 */
	public const SESSION_KEY = 'app_password';

	/**
	 * Constructor.
	 *
	 * @param ISession                $session       The current session.
	 * @param ITokenProvider          $tokenProvider Resolves an app password to its token.
	 * @param PersonalTokenGrantStore $store         The personal grants.
	 * @param TokenGrantSource        $source        The grant in force for this request.
	 * @param ICacheFactory           $cacheFactory  Holds the rate-limit counters.
	 */
	public function __construct(
		private readonly ISession $session,
		private readonly ITokenProvider $tokenProvider,
		private readonly PersonalTokenGrantStore $store,
		private readonly TokenGrantSource $source,
		private readonly ICacheFactory $cacheFactory,
	) {
	}//end __construct()

	/**
	 * Whether this request was opened with a personal token rather than an interactive sign-in.
	 *
	 * @return bool True when the session carries an app password.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	public function isTokenRequest(): bool {
		return $this->sessionPassword() !== null;
	}//end isTokenRequest()

	/**
	 * Bind the calling token's grant, and refuse the call when it is over the grant's limit.
	 *
	 * @param int $now The current unix time.
	 *
	 * @return TokenGrant|null The grant bound, or null when nothing was bound.
	 *
	 * @throws TokenRateLimitExceededException When the call is over the grant's limit.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	public function bind(int $now): ?TokenGrant {
		if ($this->source->isBound() === true) {
			return null;
		}

		$password = $this->sessionPassword();
		if ($password === null) {
			return null;
		}

		$grant = $this->grantForPassword(password: $password);
		if ($grant === null) {
			return null;
		}

		$this->source->bind(grant: $grant);
		$this->refuseOverLimit(grant: $grant, now: $now);

		return $grant;
	}//end bind()

	/**
	 * Count this call against the grant's rate limit and refuse it when over.
	 *
	 * @param TokenGrant $grant The grant just bound.
	 * @param int        $now   The current unix time.
	 *
	 * @return void
	 *
	 * @throws TokenRateLimitExceededException When the call is over the limit.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#scenario-one-runaway-integration-does-not-take-the-balie-down
	 */
	private function refuseOverLimit(TokenGrant $grant, int $now): void {
		if ($grant->rateLimit === null) {
			return;
		}

		$outcome = (new TokenGrantRateLimiter(cacheFactory: $this->cacheFactory))->consume(grant: $grant, now: $now);
		if ($outcome === null || $outcome['allowed'] === true) {
			return;
		}

		throw new TokenRateLimitExceededException(
			limit: $outcome['limit'],
			window: TokenGrantRateLimiter::WINDOW_SECONDS,
			retryAfter: ($outcome['resetAt'] - $now)
		);
	}//end refuseOverLimit()

	/**
	 * The grant of the token behind an app password.
	 *
	 * 🔴 A TOKEN THAT CANNOT BE RESOLVED PERMITS NOTHING. Nextcloud accepted
	 * this password before any controller ran, so a lookup that fails now is
	 * a failure of the lookup, not an unknown token; reading it as "no grant"
	 * would hand a narrowed token its owner's full rights whenever the token
	 * table hiccups.
	 *
	 * @param string $password The session's app password.
	 *
	 * @return TokenGrant|null The grant, an empty grant when unresolvable, or null when the token has none.
	 */
	private function grantForPassword(string $password): ?TokenGrant {
		try {
			$token = $this->tokenProvider->getToken($password);
		} catch (Throwable $unresolvable) {
			return TokenGrant::fromStored(stored: false, tokenId: PersonalTokenGrantStore::actorId(tokenId: 'unresolved'));
		}

		return $this->store->grantFor(uid: $token->getUID(), tokenId: (string) $token->getId());
	}//end grantForPassword()

	/**
	 * The app password the session holds.
	 *
	 * @return string|null The password, or null when there is none.
	 */
	private function sessionPassword(): ?string {
		try {
			$password = $this->session->get(self::SESSION_KEY);
		} catch (Throwable $sessionUnavailable) {
			return null;
		}

		if (is_string($password) === false || $password === '') {
			return null;
		}

		return $password;
	}//end sessionPassword()
}//end class
