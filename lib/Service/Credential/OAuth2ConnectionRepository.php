<?php

/**
 * OAuth2ConnectionRepository — reading, gating and disabling a stored connection.
 *
 * Split out of the connect controller because the controller was accumulating the
 * three collaborators this needs (the object store, the custody leaf and the
 * organisation service) purely to answer two questions: may this caller manage this
 * connection, and how is one switched off. Both are storage-and-authorization
 * questions rather than HTTP ones, and keeping them here leaves the controller
 * about the request.
 *
 * The management rule mirrors {@see \OCA\OpenRegister\Controller\CredentialController}'s
 * deliberately: a personal credential is manageable by its owner, an organisation
 * credential by an administrator of the organisation that owns it. It is repeated
 * rather than shared because the two live on different sides of a public API, and a
 * shared helper that drifted would drift in the direction of admitting more people.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use Throwable;

/**
 * Loads, gates and disables brokered OAuth2 connections.
 */
class OAuth2ConnectionRepository {
	/**
	 * The organisation credential scope.
	 *
	 * @var string
	 */
	private const SCOPE_ORGANISATION = 'organisation';

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objectService Loads and writes the credential object.
	 * @param CredentialStore $credentialStore Deletes the stored token set on disconnect.
	 * @param OrganisationServiceInterface $organisationService Resolves and gates organisation membership.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objectService,
		private readonly CredentialStore $credentialStore,
		private readonly OrganisationServiceInterface $organisationService,
	) {
	}//end __construct()

	/**
	 * Load a connection the caller may manage, or null.
	 *
	 * @param string $credentialId The credential UUID.
	 * @param string $uid The caller.
	 *
	 * @return ObjectEntity|null The credential, or null when absent or not manageable.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-re-authorisation-overrides-the-same-credential
	 */
	public function findManageable(string $credentialId, string $uid): ?ObjectEntity {
		try {
			$entity = $this->objects()->find(credentialId: $credentialId);
		} catch (Throwable $failure) {
			return null;
		}

		if ($entity === null || $this->mayManage(entity: $entity, uid: $uid) === false) {
			return null;
		}

		return $entity;
	}//end findManageable()

	/**
	 * Resolve and gate the organisation an organisation-scoped connect belongs to.
	 *
	 * @param string $uid The initiating user.
	 * @param string $requestedScope The requested credential scope.
	 *
	 * @return string|null The organisation UUID, or null for a personal connect.
	 *
	 * @throws InvalidArgumentException When there is no active organisation.
	 * @throws CredentialAccessDeniedException When the caller does not administer the organisation.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
	 */
	public function gatedOrganisation(string $uid, string $requestedScope): ?string {
		if ($requestedScope !== self::SCOPE_ORGANISATION) {
			return null;
		}

		$uuid = $this->activeOrganisation();
		if ($this->organisationService->isOrganisationAdmin($uuid, $uid) === false) {
			throw new CredentialAccessDeniedException(message: 'only an organisation administrator may connect a shared account');
		}

		return $uuid;
	}//end gatedOrganisation()

	/**
	 * Whether the caller may manage this connection.
	 *
	 * A personal connection is managed by its owner; an organisation connection by
	 * an administrator of the organisation it names, and by nobody when it names none.
	 *
	 * @param ObjectEntity $entity The credential object.
	 * @param string $uid The caller.
	 *
	 * @return boolean True when the caller may manage it.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-re-authorisation-overrides-the-same-credential
	 */
	private function mayManage(ObjectEntity $entity, string $uid): bool {
		$data = $entity->jsonSerialize();
		if ((string)($data['scope'] ?? 'personal') !== self::SCOPE_ORGANISATION) {
			return (string)$entity->getOwner() === $uid;
		}

		$organisation = (string)($data['organisation'] ?? '');

		return $organisation !== '' && $this->organisationService->isOrganisationAdmin($organisation, $uid) === true;
	}//end mayManage()

	/**
	 * The caller's active organisation, refusing a connect when there is none.
	 *
	 * @return string The organisation UUID.
	 *
	 * @throws InvalidArgumentException When there is no active organisation.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
	 */
	private function activeOrganisation(): string {
		$uuid = (string)$this->organisationService->getActiveOrganisation()?->getUuid();
		if ($uuid === '') {
			throw new InvalidArgumentException(message: 'no active organisation to connect for');
		}

		return $uuid;
	}//end activeOrganisation()

	/**
	 * Delete a connection's stored token set and mark it disabled.
	 *
	 * The custody delete happens FIRST. A disconnect whose object write failed leaves
	 * a credential that looks connected and holds nothing, which fails closed at
	 * broker time; the other order would leave a credential that looks disconnected
	 * and still holds a live token.
	 *
	 * @param string $credentialId The credential UUID.
	 * @param array<string, mixed> $data The credential's property bag.
	 * @param string $lastError A secret-free reason the upstream revoke failed, or an empty string.
	 *
	 * @return void
	 *
	 * @throws Throwable When the custody delete or the object write fails.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-disconnecting-revokes-upstream-where-it-can-and-disables-locally
	 */
	public function disable(string $credentialId, array $data, string $lastError): void {
		$scope = (string)($data['scope'] ?? 'personal');
		$this->credentialStore->delete($credentialId, $scope);

		$disabled = array_merge($data, ['status' => 'disabled', 'lastError' => $lastError, 'expiresAt' => null]);
		$this->objects()->save(credentialId: $credentialId, object: $disabled);
	}//end disable()

	/**
	 * Delete a credential outright: its stored secret, then the object.
	 *
	 * For a client credential a connect start minted and then could not use. The
	 * secret goes first, for the reason disable() gives: a failure halfway leaves
	 * an object that holds nothing, never a secret nothing points at.
	 *
	 * @param string $credentialId The credential UUID.
	 * @param string $scope The scope its secret is stored in.
	 *
	 * @return void
	 *
	 * @throws Throwable When the custody delete or the object delete fails.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-bluesky-is-its-own-client-and-mastodon-registers-per-instance
	 */
	public function discard(string $credentialId, string $scope): void {
		$this->credentialStore->delete($credentialId, $scope);

		$this->objects()->delete(credentialId: $credentialId);
	}//end discard()

	/**
	 * The credential-object lookup and writes, over this repository's object service.
	 *
	 * @return OAuth2CredentialObjects The credential objects.
	 *
	 * @spec exclude private accessor with no behaviour of its own
	 */
	private function objects(): OAuth2CredentialObjects {
		return new OAuth2CredentialObjects(objectService: $this->objectService);
	}//end objects()
}//end class
