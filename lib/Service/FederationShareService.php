<?php

/**
 * OpenRegister FederationShareService.
 *
 * Manages cross-instance federated shares: minting scoped share tokens,
 * creating OUTGOING grants (a register / schema / object / query shared with an
 * organisation on another instance), listing and revoking them, and recording
 * INCOMING grants when a remote instance shares with an organisation here.
 * The OCM trust/transport layer (ICloudFederationProvider) sits on top of this
 * service; the scoped token it mints is what the federation serving endpoint
 * (FederationController) and the remote FederatedObjectSourceProvider use.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Federation\ShareGrantor;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Create, list and revoke federated shares.
 */
class FederationShareService {

	/**
	 * Recognised share scopes.
	 *
	 * @var string[]
	 */
	private const SCOPES = ['register', 'schema', 'object', 'query'];

	/**
	 * Recognised permission grants.
	 *
	 * @var string[]
	 */
	private const PERMISSIONS = ['read', 'read-write'];

	/**
	 * Query filter keys that would move the search to another register or schema.
	 *
	 * @var string[]
	 */
	private const RETARGETING_FILTER_KEYS = ['register', 'schema'];

	/**
	 * Constructor.
	 *
	 * @param FederatedShareMapper $shareMapper Federated-share persistence.
	 * @param ShareGrantor $grantor The signed-in user granting or managing a share.
	 * @param ISecureRandom $secureRandom Secure token generator.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly FederatedShareMapper $shareMapper,
		private readonly ShareGrantor $grantor,
		private readonly ISecureRandom $secureRandom,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create an outgoing federated share and mint its scoped token.
	 *
	 * @param array<string, mixed> $params Share parameters: scope, register,
	 *                                     schema, objectUri, queryFilter,
	 *                                     sharedWith (slug@host), permissions,
	 *                                     remoteInstanceUrl.
	 *
	 * @return FederatedShare The persisted share (carrying the minted token).
	 *
	 * @throws \InvalidArgumentException When scope, permissions or query filter are invalid.
	 * @throws NotAuthorizedException When the caller does not hold the rights the share grants.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-serves-exactly-what-its-scope-grants-req-fse-001
	 */
	public function createOutgoingShare(array $params): FederatedShare {
		$scope = (string)($params['scope'] ?? 'schema');
		if (in_array($scope, self::SCOPES, true) === false) {
			throw new InvalidArgumentException('Invalid share scope: ' . $scope);
		}

		$permissions = (string)($params['permissions'] ?? 'read');
		if (in_array($permissions, self::PERMISSIONS, true) === false) {
			throw new InvalidArgumentException('Invalid permissions: ' . $permissions);
		}

		$this->assertQueryFilterKeepsItsTarget(queryFilter: ($params['queryFilter'] ?? null));

		$data = [
			'direction' => 'outgoing',
			'shareToken' => $this->mintToken(),
			'scope' => $scope,
			'register' => ($params['register'] ?? null),
			'schema' => ($params['schema'] ?? null),
			'objectUri' => ($params['objectUri'] ?? null),
			'queryFilter' => ($params['queryFilter'] ?? null),
			'permissions' => $permissions,
			'organisation' => $this->activeOrganisationUuid(),
			'sharedWith' => ($params['sharedWith'] ?? null),
			'remoteInstanceUrl' => ($params['remoteInstanceUrl'] ?? null),
			// Outgoing token shares are immediately usable; an OCM share moves to
			// 'accepted' once the remote confirms, but the local grant stands.
			'status' => 'accepted',
		];

		// Who minted which grant is logged; the token itself never is.
		$context = [
			'actor' => $this->actor(),
			'scope' => $scope,
			'register' => $data['register'],
			'schema' => $data['schema'],
			'permissions' => $permissions,
			'organisation' => $data['organisation'],
			'sharedWith' => $data['sharedWith'],
		];

		$refusal = $this->grantor->refusal(share: (new FederatedShare())->hydrate(object: $data));
		if ($refusal !== null) {
			$this->logger->warning('[Federation] Refused to create a share: ' . $refusal, $context);
			throw new NotAuthorizedException(message: $refusal);
		}

		$share = $this->shareMapper->createFromArray(data: $data);
		$this->logger->info('[Federation] Created an outgoing share', $context + ['share' => $share->getId()]);

		return $share;
	}//end createOutgoingShare()

	/**
	 * Refuse a query filter that is not a list of conditions, or that names its own register or schema.
	 *
	 * @param mixed $queryFilter The requested filter.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the filter is malformed or re-targets the search.
	 */
	private function assertQueryFilterKeepsItsTarget(mixed $queryFilter): void {
		if ($queryFilter === null) {
			return;
		}

		if (is_array($queryFilter) === false) {
			throw new InvalidArgumentException('The query filter must be a set of conditions');
		}

		$retargeting = array_intersect(array_map('strval', array_keys($queryFilter)), self::RETARGETING_FILTER_KEYS);
		if ($retargeting !== []) {
			throw new InvalidArgumentException('The query filter cannot name a register or schema; the share names them');
		}
	}//end assertQueryFilterKeepsItsTarget()

	/**
	 * Record an incoming federated share (a remote shared with an org here).
	 *
	 * @param array<string, mixed> $params Share parameters: remoteInstanceUrl,
	 *                                     shareToken, scope, register, schema,
	 *                                     objectUri, permissions, sharedWith,
	 *                                     remoteProviderId.
	 *
	 * @return FederatedShare The persisted incoming share (status 'pending').
	 */
	public function recordIncomingShare(array $params): FederatedShare {
		$data = [
			'direction' => 'incoming',
			'remoteInstanceUrl' => ($params['remoteInstanceUrl'] ?? null),
			'remoteProviderId' => ($params['remoteProviderId'] ?? null),
			'shareToken' => (string)($params['shareToken'] ?? $this->mintToken()),
			'scope' => (string)($params['scope'] ?? 'schema'),
			'register' => ($params['register'] ?? null),
			'schema' => ($params['schema'] ?? null),
			'objectUri' => ($params['objectUri'] ?? null),
			'permissions' => (string)($params['permissions'] ?? 'read'),
			'organisation' => $this->activeOrganisationUuid(),
			'sharedWith' => ($params['sharedWith'] ?? null),
			'status' => 'pending',
		];

		return $this->shareMapper->createFromArray(data: $data);
	}//end recordIncomingShare()

	/**
	 * Ensure an outgoing object-scope share exists for a uri + target.
	 *
	 * Idempotent: returns the existing share when one is already present (so the
	 * federate-share flow action can fire on every save without duplicating).
	 *
	 * @param string $objectUri The shared object's uri/uuid.
	 * @param string|null $register The register id/slug.
	 * @param string|null $schema The schema id/slug.
	 * @param string $sharedWith The federated target (slug@host).
	 * @param string $permissions 'read' or 'read-write'.
	 *
	 * @return FederatedShare The existing or newly created share.
	 */
	public function ensureObjectShare(
		string $objectUri,
		?string $register,
		?string $schema,
		string $sharedWith,
		string $permissions = 'read',
	): FederatedShare {
		$existing = $this->shareMapper->findOutgoingObjectShare(objectUri: $objectUri, sharedWith: $sharedWith);
		if ($existing !== null) {
			return $existing;
		}

		return $this->createOutgoingShare(
			params: [
				'scope' => 'object',
				'register' => $register,
				'schema' => $schema,
				'objectUri' => $objectUri,
				'sharedWith' => $sharedWith,
				'permissions' => $permissions,
			]
		);
	}//end ensureObjectShare()

	/**
	 * List federated shares, optionally by direction.
	 *
	 * @param string|null $direction 'outgoing' | 'incoming' | null for all.
	 *
	 * The token of a share is a credential that is served without access control,
	 * so it is listed only to a caller who holds every right that share grants.
	 *
	 * @return list<array<string, mixed>> The shares for the active organisation, serialised.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-serves-exactly-what-its-scope-grants-req-fse-001
	 */
	public function listShares(?string $direction = null): array {
		$filters = [];
		if ($direction !== null && $direction !== '') {
			$filters['direction'] = $direction;
		}

		$listed = [];
		foreach ($this->shareMapper->findAll(limit: null, offset: null, filters: $filters) as $share) {
			$row = $share->jsonSerialize();
			if ($this->grantor->refusal(share: $share) !== null) {
				$row['shareToken'] = null;
			}

			$listed[] = $row;
		}

		return $listed;
	}//end listShares()

	/**
	 * Set the status of a share (e.g. accept, decline, revoke).
	 *
	 * @param int $id The share id.
	 * @param string $status The new status.
	 *
	 * @return FederatedShare The updated share.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the share is not one of the active organisation's.
	 * @throws NotAuthorizedException When the caller does not hold the rights the share grants.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-serves-exactly-what-its-scope-grants-req-fse-001
	 */
	public function setStatus(int $id, string $status): FederatedShare {
		$context = ['actor' => $this->actor(), 'share' => $id, 'status' => $status];
		$refusal = $this->grantor->changeRefusal(share: $this->shareMapper->find(id: $id));
		if ($refusal !== null) {
			$this->logger->warning('[Federation] Refused to change a share: ' . $refusal, $context);
			throw new NotAuthorizedException(message: $refusal);
		}

		$share = $this->shareMapper->updateFromArray(id: $id, data: ['status' => $status]);
		$this->logger->info('[Federation] Changed a share status', $context);

		return $share;
	}//end setStatus()

	/**
	 * The signed-in user's id for a log line.
	 *
	 * @return string The uid, or "(no user)".
	 */
	private function actor(): string {
		try {
			return ($this->grantor->userId() ?? '(no user)');
		} catch (\Throwable $e) {
			return '(unknown)';
		}
	}//end actor()

	/**
	 * Resolve the active organisation UUID (null when none).
	 *
	 * @return string|null The active organisation UUID.
	 */
	private function activeOrganisationUuid(): ?string {
		try {
			return $this->grantor->organisation();
		} catch (\Throwable $e) {
			$this->logger->warning('[Federation] could not resolve active organisation: ' . $e->getMessage());
		}

		return null;
	}//end activeOrganisationUuid()

	/**
	 * Mint a scoped bearer share token.
	 *
	 * @return string A 48-char alphanumeric token.
	 */
	private function mintToken(): string {
		return $this->secureRandom->generate(48, ISecureRandom::CHAR_ALPHANUMERIC);
	}//end mintToken()
}//end class
