<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Oas;

use OCA\OpenRegister\Service\Oas\NlGovConformanceReport;
use OCA\OpenRegister\Service\Oas\NlGovFindings;
use OCA\OpenRegister\Service\Oas\NlGovRuleCatalogue;
use OCA\OpenRegister\Service\Oas\OasIssue;
use OCA\OpenRegister\Service\Oas\OasValidationReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The conformance report: one result per rule, and pass only where a check ran and found nothing.
 *
 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
 */
#[CoversClass(NlGovConformanceReport::class)]
#[UsesClass(NlGovRuleCatalogue::class)]
#[UsesClass(NlGovFindings::class)]
#[UsesClass(OasValidationReport::class)]
#[UsesClass(OasIssue::class)]
class NlGovConformanceReportTest extends TestCase {

	/**
	 * The rules of a report, by id.
	 *
	 * @param array<string, mixed> $report The report.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function byId(array $report): array {
		return array_column($report['rules'], null, 'id');
	}

	public function testEveryRuleHasOneResultAndTheCountsAddUp(): void {
		$report = (new NlGovConformanceReport())->build(report: new OasValidationReport(), probe: null, generatedAt: '2026-11-24T12:00:00+00:00');

		$this->assertSame('2.2.1', $report['ruleset']);
		$this->assertSame('2026-11-24T12:00:00+00:00', $report['generatedAt']);
		$this->assertNull($report['probedAt']);
		$this->assertCount(count(NlGovRuleCatalogue::rules()), $report['rules']);
		$this->assertSame(count($report['rules']), array_sum($report['counts']));
		$this->assertSame(NlGovConformanceReport::RESULTS, array_keys($report['counts']));
		$this->assertSame(3, $report['counts']['not-probed']);
		$this->assertSame(0, $report['counts']['fail']);
	}

	public function testAViolationFailsADeviationIsNamedAndAnUncheckedRuleIsNeverPass(): void {
		$validation = new OasValidationReport();
		$findings   = new NlGovFindings(report: $validation);
		$findings->add(ruleId: '/core/semver', path: 'info.version', message: '"1.0" is not a semantic version');
		$findings->add(ruleId: '/core/uri-version', path: 'servers[0].url', message: 'no /v1');

		$rules = self::byId((new NlGovConformanceReport())->build(report: $validation, probe: null, generatedAt: 'now'));

		$this->assertSame('fail', $rules['/core/semver']['result']);
		$this->assertSame('info.version', $rules['/core/semver']['findings'][0]['path']);
		$this->assertSame('deviation', $rules['/core/uri-version']['result']);
		$this->assertArrayHasKey('deviation', $rules['/core/uri-version']);
		$this->assertSame('pass', $rules['/core/no-trailing-slash']['result']);
		$this->assertSame('manual', $rules['/core/naming-resources']['result']);
		$this->assertSame('manual', $rules['/core/modules/signing']['result']);
		$this->assertSame('not-probed', $rules['/core/version-header']['result']);
	}

	public function testAStatusCodeWarningStaysOnTheManualRule(): void {
		$validation = new OasValidationReport();
		$validation->addWarning(issue: new OasIssue(path: 'paths./x.get.responses.418', message: 'unusual code', code: OasValidationReport::CODE_INVALID_STATUS_CODE));

		$rule = self::byId((new NlGovConformanceReport())->build(report: $validation, probe: null, generatedAt: 'now'))['/core/http-response-code'];

		$this->assertSame('manual', $rule['result']);
		$this->assertSame('unusual code', $rule['findings'][0]['message']);
	}

	public function testAProbeDecidesTheResponseRulesAndCanFailADocumentRule(): void {
		$probe = [
			'probedAt' => '2026-11-24T11:00:00+00:00',
			'rules' => [
				'/core/version-header' => ['result' => 'fail', 'findings' => [['path' => '/objects/a', 'message' => 'API-Version is "1"']]],
				'/core/transport/security-headers' => ['result' => 'pass', 'findings' => []],
				'/core/error-handling/problem-details' => ['result' => 'fail', 'findings' => [['path' => '/objects/a/0', 'message' => 'plain json']]],
			],
		];

		$report = (new NlGovConformanceReport())->build(report: new OasValidationReport(), probe: $probe, generatedAt: 'now');
		$rules  = self::byId($report);

		$this->assertSame('2026-11-24T11:00:00+00:00', $report['probedAt']);
		$this->assertSame('fail', $rules['/core/version-header']['result']);
		$this->assertSame('API-Version is "1"', $rules['/core/version-header']['findings'][0]['message']);
		$this->assertSame('pass', $rules['/core/transport/security-headers']['result']);
		$this->assertSame('not-probed', $rules['/core/date-time/timezone']['result']);
		$this->assertSame('fail', $rules['/core/error-handling/problem-details']['result']);
		$this->assertSame(1, $report['counts']['not-probed']);
	}
}
