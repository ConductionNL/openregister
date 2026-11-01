<?php

/**
 * The old name of a moved class keeps resolving to the moved class.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppInfo;

use OCA\OpenRegister\AppInfo\ClassAliasMap;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCA\OpenRegister\Tests\Unit\AppInfo\Fixtures\MovedClassTarget;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\AppInfo\ClassAliasMap
 */
class ClassAliasMapTest extends TestCase {

	private const OLD_EAGER = 'OCA\\OpenRegister\\Tests\\Unit\\AppInfo\\OldEagerName';

	private const OLD_LAZY = 'OCA\\OpenRegister\\Tests\\Unit\\AppInfo\\OldLazyName';

	/**
	 * Every entry of the shipped map names a class that exists, and no old name
	 * has a file left at its old path.
	 *
	 * @return void
	 */
	public function testTheShippedMapNamesExistingClassesAndNoShims(): void {
		$map = ClassAliasMap::fromFile();

		$missing = [];
		$shims   = [];
		foreach ($map->entries() as $old => $entry) {
			if (class_exists($entry['class']) === false && interface_exists($entry['class']) === false) {
				$missing[] = $entry['class'];
			}

			$path = __DIR__.'/../../../lib/'.str_replace('\\', '/', substr($old, strlen('OCA\\OpenRegister\\'))).'.php';
			if (is_file($path) === true) {
				$shims[] = $old;
			}
		}

		$this->assertSame(expected: [], actual: $missing, message: 'a map entry names a class that does not exist');
		$this->assertSame(expected: [], actual: $shims, message: 'an old name still has a file at its old path');
	}//end testTheShippedMapNamesExistingClassesAndNoShims()

	/**
	 * An eager entry is an alias as soon as the map is applied, so instanceof
	 * on the old name holds without any autoload.
	 *
	 * @return void
	 */
	public function testAnEagerEntryIsAliasedAtOnce(): void {
		$map = new ClassAliasMap(entries: [self::OLD_EAGER => ['class' => MovedClassTarget::class, 'eager' => true]]);

		$map->aliasClasses();

		$this->assertTrue(class_exists(self::OLD_EAGER, false));
		$this->assertInstanceOf(self::OLD_EAGER, new MovedClassTarget());
	}//end testAnEagerEntryIsAliasedAtOnce()

	/**
	 * A lazy entry becomes an alias the first time something autoloads the old name.
	 *
	 * @return void
	 */
	public function testALazyEntryIsAliasedOnFirstAutoload(): void {
		$map = new ClassAliasMap(entries: [self::OLD_LAZY => ['class' => MovedClassTarget::class, 'eager' => false]]);

		$map->aliasClasses();

		$this->assertFalse(class_exists(self::OLD_LAZY, false));
		$this->assertTrue(class_exists(self::OLD_LAZY));
		$this->assertSame(expected: MovedClassTarget::class, actual: get_class(new (self::OLD_LAZY)()));
	}//end testALazyEntryIsAliasedOnFirstAutoload()

	/**
	 * Applying a map twice, or naming an old name that already exists, is harmless.
	 *
	 * @return void
	 */
	public function testApplyingTwiceLeavesAnExistingNameAlone(): void {
		$map = new ClassAliasMap(entries: [MovedClassTarget::class => ['class' => self::class, 'eager' => true]]);

		$map->aliasClasses();
		$map->aliasClasses();

		$this->assertSame(expected: MovedClassTarget::class, actual: get_class(new MovedClassTarget()));
	}//end testApplyingTwiceLeavesAnExistingNameAlone()

	/**
	 * The old name is a container alias of the new one, so both return the
	 * same shared instance, and the map itself is a service.
	 *
	 * @return void
	 */
	public function testTheOldNameIsAContainerAliasOfTheNewOne(): void {
		$map     = new ClassAliasMap(entries: [self::OLD_EAGER => ['class' => MovedClassTarget::class, 'eager' => true]]);
		$context = new RecordingRegistrationContext();

		$map->apply(context: $context);

		$this->assertSame(
			expected: [['alias' => self::OLD_EAGER, 'target' => MovedClassTarget::class]],
			actual: $context->aliases
		);
		$this->assertSame(expected: [ClassAliasMap::class], actual: $context->services);
		$this->assertSame(expected: $map, actual: ($context->factories[ClassAliasMap::class])());
	}//end testTheOldNameIsAContainerAliasOfTheNewOne()

	/**
	 * An entry with service false is aliased as a class but not in the container.
	 *
	 * @return void
	 */
	public function testANonServiceEntryIsNoContainerAlias(): void {
		$map     = new ClassAliasMap(entries: [self::OLD_LAZY => ['class' => MovedClassTarget::class, 'eager' => false, 'service' => false]]);
		$context = new RecordingRegistrationContext();

		$map->registerServices(context: $context);

		$this->assertSame(expected: [], actual: $context->aliases);
	}//end testANonServiceEntryIsNoContainerAlias()

	/**
	 * A missing file is an empty map; a malformed one fails loudly.
	 *
	 * @return void
	 */
	public function testAMissingFileIsEmptyAndAMalformedOneThrows(): void {
		$this->assertSame(expected: [], actual: ClassAliasMap::fromFile(path: __DIR__.'/no-such-map.json')->entries());

		$path = tempnam(sys_get_temp_dir(), 'or-alias-');
		file_put_contents($path, '{"aliases": {"A\\\\B": {"eager": true}}}');
		try {
			$this->expectException(UnexpectedValueException::class);
			ClassAliasMap::fromFile(path: $path);
		} finally {
			unlink($path);
		}
	}//end testAMissingFileIsEmptyAndAMalformedOneThrows()

	/**
	 * A file without an aliases object fails loudly.
	 *
	 * @return void
	 */
	public function testAFileWithoutAliasesThrows(): void {
		$path = tempnam(sys_get_temp_dir(), 'or-alias-');
		file_put_contents($path, '{"description": "x"}');
		try {
			$this->expectException(UnexpectedValueException::class);
			ClassAliasMap::fromFile(path: $path);
		} finally {
			unlink($path);
		}
	}//end testAFileWithoutAliasesThrows()

	/**
	 * An entry reads its eager and service flags, and defaults to a lazy service.
	 *
	 * @return void
	 */
	public function testFromFileReadsTheFlags(): void {
		$path = tempnam(sys_get_temp_dir(), 'or-alias-');
		file_put_contents($path, '{"aliases": {"A\\\\Old": {"class": "A\\\\New", "eager": true, "service": false}, "A\\\\Lazy": {"class": "A\\\\New"}}}');
		try {
			$entries = ClassAliasMap::fromFile(path: $path)->entries();
		} finally {
			unlink($path);
		}

		$this->assertSame(
			expected: [
				'A\\Old'  => ['class' => 'A\\New', 'eager' => true, 'service' => false],
				'A\\Lazy' => ['class' => 'A\\New', 'eager' => false, 'service' => true],
			],
			actual: $entries
		);
	}//end testFromFileReadsTheFlags()
}//end class
