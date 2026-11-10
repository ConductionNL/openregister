<?php

/**
 * Unit tests for finding the relation path that holds a uuid.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\RelationMap;
use PHPUnit\Framework\TestCase;

/**
 * A uuid is found on a scalar path, or at an index of a list path; a list that lacks it is passed over.
 *
 * @covers \OCA\OpenRegister\Service\Object\RelationMap
 */
class RelationMapPathTest extends TestCase {

	/**
	 * The path names the property, and the list index when the uuid sits in a list.
	 *
	 * @return void
	 */
	public function testThePathHoldingAUuid(): void {
		$map = new RelationMap(
			[
				'notes'   => ['n-1', 'n-2'],
				'owner'   => 'p-9',
				'tasks'   => ['t-1', 't-7'],
				3         => 't-7',
			]
		);

		$this->assertSame('owner', $map->pathHolding('p-9'));
		$this->assertSame('tasks.1', $map->pathHolding('t-7'), 'the notes list is passed over');
		$this->assertNull($map->pathHolding('x-0'));
		$this->assertNull($map->pathHolding(''));

	}//end testThePathHoldingAUuid()
}//end class
