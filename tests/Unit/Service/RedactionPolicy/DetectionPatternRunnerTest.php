<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\RedactionPolicy
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#requirement-an-officers-own-pattern-runs-beside-the-model-req-rpd-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\RedactionPolicy;

use OCA\OpenRegister\Service\RedactionPolicy\DetectionPatternRunner;
use OCA\OpenRegister\Service\RedactionPolicy\DetectionPatternValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The organisation's patterns turn into detections that name the pattern.
 */
#[CoversClass(DetectionPatternRunner::class)]
#[CoversClass(DetectionPatternValidator::class)]
class DetectionPatternRunnerTest extends TestCase {

	/**
	 * A case number is found by the organisation's pattern, with method pattern:<slug>.
	 *
	 * @return void
	 */
	public function testACaseNumberIsFoundByThePattern(): void {
		$text = 'Zie zaak ZK-2026-00412 en ZK-2025-00001.';
		$result = (new DetectionPatternRunner())->run(
			patterns: [['slug' => 'zaaknummer', 'kind' => 'regex', 'pattern' => '\bZK-\d{4}-\d{5}\b', 'entityType' => 'CASE_NUMBER', 'bases' => ['5.1.2e'], 'confidence' => 0.9]],
			text: $text
		);

		self::assertSame([], $result['failed']);
		self::assertSame(
			[
				['entityType' => 'CASE_NUMBER', 'text' => 'ZK-2026-00412', 'start' => 9, 'end' => 22, 'confidence' => 0.9, 'detectionMethod' => 'pattern:zaaknummer', 'bases' => ['5.1.2e']],
				['entityType' => 'CASE_NUMBER', 'text' => 'ZK-2025-00001', 'start' => 26, 'end' => 39, 'confidence' => 0.9, 'detectionMethod' => 'pattern:zaaknummer', 'bases' => ['5.1.2e']],
			],
			$result['detections']
		);
	}//end testACaseNumberIsFoundByThePattern()

	/**
	 * A literal term matches case-insensitively and is not read as a regex.
	 *
	 * @return void
	 */
	public function testATermIsLiteral(): void {
		$result = (new DetectionPatternRunner())->run(
			patterns: [['slug' => 'project', 'kind' => 'term', 'pattern' => 'Project X.1', 'entityType' => 'PROJECT']],
			text: 'Het project x.1 en project X21.'
		);

		self::assertSame(['project x.1'], array_column($result['detections'], 'text'));
		self::assertSame(1.0, $result['detections'][0]['confidence']);
	}//end testATermIsLiteral()

	/**
	 * A local override switches a base pattern off, and keeps doing so whatever the base now says.
	 *
	 * @return void
	 */
	public function testAnOverrideSwitchesOffABaseRuleAcrossAnUpdate(): void {
		$override = ['slug' => 'geen-iban', 'layer' => 'local', 'overrides' => 'iban', 'active' => false, 'kind' => 'regex', 'pattern' => 'x', 'entityType' => 'X'];
		$runner = new DetectionPatternRunner();

		$before = $runner->run(patterns: [['slug' => 'iban', 'layer' => 'base', 'kind' => 'regex', 'pattern' => 'NL\d{2}[A-Z]{4}\d{10}', 'entityType' => 'IBAN'], $override], text: 'NL91ABNA0417164300');
		$after = $runner->run(patterns: [['slug' => 'iban', 'layer' => 'base', 'kind' => 'regex', 'pattern' => '[A-Z]{2}\d{2}[A-Z0-9]{4,30}', 'entityType' => 'IBAN'], $override], text: 'NL91ABNA0417164300');
		$without = $runner->run(patterns: [['slug' => 'iban', 'layer' => 'base', 'kind' => 'regex', 'pattern' => 'NL\d{2}[A-Z]{4}\d{10}', 'entityType' => 'IBAN']], text: 'NL91ABNA0417164300');

		self::assertSame([], $before['detections']);
		self::assertSame([], $after['detections']);
		self::assertCount(1, $without['detections']);
	}//end testAnOverrideSwitchesOffABaseRuleAcrossAnUpdate()

	/**
	 * An inactive pattern does not run, and a pattern that blows the limit at run time is reported, not silent.
	 *
	 * @return void
	 */
	public function testAnInactiveOrRunawayPatternFindsNothingVisibly(): void {
		$result = (new DetectionPatternRunner())->run(
			patterns: [
				['slug' => 'uit', 'kind' => 'regex', 'pattern' => 'a', 'entityType' => 'X', 'active' => false],
				['slug' => 'oud', 'kind' => 'regex', 'pattern' => '(a+)+$', 'entityType' => 'X'],
			],
			text: str_repeat('a', 60000).'!'
		);

		self::assertSame([], $result['detections']);
		self::assertSame(['oud'], array_keys($result['failed']));
	}//end testAnInactiveOrRunawayPatternFindsNothingVisibly()
}//end class
