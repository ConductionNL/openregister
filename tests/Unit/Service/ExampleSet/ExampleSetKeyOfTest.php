<?php

/**
 * Unit test for the ExampleSetKeyOf trait: the key of a fragment entry, or ''
 * when the entry is no map or its key is no scalar.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetKeyOf;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetKeyOf
 */
class ExampleSetKeyOfTest extends TestCase {

	/**
	 * The key of an entry, read through a host of the trait.
	 *
	 * @param mixed $entry The entry.
	 *
	 * @return string
	 */
	private function keyOf(mixed $entry): string {
		$host = new class {
			use ExampleSetKeyOf;

			/**
			 * Expose the trait's key reader.
			 *
			 * @param mixed $entry The entry.
			 *
			 * @return string
			 */
			public function read(mixed $entry): string {
				return $this->keyOf(entry: $entry);
			}
		};
		return $host->read(entry: $entry);
	}//end keyOf()

	/**
	 * A scalar key is read as text.
	 *
	 * @return void
	 */
	public function testAScalarKeyIsReadAsText(): void {
		$this->assertSame('case-1', $this->keyOf(entry: ['key' => 'case-1']));
		$this->assertSame('7', $this->keyOf(entry: ['key' => 7]));
	}//end testAScalarKeyIsReadAsText()

	/**
	 * An entry that is no map, has no key or has a structured key has the empty key.
	 *
	 * @return void
	 */
	public function testAnEntryWithoutAScalarKeyHasTheEmptyKey(): void {
		$this->assertSame('', $this->keyOf(entry: 'case-1'));
		$this->assertSame('', $this->keyOf(entry: ['name' => 'x']));
		$this->assertSame('', $this->keyOf(entry: ['key' => ['a']]));
	}//end testAnEntryWithoutAScalarKeyHasTheEmptyKey()
}//end class
