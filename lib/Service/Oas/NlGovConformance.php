<?php

/**
 * Every NLGov document rule on a generated OpenAPI document, and the marker that records them.
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
 * The one entry point the OAS generator uses for the NLGov API design rules:
 * {@see check()} runs every document rule and writes the findings to the
 * validation report, {@see marker()} reads them back into the document's
 * `x-nl-api-design-rules` marker. The rules themselves live in
 * {@see NlGovDocumentRules} (paths, query keys, URI version),
 * {@see NlGovDateTimeRules}, {@see NlGovErrorRules} and
 * {@see NlGovDocumentInfoRules}.
 *
 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
 */
final class NlGovConformance {

	/**
	 * Check a whole document and write every finding to the report.
	 *
	 * @param OasValidationReport  $report The report the findings go to.
	 * @param array<string, mixed> $oas    The OpenAPI document.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	public function check(OasValidationReport $report, array $oas): void {
		(new NlGovDocumentRules(report: $report))->check(oas: $oas);

		$findings = new NlGovFindings(report: $report);
		(new NlGovDateTimeRules(findings: $findings))->check(oas: $oas);
		(new NlGovErrorRules(findings: $findings))->check(oas: $oas);
		(new NlGovDocumentInfoRules(findings: $findings))->check(oas: $oas);
	}//end check()

	/**
	 * The `x-nl-api-design-rules` marker for the findings in a report.
	 *
	 * @param OasValidationReport $report The validation report of the document.
	 *
	 * @return array{version: string, pass: list<string>, fail: list<string>, deviation: list<string>, manual: list<string>}
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	public function marker(OasValidationReport $report): array {
		return (new NlGovMarker())->forReport(report: $report);
	}//end marker()
}//end class
