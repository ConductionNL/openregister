<?php

/**
 * The database failure found in an exception chain.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Exception
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/unit-test-coverage/specs/unit-test-coverage/spec.md#requirement-test-all-exception-and-format-classes
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Exception;

use Doctrine\DBAL\Exception as DbalException;
use OCA\OpenRegister\Exception\DatabaseErrorChain;
use OCP\DB\Exception as DBException;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DatabaseErrorChain::class)]
final class DatabaseErrorChainTest extends TestCase {

	/**
	 * Each kind of database failure, as the outermost exception.
	 *
	 * @return array<string, array{\Exception}>
	 */
	public static function databaseFailures(): array {
		return [
			'Nextcloud' => [new DBException('nextcloud')],
			'Doctrine' => [new DbalException('doctrine')],
			'PDO' => [new PDOException('pdo')],
		];
	}

	#[DataProvider('databaseFailures')]
	public function testADatabaseFailureIsItsOwnCause(\Exception $failure): void {
		self::assertSame($failure, DatabaseErrorChain::causeOf($failure));
	}

	#[DataProvider('databaseFailures')]
	public function testTheFirstDatabaseFailureUnderWrappersIsFound(\Exception $failure): void {
		$wrapped = new RuntimeException('service', 0, new \LogicException('handler', 0, $failure));

		self::assertSame($failure, DatabaseErrorChain::causeOf($wrapped));
	}

	public function testTheOutermostDatabaseFailureWinsOverADeeperOne(): void {
		$deep  = new PDOException('driver');
		$outer = new DBException('query', 0, $deep);

		self::assertSame($outer, DatabaseErrorChain::causeOf(new RuntimeException('service', 0, $outer)));
	}

	public function testAChainWithoutADatabaseFailureHasNoCause(): void {
		self::assertNull(DatabaseErrorChain::causeOf(new RuntimeException('a', 0, new \LogicException('b'))));
		self::assertNull(DatabaseErrorChain::causeOf(new \Error('engine')));
	}
}
