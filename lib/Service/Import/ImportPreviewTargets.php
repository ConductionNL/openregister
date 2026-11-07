<?php

/**
 * OpenRegister Import Preview Targets
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
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

namespace OCA\OpenRegister\Service\Import;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\ValidateObjectInterface;

/**
 * Where a preview writes: the register and schema lookups, schema validation and the object write path.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */
final readonly class ImportPreviewTargets {

	/**
	 * Bundle the collaborators.
	 *
	 * @param RegisterMapperInterface $registerMapper Register lookup.
	 * @param SchemaMapperInterface $schemaMapper Schema lookup.
	 * @param ValidateObjectInterface $validateObject Schema validation.
	 * @param ObjectOperationsInterface $objectService The write path.
	 */
	public function __construct(
		public RegisterMapperInterface $registerMapper,
		public SchemaMapperInterface $schemaMapper,
		public ValidateObjectInterface $validateObject,
		public ObjectOperationsInterface $objectService,
	) {
	}//end __construct()
}//end class
