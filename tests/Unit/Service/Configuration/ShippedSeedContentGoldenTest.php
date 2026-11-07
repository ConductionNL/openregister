<?php

/**
 * Golden: what a re-import writes over a stored seed, and the hash it keeps,
 * pinned on exact arrays and hashes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
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

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Service\Configuration\ShippedSeedContent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Configuration\ShippedSeedContent
 */
class ShippedSeedContentGoldenTest extends TestCase {
	/**
	 * The hash ignores `@self` and key order, but not list order.
	 *
	 * @return void
	 */
	public function testHash(): void {
		$content = new ShippedSeedContent();
		$hash    = $content->hash(['b' => ['y' => 2, 'x' => 1], 'a' => [3, 1], 'c' => 'é/ü', '@self' => ['id' => 'x']]);
		$this->assertSame(sha1('{"a":[3,1],"b":{"x":1,"y":2},"c":"é/ü"}'), $hash);
		$this->assertSame($hash, $content->hash(['a' => [3, 1], 'c' => 'é/ü', 'b' => ['x' => 1, 'y' => 2]]));
		$this->assertNotSame($hash, $content->hash(['a' => [1, 3], 'c' => 'é/ü', 'b' => ['x' => 1, 'y' => 2]]));
	}//end testHash()

	/**
	 * Cases: stored, shipped, remembered hash (or a marker), and what is written.
	 *
	 * @return array<string, array{0: array, 1: array, 2: string|null, 3: array|null}>
	 */
	public static function cases(): array {
		$shipped = ['@self' => ['id' => 'seed', 'version' => '2'], 'name' => 'New', 'note' => 'n', 'gone' => null, 7 => 'seven'];
		return [
			'content moved: shipped wins'   => [['@self' => ['id' => 'stored'], 'name' => 'Edited'], $shipped, 'not-the-hash', $shipped],
			'same content, nothing missing' => [['@self' => ['id' => 'stored'], 'name' => 'Edited', 'note' => 'mine', 7 => 's', 'extra' => 1], $shipped, 'HASH', null],
			'no hash, nothing missing'      => [['name' => 'Edited', 'note' => 'mine', 7 => 's'], $shipped, null, null],
			'no hash, gaps filled'          => [
				['@self' => ['id' => 'stored'], 'note' => null, 'name' => 'Edited', 'extra' => 1],
				$shipped,
				null,
				['note' => 'n', 'name' => 'Edited', 'extra' => 1, 7 => 'seven', '@self' => ['id' => 'seed', 'version' => '2']],
			],
			'same hash, gaps filled'        => [
				['name' => 'Edited', 'note' => 'mine'],
				$shipped,
				'HASH',
				['name' => 'Edited', 'note' => 'mine', 7 => 'seven', '@self' => ['id' => 'seed', 'version' => '2']],
			],
			'null shipped is no gap'        => [['name' => 'x', 'note' => 'y', 7 => 'z'], $shipped, null, null],
		];
	}//end cases()

	/**
	 * A stored seed gets exactly what is written over it.
	 *
	 * @param array       $stored     The stored object.
	 * @param array       $shipped    The shipped seed.
	 * @param string|null $remembered The remembered hash; `HASH` stands for the shipped content's own.
	 * @param array|null  $expected   What is written, or null.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testUpdate(array $stored, array $shipped, ?string $remembered, ?array $expected): void {
		$content = new ShippedSeedContent();
		if ($remembered === 'HASH') {
			$remembered = $content->hash($shipped);
		}

		$this->assertSame($expected, $content->update($stored, $shipped, $remembered));
	}//end testUpdate()
}//end class
