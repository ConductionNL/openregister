<?php

/**
 * Unit tests for the NLGov path, query and URI version rules ({@see \OCA\OpenRegister\Service\Oas\NlGovDocumentRules}).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\Oas\NlGovDocumentRules;
use OCA\OpenRegister\Service\Oas\OasValidationReport;
use PHPUnit\Framework\TestCase;

/**
 * Pass 6's path and query rules: each finding is a warning with code nlgov_rule and its rule id.
 *
 * @covers \OCA\OpenRegister\Service\Oas\NlGovDocumentRules
 * @uses   \OCA\OpenRegister\Service\Oas\NlGovRuleCatalogue
 * @uses   \OCA\OpenRegister\Service\Oas\OasIssue
 * @uses   \OCA\OpenRegister\Service\Oas\OasValidationReport
 */
class OasServiceNlGovPathRulesTest extends TestCase {

	/**
	 * The findings of one document, as [rule, path, message].
	 *
	 * @param array<string, mixed> $oas The document.
	 *
	 * @return list<array{0: string, 1: string, 2: string}> The findings.
	 */
	private function findings(array $oas): array {
		$report = new OasValidationReport();
		(new NlGovDocumentRules(report: $report))->check(oas: $oas);

		$this->assertSame([], $report->getErrors());
		$findings = [];
		foreach ($report->getWarnings() as $issue) {
			$this->assertSame(OasValidationReport::CODE_NLGOV_RULE, $issue['code']);
			$findings[] = [$issue['rule'], $issue['path'], $issue['message']];
		}

		return $findings;
	}//end findings()

	/**
	 * A clean document has no findings.
	 *
	 * @return void
	 */
	public function testACleanDocumentHasNoFindings(): void {
		$oas = [
			'servers' => [['url' => 'https://host/api/v1']],
			'paths' => [
				'/' => [],
				'/objects/{register}/audit-trails' => [
					'get' => ['parameters' => [['name' => 'pageSize', 'in' => 'query'], ['name' => 'Some_Header', 'in' => 'header']]],
				],
			],
		];

		$this->assertSame([], $this->findings(oas: $oas));
	}//end testACleanDocumentHasNoFindings()

	/**
	 * A trailing slash fails /core/no-trailing-slash.
	 *
	 * @return void
	 */
	public function testATrailingSlashFails(): void {
		$findings = $this->findings(oas: ['servers' => [['url' => 'https://host/v2/']], 'paths' => ['/objects/foo/' => []]]);

		$this->assertSame(
			[['/core/no-trailing-slash', 'paths./objects/foo/', 'Violates NLGov /core/no-trailing-slash: The path "/objects/foo/" ends in a slash.']],
			$findings
		);
	}//end testATrailingSlashFails()

	/**
	 * A segment that is not kebab-case fails; template segments are not checked.
	 *
	 * @return void
	 */
	public function testSegmentsMustBeKebabCase(): void {
		$findings = $this->findings(
			oas: ['servers' => [['url' => 'https://host/v1']], 'paths' => ['/publicationTypes/{typeId}/audit_trail' => []]]
		);

		$this->assertSame(['/core/path-segments-kebab-case', '/core/path-segments-kebab-case'], array_column($findings, 0));
		$this->assertStringContainsString('"publicationTypes"', $findings[0][2]);
		$this->assertStringContainsString('"audit_trail"', $findings[1][2]);
	}//end testSegmentsMustBeKebabCase()

	/**
	 * `_extend` is the declared deviation; a snake_case key is a plain failure; references are followed.
	 *
	 * @return void
	 */
	public function testQueryKeysAndTheReservedUnderscoreDeviation(): void {
		$oas = [
			'servers' => [['url' => 'https://host/v1']],
			'components' => ['parameters' => ['Extend' => ['name' => '_extend', 'in' => 'query']]],
			'paths' => [
				'/objects' => [
					'parameters' => [['name' => 'page_size', 'in' => 'query']],
					'get' => ['parameters' => [['$ref' => '#/components/parameters/Extend'], ['$ref' => '#/components/parameters/Missing'], ['name' => '_order[title]', 'in' => 'query']]],
					'x-custom' => ['parameters' => [['name' => 'Ignored_Key', 'in' => 'query']]],
				],
			],
		];

		$findings = $this->findings(oas: $oas);

		$this->assertSame(
			['paths./objects.parameters.0', 'paths./objects.get.parameters.0', 'paths./objects.get.parameters.2'],
			array_column($findings, 1)
		);
		$this->assertSame('Violates NLGov /core/query-keys-camel-case: The query key "page_size" is not camelCase.', $findings[0][2]);
		$this->assertStringStartsWith('Declared deviation from NLGov /core/query-keys-camel-case: The query key "_extend" is not camelCase.', $findings[1][2]);
		$this->assertStringContainsString('reserved query key', $findings[1][2]);
		$this->assertStringContainsString('"_order[title]"', $findings[2][2]);
	}//end testQueryKeysAndTheReservedUnderscoreDeviation()

	/**
	 * A server URL without the major version is the declared /core/uri-version deviation.
	 *
	 * @return void
	 */
	public function testAServerWithoutTheMajorVersionIsTheDeclaredDeviation(): void {
		$findings = $this->findings(oas: ['servers' => [['url' => 'https://host/index.php/apps/openregister/api']]]);

		$this->assertCount(1, $findings);
		$this->assertSame('/core/uri-version', $findings[0][0]);
		$this->assertSame('servers.0.url', $findings[0][1]);
		$this->assertStringStartsWith('Declared deviation from NLGov /core/uri-version:', $findings[0][2]);
	}//end testAServerWithoutTheMajorVersionIsTheDeclaredDeviation()

	/**
	 * A document without servers or paths has nothing to find.
	 *
	 * @return void
	 */
	public function testNothingToCheck(): void {
		$this->assertSame([], $this->findings(oas: ['paths' => 'not a map']));
	}//end testNothingToCheck()
}//end class
