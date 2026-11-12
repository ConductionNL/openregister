<?php

/**
 * Characterisation tests for how ToolGrantSet cleans a stored structure and
 * lists its tool ids.
 *
 * Pins the exact structure `fromStored()` keeps for every shape a stored
 * level can take (unusable keys, non-array values, a bare id, a list of
 * entries, a single map entry, levels that end up empty) and the exact list
 * `toolIds()` returns (order, de-duplication, entries without an id).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Capability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/structured-tool-grants/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Capability;

use OCA\OpenRegister\Service\Capability\ToolGrantSet;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for the stored-structure cleaning and the tool id listing.
 */
class ToolGrantSetSanitiseGoldenTest extends TestCase {

	/**
	 * Every shape a stored level can take is kept or dropped exactly as before.
	 *
	 * @return void
	 */
	public function testStoredStructureIsCleanedLevelByLevel(): void {
		$set = ToolGrantSet::fromStored([
			'hermiq' => [
				'file' => [
					'list' => 'hermiq.listFiles',
					'read' => ['hermiq.readFile', '', 42, ['id' => 'hermiq.readFile', 'args' => ['path' => 'a']]],
					'move' => ['id' => 'hermiq.moveFile', 'args' => ['to' => 'b']],
					'copy' => ['id' => 'hermiq.copyFile'],
					'drop' => [42, null, ''],
					''     => 'hermiq.unnamed',
					3      => 'hermiq.numeric',
				],
				'empty'  => ['gone' => [null]],
				'scalar' => 'not-a-level',
				''       => ['list' => 'hermiq.nameless'],
			],
			'deadapp' => [
				'thing' => ['act' => 7],
			],
			'flat'    => 'not-a-level',
			''        => ['x' => ['y' => 'z.y']],
			9         => ['x' => ['y' => 'z.y']],
		]);

		$this->assertSame(
			[
				'hermiq' => [
					'file' => [
						'list' => ['hermiq.listFiles'],
						'read' => ['hermiq.readFile', ['id' => 'hermiq.readFile', 'args' => ['path' => 'a']]],
						'move' => [['id' => 'hermiq.moveFile', 'args' => ['to' => 'b']]],
						'copy' => ['hermiq.copyFile'],
					],
				],
			],
			$set->toStored()
		);
	}//end testStoredStructureIsCleanedLevelByLevel()

	/**
	 * A structure in which every level drops out is empty.
	 *
	 * @return void
	 */
	public function testAStructureWithNothingUsableIsEmpty(): void {
		$set = ToolGrantSet::fromStored(['app' => ['subject' => ['action' => [null]]]]);

		$this->assertTrue($set->isEmpty());
		$this->assertSame([], $set->toolIds());
	}//end testAStructureWithNothingUsableIsEmpty()

	/**
	 * Tool ids come out in walk order, de-duplicated, from bare and constrained entries.
	 *
	 * @return void
	 */
	public function testToolIdsWalkEveryEntryInOrder(): void {
		$set = ToolGrantSet::fromStored([
			'b' => [
				's' => [
					'one' => ['b.one', ['id' => 'b.two', 'args' => ['k' => 'v']]],
					'two' => 'b.one',
				],
			],
			'a' => [
				's' => ['three' => 'a.three'],
				't' => ['four' => ['id' => 'a.four', 'args' => ['k' => 'v']]],
			],
		]);

		$this->assertSame(['b.one', 'b.two', 'a.three', 'a.four'], $set->toolIds());
	}//end testToolIdsWalkEveryEntryInOrder()
}//end class
