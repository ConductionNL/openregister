<?php

/**
 * Tests for WriteOnlyPathWalker.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\WriteOnlyValue;
use OCA\OpenRegister\Service\Rbac\WriteOnlyPathWalker;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\WriteOnlyPathWalker
 */
final class WriteOnlyPathWalkerTest extends TestCase {

	/**
	 * A strip removes the body leaf and every mirror key at or under the path, and nothing else.
	 *
	 * @return void
	 */
	public function testStripRemovesTheBodyLeafAndTheMirrorSubtree(): void {
		$walker = new WriteOnlyPathWalker(logger: new NullLogger());
		$object = [
			'a' => ['b' => ['c' => 1, 'd' => 2]],
			'a.b' => 'mirror',
			'a.b.c' => 'mirror2',
			'a.bc' => 'kept',
		];

		$this->assertSame(['a' => [], 'a.bc' => 'kept'], $walker->strip(path: 'a.b', object: $object));
		$this->assertSame(['a' => ['b' => ['d' => 2]], 'a.b' => 'mirror', 'a.bc' => 'kept'], $walker->strip(path: 'a.b.c', object: $object));
	}

	/**
	 * A path that stops resolving, or runs into a scalar, leaves the body untouched and creates nothing.
	 *
	 * @return void
	 */
	public function testStripNeverCreatesKeys(): void {
		$walker = new WriteOnlyPathWalker(logger: new NullLogger());

		$this->assertSame(['x' => 1], $walker->strip(path: 'a.b', object: ['x' => 1]));
		$this->assertSame(['a' => 'flat'], $walker->strip(path: 'a.b', object: ['a' => 'flat']));
	}

	/**
	 * Exists reports an explicit null as present, and read returns it.
	 *
	 * @return void
	 */
	public function testAnExplicitNullExists(): void {
		$walker = new WriteOnlyPathWalker(logger: new NullLogger());

		$this->assertTrue($walker->exists(path: 'a.b', object: ['a' => ['b' => null]]));
		$this->assertNull($walker->read(path: 'a.b', object: ['a' => ['b' => null]]));
		$this->assertFalse($walker->exists(path: 'a.b', object: ['a' => null]));
		$this->assertFalse($walker->exists(path: 'a.b.c', object: ['a' => ['b' => 'flat']]));
	}

	/**
	 * Restore creates an absent or null parent, keeps siblings, and bails on a scalar parent.
	 *
	 * @return void
	 */
	public function testRestoreWritesOnlyTheLeaf(): void {
		$walker = new WriteOnlyPathWalker(logger: new NullLogger());

		$this->assertSame(['a' => ['e' => 1, 'b' => ['c' => 's']]], $walker->restore(prepared: ['a' => ['e' => 1]], stored: new WriteOnlyValue(path: 'a.b.c', value: 's')));
		$this->assertSame(['a' => ['b' => ['c' => 's']]], $walker->restore(prepared: ['a' => null], stored: new WriteOnlyValue(path: 'a.b.c', value: 's')));
		$this->assertSame(['a' => 'flat'], $walker->restore(prepared: ['a' => 'flat'], stored: new WriteOnlyValue(path: 'a.b.c', value: 's')));
		$this->assertSame(['a' => ['b' => 'flat']], $walker->restore(prepared: ['a' => ['b' => 'flat']], stored: new WriteOnlyValue(path: 'a.b.c', value: 's')));
		$this->assertSame(['top' => 's'], $walker->restore(prepared: [], stored: new WriteOnlyValue(path: 'top', value: 's')));
	}
}
