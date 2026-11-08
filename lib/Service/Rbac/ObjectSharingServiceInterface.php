<?php

/**
 * ObjectSharingServiceInterface: the part of ObjectSharingService that the object sharing controller uses.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;

/**
 * The methods of ObjectSharingService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface ObjectSharingServiceInterface {

	/**
	 * Set one object's scope, as its owner or an administrator.
	 *
	 * @param Register $register The register.
	 * @param Schema $schema The schema.
	 * @param ObjectEntity $object The object.
	 * @param string $scope The requested scope.
	 *
	 * @throws NotAuthorizedException When the caller is neither owner nor admin.
	 * @throws InvalidArgumentException When the scope is not in the vocabulary.
	 *
	 * @return array<string, mixed> The stored authorization block after the write.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public function setScope(Register $register, Schema $schema, ObjectEntity $object, string $scope): array;

	/**
	 * List the grants on one object.
	 *
	 * Readable by the owner and administrators. A recipient does not get to
	 * enumerate who else an object was shared with.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @throws NotAuthorizedException When the caller is neither owner nor admin.
	 *
	 * @return array<int, array<string, mixed>> The grants.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public function listGrants(ObjectEntity $object): array;

	/**
	 * Grant one principal access to one object.
	 *
	 * The grant is a real Nextcloud share on the object's folder, so core owns
	 * the record and its whole lifecycle (design D1).
	 *
	 * @param ObjectEntity $object The object to share.
	 * @param string $type One of the GRANTABLE_TYPES keys.
	 * @param string $shareWith The principal to grant.
	 * @param integer $permissions Core permission bitmask.
	 * @param string[] $verbs Extension verbs (e.g. `use`, `run`) carried in the share's
	 *                        attribute bag. Core's bitmask has no room for a verb it does
	 *                        not define, so ADR-010 puts them here rather than widening
	 *                        the RBAC vocabulary.
	 * @param DateTime|null $expiresAt When the grant ends; null for no end.
	 * @param DateTime|null $sharedAt The share time to record; null for now. Core stamps a new
	 *                                share with the current time, so it is written after creation.
	 *
	 * @throws NotAuthorizedException When the caller is neither owner nor admin.
	 * @throws InvalidArgumentException On an unsupported type or unresolvable folder.
	 *
	 * @return array<string, mixed> The created grant.
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
	 */
	public function grant(
		ObjectEntity $object,
		string $type,
		string $shareWith,
		int $permissions = 1,
		array $verbs = [],
		?DateTime $expiresAt = null,
		?DateTime $sharedAt = null,
	): array;

	/**
	 * Create a tokenised LINK to an object, optionally expiring and passworded.
	 *
	 * A link is a CAPABILITY, not a principal grant: it admits whoever presents
	 * the token, so it is deliberately absent from
	 * {@see ObjectGrantResolver::PRINCIPAL_SHARE_TYPES} and is never resolved by
	 * the RBAC filter. It is decided on the public entry point instead, from the
	 * token itself.
	 *
	 * That distinction is what reconciles this with ADR-006 (design Q4): the ADR
	 * says publication is a schema-level RBAC change and not a per-object data
	 * flag, and a revocable, expiring, core-issued bearer token is neither of
	 * those things.
	 *
	 * Everything about the token's lifecycle — generation, expiry enforcement,
	 * password hashing, revocation — stays core's.
	 *
	 * @param ObjectEntity $object The object to link.
	 * @param integer $permissions Core permission bitmask; defaults to READ.
	 * @param string|null $password Optional password.
	 * @param string|null $expiration Optional expiry, any format DateTime parses.
	 *
	 * @throws NotAuthorizedException When the caller is neither owner nor admin.
	 * @throws InvalidArgumentException On an unresolvable folder or a bad expiry.
	 *
	 * @return array<string, mixed> The created link, including its token.
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function createLink(
		ObjectEntity $object,
		int $permissions = 1,
		?string $password = null,
		?string $expiration = null,
	): array;

	/**
	 * Invite an EMAIL ADDRESS to an object.
	 *
	 * `TYPE_EMAIL` is core's account-less link addressed to an address — the
	 * Files behaviour, chosen deliberately (design Q2), because requiring the
	 * recipient to already have an account defeats inviting a colleague who does
	 * not have one.
	 *
	 * The message carries no object data: the recipient follows the invitation to
	 * reach the object, so revoking it still works after the mail has been sent.
	 * Delivery is core's mailer, not ours.
	 *
	 * @param ObjectEntity $object The object to share.
	 * @param string $email The address to invite.
	 * @param integer $permissions Core permission bitmask; defaults to READ.
	 * @param string|null $password Optional password.
	 * @param string|null $expiration Optional expiry.
	 *
	 * @throws NotAuthorizedException When the caller is neither owner nor admin.
	 * @throws InvalidArgumentException On a missing address or unresolvable folder.
	 *
	 * @return array<string, mixed> The created invitation.
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function inviteByEmail(
		ObjectEntity $object,
		string $email,
		int $permissions = 1,
		?string $password = null,
		?string $expiration = null,
	): array;

	/**
	 * Revoke one grant.
	 *
	 * The share MUST hang on this object's own folder. Without that check an
	 * owner could revoke a share belonging to a different object by guessing its
	 * id through their own object's endpoint.
	 *
	 * @param ObjectEntity $object The object the grant belongs to.
	 * @param string $shareId The share to revoke.
	 *
	 * @throws NotAuthorizedException When the caller is neither owner nor admin.
	 * @throws InvalidArgumentException When the share is not on this object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function revoke(ObjectEntity $object, string $shareId): void;
}//end interface
