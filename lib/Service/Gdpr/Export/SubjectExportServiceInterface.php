<?php

/**
 * SubjectExportServiceInterface: the part of SubjectExportService that the subject export controller uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Gdpr\Export
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Export;

use DateTime;
use OCA\OpenRegister\Db\SubjectExport;

/**
 * The methods of SubjectExportService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface SubjectExportServiceInterface {

	/**
	 * Ask for a subject's own export. Queues the assembly, writes nothing else.
	 *
	 * @param SubjectExportRequest $request The subject, type filter and request id.
	 *
	 * @return SubjectExport The recorded request.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function requestFor(SubjectExportRequest $request): SubjectExport;

	/**
	 * Ask for a subject's own export by its parts; see requestFor().
	 *
	 * @param string      $subject   The data subject.
	 * @param string|null $type      Optional PII type filter.
	 * @param string|null $requestId The data subject request this answers.
	 *
	 * @return SubjectExport The recorded request.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function request(string $subject, ?string $type = null, ?string $requestId = null): SubjectExport;

	/**
	 * Load an export the caller may see, or null.
	 *
	 * @param string $uuid The export uuid.
	 *
	 * @return SubjectExport|null The export, or null when absent or not theirs.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function load(string $uuid): ?SubjectExport;

	/**
	 * The export's bytes, or null when the delivery may not happen.
	 *
	 * The expiry is asked BEFORE the assembly, so an expired link costs nothing
	 * and reveals nothing.
	 *
	 * @param string        $uuid The export uuid.
	 * @param DateTime|null $now  The moment, or null for the real one.
	 *
	 * @return string|null The machine readable export, or null when refused.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function download(string $uuid, ?DateTime $now = null): ?string;
}//end interface
