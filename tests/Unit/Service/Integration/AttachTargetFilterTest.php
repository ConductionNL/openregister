<?php

/**
 * What the attach picker offers, and what it says nothing about.
 *
 * 🔴 A PICKER THAT OFFERS AN UNWRITABLE TARGET FAILS ON CLICK, one entry at a
 * time, and the caller learns the rights matrix by trial. The filter is
 * asserted on both conditions, because they fail differently and both fail
 * silently: an unwritable schema is refused at the write, and a schema with no
 * files leaf accepts the pick and then has nowhere to put the file.
 *
 * 🔴 AN UNOFFERABLE TARGET IS NOT COUNTED, AND THAT IS DELIBERATELY THE
 * OPPOSITE OF THE CONTACT PANEL. There a reader is owed a true total, so a row
 * they may not read is counted and never named. Here nobody is owed a count of
 * registers they cannot write to, and a count would name which registers
 * exist. The test asserts the absence of a tally rather than the presence of
 * one.
 *
 * 🔴 A MANIFEST DECLARATION NARROWS AND NEVER WIDENS. A pin that could add a
 * target would be a manifest handing out write access.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
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
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Integration\AttachTargetFilter;
use PHPUnit\Framework\TestCase;

/**
 * The attach picker's targets.
 *
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md
 */
class AttachTargetFilterTest extends TestCase {

	private AttachTargetFilter $filter;

	protected function setUp(): void {
		parent::setUp();
		$this->filter = new AttachTargetFilter();
	}//end setUp()

	/**
	 * One candidate schema.
	 *
	 * @param string $schema   Its slug.
	 * @param bool   $writable Whether the caller may write it.
	 * @param bool   $leaf     Whether it holds files.
	 *
	 * @return array<string,mixed> The candidate.
	 */
	private function candidate(string $schema, bool $writable = true, bool $leaf = true): array {
		return [
			'schema' => $schema,
			'register' => 'dossiq',
			'label' => ucfirst($schema),
			'writable' => $writable,
			'hasFilesLeaf' => $leaf,
		];
	}//end candidate()

	public function testOnlyWritableSchemasWithAFilesLeafAreOffered(): void {
		$offerable = $this->filter->offerableSchemas([
			$this->candidate('case'),
			$this->candidate('bezwaar', false, true),
			$this->candidate('advies', true, false),
		]);

		$this->assertSame(['case'], array_column($offerable, 'schema'));
	}//end testOnlyWritableSchemasWithAFilesLeafAreOffered()

	public function testAnUnofferableTargetIsNotCountedAnywhere(): void {
		$offerable = $this->filter->offerableSchemas([
			$this->candidate('case'),
			$this->candidate('bezwaar', false, true),
		]);

		// Deliberately the opposite of ContactCasesPanel: nobody is owed a
		// tally of registers they cannot write to, and a tally names them.
		$rendered = json_encode($offerable);
		$this->assertStringNotContainsString('bezwaar', (string)$rendered);
		$this->assertStringNotContainsString('unwritable', (string)$rendered);
		$this->assertCount(1, $offerable);
	}//end testAnUnofferableTargetIsNotCountedAnywhere()

	public function testADeclarationNarrowsAndKeepsItsOrder(): void {
		$offerable = $this->filter->offerableSchemas(
			[$this->candidate('case'), $this->candidate('besluit'), $this->candidate('advies')],
			['besluit', 'case']
		);

		$this->assertSame(['besluit', 'case'], array_column($offerable, 'schema'));
	}//end testADeclarationNarrowsAndKeepsItsOrder()

	public function testADeclarationNeverWidens(): void {
		$offerable = $this->filter->offerableSchemas(
			[$this->candidate('case'), $this->candidate('bezwaar', false, true)],
			['bezwaar', 'case']
		);

		// A pin that could add a target would be a manifest handing out write
		// access.
		$this->assertSame(['case'], array_column($offerable, 'schema'));
	}//end testADeclarationNeverWidens()

	public function testADeclarationNamingNothingOfferableOffersNothing(): void {
		$offerable = $this->filter->offerableSchemas([$this->candidate('case')], ['iets-anders']);

		$this->assertSame([], $offerable);
	}//end testADeclarationNamingNothingOfferableOffersNothing()

	public function testNoDeclarationOffersEveryWritableSchemaWithALeaf(): void {
		$offerable = $this->filter->offerableSchemas([$this->candidate('case'), $this->candidate('besluit')], null);

		$this->assertSame(['case', 'besluit'], array_column($offerable, 'schema'));
	}//end testNoDeclarationOffersEveryWritableSchemaWithALeaf()

	public function testAFileTheCallerCannotOpenIsRefusedBeforeTheCopy(): void {
		$refusal = $this->filter->whyRefused(
			['id' => 7, 'readable' => false],
			['schema' => 'case', 'writable' => true, 'hasFilesLeaf' => true]
		);

		// Said plainly: the caller already knows they cannot open it, so this
		// is the answer to what they just tried rather than an oracle.
		$this->assertStringContainsString('cannot open this file', $refusal);
	}//end testAFileTheCallerCannotOpenIsRefusedBeforeTheCopy()

	public function testATargetThatChangedUnderTheCallerIsRefusedWithoutDetail(): void {
		$refusal = $this->filter->whyRefused(
			['id' => 7, 'readable' => true],
			['schema' => 'case', 'writable' => false, 'hasFilesLeaf' => true]
		);

		$this->assertStringContainsString('may not add files', $refusal);
		// No schema, no register, no title: they picked from a list this
		// class built, so nothing new is revealed by the refusal.
		$this->assertStringNotContainsString('case', $refusal);
	}//end testATargetThatChangedUnderTheCallerIsRefusedWithoutDetail()

	public function testAnObjectThatHoldsNoFilesSaysSo(): void {
		$refusal = $this->filter->whyRefused(
			['id' => 7, 'readable' => true],
			['schema' => 'advies', 'writable' => true, 'hasFilesLeaf' => false]
		);

		$this->assertStringContainsString('does not hold files', $refusal);
	}//end testAnObjectThatHoldsNoFilesSaysSo()

	public function testAnAttachThatMayProceedSaysNothing(): void {
		$this->assertSame(
			'',
			$this->filter->whyRefused(
				['id' => 7, 'readable' => true],
				['schema' => 'case', 'writable' => true, 'hasFilesLeaf' => true]
			)
		);
	}//end testAnAttachThatMayProceedSaysNothing()

	public function testSearchResultsStayInsideTheOfferableSchemas(): void {
		$results = $this->filter->searchResults(
			[
				['objectUuid' => 'o1', 'title' => 'Zaak A', 'schema' => 'case'],
				['objectUuid' => 'o2', 'title' => 'Geheim bezwaar', 'schema' => 'bezwaar'],
			],
			['case']
		);

		$this->assertSame(['o1'], array_column($results, 'objectUuid'));
		$this->assertStringNotContainsString('Geheim bezwaar', (string)json_encode($results));
	}//end testSearchResultsStayInsideTheOfferableSchemas()

	public function testSearchResultsAreBounded(): void {
		$hits = [];
		for ($i = 0; $i < 100; $i++) {
			$hits[] = ['objectUuid' => 'o' . $i, 'title' => 'Zaak ' . $i, 'schema' => 'case'];
		}

		$this->assertCount(AttachTargetFilter::MAX_RESULTS, $this->filter->searchResults($hits, ['case']));
	}//end testSearchResultsAreBounded()
}//end class
