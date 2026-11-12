<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Vocabulary\ConceptRelations}.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Vocabulary;

use OCA\OpenRegister\Service\Vocabulary\ConceptRelations;
use PHPUnit\Framework\TestCase;

/**
 * Narrower relations are read from both sides: a parent's `narrower` and a child's `broader`.
 *
 * @covers \OCA\OpenRegister\Service\Vocabulary\ConceptRelations
 */
class ConceptRelationsTest extends TestCase {

	/**
	 * A small scheme mixing the relation spellings the class accepts.
	 *
	 * @return ConceptRelations
	 */
	private function relations(): ConceptRelations {
		return new ConceptRelations(
			[
				'urn:root'  => ['uri' => 'urn:root', 'narrower' => ['urn:a', ['id' => 'urn:b'], 'urn:missing', '', 7]],
				'urn:a'     => ['uri' => 'urn:a', 'broader' => 'urn:root'],
				'urn:b'     => ['uri' => 'urn:b'],
				'urn:c'     => ['uri' => 'urn:c', 'broader' => [['@id' => 'urn:root']]],
				'urn:d'     => ['uri' => 'urn:d', 'broader' => [['uri' => '', 'id' => null, 'uuid' => 'urn:a']]],
				'urn:self'  => ['uri' => 'urn:self', 'broader' => 'urn:self'],
				'urn:weird' => ['uri' => 'urn:weird', 'broader' => 42],
			]
		);

	}//end relations()

	/**
	 * A known concept is returned as-is; an unknown one is empty.
	 *
	 * @return void
	 */
	public function testConceptLookup(): void {
		$relations = $this->relations();

		$this->assertSame(['uri' => 'urn:b'], $relations->concept('urn:b'));
		$this->assertSame([], $relations->concept('urn:nope'));

	}//end testConceptLookup()

	/**
	 * Declared narrower entries that exist, plus concepts naming it as broader, de-duplicated.
	 *
	 * @return void
	 */
	public function testNarrowerOfMergesBothDirections(): void {
		$children = $this->relations()->narrowerOf('urn:root');

		sort($children);
		$this->assertSame(['urn:a', 'urn:b', 'urn:c'], $children);

	}//end testNarrowerOfMergesBothDirections()

	/**
	 * A relation entry's URI is the first non-empty of uri, id, uuid, @id.
	 *
	 * @return void
	 */
	public function testRelationEntryUriFallsThroughEmptyKeys(): void {
		$this->assertSame(['urn:d'], $this->relations()->narrowerOf('urn:a'));

	}//end testRelationEntryUriFallsThroughEmptyKeys()

	/**
	 * A concept naming itself as broader is not its own child; leaves have none.
	 *
	 * @return void
	 */
	public function testSelfReferenceAndLeaves(): void {
		$relations = $this->relations();

		$this->assertSame([], $relations->narrowerOf('urn:self'));
		$this->assertSame([], $relations->narrowerOf('urn:b'));
		$this->assertSame([], $relations->narrowerOf('urn:unknown'));

	}//end testSelfReferenceAndLeaves()

	/**
	 * Children of the whole frontier, minus everything already known, without duplicates.
	 *
	 * @return void
	 */
	public function testUnseenChildren(): void {
		$relations = $this->relations();

		$fresh = $relations->unseenChildren(['urn:root', 'urn:a', 'urn:root'], ['urn:a']);
		sort($fresh);
		$this->assertSame(['urn:b', 'urn:c', 'urn:d'], $fresh);

		$this->assertSame([], $relations->unseenChildren(['urn:root'], ['urn:a', 'urn:b', 'urn:c']));
		$this->assertSame([], $relations->unseenChildren([], []));

	}//end testUnseenChildren()

	/**
	 * The result is a list (re-indexed), not a sparse array.
	 *
	 * @return void
	 */
	public function testUnseenChildrenIsAList(): void {
		$fresh = $this->relations()->unseenChildren(['urn:root'], ['urn:a']);

		$this->assertTrue(array_is_list($fresh));
		$this->assertCount(2, $fresh);

	}//end testUnseenChildrenIsAList()
}//end class
