<?php

/**
 * Writes one NLGov rule finding to the OAS validation report.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Oas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Oas;

/**
 * One place that words an NLGov finding, so the document rules and the marker
 * agree on what a declared deviation looks like.
 *
 * Every finding is a warning with code `nlgov_rule` and the rule's id. A
 * finding the catalogue declares a deviation for starts with
 * {@see self::DEVIATION_PREFIX} and carries the reason; any other starts with
 * {@see self::VIOLATION_PREFIX}.
 *
 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
 */
final class NlGovFindings {

	/**
	 * How a finding covered by a declared deviation starts.
	 *
	 * @var string
	 */
	public const DEVIATION_PREFIX = 'Declared deviation from NLGov ';

	/**
	 * How any other finding starts.
	 *
	 * @var string
	 */
	public const VIOLATION_PREFIX = 'Violates NLGov ';

	/**
	 * Constructor.
	 *
	 * @param OasValidationReport $report The report the findings go to.
	 */
	public function __construct(
		private readonly OasValidationReport $report,
	) {
	}//end __construct()

	/**
	 * Write one finding as a warning, naming the deviation when the rule declares one.
	 *
	 * @param string $ruleId  The rule.
	 * @param string $path    Where in the document.
	 * @param string $message What was found.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	public function add(string $ruleId, string $path, string $message): void {
		$reason = (new NlGovRuleCatalogue())->deviationOf(ruleId: $ruleId);
		if ($reason === null) {
			$this->addViolation(ruleId: $ruleId, path: $path, message: $message);
			return;
		}

		$this->write(ruleId: $ruleId, path: $path, message: self::DEVIATION_PREFIX.$ruleId.': '.$message.' '.$reason);
	}//end add()

	/**
	 * Write one finding as a warning that no declared deviation covers, whatever the rule declares.
	 *
	 * For a rule with a deviation whose finding falls outside it: an
	 * underscore query key is the declared deviation, a snake_case one is not.
	 *
	 * @param string $ruleId  The rule.
	 * @param string $path    Where in the document.
	 * @param string $message What was found.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	public function addViolation(string $ruleId, string $path, string $message): void {
		$this->write(ruleId: $ruleId, path: $path, message: self::VIOLATION_PREFIX.$ruleId.': '.$message);
	}//end addViolation()

	/**
	 * Write one worded finding to the report.
	 *
	 * @param string $ruleId  The rule.
	 * @param string $path    Where in the document.
	 * @param string $message The worded message.
	 *
	 * @return void
	 */
	private function write(string $ruleId, string $path, string $message): void {
		$this->report->addWarning(
			issue: new OasIssue(path: $path, message: $message, code: OasValidationReport::CODE_NLGOV_RULE, rule: $ruleId)
		);
	}//end write()
}//end class
