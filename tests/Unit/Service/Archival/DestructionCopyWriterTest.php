<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Archival\DestructionCopyWriter}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
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
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#requirement-a-destruction-writes-a-restorable-copy-first-req-ipc-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Archival;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\DestructionCopyWriter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The copy is written where it is asked, holds what a restore needs, and a
 * location that cannot take it is refused by name.
 *
 * @covers \OCA\OpenRegister\Service\Archival\DestructionCopyWriter
 */
class DestructionCopyWriterTest extends TestCase {

	private string $location = '';

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->location = sys_get_temp_dir() . '/or-copy-writer-' . bin2hex(random_bytes(4));
		mkdir($this->location);
	}//end setUp()

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ((array) glob($this->location . '/*/*') as $file) {
			unlink((string) $file);
		}

		foreach ((array) glob($this->location . '/*') as $directory) {
			is_dir((string) $directory) ? rmdir((string) $directory) : unlink((string) $directory);
		}

		rmdir($this->location);
	}//end tearDown()

	/**
	 * An object to destroy.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private static function object(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('7');
		$object->setSchema('8');
		$object->setObject(['titel' => 'Besluit 12']);

		return $object;
	}//end object()

	/**
	 * The copy lands under the list's directory and carries the object, its register and its schema.
	 *
	 * @return void
	 */
	public function testTheCopyHoldsWhatARestoreNeeds(): void {
		$path = (new DestructionCopyWriter())->write(location: $this->location . '/', listUuid: 'list-1', object: self::object(uuid: 'obj-1'));

		$this->assertSame($this->location . '/list-1/obj-1.json', $path);
		$copy = json_decode((string) file_get_contents($path), true);
		$this->assertSame('obj-1', $copy['uuid']);
		$this->assertSame('7', $copy['register']);
		$this->assertSame('8', $copy['schema']);
		$this->assertSame('Besluit 12', $copy['object']['titel']);
	}//end testTheCopyHoldsWhatARestoreNeeds()

	/**
	 * A second object of the same list goes into the same directory.
	 *
	 * @return void
	 */
	public function testObjectsOfOneListShareItsDirectory(): void {
		$writer = new DestructionCopyWriter();
		$writer->write(location: $this->location, listUuid: 'list-1', object: self::object(uuid: 'obj-1'));
		$writer->write(location: $this->location, listUuid: 'list-1', object: self::object(uuid: 'obj-2'));

		$this->assertSame(['obj-1.json', 'obj-2.json'], array_values(array_diff((array) scandir($this->location . '/list-1'), ['.', '..'])));
	}//end testObjectsOfOneListShareItsDirectory()

	/**
	 * A uuid or list id cannot steer the copy out of the location.
	 *
	 * @return void
	 */
	public function testIdsCannotLeaveTheLocation(): void {
		$path = (new DestructionCopyWriter())->write(location: $this->location, listUuid: '../up', object: self::object(uuid: '../../etc/x'));

		$this->assertSame($this->location . '/___up/______etc_x.json', $path);
		$this->assertFileExists($path);
	}//end testIdsCannotLeaveTheLocation()

	/**
	 * A relative location, a missing directory and a file are each refused, naming the location.
	 *
	 * @return void
	 */
	public function testALocationThatCannotTakeTheCopyIsRefusedByName(): void {
		file_put_contents($this->location . '/a-file', 'x');
		foreach (['copies', $this->location . '/missing', $this->location . '/a-file'] as $location) {
			try {
				(new DestructionCopyWriter())->write(location: $location, listUuid: 'list-1', object: self::object(uuid: 'obj-1'));
				$this->fail('a copy was written to ' . $location);
			} catch (RuntimeException $e) {
				$this->assertStringContainsString('"' . $location . '"', $e->getMessage());
			}
		}
	}//end testALocationThatCannotTakeTheCopyIsRefusedByName()
}//end class
