<?php

/**
 * PatchDataMerge and SavedObjectScope: the PATCH merge and the saved-object log context.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\PatchDataMerge;
use OCA\OpenRegister\Service\Object\SavedObjectScope;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Null clears, maps merge recursively, lists replace wholesale; the log context names the object.
 *
 * @covers \OCA\OpenRegister\Service\Object\PatchDataMerge
 * @covers \OCA\OpenRegister\Service\Object\SavedObjectScope
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
final class PatchDataMergeTest extends TestCase {

	/**
	 * Scalars replace, new keys are added, untouched keys stay and an explicit null clears.
	 *
	 * @return void
	 */
	public function testScalarsReplaceAndNullClears(): void {
		$merged = (new PatchDataMerge())->merge(
			stored: ['title' => 'Old', 'status' => 'open', 'note' => 'keep me'],
			patch: ['title' => 'New', 'status' => null, 'extra' => 1]
		);

		$this->assertSame(expected: ['title' => 'New', 'status' => null, 'note' => 'keep me', 'extra' => 1], actual: $merged);
	}//end testScalarsReplaceAndNullClears()

	/**
	 * Two maps merge recursively, keeping the nested keys the patch does not name.
	 *
	 * @return void
	 */
	public function testMapsMergeRecursively(): void {
		$merged = (new PatchDataMerge())->merge(
			stored: ['address' => ['street' => 'Main', 'geo' => ['lat' => 1, 'lon' => 2]]],
			patch: ['address' => ['geo' => ['lat' => 9]]]
		);

		$this->assertSame(expected: ['address' => ['street' => 'Main', 'geo' => ['lat' => 9, 'lon' => 2]]], actual: $merged);
	}//end testMapsMergeRecursively()

	/**
	 * A list on either side replaces the stored value wholesale.
	 *
	 * @return void
	 */
	public function testListsReplaceWholesale(): void {
		$merge = new PatchDataMerge();

		$this->assertSame(expected: ['tags' => ['c']], actual: $merge->merge(stored: ['tags' => ['a', 'b']], patch: ['tags' => ['c']]));
		$this->assertSame(expected: ['meta' => ['x']], actual: $merge->merge(stored: ['meta' => ['k' => 'v']], patch: ['meta' => ['x']]));
		$this->assertSame(expected: ['meta' => ['k' => 'v']], actual: $merge->merge(stored: ['meta' => ['x']], patch: ['meta' => ['k' => 'v']]));
		$this->assertSame(expected: ['meta' => ['k' => 'v']], actual: $merge->merge(stored: ['meta' => 'flat'], patch: ['meta' => ['k' => 'v']]));
	}//end testListsReplaceWholesale()

	/**
	 * The log context of a failure carries its message and the object's identity triple.
	 *
	 * @return void
	 */
	public function testTheSavedScopeLogContextNamesTheObject(): void {
		$object = new ObjectEntity();
		$object->setUuid('u-42');
		$scope = new SavedObjectScope(object: $object, registerId: 3, schemaId: null);

		$this->assertSame(
			expected: ['exception' => 'disk full', 'uuid' => 'u-42', 'register' => 3, 'schema' => null],
			actual: $scope->logContext(failure: new RuntimeException('disk full'))
		);
	}//end testTheSavedScopeLogContextNamesTheObject()
}//end class
