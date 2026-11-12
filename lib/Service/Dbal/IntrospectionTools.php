<?php

/**
 * OpenRegister introspection tools
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Dbal
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

namespace OCA\OpenRegister\Service\Dbal;

use OCA\OpenRegister\Service\Schema\SchemaDiffService;

/**
 * What an introspection run uses to reach an external database and compare it with a schema.
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */
final class IntrospectionTools {
	/**
	 * Bundle the collaborators.
	 *
	 * @param DbalConnectionFactory $connectionFactory Opens the DBAL connection for a source.
	 * @param SqlTypeMapper         $typeMapper        SQL column → JSON-Schema mapping.
	 * @param SchemaDiffService     $diffService       Classifies structural drift between runs.
	 */
	public function __construct(
		public readonly DbalConnectionFactory $connectionFactory,
		public readonly SqlTypeMapper $typeMapper,
		public readonly SchemaDiffService $diffService,
	) {
	}//end __construct()
}//end class
