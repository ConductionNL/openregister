<?php

/**
 * OpenRegister state field rule platform
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\Support
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

namespace OCA\OpenRegister\Listener\Support;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Rules\RuleRunRecorderInterface;
use Psr\Log\LoggerInterface;

/**
 * The schema lookup, the rule-run recorder and the logger of the state field rule listener.
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */
final readonly class StateFieldRulePlatform {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaMapperInterface    $schemaMapper Schema lookup.
	 * @param RuleRunRecorderInterface $ruleRuns     Records each rule run.
	 * @param LoggerInterface          $logger       Logger.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function __construct(
		public SchemaMapperInterface $schemaMapper,
		public RuleRunRecorderInterface $ruleRuns,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
