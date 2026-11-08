<?php

/**
 * ObjectSlugClash unit tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-objectslugsuffix-must-append-a-utc-date-time-suffix-to-a-colliding-slug
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugClash;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugScope;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugWant;
use PHPUnit\Framework\TestCase;

/**
 * A clash holds who wanted, who holds and the suffixed slug to try.
 */
final class ObjectSlugClashTest extends TestCase {

	public function testItKeepsWhatItWasGiven(): void {
		$want = new ObjectSlugWant('a', new ObjectSlugScope(null, new Schema()), 'u-1', 'bulk');

		$clash = new ObjectSlugClash($want, 'u-2', null);

		$this->assertSame($want, $clash->want);
		$this->assertSame('u-2', $clash->holderUuid);
		$this->assertNull($clash->suffixed);
		$this->assertSame('a-1', (new ObjectSlugClash($want, 'u-2', 'a-1'))->suffixed);
	}
}//end class
