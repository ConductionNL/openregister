<?php

/**
 * Unit tests for ReadOnlyQueryGuard: it accepts a single read and refuses
 * anything that could change data or bind a placeholder (design D-1).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Dbal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Dbal;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Dbal\ReadOnlyQueryGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ReadOnlyQueryGuard::assert().
 */
#[CoversClass(ReadOnlyQueryGuard::class)]
class ReadOnlyQueryGuardTest extends TestCase {

	/**
	 * The guard under test.
	 *
	 * @return ReadOnlyQueryGuard
	 */
	private function guard(): ReadOnlyQueryGuard {
		return new ReadOnlyQueryGuard();
	}//end guard()

	/**
	 * Queries the guard accepts.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function accepted(): array {
		return [
			'a join'                    => ['SELECT a.id, b.name FROM a JOIN b ON b.a_id = a.id WHERE a.active'],
			'a WITH'                    => ['WITH recent AS (SELECT id FROM orders WHERE created > now() - interval \'7 days\') SELECT * FROM recent'],
			'a forbidden keyword in a string literal' => ["SELECT 'UPDATE' AS action, id FROM audit"],
			'a trailing semicolon'      => ['SELECT 1 AS n;'],
			'a block comment between tokens' => ["SELECT id /* UPDATE */ FROM t"],
		];
	}//end accepted()

	/**
	 * A single read, a join, a CTE and a keyword hidden in a literal all pass.
	 *
	 * @param string $sql The query.
	 *
	 * @return void
	 */
	#[DataProvider('accepted')]
	public function testAReadOnlyQueryIsAccepted(string $sql): void {
		$this->guard()->assert(sql: $sql, platform: 'postgresql');

		$this->addToAssertionCount(1);
	}//end testAReadOnlyQueryIsAccepted()

	/**
	 * Queries the guard refuses.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function refused(): array {
		return [
			'two statements'          => ['SELECT 1; SELECT 2'],
			'an UPDATE inside a CTE'  => ['WITH moved AS (UPDATE t SET x = 1 RETURNING id) SELECT * FROM moved'],
			'SELECT ... INTO'         => ['SELECT id INTO backup FROM t'],
			'FOR UPDATE'              => ['SELECT id FROM t FOR UPDATE'],
			'a positional placeholder' => ['SELECT id FROM t WHERE id = ?'],
			'a named placeholder'     => ['SELECT id FROM t WHERE id = :id'],
			'a statement that is not a read' => ['DELETE FROM t'],
			'a bare non-select head'  => ['TRUNCATE t'],
		];
	}//end refused()

	/**
	 * Anything that could change data, run twice or bind a placeholder is refused.
	 *
	 * @param string $sql The query.
	 *
	 * @return void
	 */
	#[DataProvider('refused')]
	public function testADangerousQueryIsRefused(string $sql): void {
		$this->expectException(InvalidArgumentException::class);

		$this->guard()->assert(sql: $sql, platform: 'postgresql');
	}//end testADangerousQueryIsRefused()

	/**
	 * A PostgreSQL cast `::type` is not read as a named placeholder.
	 *
	 * @return void
	 */
	public function testAPostgresCastIsNotAPlaceholder(): void {
		$this->guard()->assert(sql: "SELECT id::text AS id FROM t", platform: 'postgresql');

		$this->addToAssertionCount(1);
	}//end testAPostgresCastIsNotAPlaceholder()
}//end class
