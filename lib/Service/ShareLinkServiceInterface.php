<?php

/**
 * ShareLinkServiceInterface: the part of ShareLinkService that the share links controller uses.
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
 * @spec openspec/specs/integration-shares/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Exception;
use OCP\Share\IShare;

/**
 * The methods of ShareLinkService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface ShareLinkServiceInterface {

	/**
	 * List shares linked to an OR object.
	 *
	 * Walks the object's NC folder (resolved via the H-1 entity-input
	 * branch of {@see FileService::getObjectFolder()}) and
	 * unions shares across the object's files for every relevant share
	 * type, deduplicated by share id.
	 *
	 * @param string $objectUuid Owning object uuid.
	 *
	 * @return array<int,array<string,mixed>> Normalised share rows.
	 *
	 * @throws Exception When the share subsystem is unreachable (503).
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the shares-listing contract is owned by the
	 *              integration-shares / generic-integrations capability.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) getLinkedShares() walks six share types per folder
	 * node with per-type Throwable swallow and per-share deduplication; each branch is a required
	 * degradation guard per the AD-23 graceful-degradation contract.
	 */
	public function getLinkedShares(string $objectUuid): array;

	/**
	 * Create a share on a file inside the object's folder.
	 *
	 * The target `fileId` MUST resolve to a node within the object's
	 * folder — this is the ownership boundary that keeps OR from
	 * minting shares on arbitrary files. `IManager::newShare()` builds
	 * the prototype, which is then populated and handed to
	 * `IManager::createShare()`.
	 *
	 * @param string     $objectUuid Owning object uuid.
	 * @param ShareDraft $draft      File (must be in the object's folder), share type, recipient,
	 *                               permissions and optional password and expiration.
	 *
	 * @return IShare The created share.
	 *
	 * @throws Exception On missing user (401), file-not-in-folder (404),
	 *                   invalid recipient (400) or share-manager failure.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the create-share contract is owned by the
	 *              integration-shares / generic-integrations capability.
	 */
	public function createShare(string $objectUuid, ShareDraft $draft): IShare;

	/**
	 * Revoke a share by id, ownership-checked.
	 *
	 * The share's backing node MUST live inside the object's folder,
	 * otherwise the request is rejected with 404 — this prevents
	 * revoking a share through an unrelated object's endpoint.
	 *
	 * @param string $objectUuid Owning object uuid.
	 * @param string $shareId Share id to revoke.
	 *
	 * @return void
	 *
	 * @throws Exception On missing user (401), or 404 when the share does not exist,
	 *                   lies outside the object's folder or is not the caller's.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the revoke-share contract is owned by the
	 *              integration-shares / generic-integrations capability.
	 */
	public function revokeShare(string $objectUuid, string $shareId): void;

	/**
	 * List the files inside an object's folder that can be shared.
	 *
	 * @param string $objectUuid Owning object uuid.
	 *
	 * @return array<int,array<string,mixed>> File descriptors `{fileId, fileName, mimetype, size}`.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the shareable-files listing contract is owned by
	 *              the integration-shares / generic-integrations capability.
	 */
	public function getShareableFiles(string $objectUuid): array;
}//end interface
