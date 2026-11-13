<?php

/**
 * OpenRegister document redaction services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File
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

namespace OCA\OpenRegister\Files\Service\File;

use OCA\OpenRegister\Files\Db\AnonymisationLogMapper;
use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;

/**
 * The redaction collaborators of the document processing handler.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class DocumentRedactionServices {
	/**
	 * Bundle the collaborators.
	 *
	 * @param EntityRelationMapperInterface $entityRelationMapper   Used to honour skip-anonymization flags during the redaction pass.
	 * @param OfficeDocumentSanitizer       $sanitizer              Office document sanitiser (DOCX / ODT).
	 * @param AnonymisationLogMapper|null   $anonymisationLogMapper Mapper for per-run anonymisation log rows; null keeps the handler construct-safe
	 *                                                              without persistence.
	 * @param FileSettingsHandler|null     $fileSettings           The file settings, for the mask form per entity type; null masks nothing.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly EntityRelationMapperInterface $entityRelationMapper,
		public readonly OfficeDocumentSanitizer $sanitizer,
		public readonly ?AnonymisationLogMapper $anonymisationLogMapper = null,
		public readonly ?FileSettingsHandler $fileSettings = null,
	) {
	}//end __construct()
}//end class
