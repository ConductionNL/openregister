<?php

/**
 * OAuth2CredentialObjects — the one lookup of a brokered credential object.
 *
 * Loading a credential object by id is the same call everywhere in the connect
 * flow: the broker's register and schema, no RBAC and no multitenancy (the caller
 * makes its own access decision on what comes back), no rendering, inside the
 * ambient lookup cause. Having it once means a later change to how credentials are
 * read cannot reach one caller and miss another.
 *
 * The two writes the connection repository makes (disable and discard) go through
 * here for the same reason: register, schema and the access-control switches are
 * spelled once.
 *
 * It decides nothing. Whether an absent credential is a refusal, a fresh connect
 * or "nothing to reuse" stays with each caller, and so does whether a failed
 * lookup is swallowed; the authorization decision for a write was taken by the
 * caller before it got here.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\WriteCause;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;

/**
 * Loads a brokered credential object by id.
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-re-authorisation-overrides-the-same-credential
 */
final readonly class OAuth2CredentialObjects {
	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objectService Loads the credential object.
	 *
	 * @return void
	 */
	public function __construct(
		private ObjectOperationsInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Load a credential object, or null when there is none.
	 *
	 * @param string $credentialId The credential UUID.
	 *
	 * @return ObjectEntity|null The credential object, or null.
	 *
	 * @throws \Throwable When the lookup itself fails; each caller decides what that means.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-re-authorisation-overrides-the-same-credential
	 */
	public function find(string $credentialId): ?ObjectEntity {
		$entity = WriteCause::asLookup(fn () => $this->objectService->find(
			id: $credentialId,
			register: CredentialBrokerService::REGISTER,
			schema: CredentialBrokerService::SCHEMA,
			_rbac: false,
			_multitenancy: false,
			_render: false
		));

		if ($entity instanceof ObjectEntity === false) {
			return null;
		}

		return $entity;
	}//end find()

	/**
	 * Write a credential object's property bag.
	 *
	 * @param string $credentialId The credential UUID.
	 * @param array<string, mixed> $object The full property bag to store.
	 *
	 * @return void
	 *
	 * @throws \Throwable When the write fails.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-disconnecting-revokes-upstream-where-it-can-and-disables-locally
	 */
	public function save(string $credentialId, array $object): void {
		$this->objectService->saveObject(
			object: $object,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: CredentialBrokerService::REGISTER, schema: CredentialBrokerService::SCHEMA),
				uuid: $credentialId
			),
			_rbac: false,
			_multitenancy: false
		);
	}//end save()

	/**
	 * Delete a credential object.
	 *
	 * @param string $credentialId The credential UUID.
	 *
	 * @return void
	 *
	 * @throws \Throwable When the delete fails.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-bluesky-is-its-own-client-and-mastodon-registers-per-instance
	 */
	public function delete(string $credentialId): void {
		$this->objectService->deleteObject(
			uuid: $credentialId,
			register: CredentialBrokerService::REGISTER,
			schema: CredentialBrokerService::SCHEMA,
			_rbac: false,
			_multitenancy: false
		);
	}//end delete()
}//end class
