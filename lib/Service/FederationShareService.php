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
use OCA\OpenRegister\Db\FederatedShareMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Exception\FederatedShareNotDeliveredException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Federation\OcmShareSender;
use OCA\OpenRegister\Service\Federation\ShareCreatorCheck;
use OCA\OpenRegister\Service\Federation\ShareCrosses;
use OCA\OpenRegister\Service\Federation\ShareGrantor;
use OCA\OpenRegister\Service\Federation\ShareLifetime;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Create, list, renew and revoke federated shares.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Each collaborator is one share concern: storage, grant, creator, lifetime.
 */
class FederationShareService implements FederationShareServiceInterface {

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
	 * @param FederatedShareMapperInterface $shareMapper Federated-share persistence.
	 * @param ShareGrantor $grantor The signed-in user granting or managing a share.
	 * @param ISecureRandom $secureRandom Secure token generator.
	 * @param LoggerInterface $logger Logger.
	 * @param ShareCreatorCheck $creatorCheck Judges a share as its creator when it is used.
	 * @param ShareLifetime $lifetime How long a share is served.
	 * @param OcmShareSender|null $sender Sends a share to another instance and tells it about status changes.
	 */
	public function __construct(
		private readonly FederatedShareMapperInterface $shareMapper,
		private readonly ShareGrantor $grantor,
		private readonly ISecureRandom $secureRandom,
		private readonly LoggerInterface $logger,
		private readonly ShareCreatorCheck $creatorCheck,
		private readonly ShareLifetime $lifetime,
		private readonly ?OcmShareSender $sender = null,
	) {
	}//end __construct()

	/**
	 * Why a share may not be served now, or null when its creator still holds what it hands out.
	 *
	 * Asked on every use of a share token, so a creator who loses a right, or is
	 * removed, stops the share at once. A share without a recorded creator is refused,
	 * and so is a share past its expiry.
	 *
	 * @param FederatedShare $share The share about to be served.
	 *
	 * @return string|null The refusal.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
	 */
	public function servingRefusal(FederatedShare $share): ?string {
		if ($this->lifetime->hasExpired(share: $share) === true) {
			return 'the share has expired';
		}

		return $this->creatorCheck->refusal(share: $share);
	}//end servingRefusal()

	/**
	 * Give a share a new lifetime from now; only its creator may, while they hold what it grants.
	 *
	 * @param int $id The share id.
	 *
	 * @return FederatedShare The renewed share.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the share is not one of the active organisation's.
	 * @throws NotAuthorizedException When the caller did not create the share, it is no longer live,
	 *                                or the caller no longer holds the rights it grants.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md
	 */
	public function renewShare(int $id): FederatedShare {
		$share  = $this->shareMapper->find(id: $id);
		$caller = $this->grantor->userId();
		if ($caller === null || $caller !== $share->getOwner()) {
			throw new NotAuthorizedException(message: 'Only the creator of a share may renew it.');
		}

		if ($share->getDirection() !== 'outgoing' || in_array($share->getStatus(), ['revoked', 'declined'], true) === true) {
			throw new NotAuthorizedException(message: 'Only a live outgoing share can be renewed.');
		}

		$refusal = $this->grantor->refusal(share: $share);
		if ($refusal !== null) {
			throw new NotAuthorizedException(message: $refusal);
		}

		return $this->shareMapper->updateFromArray(id: $id, data: ['expiresAt' => $this->lifetime->newExpiry()]);
	}//end renewShare()

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
	 * @throws FederatedShareNotDeliveredException When the recipient's instance could not take the share.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-serves-exactly-what-its-scope-grants-req-fse-001
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
	 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-a-federated-share-declares-what-crosses-req-pcf-002
	 */
	public function createOutgoingShare(array $params): FederatedShare {
		$data  = $this->outgoingShareData(params: $params);
		$share = (new FederatedShare())->hydrate(object: $data);
		$refusal = ($this->grantor->refusal(share: $share) ?? $this->grantor->federationRefusal(share: $share));
		if ($refusal !== null) {
			throw new NotAuthorizedException(message: $refusal);
		}

		$created = $this->shareMapper->createFromArray(data: $data);

		return $this->sendToRemote(share: $created);
	}//end createOutgoingShare()

	/**
	 * Send a just-created share to the recipient's instance, when it names one.
	 *
	 * A share sent over OCM waits for the recipient's answer as `pending`
	 * (still served: the local grant stands). A share the other instance could
	 * not take is revoked at once, so no live token is left behind for a share
	 * nobody received, and the caller is told.
	 *
	 * @param FederatedShare $share The stored outgoing share.
	 *
	 * @return FederatedShare The share as it now stands.
	 *
	 * @throws FederatedShareNotDeliveredException When the other instance could not take the share.
	 *
	 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-an-object-is-shared-with-a-principal-at-another-instance-with-a-two-sided-lifecycle-req-pcf-001
	 */
	private function sendToRemote(FederatedShare $share): FederatedShare {
		if ($this->sender === null) {
			return $share;
		}

		$outcome = $this->sender->send(share: $share);
		if ($outcome === OcmShareSender::SENT) {
			return $this->shareMapper->updateFromArray(id: (int)$share->getId(), data: ['status' => 'pending']);
		}

		if ($outcome === OcmShareSender::FAILED) {
			$this->shareMapper->updateFromArray(id: (int)$share->getId(), data: ['status' => 'revoked']);
			throw new FederatedShareNotDeliveredException(message: 'The share could not be delivered to the other instance');
		}

		return $share;
	}//end sendToRemote()

	/**
	 * The validated record of an outgoing share, owned by the current user and active organisation.
	 *
	 * Outgoing token shares are immediately usable; an OCM share moves to
	 * 'accepted' once the remote confirms, but the local grant stands.
	 *
	 * @param array<string, mixed> $params The share parameters.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws InvalidArgumentException When the scope, permissions or query filter are invalid.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
	 */
	private function outgoingShareData(array $params): array {
		$grant = $this->outgoingGrant(params: $params);
		return ['direction' => 'outgoing', 'shareToken' => $this->mintToken()] + $grant + [
			'organisation' => $this->activeOrganisationUuid(),
			'owner' => $this->grantor->userId(),
			'sharedWith' => ($params['sharedWith'] ?? null),
			'remoteInstanceUrl' => ($params['remoteInstanceUrl'] ?? null),
			'status' => 'accepted',
			'expiresAt' => $this->lifetime->newExpiry(),
		];
	}//end outgoingShareData()

	/**
	 * What an outgoing share grants, validated: its scope, target and permissions.
	 *
	 * @param array<string, mixed> $params The share parameters.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws InvalidArgumentException When the scope, permissions or query filter are invalid.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
	 */
	private function outgoingGrant(array $params): array {
		$scope = (string)($params['scope'] ?? 'schema');
		if (in_array($scope, self::SCOPES, true) === false) {
			throw new InvalidArgumentException('Invalid share scope: ' . $scope);
		}

		$permissions = (string)($params['permissions'] ?? 'read');
		if (in_array($permissions, self::PERMISSIONS, true) === false) {
			throw new InvalidArgumentException('Invalid permissions: ' . $permissions);
		}

		$queryFilter = ($params['queryFilter'] ?? null);
		$this->assertQueryFilterKeepsItsTarget(queryFilter: $queryFilter);
		$crosses = ShareCrosses::declared(requested: ($params['crosses'] ?? null));

		$grant = ['queryFilter' => $queryFilter, 'permissions' => $permissions, 'crosses' => $crosses];

		return ['scope' => $scope] + $this->shareTarget(params: $params) + $grant;
	}//end outgoingGrant()

	/**
	 * What a share points at.
	 *
	 * @param array<string, mixed> $params The share parameters.
	 *
	 * @return array{register: mixed, schema: mixed, objectUri: mixed}
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
	 */
	private function shareTarget(array $params): array {
		return ['register' => ($params['register'] ?? null), 'schema' => ($params['schema'] ?? null), 'objectUri' => ($params['objectUri'] ?? null)];
	}//end shareTarget()

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
		$remote = ['remoteInstanceUrl' => ($params['remoteInstanceUrl'] ?? null), 'remoteProviderId' => ($params['remoteProviderId'] ?? null)];
		$origin = ['direction' => 'incoming'] + $remote + ['shareToken' => (string)($params['shareToken'] ?? $this->mintToken())];
		$grant = ['scope' => (string)($params['scope'] ?? 'schema')] + $this->shareTarget(params: $params);
		return $this->shareMapper->createFromArray(
			data: $origin + $grant + [
				'permissions' => (string)($params['permissions'] ?? 'read'),
				'organisation' => $this->activeOrganisationUuid(),
				'sharedWith' => ($params['sharedWith'] ?? null),
				'status' => 'pending',
			]
		);
	}//end recordIncomingShare()

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
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
	 */
	public function listShares(?string $direction = null): array {
		$filters = [];
		if ($direction !== null && $direction !== '') {
			$filters['direction'] = $direction;
		}

		return array_map(
			fn (FederatedShare $share): array => $this->listedRow(share: $share),
			$this->shareMapper->findAll(query: ListQuery::unbounded(filters: $filters))
		);
	}//end listShares()

	/**
	 * One listed share; its token is withheld from a caller who does not hold every right it grants.
	 *
	 * @param FederatedShare $share The share.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
	 */
	private function listedRow(FederatedShare $share): array {
		$row = $share->jsonSerialize();
		if ($this->grantor->refusal(share: $share) !== null) {
			$row['shareToken'] = null;
		}

		return $row;
	}//end listedRow()

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
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
	 */
	public function setStatus(int $id, string $status): FederatedShare {
		$current = $this->shareMapper->find(id: $id);
		$refusal = $this->grantor->changeRefusal(share: $current);
		if ($refusal !== null) {
			throw new NotAuthorizedException(message: $refusal);
		}

		$previous = $current->getStatus();
		$updated  = $this->shareMapper->updateFromArray(id: $id, data: ['status' => $status]);
		if ($this->sender !== null && $previous !== $status) {
			// REQ-PCF-001: the other side learns of a revoke, accept or decline made here.
			$this->sender->notify(share: $updated, status: $status);
		}

		return $updated;
	}//end setStatus()

	/**
	 * Accept or decline a share another instance sent here, and answer its sender.
	 *
	 * Only a pending incoming share can be answered; the same rights as any
	 * other change to a share apply.
	 *
	 * @param int  $id     The share id.
	 * @param bool $accept True to accept, false to decline.
	 *
	 * @return FederatedShare The answered share.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the share is not one of the active organisation's.
	 * @throws NotAuthorizedException When the caller may not change the share.
	 * @throws InvalidArgumentException When the share is not a pending incoming share.
	 *
	 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-an-object-is-shared-with-a-principal-at-another-instance-with-a-two-sided-lifecycle-req-pcf-001
	 */
	public function answerIncomingShare(int $id, bool $accept): FederatedShare {
		$share = $this->shareMapper->find(id: $id);
		if ($share->getDirection() !== 'incoming' || $share->getStatus() !== 'pending') {
			throw new InvalidArgumentException('Only a pending share received from another instance can be accepted or declined');
		}

		$status = 'declined';
		if ($accept === true) {
			$status = 'accepted';
		}

		return $this->setStatus(id: $id, status: $status);
	}//end answerIncomingShare()

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
