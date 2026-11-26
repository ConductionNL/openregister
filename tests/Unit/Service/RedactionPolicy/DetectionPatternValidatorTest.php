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

use OCA\OpenRegister\Service\RedactionPolicy\DetectionPatternValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A dangerous or useless pattern is refused at save; a sound one is not.
 */
#[CoversClass(DetectionPatternValidator::class)]
class DetectionPatternValidatorTest extends TestCase {

	/**
	 * Nested quantifiers that try every split of a run are refused, naming the backtrack limit.
	 *
	 * @param string $pattern The pattern.
	 *
	 * @return void
	 */
	#[DataProvider('catastrophic')]
	public function testCatastrophicBacktrackingIsRefused(string $pattern): void {
		$problems = (new DetectionPatternValidator())->problemsOf(['kind' => 'regex', 'pattern' => $pattern, 'entityType' => 'X']);

		self::assertCount(1, $problems);
		self::assertStringContainsString('backtrack limit', $problems[0]);
	}//end testCatastrophicBacktrackingIsRefused()

	/**
	 * The catastrophic patterns.
	 *
	 * @return array<string, array{0: string}> The cases.
	 */
	public static function catastrophic(): array {
		return ['letters' => ['(a+)+$'], 'digits' => ['(\d+)+$'], 'spaces' => ['( +)+$'], 'alternation' => ['(\w|\d)+$']];
	}//end catastrophic()

	/**
	 * A pattern that matches nothing at all marks every position, so it is refused.
	 *
	 * @return void
	 */
	public function testAnEmptyMatchIsRefused(): void {
		$problems = (new DetectionPatternValidator())->problemsOf(['kind' => 'regex', 'pattern' => '\d*', 'entityType' => 'X']);

		self::assertSame(['the pattern matches the empty string, so it would mark every position of a document'], $problems);
	}//end testAnEmptyMatchIsRefused()

	/**
	 * A pattern that does not compile, and one that is too long, are refused.
	 *
	 * @return void
	 */
	public function testAnInvalidOrOverlongPatternIsRefused(): void {
		$validator = new DetectionPatternValidator();

		self::assertSame(['the pattern is not a valid regular expression'], $validator->problemsOf(['kind' => 'regex', 'pattern' => 'ZK-(\d+']));
		self::assertSame(['the pattern is longer than 500 characters'], $validator->problemsOf(['kind' => 'regex', 'pattern' => str_repeat('a', 501)]));
		self::assertSame(['the pattern is empty'], $validator->problemsOf(['kind' => 'regex', 'pattern' => '']));
	}//end testAnInvalidOrOverlongPatternIsRefused()

	/**
	 * The organisation's case-number pattern and a literal term are accepted, slashes and all.
	 *
	 * @return void
	 */
	public function testASoundPatternIsAccepted(): void {
		$validator = new DetectionPatternValidator();

		self::assertSame([], $validator->problemsOf(['kind' => 'regex', 'pattern' => '\bZK-\d{4}-\d{5}\b', 'entityType' => 'CASE_NUMBER']));
		self::assertSame([], $validator->problemsOf(['kind' => 'regex', 'pattern' => '\d{2}/\d{2}/\d{4}', 'entityType' => 'DATE']));
		self::assertSame([], $validator->problemsOf(['kind' => 'term', 'pattern' => '(a+)+$', 'entityType' => 'X']));
	}//end testASoundPatternIsAccepted()

	/**
	 * Checking a pattern leaves the process's backtrack limit as it found it.
	 *
	 * @return void
	 */
	public function testTheBacktrackLimitIsRestored(): void {
		$before = ini_get('pcre.backtrack_limit');
		(new DetectionPatternValidator())->problemsOf(['kind' => 'regex', 'pattern' => '(a+)+$']);

		self::assertSame($before, ini_get('pcre.backtrack_limit'));
	}//end testTheBacktrackLimitIsRestored()
}//end class
