<?php

/**
 * SubjectExportAssemblerInterface: assemble one pending subject export.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Export
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

namespace OCA\OpenRegister\Service\Gdpr\Export;

use OCA\OpenRegister\Db\SubjectExport;

/**
 * The assembly step the subject-export background job runs.
 *
 * Bound to SubjectExportService in ContractBindings.
 */
interface SubjectExportAssemblerInterface {

	/**
	 * Assemble one pending export. Called by the background job.
	 *
	 * @param string $uuid The export uuid.
	 *
	 * @return SubjectExport|null The export as it now stands, or null when it is gone.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function assemble(string $uuid): ?SubjectExport;
}//end interface
