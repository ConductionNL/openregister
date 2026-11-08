<?php

/**
 * FederationShareServiceInterface: what callers outside the component use of FederationShareService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Exception\NotAuthorizedException;

/**
 * The federated share management the federation endpoints call.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface FederationShareServiceInterface {

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
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
	 */
	public function createOutgoingShare(array $params): FederatedShare;

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
	public function listShares(?string $direction = null): array;

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
	public function setStatus(int $id, string $status): FederatedShare;
}//end interface
