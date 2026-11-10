<?php

/**
 * OpenRegister rules mappers
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\RuleRunMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The mappers and repositories of the rules API.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RulesMappers {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaMapperInterface  $schemaMapper Resolves the schema a rule is declared on.
	 * @param RuleRunMapperInterface $runs         Reads the run log.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function __construct(
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly RuleRunMapperInterface $runs,
	) {
	}//end __construct()
}//end class
