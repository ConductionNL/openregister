<?php

/**
 * OcrWordLocator: a value's boxes among OCR words.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
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

namespace OCA\OpenRegister\Tests\Unit\Service\File;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
use OCA\OpenRegister\Service\File\OcrWordLocator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\File\OcrWordLocator
 */
final class OcrWordLocatorTest extends TestCase {

	private static function word(string $text, ?int $page, float $x, float $y = 0.5): array {
		return ['text' => $text, 'page' => $page, 'box' => ['x' => $x, 'y' => $y, 'w' => 0.1, 'h' => 0.02]];
	}//end word()

	public function testTwoWordsMakeOneBoxCoveringBoth(): void {
		$words = [self::word('Aan', 1, 0.0), self::word('Jan', 1, 0.2, 0.5), self::word('Jansen,', 1, 0.35, 0.51)];

		$found = (new OcrWordLocator())->locate('Jan Jansen', $words);

		$this->assertCount(1, $found);
		$this->assertSame(1, $found[0]['page']);
		$this->assertEqualsWithDelta(['x' => 0.2, 'y' => 0.5, 'w' => 0.25, 'h' => 0.03], $found[0]['box'], 1e-9);
	}//end testTwoWordsMakeOneBoxCoveringBoth()

	public function testEveryOccurrenceIsFoundInReadingOrder(): void {
		$words = [self::word('jansen', 1, 0.1), self::word('en', 1, 0.3), self::word('JANSEN', 2, 0.5)];

		$found = (new OcrWordLocator())->locate('Jansen', $words);

		$this->assertSame([1, 2], array_column($found, 'page'));
		$this->assertSame(0.5, $found[1]['box']['x']);
	}//end testEveryOccurrenceIsFoundInReadingOrder()

	public function testAMatchDoesNotCrossAPage(): void {
		$words = [self::word('Jan', 1, 0.8), self::word('Jansen', 2, 0.1)];

		$this->assertSame([], (new OcrWordLocator())->locate('Jan Jansen', $words));
	}//end testAMatchDoesNotCrossAPage()

	public function testAPartialMatchAndAnEmptyValueFindNothing(): void {
		$words = [self::word('Jan', 1, 0.1), self::word('Pietersen', 1, 0.2)];

		$this->assertSame([], (new OcrWordLocator())->locate('Jan Jansen', $words));
		$this->assertSame([], (new OcrWordLocator())->locate(' , ', $words));
	}//end testAPartialMatchAndAnEmptyValueFindNothing()

	public function testAPunctuationOnlyWordInsideAValueIsSpanned(): void {
		$words = [self::word('1234', 1, 0.1), self::word('-', 1, 0.2), self::word('AB', 1, 0.3)];

		$found = (new OcrWordLocator())->locate('1234 AB', $words);

		$this->assertCount(1, $found);
		$this->assertEqualsWithDelta(0.3, $found[0]['box']['w'], 1e-9);
	}//end testAPunctuationOnlyWordInsideAValueIsSpanned()
}//end class
