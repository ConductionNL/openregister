<?php

/**
 * OpenRegister rule targets
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
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

namespace OCA\OpenRegister\Service\Rules;

use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * The rules a schema declares, and the schemas, tables and stored objects they act on.
 *
 * Shared by the services that operate on one declared rule: switching it,
 * trying it and replaying it.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RuleTargets {
	/**
	 * Bundle the collaborators.
	 *
	 * @param RuleInventoryServiceInterface $rules The rules declared on a schema.
	 * @param SchemaCatalogInterface    $catalog The register, schema and magic table mappers.
	 * @param ObjectOperationsInterface $objects The stored objects a rule is applied to.
	 */
	public function __construct(
		public readonly RuleInventoryServiceInterface $rules,
		public readonly SchemaCatalogInterface $catalog,
		public readonly ObjectOperationsInterface $objects,
	) {
	}//end __construct()
}//end class
