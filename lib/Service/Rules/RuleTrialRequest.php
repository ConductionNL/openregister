<?php

/**
 * OpenRegister rule trial request
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

use OCA\OpenRegister\Db\Schema;

/**
 * Which rule of a schema to try, and the unsaved declaration to try in its place.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RuleTrialRequest {
	/**
	 * Name the rule to try.
	 *
	 * @param Schema                    $schema   The schema the rule belongs to.
	 * @param string                    $ruleId   The derived rule id.
	 * @param array<string, mixed>|null $override An unsaved declaration to try in the rule's place.
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly string $ruleId,
		public readonly ?array $override = null,
	) {
	}//end __construct()

	/**
	 * The schema's slug, empty when it has none.
	 *
	 * @return string The slug.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function schemaSlug(): string {
		return (string)($this->schema->getSlug() ?? '');
	}//end schemaSlug()
}//end class
