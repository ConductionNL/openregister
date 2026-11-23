<?php

/**
 * Which grant is in force for this request, and nothing else.
 *
 * One object, bound once where a machine principal is resolved and read
 * wherever a decision is made. It exists so that the permission handler does
 * not have to know how a Consumer is authenticated, and so that the
 * authentication path does not have to know how a grant is evaluated — ADR-091
 * draws exactly that line: the protocol that validates a credential is
 * OpenConnector's, the grant a resolved principal carries is the authorization
 * layer's.
 *
 * 🔴 UNBOUND IS NOT "NO GRANT" BY ACCIDENT. A session user has no token and
 * legitimately narrows nothing. That is why binding is explicit: an
 * authenticated machine principal binds, even when its grant is null, so the
 * difference between "a person is calling" and "a token with no grant is
 * calling" stays visible to anyone reading this later.
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
use OCA\OpenRegister\Kernel\Contracts\CurrentTokenGrantInterface;
use OCP\ICacheFactory;

/**
 * The grant in force for the current request.
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */
class TokenGrantSource implements CurrentTokenGrantInterface {

	/**
	 * The grant in force, when one is.
	 *
	 * @var TokenGrant|null
	 */
	private ?TokenGrant $grant = null;

	/**
	 * Whether a machine principal was bound for this request at all.
	 *
	 * @var bool
	 */
	private bool $bound = false;

	/**
	 * Bind the principal this request is acting as.
	 *
	 * @param TokenGrant|null $grant The grant it carries, or null for none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	public function bind(?TokenGrant $grant): void {
		$this->grant = $grant;
		$this->bound = true;
	}//end bind()

	/**
	 * Bind from a Consumer's stored authorization configuration.
	 *
	 * @param array<string, mixed>|null $storedAuthorization The Consumer's configuration.
	 * @param string                    $tokenId                    Which Consumer this is.
	 *
	 * @return TokenGrant|null The grant that was bound.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	public function bindFromConsumer(?array $storedAuthorization, string $tokenId): ?TokenGrant {
		$stored = null;
		if (is_array($storedAuthorization) === true) {
			$stored = ($storedAuthorization[TokenGrant::KEY] ?? null);
		}

		$grant = TokenGrant::fromStored(stored: $stored, tokenId: $tokenId);
		$this->bind(grant: $grant);

		return $grant;
	}//end bindFromConsumer()

	/**
	 * The grant in force, or null when nothing narrows this request.
	 *
	 * @return TokenGrant|null The grant.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	public function current(): ?TokenGrant {
		return $this->grant;
	}//end current()

	/**
	 * The id of the token whose grant is bound to this request, or null when no
	 * grant with an id is bound (an interactive session or an ungranted token).
	 *
	 * @return string|null The token id, or null.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-the-effective-grant-is-visible-and-writes-name-the-token
	 */
	public function currentTokenId(): ?string {
		if ($this->grant === null || $this->grant->tokenId === '') {
			return null;
		}

		return $this->grant->tokenId;
	}//end currentTokenId()

	/**
	 * Count this call against the grant's own rate limit, refusing it when over.
	 *
	 * Counted at the moment the credential is accepted, before the request acts
	 * as the consumer's user, so a call over the limit never runs. A grant
	 * without a limit, or an instance without a distributed cache, counts and
	 * refuses nothing.
	 *
	 * @param TokenGrant|null    $grant        The grant just bound, or null.
	 * @param ICacheFactory|null $cacheFactory The cache the counter lives in, or null.
	 * @param int                $now          The current time (unix seconds).
	 *
	 * @return void
	 *
	 * @throws TokenRateLimitExceededException When the call is over the grant's limit.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#scenario-one-runaway-integration-does-not-take-the-balie-down
	 */
	public function refuseOverGrantLimit(?TokenGrant $grant, ?ICacheFactory $cacheFactory, int $now): void {
		if ($grant === null || $grant->rateLimit === null || $cacheFactory === null) {
			return;
		}

		$outcome = (new TokenGrantRateLimiter(cacheFactory: $cacheFactory))->consume(grant: $grant, now: $now);
		if ($outcome === null || $outcome['allowed'] === true) {
			return;
		}

		throw new TokenRateLimitExceededException(
			limit: $outcome['limit'],
			window: TokenGrantRateLimiter::WINDOW_SECONDS,
			retryAfter: ($outcome['resetAt'] - $now)
		);
	}//end refuseOverGrantLimit()

	/**
	 * Whether a machine principal was bound for this request.
	 *
	 * @return bool True when one was.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	public function isBound(): bool {
		return $this->bound;
	}//end isBound()


	/**
	 * Run a callable with no grant in force, then put the binding back.
	 *
	 * A grant is a ceiling on what ITS HOLDER may do. An evaluation that has
	 * deliberately stopped acting as anybody — {@see
	 * \OCA\OpenRegister\Service\ObjectService::runAsAnonymous()} — has no
	 * holder, so there is nothing for the ceiling to apply to. Leaving the
	 * binding in place there does not narrow "the caller"; it narrows the
	 * PUBLIC answer by the private state of a token that is no longer the
	 * subject of the question, which is how two callers end up getting
	 * different answers from an endpoint whose whole contract is that they
	 * must not.
	 *
	 * 🔴 IT CLEARS BOTH FIELDS, NOT JUST THE GRANT. `bind(null)` would leave
	 * `isBound()` true, and this class's own contract says that means "a token
	 * with no grant is calling" — a different statement from "no token is
	 * calling", which is what holds inside the scope. Both are saved and both
	 * are restored.
	 *
	 * Restores in a `finally`, so a throw inside the callable cannot leak a
	 * cleared binding forward, and nesting composes: an inner call restores
	 * the outer call's state rather than the unbound one.
	 *
	 * @param callable $operation The operation to run with no grant in force.
	 *
	 * @return mixed Whatever the callable returns.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function runWithoutGrant(callable $operation) {
		$previousGrant = $this->grant;
		$previousBound = $this->bound;

		$this->grant = null;
		$this->bound = false;

		try {
			return $operation();
		} finally {
			$this->grant = $previousGrant;
			$this->bound = $previousBound;
		}
	}//end runWithoutGrant()
}//end class
