<?php

/**
 * OdtHouseStyle test.
 *
 * Pins how a thematiq document style profile becomes the table header colours
 * and footer lines of a PDF export, and that anything malformed is dropped
 * rather than failing the export (export-pdf-house-style, task 2.1).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export\Odt
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-pdf-export-carries-the-organisations-house-style
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Export\Odt;

use OCA\OpenRegister\Service\Export\Odt\OdtHouseStyle;
use PHPUnit\Framework\TestCase;

class OdtHouseStyleTest extends TestCase {

	public function testNoProfileAndAnEmptyProfileGiveNoStyle(): void {
		$this->assertNull(OdtHouseStyle::fromProfile(profile: null));
		$this->assertNull(OdtHouseStyle::fromProfile(profile: []));
		$this->assertNull(OdtHouseStyle::fromProfile(profile: ['colours' => 'red', 'footer' => 42]));
	}

	public function testAProfileGivesItsColoursAndFooterLinesInOrder(): void {
		$style = OdtHouseStyle::fromProfile(profile: [
			'colours' => ['primary' => ' #00529B ', 'primaryText' => '#fff', 'text' => '#000000'],
			'footer' => [
				'lines' => ['Gemeente Hilversum, Dudokpark 1', "Postbus 9900\n 1201 GM  Hilversum"],
				'accessibilityUrl' => 'https://hilversum.nl/toegankelijkheid',
				'privacyUrl' => 'https://hilversum.nl/privacy',
			],
		]);

		$this->assertNotNull($style);
		$this->assertSame('#00529b', $style->headBackground);
		$this->assertSame('#ffffff', $style->headText);
		$this->assertSame(
			[
				'Gemeente Hilversum, Dudokpark 1',
				'Postbus 9900 1201 GM Hilversum',
				'https://hilversum.nl/toegankelijkheid',
				'https://hilversum.nl/privacy',
			],
			$style->footerLines
		);
	}

	public function testMalformedValuesAreDroppedNotApplied(): void {
		$style = OdtHouseStyle::fromProfile(profile: [
			'colours' => ['primary' => 'red" fo:break-before="page', 'primaryText' => ['#ffffff']],
			'footer' => ['lines' => [null, 7, '   ', 'Echte regel'], 'privacyUrl' => ''],
		]);

		$this->assertNotNull($style);
		$this->assertNull($style->headBackground);
		$this->assertNull($style->headText);
		$this->assertSame(['Echte regel'], $style->footerLines);
	}

	public function testFooterLinesAreCappedInCountAndLength(): void {
		$lines = [];
		for ($index = 1; $index <= 9; $index++) {
			$lines[] = 'Regel ' . $index;
		}

		$lines[0] = str_repeat('x', 250);
		$style = OdtHouseStyle::fromProfile(profile: ['footer' => ['lines' => $lines, 'privacyUrl' => 'https://example.org/privacy']]);

		$this->assertNotNull($style);
		$this->assertCount(OdtHouseStyle::MAX_FOOTER_LINES, $style->footerLines);
		$this->assertSame(OdtHouseStyle::MAX_LINE_LENGTH, mb_strlen($style->footerLines[0]));
		$this->assertSame('Regel 6', $style->footerLines[5]);
	}
}//end class
