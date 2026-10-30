<?php

/**
 * Tests for ShippedSeedContent: what a re-import writes to a stored seed whose version did not move.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Service\Configuration\ShippedSeedContent;
use PHPUnit\Framework\TestCase;

/**
 * The content hash and the update a stored seed receives.
 */
class ShippedSeedContentTest extends TestCase {

	/**
	 * A status type as dossiq ships it now.
	 *
	 * @return array<string, mixed>
	 */
	private static function shipped(): array {
		return [
			'@self' => ['register' => 23, 'schema' => 194, 'slug' => 'omgevingsvergunning-ontvangen'],
			'phase' => 'Ontvangst',
			'name'  => 'Ontvangen',
			'order' => 1,
		];
	}//end shipped()

	/**
	 * The hash ignores `@self` and the order of keys.
	 *
	 * @return void
	 */
	public function testTheHashIsTheContentOnly(): void {
		$content  = new ShippedSeedContent();
		$reversed = ['order' => 1, 'name' => 'Ontvangen', 'phase' => 'Ontvangst', '@self' => ['slug' => 'other']];

		$this->assertSame($content->hash(shipped: self::shipped()), $content->hash(shipped: $reversed));
		$this->assertNotSame($content->hash(shipped: self::shipped()), $content->hash(shipped: ['phase' => 'Besluit'] + self::shipped()));
	}//end testTheHashIsTheContentOnly()

	/**
	 * Without a remembered hash the stored seed gains only what it lacks, and keeps every value it holds.
	 *
	 * @return void
	 */
	public function testWithoutAHashOnlyMissingFieldsAreFilled(): void {
		$stored = ['@self' => ['id' => 'u-1'], 'id' => 'u-1', 'phase' => null, 'name' => 'Binnengekomen', 'order' => 1];

		$update = (new ShippedSeedContent())->update(stored: $stored, shipped: self::shipped(), remembered: null);

		$this->assertSame('Ontvangst', $update['phase']);
		$this->assertSame('Binnengekomen', $update['name']);
		$this->assertSame(self::shipped()['@self'], $update['@self']);
		$this->assertNull((new ShippedSeedContent())->update(stored: $update, shipped: self::shipped(), remembered: null));
	}//end testWithoutAHashOnlyMissingFieldsAreFilled()

	/**
	 * With a remembered hash, changed content is written whole and unchanged content leaves the stored seed alone.
	 *
	 * @return void
	 */
	public function testARememberedHashDecides(): void {
		$content = new ShippedSeedContent();
		$stored  = ['@self' => ['id' => 'u-1'], 'name' => 'Binnengekomen'];

		$this->assertSame(self::shipped(), $content->update(stored: $stored, shipped: self::shipped(), remembered: 'older'));
		$this->assertNull($content->update(stored: ['phase' => 'Ontvangst', 'order' => 1] + $stored, shipped: self::shipped(), remembered: $content->hash(shipped: self::shipped())));
	}//end testARememberedHashDecides()

	/**
	 * Content that did not change still fills a field the stored seed lacks: an earlier write may have failed after
	 * the hash was remembered, and nothing the stored seed holds is touched.
	 *
	 * @return void
	 */
	public function testUnchangedContentStillFillsAMissingField(): void {
		$content = new ShippedSeedContent();
		$stored  = ['@self' => ['id' => 'u-1'], 'phase' => null, 'name' => 'Binnengekomen', 'order' => 1];

		$update = $content->update(stored: $stored, shipped: self::shipped(), remembered: $content->hash(shipped: self::shipped()));

		$this->assertSame(['Ontvangst', 'Binnengekomen'], [$update['phase'], $update['name']]);
	}//end testUnchangedContentStillFillsAMissingField()
}//end class
