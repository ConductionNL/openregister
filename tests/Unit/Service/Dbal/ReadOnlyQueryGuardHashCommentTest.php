<?php

/**
 * Unit tests for ReadOnlyQueryGuard's platform-specific comments: `#` starts a
 * comment on MySQL and MariaDB only.
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
 * ReadOnlyQueryGuard::assert() with a `#` in the query.
 */
#[CoversClass(ReadOnlyQueryGuard::class)]
class ReadOnlyQueryGuardHashCommentTest extends TestCase {

	/**
	 * The MySQL-family platform names.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function mysqlFamily(): array {
		return [
			'mysql'   => ['mysql'],
			'mariadb' => ['mariadb'],
			'MySQL80' => ['MySQL80'],
		];
	}//end mysqlFamily()

	/**
	 * On MySQL and MariaDB a keyword after `#` is a comment and the read passes.
	 *
	 * @param string $platform The platform name.
	 *
	 * @return void
	 */
	#[DataProvider('mysqlFamily')]
	public function testAKeywordInAHashCommentIsIgnoredOnTheMysqlFamily(string $platform): void {
		(new ReadOnlyQueryGuard())->assert("SELECT id FROM t # DELETE everything\nWHERE active = 1", $platform);

		$this->addToAssertionCount(1);
	}//end testAKeywordInAHashCommentIsIgnoredOnTheMysqlFamily()

	/**
	 * Elsewhere `#` is no comment, so the keyword after it is still seen.
	 *
	 * @return void
	 */
	public function testAHashIsNotACommentOnPostgres(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A query-backed schema may not use "DELETE".');

		(new ReadOnlyQueryGuard())->assert('SELECT id FROM t # DELETE everything', 'postgresql');
	}//end testAHashIsNotACommentOnPostgres()

	/**
	 * A hash comment ends at the line break: what follows is checked again.
	 *
	 * @return void
	 */
	public function testAHashCommentEndsAtTheLineBreak(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A query-backed schema may not use "UPDATE".');

		(new ReadOnlyQueryGuard())->assert("SELECT id FROM t # a note\nUNION SELECT 1 FROM t FOR UPDATE", 'mysql');
	}//end testAHashCommentEndsAtTheLineBreak()
}//end class
