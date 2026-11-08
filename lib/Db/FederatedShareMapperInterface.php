<?php

/**
 * FederatedShareMapperInterface: what callers outside the Db component use of FederatedShareMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * The methods of FederatedShareMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to FederatedShareMapper. Guarded by C04InterfaceBindingsTest.
 */
interface FederatedShareMapperInterface {

	/**
	 * Create a share from an array of data.
	 *
	 * @param array<string, mixed> $data The share data.
	 *
	 * @return FederatedShare The created share.
	 */
	public function createFromArray(array $data): FederatedShare;

	/**
	 * Find a federated share by database id.
	 *
	 * @param int $id The share id.
	 *
	 * @return FederatedShare The share.
	 */
	public function find(int $id): FederatedShare;

	/**
	 * Find all federated shares, optionally filtered.
	 *
	 * @param int|null $limit Maximum number of results.
	 * @param int|null $offset Result offset.
	 * @param array<string, mixed>|null $filters Column => value equality filters.
	 *
	 * @return FederatedShare[] The matching shares.
	 *
	 * @psalm-return list<FederatedShare>
	 */
	public function findAll(?int $limit = null, ?int $offset = null, ?array $filters = []): array;

	/**
	 * Find a federated share by its scoped share token.
	 *
	 * Used by the federation serving endpoint to resolve an incoming request;
	 * the organisation filter is intentionally NOT applied because the token
	 * itself is the access credential (the caller is a remote instance, not a
	 * local session).
	 *
	 * @param string $shareToken The scoped bearer token.
	 *
	 * @return FederatedShare The share.
	 */
	public function findByToken(string $shareToken): FederatedShare;

	/**
	 * Every outgoing share that is not revoked or declined, across all organisations.
	 *
	 * No organisation filter: for administrator reports from the command line only.
	 *
	 * @return FederatedShare[] The shares, oldest first.
	 *
	 * @psalm-return list<FederatedShare>
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
	 */
	public function findLiveOutgoingAcrossOrganisations(): array;

	/**
	 * Update a share from an array of data.
	 *
	 * @param int $id The share id.
	 * @param array<string, mixed> $data The updated share data.
	 *
	 * @return FederatedShare The updated share.
	 */
	public function updateFromArray(int $id, array $data): FederatedShare;
}//end interface
