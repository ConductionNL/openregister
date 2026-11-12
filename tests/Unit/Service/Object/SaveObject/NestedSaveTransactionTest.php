<?php

/**
 * How a save that writes nested objects runs as one transaction.
 *
 * Pins that a failure reaching the outermost save rolls back the parent and
 * every nested row written before it, that a nested failure the cascade
 * swallows undoes only that nested row, that tables are healed before the
 * transaction opens and never inside it, and that a save without nested
 * objects opens no transaction.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\SaveObject\NestedSaveTransaction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NestedSaveTransactionTest extends TestCase {

	/**
	 * The rows the fake connection holds.
	 *
	 * @var list<string>
	 */
	private array $rows = [];

	/**
	 * Row snapshots, one per open transaction or savepoint.
	 *
	 * @var list<list<string>>
	 */
	private array $snapshots = [];

	/**
	 * What happened, in order.
	 *
	 * @var list<string>
	 */
	private array $log = [];

	/**
	 * A connection whose transactions and savepoints restore the row list on rollback.
	 *
	 * @return IDBConnection
	 */
	private function connection(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturnCallback(fn (): bool => $this->snapshots !== []);
		$db->method('beginTransaction')->willReturnCallback(function (): void {
			$this->log[] = 'begin';
			$this->snapshots[] = $this->rows;
		});
		$db->method('commit')->willReturnCallback(function (): void {
			$this->log[] = 'commit';
			array_pop($this->snapshots);
		});
		$db->method('rollBack')->willReturnCallback(function (): void {
			$this->log[] = 'rollback';
			$this->rows = array_pop($this->snapshots);
		});

		return $db;
	}//end connection()

	public function testARefusedNestedObjectUndoesTheSiblingWrittenBeforeIt(): void {
		$transaction = new NestedSaveTransaction(db: $this->connection());
		$noHeal = static function (): void {
		};

		try {
			$transaction->run(opens: true, heal: $noHeal, save: function () use ($transaction, $noHeal): string {
				$transaction->run(opens: false, heal: $noHeal, save: function (): string {
					$this->rows[] = 'note';
					return 'note';
				});
				$transaction->run(opens: false, heal: $noHeal, save: static function (): string {
					throw new NotAuthorizedException('refused');
				});
				$this->rows[] = 'parent';
				return 'parent';
			});
			$this->fail('The refusal must reach the caller.');
		} catch (NotAuthorizedException $e) {
			$this->assertSame('refused', $e->getMessage());
		}

		$this->assertSame([], $this->rows);
		$this->assertFalse($transaction->isOpen());
	}//end testARefusedNestedObjectUndoesTheSiblingWrittenBeforeIt()

	public function testASwallowedNestedFailureUndoesOnlyThatNestedObject(): void {
		$transaction = new NestedSaveTransaction(db: $this->connection());
		$noHeal = static function (): void {
		};

		$saved = $transaction->run(opens: true, heal: $noHeal, save: function () use ($transaction, $noHeal): string {
			$transaction->run(opens: false, heal: $noHeal, save: function (): string {
				$this->rows[] = 'note';
				return 'note';
			});
			try {
				$transaction->run(opens: false, heal: $noHeal, save: function (): string {
					$this->rows[] = 'half-written';
					throw new RuntimeException('storage');
				});
			} catch (RuntimeException $e) {
				// The cascade logs a failing nested object and continues.
			}

			$this->rows[] = 'parent';
			return 'parent';
		});

		$this->assertSame('parent', $saved);
		$this->assertSame(['note', 'parent'], $this->rows);
		$this->assertSame([], $this->snapshots);
	}//end testASwallowedNestedFailureUndoesOnlyThatNestedObject()

	public function testTablesAreHealedBeforeTheTransactionOpensAndOnlyOnce(): void {
		$transaction = new NestedSaveTransaction(db: $this->connection());
		$heal = function (): void {
			$this->log[] = 'heal';
		};

		$transaction->run(opens: true, heal: $heal, save: function () use ($transaction, $heal): string {
			return $transaction->run(opens: true, heal: $heal, save: static fn (): string => 'child');
		});

		$this->assertSame(['heal', 'begin', 'begin', 'commit', 'commit'], $this->log);
	}//end testTablesAreHealedBeforeTheTransactionOpensAndOnlyOnce()

	public function testASaveInsideACallersTransactionDoesNotHeal(): void {
		$db = $this->connection();
		$db->beginTransaction();
		$this->log = [];
		$transaction = new NestedSaveTransaction(db: $db);

		$transaction->run(opens: true, heal: function (): void {
			$this->log[] = 'heal';
		}, save: static fn (): string => 'parent');

		$this->assertSame(['begin', 'commit'], $this->log);
	}//end testASaveInsideACallersTransactionDoesNotHeal()

	public function testASaveWithoutNestedObjectsOpensNoTransaction(): void {
		$transaction = new NestedSaveTransaction(db: $this->connection());

		$saved = $transaction->run(opens: false, heal: function (): void {
			$this->log[] = 'heal';
		}, save: static fn (): string => 'plain');

		$this->assertSame('plain', $saved);
		$this->assertSame([], $this->log);
	}//end testASaveWithoutNestedObjectsOpensNoTransaction()

	public function testWithoutAConnectionTheSaveRunsAsBefore(): void {
		$transaction = new NestedSaveTransaction(db: null);

		$this->assertSame('parent', $transaction->run(opens: true, heal: static function (): void {
		}, save: static fn (): string => 'parent'));
		$this->assertFalse($transaction->isOpen());
	}//end testWithoutAConnectionTheSaveRunsAsBefore()
}//end class
