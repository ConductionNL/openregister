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

use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\ObjectValidatorInterface;

/**
 * Where a preview writes: the register and schema lookups, schema validation and the object write path.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */
final readonly class ImportPreviewTargets {

	/**
	 * Bundle the collaborators.
	 *
	 * @param SchemaCatalogInterface $catalog The register and schema lookups.
	 * @param ObjectValidatorInterface $validateObject Schema validation.
	 * @param ObjectOperationsInterface $objectService The write path.
	 */
	public function __construct(
		public SchemaCatalogInterface $catalog,
		public ObjectValidatorInterface $validateObject,
		public ObjectOperationsInterface $objectService,
	) {
	}//end __construct()
}//end class
