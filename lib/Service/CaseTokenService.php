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
use InvalidArgumentException;
use OCA\OpenRegister\Db\CaseToken;
use OCA\OpenRegister\Db\CaseTokenMapper;
use OCA\OpenRegister\Service\Timeline\TimelineEntryService;
use OCA\OpenRegister\Service\TimelineVisibilityService;
use OCP\IURLGenerator;
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
 */
class CaseTokenService {

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
	 * @param CaseTokenMapper $mapper Token persistence.
	 * @param ISecureRandom $secureRandom NC secure RNG.
	 * @param IUserSession $userSession Current user (minter).
	 * @param IURLGenerator $urlGenerator Public URL builder.
	 * @param LoggerInterface $logger Logger.
	 * @param ContainerInterface $container App container the ObjectService is resolved from on demand.
	 *
	 * @return void
	 */
	public function __construct(
		private CaseTokenMapper $mapper,
		private ISecureRandom $secureRandom,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
		private ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Mint a public token-link for an object.
	 *
	 * @param string $objectUuid Object uuid to bind the token to.
	 * @param int|null $registerId Register id of the object.
	 * @param int|null $schemaId Schema id of the object.
	 * @param string|null $label Optional human label.
	 * @param int|null $ttlSeconds Optional time-to-live in seconds;
	 *                             null = never expires.
	 *
	 * @return array<string,mixed> Minted token metadata + the public URL.
	 *
	 * @throws InvalidArgumentException When objectUuid is empty or the
	 *                                  minter is anonymous.
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function mint(
		string $objectUuid,
		?int $registerId = null,
		?int $schemaId = null,
		?string $label = null,
		?int $ttlSeconds = null,
	): array {
		if (trim($objectUuid) === '') {
			throw new InvalidArgumentException('objectUuid is required to mint a case token');
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			// Minting is a write that establishes a public surface — it
			// MUST be performed by an authenticated user, never anonymously.
			throw new InvalidArgumentException('A logged-in user is required to mint a case token');
		}

		$now = new DateTime();
		$expires = null;
		if ($ttlSeconds !== null && $ttlSeconds > 0) {
			$expires = (clone $now)->modify('+' . $ttlSeconds . ' seconds');
		}

		$token = $this->secureRandom->generate(
			self::TOKEN_LENGTH,
			ISecureRandom::CHAR_ALPHANUMERIC
		);

		$entity = new CaseToken();
		$entity->setToken($token);
		$entity->setObjectUuid($objectUuid);
		$entity->setRegisterId($registerId);
		$entity->setSchemaId($schemaId);
		$entity->setLabel($label);
		$entity->setCreatedBy($user->getUID());
		$entity->setCreatedAt($now);
		$entity->setExpiresAt($expires);
		$entity->setRevokedAt(null);

		$saved = $this->mapper->insert($entity);

		$data = $saved->jsonSerialize();
		$data['url'] = $this->buildPublicUrl(token: $token);
		return $data;
	}//end mint()

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
	 * @param string $token The opaque token.
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
		if (trim($token) === '') {
			return null;
		}

		$row = $this->mapper->findByToken($token);
		if ($row === null) {
			return null;
		}

		if ($row->isValidAt(new DateTime()) === false) {
			// Revoked or expired — fail closed.
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
				_extend: [],
				files: false,
				register: $row->getRegisterId(),
				schema: $row->getSchemaId(),
				_rbac: true,
				_multitenancy: true
			);

			if ($entity === null) {
				return null;
			}

			$rendered = $objectService->renderEntity(
				entity: $entity,
				_extend: [],
				depth: 0,
				filter: [],
				fields: [],
				unset: [],
				_rbac: true,
				_multitenancy: true
			);

			return [
				'token' => $row->getToken(),
				'label' => $row->getLabel(),
				'object' => $rendered,
				'timeline' => $this->publicTimeline(entity: $entity),
			];
		} catch (Throwable $e) {
			// RBAC-denied / not-found / any read failure → 404 (null).
			// Logged server-side; the caller never learns why.
			$this->logger->debug(
				'[CaseTokenService] resolve failed for a token',
				['exception' => $e]
			);
			return null;
		}//end try
	}//end resolve()

	/**
	 * The public entries on one object, cut down to what a stranger may read.
	 *
	 * SOFT BY DESIGN. A timeline that cannot be read answers the empty list,
	 * never an exception: the status page existed before the timeline did, and
	 * an instance whose timeline tables are not migrated yet must still show
	 * the status rather than a uniform 404 that reads as a revoked link.
	 *
	 * THE PROJECTION IS A WHITELIST, NOT A BLACKLIST. `TimelineEntry` carries
	 * the author's user id, the raw source of an intake mail and the entry's
	 * own visibility, and a projection that removed those three by name would
	 * hand out the fourth one somebody adds later. Only the five keys named
	 * below leave the building.
	 *
	 * @param object $entity The object the timeline hangs on.
	 *
	 * @return array<int, array<string,mixed>> The public entries, newest first.
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	private function publicTimeline(object $entity): array {
		try {
			$entries = $this->container
				->get(TimelineEntryService::class)
				->listForObject(
					object: $entity,
					visibility: TimelineVisibilityService::PUBLIC_ENTRY,
					limit: self::PUBLIC_TIMELINE_LIMIT
				);
		} catch (Throwable $e) {
			$this->logger->debug(
				'[CaseTokenService] no public timeline for a resolved token',
				['exception' => $e]
			);
			return [];
		}

		$public = [];
		foreach ($entries as $entry) {
			$row = $entry->jsonSerialize();
			$public[] = [
				'id' => ($row['id'] ?? ''),
				'kind' => ($row['kind'] ?? ''),
				'message' => ($row['message'] ?? ''),
				'fields' => ($row['fields'] ?? []),
				'occurredAt' => ($row['created'] ?? ''),
			];
		}

		return $public;
	}//end publicTimeline()

	/**
	 * Revoke a token so it can no longer be resolved.
	 *
	 * Idempotent: revoking an already-revoked or unknown token returns
	 * false rather than throwing. Accepts either the opaque token string
	 * or the numeric row id (the provider addresses tokens by id).
	 *
	 * @param string $tokenOrId The opaque token, or its numeric row id.
	 *
	 * @return bool True when a token was revoked, false when none matched.
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function revoke(string $tokenOrId): bool {
		$row = $this->mapper->findByToken($tokenOrId);
		if ($row === null && ctype_digit($tokenOrId) === true) {
			$row = $this->mapper->findById((int)$tokenOrId);
		}

		if ($row === null) {
			return false;
		}

		if ($row->getRevokedAt() !== null) {
			// Already revoked — idempotent no-op.
			return false;
		}

		$row->setRevokedAt(new DateTime());
		$this->mapper->update($row);
		return true;
	}//end revoke()

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
		$rows = $this->mapper->findByObjectUuid($objectUuid);
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
		$path = $this->urlGenerator->linkToRoute(
			'openregister.caseToken.resolve',
			['token' => $token]
		);
		return $this->urlGenerator->getAbsoluteURL($path);
	}//end buildPublicUrl()

	/**
	 * Resolve the ObjectService from the active container.
	 *
	 * @return object|null The ObjectService, or null when unresolvable.
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
