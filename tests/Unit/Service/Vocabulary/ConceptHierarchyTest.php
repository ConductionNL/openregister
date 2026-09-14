<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Vocabulary\ConceptHierarchy}.
 *
 * The depth bound is the assertion that matters most here. A scheme whose
 * narrower relations loop is not hypothetical (a one-sided import produces
 * one), and an unbounded walk over it is a hung request rather than a wrong
 * answer, which is harder to notice and worse to suffer.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Vocabulary
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Vocabulary;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Vocabulary\ConceptHierarchy;
use OCA\OpenRegister\Service\Vocabulary\ConceptLifecycle;
use PHPUnit\Framework\TestCase;

class ConceptHierarchyTest extends TestCase {

	private ConceptHierarchy $hierarchy;

	/**
	 * A three-level scheme: vergunning > kapvergunning > kapvergunning-spoed,
	 * plus an unrelated top term.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $scheme;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->hierarchy = new ConceptHierarchy(lifecycle: new ConceptLifecycle());

		$this->scheme = [
			'urn:vergunning' => [
				'uri' => 'urn:vergunning',
				'prefLabel' => ['nl' => 'Vergunning', 'en' => 'Permit'],
				'narrower' => ['urn:kap'],
			],
			'urn:kap' => [
				'uri' => 'urn:kap',
				'prefLabel' => ['nl' => 'Kapvergunning'],
				'broader' => ['urn:vergunning'],
				'narrower' => ['urn:kap-spoed'],
			],
			'urn:kap-spoed' => [
				'uri' => 'urn:kap-spoed',
				'prefLabel' => ['nl' => 'Kapvergunning spoed'],
				'broader' => ['urn:kap'],
			],
			'urn:melding' => [
				'uri' => 'urn:melding',
				'prefLabel' => ['nl' => 'Melding'],
			],
		];
	}//end setUp()

	/**
	 * The branch includes its own root: filtering on `vergunning` must also
	 * return the objects that hold `vergunning` itself.
	 *
	 * @return void
	 */
	public function testTheBranchIncludesItsRootAndEveryNarrowerConcept(): void {
		$this->assertSame(
			['urn:vergunning', 'urn:kap', 'urn:kap-spoed'],
			$this->hierarchy->branchUris(rootUri: 'urn:vergunning', conceptsByUri: $this->scheme)
		);
	}//end testTheBranchIncludesItsRootAndEveryNarrowerConcept()

	/**
	 * The depth bound is honoured exactly, which is what makes a branch
	 * filter a bounded question.
	 *
	 * @return void
	 */
	public function testTheDepthBoundIsHonoured(): void {
		$this->assertSame(
			['urn:vergunning'],
			$this->hierarchy->branchUris(rootUri: 'urn:vergunning', conceptsByUri: $this->scheme, maxDepth: 0)
		);
		$this->assertSame(
			['urn:vergunning', 'urn:kap'],
			$this->hierarchy->branchUris(rootUri: 'urn:vergunning', conceptsByUri: $this->scheme, maxDepth: 1)
		);
	}//end testTheDepthBoundIsHonoured()

	/**
	 * A cycle terminates with the set it reached, not with a hung request.
	 *
	 * @return void
	 */
	public function testACyclicSchemeTerminates(): void {
		$cyclic = [
			'urn:a' => ['uri' => 'urn:a', 'narrower' => ['urn:b']],
			'urn:b' => ['uri' => 'urn:b', 'narrower' => ['urn:a']],
		];

		$this->assertSame(
			['urn:a', 'urn:b'],
			$this->hierarchy->branchUris(rootUri: 'urn:a', conceptsByUri: $cyclic, maxDepth: 50)
		);
	}//end testACyclicSchemeTerminates()

	/**
	 * A one-sided import leaves `narrower` empty on a term that other terms
	 * point at through `broader`, so both directions are read.
	 *
	 * @return void
	 */
	public function testTheRelationIsReadFromBothSides(): void {
		$oneSided = [
			'urn:top' => ['uri' => 'urn:top'],
			'urn:child' => ['uri' => 'urn:child', 'broader' => ['urn:top']],
		];

		$this->assertSame(
			['urn:top', 'urn:child'],
			$this->hierarchy->branchUris(rootUri: 'urn:top', conceptsByUri: $oneSided)
		);
		$this->assertFalse($this->hierarchy->isLeaf(uri: 'urn:top', conceptsByUri: $oneSided));
		$this->assertTrue($this->hierarchy->isLeaf(uri: 'urn:child', conceptsByUri: $oneSided));
	}//end testTheRelationIsReadFromBothSides()

	/**
	 * A child inherits nothing from its parent: not the label, not the
	 * window, not the weight, not the group. The only thing the hierarchy
	 * carries is membership of the branch.
	 *
	 * @return void
	 */
	public function testAChildInheritsNothingFromItsParent(): void {
		$scheme = [
			'urn:parent' => [
				'uri' => 'urn:parent',
				'prefLabel' => ['nl' => 'Ouder'],
				'weight' => 7,
				'exclusiveGroup' => 'urgentie',
				'validUntil' => '2020-01-01',
				'narrower' => ['urn:child'],
			],
			'urn:child' => [
				'uri' => 'urn:child',
				'prefLabel' => ['nl' => 'Kind'],
				'broader' => ['urn:parent'],
			],
		];

		$lifecycle = new ConceptLifecycle();
		$child = $scheme['urn:child'];

		$this->assertSame('Kind', $this->hierarchy->labelOf(concept: $child, language: 'nl'));
		$this->assertSame(0.0, $lifecycle->weightOf(concept: $child));
		$this->assertNull($lifecycle->exclusiveGroupOf(concept: $child));
		$this->assertTrue(
			$lifecycle->isWithinWindow(concept: $child, at: new DateTimeImmutable('2026-09-14')),
			'A retired parent does not retire its children.'
		);

		// And the branch walk still finds it, which is the one thing the
		// hierarchy does carry.
		$this->assertContains(
			'urn:child',
			$this->hierarchy->branchUris(rootUri: 'urn:parent', conceptsByUri: $scheme)
		);
	}//end testAChildInheritsNothingFromItsParent()

	/**
	 * The label falls back rather than rendering a uri at someone.
	 *
	 * @return void
	 */
	public function testTheLabelFallsBackThroughDutchThenNotationThenUri(): void {
		$this->assertSame(
			'Permit',
			$this->hierarchy->labelOf(concept: $this->scheme['urn:vergunning'], language: 'en')
		);
		$this->assertSame(
			'Vergunning',
			$this->hierarchy->labelOf(concept: $this->scheme['urn:vergunning'], language: 'de'),
			'An unknown language falls back to the required Dutch label.'
		);
		$this->assertSame(
			'c_123',
			$this->hierarchy->labelOf(concept: ['uri' => 'urn:x', 'notation' => 'c_123'], language: 'nl')
		);
		$this->assertSame(
			'urn:x',
			$this->hierarchy->labelOf(concept: ['uri' => 'urn:x'], language: 'nl')
		);
	}//end testTheLabelFallsBackThroughDutchThenNotationThenUri()

	/**
	 * The tree keeps a retired node and marks it, rather than dropping it:
	 * a picker still has to show the path to an offerable value below it.
	 *
	 * @return void
	 */
	public function testTheTreeMarksRatherThanDropsARetiredNode(): void {
		$scheme = $this->scheme;
		$scheme['urn:kap']['validUntil'] = '2020-01-01';

		$tree = $this->hierarchy->tree(
			rootUri: 'urn:vergunning',
			conceptsByUri: $scheme,
			language: 'nl',
			at: new DateTimeImmutable('2026-09-14')
		);

		$this->assertTrue($tree['offerable']);
		$this->assertFalse($tree['leaf']);
		$this->assertCount(1, $tree['children']);
		$this->assertSame('urn:kap', $tree['children'][0]['value']);
		$this->assertFalse($tree['children'][0]['offerable']);
		$this->assertTrue($tree['children'][0]['children'][0]['offerable']);
	}//end testTheTreeMarksRatherThanDropsARetiredNode()
}//end class
