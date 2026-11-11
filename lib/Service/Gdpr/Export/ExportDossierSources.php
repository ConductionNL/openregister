<?php

/**
 * OpenRegister export dossier sources
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Export;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;

/**
 * Where an export bundle reads the dossier it signs: the subject's data, the case and its trail.
 *
 * @spec openspec/specs/dsar-export-bundle/spec.md
 */
final class ExportDossierSources {
	/**
	 * Bundle the collaborators.
	 *
	 * @param DataSubjectRequestService $dsrService       Reused access-export assembler (ADR-011).
	 * @param CaseObjectAccessor        $accessor         RBAC-scoped, audited case load/save.
	 * @param AuditTrailMapperInterface $auditTrailMapper Immutable trail reader for the dossier history.
	 */
	public function __construct(
		public readonly DataSubjectRequestService $dsrService,
		public readonly CaseObjectAccessor $accessor,
		public readonly AuditTrailMapperInterface $auditTrailMapper,
	) {
	}//end __construct()
}//end class
