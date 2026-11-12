<?php

/**
 * OpenRegister RuleRunSubject
 *
 * The rule an evaluation ran and the object it ran on.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
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

namespace OCA\OpenRegister\Service\Rules;

/**
 * What a rule run row is about: which rule, declared on which schema, judged
 * against which object in which register.
 *
 * Every evaluation point names the same four things, and the run log keys both
 * its detail rows and its summaries on them; the verdict itself travels
 * separately as a RuleTrace.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RuleRunSubject {

	/**
	 * Constructor.
	 *
	 * @param string      $ruleId       The derived rule id.
	 * @param string      $schemaSlug   The slug of the schema the rule is declared on.
	 * @param string|null $objectUuid   The object evaluated, when there was one.
	 * @param string|null $registerSlug The register the object lives in.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $ruleId,
		public readonly string $schemaSlug,
		public readonly ?string $objectUuid = null,
		public readonly ?string $registerSlug = null,
	) {
	}//end __construct()
}//end class
