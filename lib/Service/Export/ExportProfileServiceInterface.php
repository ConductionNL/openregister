<?php

/**
 * ExportProfileServiceInterface: what callers outside the component use of ExportProfileService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
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

namespace OCA\OpenRegister\Service\Export;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ExportProfile;
use Symfony\Component\Uid\Uuid;

/**
 * Declares only what callers outside the component use of ExportProfileService; the rest
 * of the class stays its own.
 */
interface ExportProfileServiceInterface {

	/**
	 * Find a profile by id.
	 *
	 * @param int $id The profile id.
	 *
	 * @return ExportProfile The profile.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no row matches.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function find(int $id): ExportProfile;

	/**
	 * The profiles a caller may list.
	 *
	 * @param string $callerUid     The caller.
	 * @param bool   $callerIsAdmin Whether the caller is an administrator.
	 *
	 * @return ExportProfile[] The profiles.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 *
	 *     same listing with a wider scope, not a second method.
	 */
	public function listFor(string $callerUid, bool $callerIsAdmin): array;

	/**
	 * Create a profile.
	 *
	 * @param array<string, mixed> $data     The submitted profile.
	 * @param string               $ownerUid The owning user.
	 *
	 * @return ExportProfile The persisted profile.
	 *
	 * @throws InvalidArgumentException When the submission does not make sense.
	 *
	 *     pattern, as AuditTrailMapper::createToolInvocationEntry().
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function create(array $data, string $ownerUid): ExportProfile;

	/**
	 * Update a profile.
	 *
	 * @param ExportProfile        $profile The profile to change.
	 * @param array<string, mixed> $data    The submitted changes.
	 *
	 * @return ExportProfile The persisted profile.
	 *
	 * @throws InvalidArgumentException When the submission does not make sense.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function update(ExportProfile $profile, array $data): ExportProfile;

	/**
	 * Delete a profile.
	 *
	 * @param ExportProfile $profile The profile to delete.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function delete(ExportProfile $profile): void;

	/**
	 * Refuse a caller who is neither the owner nor an administrator.
	 *
	 * @param ExportProfile       $profile The profile.
	 * @param ExportProfileCaller $caller  The caller's uid, and whether they are an administrator.
	 *
	 * @return void
	 *
	 * @throws ExportRefusedException When the caller may not touch the profile.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function assertOwnerOrAdmin(ExportProfile $profile, ExportProfileCaller $caller): void;

	/**
	 * Run a profile as a named principal, and record what left.
	 *
	 * @param ExportProfile $profile The profile to run.
	 * @param string        $actorUid The principal the export runs as.
	 *
	 * @return array{bytes: string, rowCount: int, metadata: array<string, mixed>, filename: string} The file.
	 *
	 * @throws ExportRefusedException When the principal does not hold the export verb.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function run(ExportProfile $profile, string $actorUid): array;
}//end interface
