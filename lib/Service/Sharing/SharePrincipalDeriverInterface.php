<?php

/**
 * SharePrincipalDeriverInterface: what callers outside the component use of SharePrincipalDeriver.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sharing
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
 * @spec openspec/changes/shared-credentials-and-flows/specs/credential-sharing/spec.md#requirement-a-brokered-credential-carries-a-principal-share-list
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sharing;

/**
 * Validates an object's share list and derives the principal lists RBAC matches.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface SharePrincipalDeriverInterface {

	/**
	 * The rich share list property.
	 *
	 * @var string
	 */
	public const PROP_SHARED_WITH = 'sharedWith';

	/**
	 * The derived user-principal list property.
	 *
	 * @var string
	 */
	public const PROP_SHARED_USERS = 'sharedUsers';

	/**
	 * The derived group-principal list property.
	 *
	 * @var string
	 */
	public const PROP_SHARED_GROUPS = 'sharedGroups';

	/**
	 * Recompute the derived principal lists on an object body.
	 *
	 * Call this on EVERY write. Any `sharedUsers` / `sharedGroups` the caller
	 * supplied is discarded and replaced — they are derived, and accepting them
	 * would let a client grant itself access without appearing in `sharedWith`.
	 *
	 * An absent or malformed `sharedWith` yields two EMPTY lists rather than
	 * leaving whatever was there before, so a share list cleared by an update
	 * cannot leave stale principals behind still granting access.
	 *
	 * @param array<string, mixed> $object The object body being written.
	 *
	 * @return array<string, mixed> The body with the derived lists recomputed.
	 */
	public function apply(array $object): array;

	/**
	 * The entries of a share list that are well-formed enough to grant anything.
	 *
	 * Fails closed per entry: an unknown `type`, a missing/blank/non-string `id`,
	 * or a non-array entry is DROPPED rather than stored, so a malformed entry
	 * can never widen access. Dropping one entry does not invalidate the others.
	 *
	 * @param mixed $sharedWith The raw `sharedWith` value.
	 *
	 * @return array<int, array{type: string, id: string, permission: string|null}> Valid entries.
	 */
	public function validEntries(mixed $sharedWith): array;

	/**
	 * Whether a principal holds one of the given permissions on a share list.
	 *
	 * The RBAC predicate can only grant VISIBILITY — it matches the derived
	 * lists and knows nothing about the permission verb. Anything that enforces
	 * a verb (the flow trigger endpoint refusing a `read`-only recipient) has to
	 * read the rich list, which is what this answers.
	 *
	 * @param ShareGrantee   $grantee The acting user id and the acting user's group ids.
	 * @param ShareListCheck $check   The raw `sharedWith` value and the permissions that satisfy the check.
	 *        null is a MEANINGFUL permission, not sloppiness: a share entry that
	 *        carries no explicit permission has `permission => null`, and both
	 *        callers pass `['use', null]` to mean "an unqualified share counts
	 *        as 'use'". Narrowing the permissions to string[] would silently
	 *        stop matching those entries.
	 *
	 * @return bool True when a matching principal holds one of the permissions.
	 */
	public function grants(ShareGrantee $grantee, ShareListCheck $check): bool;
}//end interface
