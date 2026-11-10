<?php

/**
 * OpenRegister QualityScopeMappers
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/mdm-surface-api/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Quality;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The register and schema lookups the quality surface resolves a schema inside its register with.
 *
 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
 */
final readonly class QualityScopeMappers {

	/**
	 * Bundle the collaborators.
	 *
	 * @param SchemaMapperInterface $schemaMapper Schema lookup for the quality annotation.
	 * @param RegisterMapperInterface $registerMapper Register lookup — the boundary the schema resolves inside.
	 */
	public function __construct(
		public SchemaMapperInterface $schemaMapper,
		public RegisterMapperInterface $registerMapper,
	) {
	}//end __construct()
}//end class
