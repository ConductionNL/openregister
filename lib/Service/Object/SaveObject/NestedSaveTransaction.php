<?php

/**
 * OpenRegister NestedSaveTransaction
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCP\IDBConnection;
use Throwable;

/**
 * Runs a save that writes nested objects as one database transaction.
 *
 * The outermost nested save opens the transaction; every save made while it is
 * open runs in a savepoint of its own, so a nested failure the cascade swallows
 * undoes only that nested object, and a failure that reaches the outermost
 * save undoes the parent, every nested object and every orphan cleanup.
 *
 * Table creation and column syncs are DDL, which commits an open transaction
 * on MySQL and MariaDB. The caller heals the tables a nested save writes to
 * before the transaction opens, through the `$heal` callable.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class NestedSaveTransaction {

	/**
	 * How many saves of this transaction are running, outermost included.
	 *
	 * @var integer
	 */
	private int $depth = 0;

	/**
	 * Build the runner.
	 *
	 * @param IDBConnection|null $db The connection, or null when none is wired (the save then runs as before).
	 */
	public function __construct(private readonly ?IDBConnection $db) {
	}//end __construct()

	/**
	 * Whether a nested save transaction is open.
	 *
	 * @return bool True while a save inside the transaction runs.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function isOpen(): bool {
		return $this->depth > 0;
	}//end isOpen()

	/**
	 * Run a save, transactionally when it opens or joins a nested save.
	 *
	 * @param bool     $opens Whether this save writes nested objects and may open the transaction.
	 * @param callable $heal  Heals the tables the save writes to; runs only before a new transaction opens.
	 * @param callable $save  The save; its return value is returned.
	 *
	 * @return mixed What the save returns.
	 *
	 * @throws Throwable Whatever the save throws, after its writes are rolled back.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The caller decides whether this save opens a transaction
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function run(bool $opens, callable $heal, callable $save): mixed {
		if ($this->db === null || ($this->isOpen() === false && $opens === false)) {
			return $save();
		}

		if ($this->isOpen() === false && $this->db->inTransaction() === false) {
			$heal();
		}

		$this->db->beginTransaction();
		$this->depth++;
		try {
			$result = $save();
			$this->db->commit();
			return $result;
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		} finally {
			$this->depth--;
		}
	}//end run()
}//end class
