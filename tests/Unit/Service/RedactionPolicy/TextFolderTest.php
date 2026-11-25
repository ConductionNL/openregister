<?php

/**
 * TextFolder: case- and accent-folding, and whole-word occurrences mapped back to the original text.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\RedactionPolicy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\RedactionPolicy;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\RedactionPolicy\TextFolder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\RedactionPolicy\TextFolder
 */
class TextFolderTest extends TestCase {

	public function testFoldingLowersCaseStripsAccentsAndTrims(): void {
		$folder = new TextFolder();

		$this->assertSame('jose muller', $folder->fold('  José MÜLLER '));
		$this->assertSame('aeron', $folder->fold('Aëron'));
	}

	public function testAnOccurrenceIsFoundRegardlessOfCaseAndAccents(): void {
		$found = (new TextFolder())->occurrences('Brief aan José Müller over de aanvraag.', 'jose muller');

		$this->assertSame([['start' => 10, 'length' => 11, 'text' => 'José Müller']], $found);
	}

	public function testEveryWholeWordOccurrenceIsReturnedInOrder(): void {
		$found = (new TextFolder())->occurrences('Jansen schreef; JANSEN tekende.', 'jansen');

		$this->assertSame(
			[
				['start' => 0, 'length' => 6, 'text' => 'Jansen'],
				['start' => 16, 'length' => 6, 'text' => 'JANSEN'],
			],
			$found
		);
	}

	public function testAMatchInsideALongerWordIsNotAnOccurrence(): void {
		$folder = new TextFolder();

		$this->assertSame([], $folder->occurrences('Jansens en Kjansen', 'jansen'));
		$this->assertSame([], $folder->occurrences('Jan2 kwam', 'jan'), 'a digit next to the match also makes it part of a word');
	}

	public function testPunctuationAndTheEdgesOfTheTextBoundAWord(): void {
		$found = (new TextFolder())->occurrences('(Jan)', 'jan');

		$this->assertSame([['start' => 1, 'length' => 3, 'text' => 'Jan']], $found);
	}

	public function testAnEmptyOrBlankTermFindsNothing(): void {
		$folder = new TextFolder();

		$this->assertSame([], $folder->occurrences('Some text', ''));
		$this->assertSame([], $folder->occurrences('Some text', '   '));
	}
}
