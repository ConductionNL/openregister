<?php

/**
 * CaseTokenService — mint / resolve / revoke public "track your case"
 * links to an OpenRegister object.
 *
 * This is the backend behind the Shares integration provider's public
 * token-link surface (AD-22 create()/delete()). A leaf app (procest)
 * mints a token bound to an object; the token resolves anonymously to a
 * PUBLIC-SAFE view of that object — RBAC-respecting, NOT a bypass.
 *
 * RBAC contract (ADR-005, fail-closed):
 *   - mint requires a logged-in user; the minter is recorded.
 *   - resolve runs the canonical OR read path with `_rbac: true`, so
 *     only the fields the PUBLIC group may read are returned (the same
 *     `publicatiedatum<=$now` + public-group predicate that drives
 *     `ObjectsController::show()` for anonymous callers). The token is
 *     an addressing handle, never an authorisation grant.
 *   - revoked / expired / unknown tokens resolve to null so the
 *     controller returns 404 — no enumeration oracle.
 *
 * Lazy-resolution policy mirrors {@see ShareLinkService}: ObjectService
 * is pulled from the injected container on demand so the ctor stays light.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Db\CaseToken;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Notifications\Service\Timeline\PublicTimelineInterface;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * CaseTokenService — public case-token link mint / resolve / revoke.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Service composes the token mapper, secure-random,
 * user-session, URL generator and the lazily-resolved ObjectService — each is required for the
 * mint / resolve / revoke contract.
 *
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */
class CaseTokenService implements CaseTokenServiceInterface {

	/**
	 * Token length in characters (URL-safe alphanumeric). 43 chars of
	 * the 62-symbol alphabet ≈ 256 bits of entropy — non-guessable, so a
	 * brute-force enumeration of the resolve endpoint is infeasible.
	 *
	 * @var int
	 */
	private const TOKEN_LENGTH = 43;

	/**
	 * How many public entries a resolved token carries at most.
	 *
	 * A public page is read on a phone and the newest entries are the ones
	 * that answer "what is happening with my case". A case with a longer
	 * history is not an error, it is a case that needs paging, and paging an
	 * anonymous endpoint is a separate decision.
	 *
	 * @var int
	 */
	private const PUBLIC_TIMELINE_LIMIT = 50;

	/**
	 * Constructor.
	 *
	 * @param CaseTokenIssuance  $issuance    Token store, secure RNG and public URL builder.
	 * @param IUserSession       $userSession Current user (minter).
	 * @param LoggerInterface    $logger      Logger.
	 * @param ContainerInterface $container   App container the ObjectOperationsInterface is resolved from on demand.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CaseTokenIssuance $issuance,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Mint a public token-link for an object.
	 *
	 * @param CaseTokenTarget $target     The object the token is bound to, and its label.
	 * @param int|null        $ttlSeconds Optional time-to-live in seconds; null = never expires.
	 *
	 * @return array<string,mixed> Minted token metadata + the public URL.
	 *
	 * @throws InvalidArgumentException When objectUuid is empty or the
	 *                                  minter is anonymous.
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function mintFor(CaseTokenTarget $target, ?int $ttlSeconds = null): array {
		if (trim($target->objectUuid) === '') {
			throw new InvalidArgumentException('objectUuid is required to mint a case token');
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			// Minting is a write that establishes a public surface — it
			// MUST be performed by an authenticated user, never anonymously.
			throw new InvalidArgumentException('A logged-in user is required to mint a case token');
		}

		$now = new DateTime();
		$expires = $this->expiryOf(now: $now, ttlSeconds: $ttlSeconds);

		$token = $this->issuance->secureRandom->generate(
			self::TOKEN_LENGTH,
			ISecureRandom::CHAR_ALPHANUMERIC
		);

		$entity = $this->newToken(token: $token, target: $target);
		$entity->setCreatedBy($user->getUID());
		$entity->setCreatedAt($now);
		$entity->setExpiresAt($expires);
		$entity->setRevokedAt(null);

		$saved = $this->issuance->mapper->insert($entity);

		$data = $saved->jsonSerialize();
		$data['url'] = $this->buildPublicUrl(token: $token);
		return $data;
	}//end mintFor()

	/**
	 * When a token minted now expires.
	 *
	 * @param DateTime $now        The mint moment.
	 * @param int|null $ttlSeconds Time-to-live in seconds.
	 *
	 * @return DateTime|null The expiry, or null when the ttl is absent or not positive.
	 */
	private function expiryOf(DateTime $now, ?int $ttlSeconds): ?DateTime {
		if ($ttlSeconds === null || $ttlSeconds <= 0) {
			return null;
		}

		return (clone $now)->modify('+' . $ttlSeconds . ' seconds');
	}//end expiryOf()

	/**
	 * A new, unsaved token row bound to the target object.
	 *
	 * @param string          $token  The opaque token.
	 * @param CaseTokenTarget $target The object and label.
	 *
	 * @return CaseToken
	 */
	private function newToken(string $token, CaseTokenTarget $target): CaseToken {
		$entity = new CaseToken();
		$entity->setToken($token);
		$entity->setObjectUuid($target->objectUuid);
		$entity->setRegisterId($target->registerId);
		$entity->setSchemaId($target->schemaId);
		$entity->setLabel($target->label);

		return $entity;
	}//end newToken()

	/**
	 * Resolve a token to a PUBLIC-SAFE view of the referenced object.
	 *
	 * Runs the canonical OR read path with RBAC enforced (`_rbac: true`):
	 * an anonymous caller only sees the fields the public group may read,
	 * exactly as `ObjectsController::show()` does for anonymous requests.
	 * The token never bypasses RBAC — it only addresses the object.
	 *
	 * Returns null on any failure (unknown / revoked / expired token,
	 * object missing, RBAC-denied) so the caller returns a uniform 404
	 * and the endpoint is not an enumeration oracle.
	 *
	 * THE VIEW CARRIES THE OBJECT'S PUBLIC TIMELINE. A citizen following a
	 * "track your case" link came to find out what has happened, and a status
	 * with no history answers half the question. The entries are filtered on
	 * `public` HERE, on the server, by the same service the signed-in timeline
	 * reads: nothing that says `internal` crosses this boundary, and no caller
	 * can ask this method for a different filter.
	 *
	 * @param string $token The opaque token.
	 *
	 * @return array<string,mixed>|null The public-safe object view, or
	 *                                  null when the token cannot be
	 *                                  resolved.
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function resolve(string $token): ?array {
		$row = $this->validToken(token: $token);
		if ($row === null) {
			return null;
		}

		$objectService = $this->resolveObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			// RBAC-respecting read: no user is logged in on the public
			// resolve endpoint, so `_rbac: true` enforces the public-group
			// read predicate. We do NOT pass an admin bypass.
			$entity = $objectService->find(
				id: $row->getObjectUuid(),
				options: new ObjectFindOptions(register: $row->getRegisterId(), schema: $row->getSchemaId()),
				_rbac: true,
				_multitenancy: true
			);

			return $this->publicView(row: $row, read: ['objectService' => $objectService, 'entity' => $entity]);
		} catch (Throwable $e) {
			// RBAC-denied / not-found / any read failure → 404 (null).
			// Logged server-side; the caller never learns why.
			$this->logger->debug(
				'[CaseTokenService] resolve failed for a token',
				['exception' => $e]
			);
			return null;
		}
	}//end resolve()

	/**
	 * The stored row of a token that may be resolved now.
	 *
	 * @param string $token The opaque token.
	 *
	 * @return CaseToken|null The row, or null when blank, unknown, revoked or expired.
	 */
	private function validToken(string $token): ?CaseToken {
		if (trim($token) === '') {
			return null;
		}

		$row = $this->issuance->mapper->findByToken($token);
		if ($row === null || $row->isValidAt(new DateTime()) === false) {
			// Unknown, revoked or expired — fail closed.
			return null;
		}

		return $row;
	}//end validToken()

	/**
	 * The public-safe view of the object a token addresses.
	 *
	 * The object is read in {@see resolve()}; this renders it through the
	 * same RBAC- and tenancy-respecting path.
	 *
	 * @param CaseToken $row The valid token row.
	 * @param array{objectService: object, entity: ?object} $read The ObjectService and the object it found.
	 *
	 * @return array<string,mixed>|null The view, or null when the object is not found.
	 */
	private function publicView(CaseToken $row, array $read): ?array {
		$entity = $read['entity'];
		if ($entity === null) {
			return null;
		}

		$rendered = $read['objectService']->renderEntity(entity: $entity, _rbac: true, _multitenancy: true);

		return [
			'token' => $row->getToken(),
			'label' => $row->getLabel(),
			'object' => $rendered,
			'timeline' => $this->publicTimeline(entity: $entity),
		];
	}//end publicView()

	/**
	 * The public entries on one object, as a stranger may read them.
	 *
	 * The read and the five-key projection are {@see PublicTimeline}'s, the
	 * same class the access-link reader asks. Two anonymous surfaces that each
	 * decided for themselves what leaves would come to disagree, and the
	 * disagreement would be a handler's name on a citizen's screen.
	 *
	 * SOFT BY DESIGN. A timeline that cannot be read answers the empty list:
	 * the status page shipped before the timeline did, and an instance that
	 * cannot build the reader must still show the status rather than a uniform
	 * 404 a citizen reads as a revoked link. `PublicTimeline` already softens a
	 * failed read per source; this catch covers the container failing to build
	 * it at all, and anything that is not an entity has no timeline to read.
	 *
	 * @param object $entity The object the timeline hangs on.
	 *
	 * @return array<int, array<string,mixed>> The public entries, newest first.
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	private function publicTimeline(object $entity): array {
		if (($entity instanceof ObjectEntity) === false) {
			return [];
		}

		try {
			$reader = $this->container->get(PublicTimelineInterface::class);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[CaseTokenService] the public timeline reader could not be built: ' . $e->getMessage()
			);
			return [];
		}

		return $reader->forObject(object: $entity, limit: self::PUBLIC_TIMELINE_LIMIT);
	}//end publicTimeline()

	/**
	 * Revoke a token so it can no longer be resolved.
	 *
	 * Idempotent: revoking an already-revoked or unknown token returns
	 * false rather than throwing. Accepts either the opaque token string
	 * or the numeric row id (the provider addresses tokens by id).
	 *
	 * @param string $objectUuid The object the token must be minted against.
	 * @param string $tokenOrId  The opaque token, or its numeric row id.
	 *
	 * @return bool True when a token was revoked, false when it already was.
	 *
	 * @throws Exception With code 404 when no token of this object matches.
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function revoke(string $objectUuid, string $tokenOrId): bool {
		$row = $this->tokenOfObject(objectUuid: $objectUuid, tokenOrId: $tokenOrId);

		if ($row->getRevokedAt() !== null) {
			// Already revoked — idempotent no-op.
			return false;
		}

		$row->setRevokedAt(new DateTime());
		$this->issuance->mapper->update($row);
		return true;
	}//end revoke()

	/**
	 * The token row, addressed by token or numeric id, of one object.
	 *
	 * @param string $objectUuid The object the token must be minted against.
	 * @param string $tokenOrId  The opaque token, or its numeric row id.
	 *
	 * @return CaseToken The row.
	 *
	 * @throws Exception With code 404 when no token of this object matches.
	 */
	private function tokenOfObject(string $objectUuid, string $tokenOrId): CaseToken {
		$row = $this->issuance->mapper->findByToken($tokenOrId);
		if ($row === null && ctype_digit($tokenOrId) === true) {
			$row = $this->issuance->mapper->findById((int)$tokenOrId);
		}

		if ($row === null || $row->getObjectUuid() !== $objectUuid) {
			throw new Exception('Token not found', 404);
		}

		return $row;
	}//end tokenOfObject()

	/**
	 * List the (public-safe metadata of) tokens minted against an object.
	 *
	 * @param string $objectUuid Object uuid.
	 *
	 * @return array<int,array<string,mixed>> Token metadata rows.
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function listForObject(string $objectUuid): array {
		$rows = $this->issuance->mapper->findByObjectUuid($objectUuid);
		return array_map(
			function (CaseToken $row): array {
				$data = $row->jsonSerialize();
				$data['url'] = $this->buildPublicUrl(token: (string)$row->getToken());
				return $data;
			},
			$rows
		);
	}//end listForObject()

	/**
	 * Build the absolute public resolve URL for a token.
	 *
	 * @param string $token The opaque token.
	 *
	 * @return string Absolute URL.
	 */
	private function buildPublicUrl(string $token): string {
		$path = $this->issuance->urlGenerator->linkToRoute(
			'openregister.caseToken.resolve',
			['token' => $token]
		);
		return $this->issuance->urlGenerator->getAbsoluteURL($path);
	}//end buildPublicUrl()

	/**
	 * Resolve the ObjectService from the active container.
	 *
	 * @return object|null The ObjectOperationsInterface, or null when unresolvable.
	 */
	private function resolveObjectService(): ?object {
		try {
			$service = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			if (is_object($service) === true) {
				return $service;
			}

			return null;
		} catch (Throwable $e) {
			return null;
		}
	}//end resolveObjectService()
}//end class
