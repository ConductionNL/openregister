<?php

/**
 * SubjectExportMapperInterface: what callers outside the Db component use of SubjectExportMapper.
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

use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The methods of SubjectExportMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to SubjectExportMapper. Guarded by C04InterfaceBindingsTest.
 */
interface SubjectExportMapperInterface {

	/**
	 * Create an export request, assigning a uuid and timestamps.
	 *
	 * @param array<string, mixed> $data The request values.
	 *
	 * @return SubjectExport The persisted request.
	 */
	public function createFromArray(array $data): SubjectExport;

	/**
	 * Find an export by its uuid.
	 *
	 * @param string $uuid The export uuid.
	 *
	 * @return SubjectExport The export.
	 *
	 * @throws DoesNotExistException When no such export exists.
	 */
	public function findByUuid(string $uuid): SubjectExport;

	/**
	 * Persist an export, refreshing the updated timestamp.
	 *
	 * @param SubjectExport $export The export to persist.
	 *
	 * @return SubjectExport The updated export.
	 */
	public function save(SubjectExport $export): SubjectExport;
}//end interface
