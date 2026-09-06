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
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
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
	 * @param ObjectService $objectService Loads and writes the credential object.
	 * @param CredentialStore $credentialStore Deletes the stored token set on disconnect.
	 * @param OrganisationService $organisationService Resolves and gates organisation membership.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly CredentialStore $credentialStore,
		private readonly OrganisationService $organisationService,
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
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-re-authorisation-overrides-the-same-credential
	 */
	public function findManageable(string $credentialId, string $uid): ?ObjectEntity {
		try {
			$entity = $this->objectService->find(
				id: $credentialId,
				register: CredentialBrokerService::REGISTER,
				schema: CredentialBrokerService::SCHEMA,
				_rbac: false,
				_multitenancy: false,
				_render: false
			);
		} catch (Throwable $failure) {
			return null;
		}

		if ($entity instanceof ObjectEntity === false) {
			return null;
		}

		$data = $entity->jsonSerialize();
		if ((string)($data['scope'] ?? 'personal') !== self::SCOPE_ORGANISATION) {
			if ((string)$entity->getOwner() !== $uid) {
				return null;
			}

			return $entity;
		}

		$organisation = (string)($data['organisation'] ?? '');
		if ($organisation === '' || $this->organisationService->isOrganisationAdmin($organisation, $uid) === false) {
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
	 * @throws InvalidArgumentException When there is no active organisation, or the caller does not administer it.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
	 */
	public function gatedOrganisation(string $uid, string $requestedScope): ?string {
		if ($requestedScope !== self::SCOPE_ORGANISATION) {
			return null;
		}

		$uuid = $this->organisationService->getActiveOrganisation()?->getUuid();
		if ($uuid === null || $uuid === '') {
			throw new InvalidArgumentException(message: 'no active organisation to connect for');
		}

		if ($this->organisationService->isOrganisationAdmin($uuid, $uid) === false) {
			throw new InvalidArgumentException(message: 'only an organisation administrator may connect a shared account');
		}

		return $uuid;
	}//end gatedOrganisation()

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
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-disconnecting-revokes-upstream-where-it-can-and-disables-locally
	 */
	public function disable(string $credentialId, array $data, string $lastError): void {
		$scope = (string)($data['scope'] ?? 'personal');
		$this->credentialStore->delete($credentialId, $scope);

		$this->objectService->saveObject(
			object: array_merge($data, ['status' => 'disabled', 'lastError' => $lastError, 'expiresAt' => null]),
			register: CredentialBrokerService::REGISTER,
			schema: CredentialBrokerService::SCHEMA,
			uuid: $credentialId,
			_rbac: false,
			_multitenancy: false
		);
	}//end disable()
}//end class
