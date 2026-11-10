<?php

/**
 * Unit tests for the administered synonym and stopword dictionary.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search;

use OCA\OpenRegister\Service\Search\SearchDictionary;
use PHPUnit\Framework\TestCase;

class SearchDictionaryTest extends TestCase {

	/**
	 * The dictionary from the row this change closes: an administrator teaches
	 * the search that omgevingsvergunning and bouwvergunning are the same thing
	 * to the person typing in the portal.
	 *
	 * @param array $stopwords The stopwords.
	 *
	 * @return SearchDictionary
	 */
	private function dictionary(array $stopwords = ['de', 'een']): SearchDictionary {
		return SearchDictionary::fromDeclarations(
			[
				['prefLabel' => 'omgevingsvergunning', 'altLabel' => ['bouwvergunning', 'bouwaanvraag']],
				['prefLabel' => 'bezwaar', 'altLabel' => ['beroep']],
			],
			$stopwords
		);
	}//end dictionary()

	/**
	 * A word an administrator taught is searched alongside its group, written
	 * in the search grammar the term parser already reads.
	 *
	 * @return void
	 */
	public function testATaughtWordIsSearchedAlongsideItsGroup(): void {
		$expansion = $this->dictionary()->expand('omgevingsvergunning', 5, 20);

		$this->assertTrue($expansion->changed());
		$this->assertSame('(omgevingsvergunning OR bouwvergunning OR bouwaanvraag)', $expansion->term());
		$this->assertSame(['bouwvergunning', 'bouwaanvraag'], $expansion->jsonSerialize()['added']);
	}//end testATaughtWordIsSearchedAlongsideItsGroup()

	/**
	 * A word nobody taught is left exactly as typed. Paired with the test
	 * above: a dictionary that rewrote everything would pass that one alone.
	 *
	 * @return void
	 */
	public function testAnUntaughtWordIsLeftAlone(): void {
		$expansion = $this->dictionary()->expand('kapvergunning', 5, 20);

		$this->assertFalse($expansion->changed());
		$this->assertSame('kapvergunning', $expansion->term());
		$this->assertFalse($expansion->isReportable());
	}//end testAnUntaughtWordIsLeftAlone()

	/**
	 * A stopword is dropped from the term and named in the report.
	 *
	 * @return void
	 */
	public function testAStopwordIsDroppedAndReported(): void {
		$expansion = $this->dictionary()->expand('de bezwaar', 5, 20);

		$this->assertSame('(bezwaar OR beroep)', $expansion->term());
		$this->assertSame(['de'], $expansion->jsonSerialize()['removedStopwords']);
	}//end testAStopwordIsDroppedAndReported()

	/**
	 * A term made only of stopwords keeps the term as typed.
	 *
	 * Removing every word leaves an empty term, and an empty term answers with
	 * the whole register: the loudest possible response to the quietest
	 * possible input, and it would look like the search simply ignored them.
	 *
	 * @return void
	 */
	public function testATermOfOnlyStopwordsFallsBackToWhatWasTyped(): void {
		$expansion = $this->dictionary()->expand('de een', 5, 20);

		$this->assertSame('de een', $expansion->term());
		$this->assertFalse($expansion->changed());
		$this->assertTrue($expansion->isReportable(), 'the fallback is reported, or it looks like nothing loaded');
		$this->assertTrue($expansion->jsonSerialize()['keptBecauseRemovalWouldEmptyIt']);
	}//end testATermOfOnlyStopwordsFallsBackToWhatWasTyped()

	/**
	 * The per-group cap bounds what ONE word may contribute.
	 *
	 * @return void
	 */
	public function testThePerGroupCapBoundsOneWord(): void {
		$expansion = $this->dictionary()->expand('omgevingsvergunning', 1, 20);

		$this->assertSame('(omgevingsvergunning OR bouwvergunning)', $expansion->term());
	}//end testThePerGroupCapBoundsOneWord()

	/**
	 * The per-query cap bounds the whole term, across groups.
	 *
	 * @return void
	 */
	public function testThePerQueryCapBoundsTheWholeTerm(): void {
		$expansion = $this->dictionary()->expand('omgevingsvergunning bezwaar', 5, 2);

		// The first word takes both of its synonyms and exhausts the budget, so
		// the second is searched as typed rather than half-expanded.
		$this->assertSame('(omgevingsvergunning OR bouwvergunning OR bouwaanvraag) bezwaar', $expansion->term());
		$this->assertCount(2, $expansion->jsonSerialize()['added']);
	}//end testThePerQueryCapBoundsTheWholeTerm()

	/**
	 * A cap of zero disables expansion without disabling the dictionary: the
	 * stopwords still apply.
	 *
	 * @return void
	 */
	public function testAZeroCapStopsExpansionButNotStopwords(): void {
		$expansion = $this->dictionary()->expand('de bezwaar', 0, 0);

		$this->assertSame('bezwaar', $expansion->term());
		$this->assertSame([], $expansion->jsonSerialize()['added']);
	}//end testAZeroCapStopsExpansionButNotStopwords()

	/**
	 * A group that cannot expand anything is not a group.
	 *
	 * A concept with only a preferred label would expand a word to itself and
	 * be reported as an expansion that changed nothing.
	 *
	 * @return void
	 */
	public function testAConceptWithNoAlternateLabelIsNotAGroup(): void {
		$dictionary = SearchDictionary::fromDeclarations(
			[['prefLabel' => 'bezwaar', 'altLabel' => []]],
			[]
		);

		$this->assertTrue($dictionary->isEmpty());
		$this->assertFalse($dictionary->expand('bezwaar', 5, 20)->changed());
	}//end testAConceptWithNoAlternateLabelIsNotAGroup()

	/**
	 * Matching ignores case, because a person typing in a portal does not
	 * capitalise the way an administrator did.
	 *
	 * @return void
	 */
	public function testMatchingIgnoresCase(): void {
		$expansion = $this->dictionary()->expand('Bezwaar', 5, 20);

		$this->assertSame('(Bezwaar OR beroep)', $expansion->term());
	}//end testMatchingIgnoresCase()

	/**
	 * An empty dictionary changes nothing at all: an instance with no
	 * administered dictionary searches exactly as it did before one existed.
	 *
	 * @return void
	 */
	public function testAnEmptyDictionaryChangesNothing(): void {
		$expansion = SearchDictionary::empty()->expand('de omgevingsvergunning', 5, 20);

		$this->assertSame('de omgevingsvergunning', $expansion->term());
		$this->assertFalse($expansion->isReportable());
	}//end testAnEmptyDictionaryChangesNothing()

	/**
	 * The report names what the DICTIONARY added, and what was typed, so a
	 * result nobody expected carries its own reason.
	 *
	 * @return void
	 */
	public function testTheReportNamesTheOriginalAndWhatWasAdded(): void {
		$report = $this->dictionary()->expand('de bezwaar', 5, 20)->jsonSerialize();

		$this->assertSame('de bezwaar', $report['original']);
		$this->assertSame('(bezwaar OR beroep)', $report['searched']);
		$this->assertSame(['beroep'], $report['added']);
		$this->assertSame(['de'], $report['removedStopwords']);
	}//end testTheReportNamesTheOriginalAndWhatWasAdded()
}//end class
