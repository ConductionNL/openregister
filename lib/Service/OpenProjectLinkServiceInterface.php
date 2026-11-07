<?php

/**
 * OpenProjectLinkServiceInterface: what callers outside the Service component ask of OpenProjectLinkService.
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
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\OpenProjectLink;

/**
 * Links OpenProject work packages to objects.
 */
interface OpenProjectLinkServiceInterface {

	/**
	 * Create a new OpenProject work package and link it to an OR object.
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param string $projectId Target OpenProject project id.
	 * @param string $subject New work-package subject.
	 * @param string $type Optional work-package type (id or label).
	 *
	 * @return OpenProjectLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), empty subject (400),
	 *                   missing project (400), source unavailable (503).
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function createAndLinkWorkPackage(
		LinkTarget $target,
		string $projectId,
		string $subject,
		string $type = '',
	): OpenProjectLink;

	/**
	 * Return the work packages reachable through the OpenConnector
	 * `openproject` source (picker source), optionally filtered by a
	 * search substring.
	 *
	 * @param string|null $search Optional subject-substring filter.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws Exception When the source is unconfigured / unreachable (503).
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function getAvailableWorkPackages(?string $search = null): array;

	/**
	 * Return the linked work packages for an object, refreshing the
	 * cached metadata columns when a row is older than 24h and the
	 * OpenConnector source is reachable.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function getLinkedWorkPackages(string $objectUuid): array;

	/**
	 * Whether OpenConnector (which carries the OpenProject source) is
	 * installed + enabled for the current user.
	 *
	 * @return bool
	 */
	public function isOpenConnectorAvailable(): bool;

	/**
	 * Link an existing OpenProject work package to an OR object.
	 *
	 * Idempotent: a duplicate link raises a 409 Exception. Work-package
	 * metadata is cached at link time, pulled fresh from OpenProject when
	 * the source is configured.
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param int $workPackageId OpenProject work-package id.
	 *
	 * @return OpenProjectLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), duplicate (409),
	 *                   OpenConnector/source unavailable (503).
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function linkWorkPackage(LinkTarget $target, int $workPackageId): OpenProjectLink;

	/**
	 * Unlink a work package from an object.
	 *
	 * Does NOT delete the work package itself — it stays in OpenProject.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int $workPackageId OpenProject work-package id.
	 *
	 * @return void
	 *
	 * @throws Exception On missing user (401) or no matching link (404).
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function unlink(string $objectUuid, int $workPackageId): void;
}//end interface
