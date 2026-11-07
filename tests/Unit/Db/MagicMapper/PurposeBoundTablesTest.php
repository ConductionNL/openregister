<?php

/**
 * Doelbinding on a search that finds its own tables.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\PurposeBoundTables;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\Audit\PurposeRefusedException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\PurposeBoundTables
 */
final class PurposeBoundTablesTest extends TestCase {

	/**
	 * Tables of schema 1, 2 and 3; the guard refuses schema 2.
	 *
	 * @return array<int, array{register: Register, schema: Schema}>
	 */
	private static function pairs(): array {
		$pairs = [];
		foreach ([1, 2, 3] as $id) {
			$schema = new Schema();
			$schema->setId($id);
			$pairs[] = ['register' => new Register(), 'schema' => $schema];
		}

		return $pairs;
	}//end pairs()

	private function guard(): PurposeGuard {
		$guard = $this->createMock(PurposeGuard::class);
		$guard->method('allows')->willReturnCallback(static fn ($register, Schema $schema): bool => $schema->getId() !== 2);
		$guard->method('enforce')->willReturnCallback(
			static function ($register, Schema $schema): ?string {
				if ($schema->getId() === 2) {
					throw new PurposeRefusedException(rule: PurposeRefusedException::RULE_MISSING, reason: 'no purpose', purpose: null);
				}

				return null;
			}
		);

		return $guard;
	}//end guard()

	public function testReadableLeavesOutTheRefusedTablesInOrder(): void {
		$readable = (new PurposeBoundTables(guard: $this->guard()))->readable(pairs: self::pairs());

		$this->assertSame([1, 3], array_map(static fn (array $pair): int => $pair['schema']->getId(), $readable));
	}//end testReadableLeavesOutTheRefusedTablesInOrder()

	public function testOneRefusedTableRefusesThemAll(): void {
		$this->expectException(PurposeRefusedException::class);

		(new PurposeBoundTables(guard: $this->guard()))->refuseUnbound(pairs: self::pairs());
	}//end testOneRefusedTableRefusesThemAll()

	public function testTablesThatAllAllowPass(): void {
		$pairs = self::pairs();
		unset($pairs[1]);
		$guard = $this->guard();

		(new PurposeBoundTables(guard: $guard))->refuseUnbound(pairs: $pairs);
		$this->assertCount(2, (new PurposeBoundTables(guard: $guard))->readable(pairs: $pairs));
	}//end testTablesThatAllAllowPass()
}//end class
