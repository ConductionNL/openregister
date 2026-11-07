<?php

/**
 * Unit test for RelationGraphShapes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Relation;

use OCA\OpenRegister\Db\ObjectRelation;
use OCA\OpenRegister\Service\Relation\RelationGraphShapes;
use PHPUnit\Framework\TestCase;

/**
 * The node and edge shapes of a relation graph.
 */
class RelationGraphShapesTest extends TestCase {

	/**
	 * A stored row reads outgoing from a node of the level, incoming otherwise, and an address stands in for a missing uuid.
	 *
	 * @return void
	 */
	public function testAStoredRowReadsInItsDirection(): void {
		$row = new ObjectRelation();
		$row->setSourceUuid('s');
		$row->setTargetUrl('https://example.org');
		$row->setRelationType('links');
		$row->setKind(ObjectRelation::KIND_EXTERNAL);
		$shapes = new RelationGraphShapes();

		$out = $shapes->storedEdge(stored: $row, uuids: ['s']);
		$in  = $shapes->storedEdge(stored: $row, uuids: ['t']);

		$this->assertSame(['s', 'https://example.org', 'outgoing', 'links'], [$out['from'], $out['to'], $out['direction'], $out['label']]);
		$this->assertSame('incoming', $in['direction']);
		$this->assertTrue($shapes->isExternal(edge: $out));
		$this->assertFalse($shapes->isExternal(edge: ['kind' => ObjectRelation::KIND_OBJECT]));
	}//end testAStoredRowReadsInItsDirection()

	/**
	 * Unresolved and external nodes carry their fixed shape.
	 *
	 * @return void
	 */
	public function testNodeShapes(): void {
		$shapes = new RelationGraphShapes();

		$this->assertSame(
			['uuid' => 'u', 'title' => null, 'register' => null, 'schema' => null, 'distance' => 2, 'resolved' => false, 'external' => false],
			$shapes->unresolvedNode(uuid: 'u', distance: 2)
		);
		$this->assertSame('u', $shapes->externalNode(edge: [], uuid: 'u', distance: 1)['title']);
		$this->assertSame('T', $shapes->externalNode(edge: ['targetTitle' => 'T'], uuid: 'u', distance: 1)['title']);
		$this->assertSame('property', $shapes->outgoingDetails(row: [])['origin']);
	}//end testNodeShapes()
}//end class
