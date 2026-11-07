<?php

/**
 * OpenRegister RuleTrialLookups
 *
 * The lookups a rule trial reads a stored object through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules\Support;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;

/**
 * The register and object mappers, bundled for RuleTrialService.
 *
 * Both are the RBAC- and tenancy-scoped mappers, so a caller who may not read
 * an object gets a not-found refusal rather than its values.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RuleTrialLookups {

	/**
	 * Bundle the lookups.
	 *
	 * @param RegisterMapperInterface $registerMapper Register lookup for a named object.
	 * @param MagicMapperInterface $objectMapper Object lookup in the register and schema table.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function __construct(
		public readonly RegisterMapperInterface $registerMapper,
		public readonly MagicMapperInterface $objectMapper,
	) {
	}//end __construct()
}//end class
