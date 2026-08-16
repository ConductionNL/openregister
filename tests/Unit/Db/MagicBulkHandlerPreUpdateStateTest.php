<?php

/**
 * The bulk upsert's existence probe reads only what the caller will use.
 *
 * Before each chunk the handler asks which uuids already exist, so rows can be
 * classified created-vs-updated. It fetched every COLUMN of every existing row,
 * because an audit entry or an update event needs the old-vs-new changeset.
 *
 * A caller with both switched off — which every synchronisation is — reads
 * nothing but the presence of the uuid. `SELECT *` then drags the whole row
 * across for nothing, and it changes the query PLAN: measured on this instance,
 * Seq Scan at 1.574 ms per 100 rows versus an Index Only Scan at 0.537 ms, on
 * 2.3 KB rows. Real tables here run to 4.2 KB a row.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Db;

use OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MagicBulkHandlerPreUpdateStateTest extends TestCase {

	/**
	 * Every layer between the caller and the query must carry the flag, or the
	 * decision is made where the answer is not known. A default of TRUE is the
	 * load-bearing half: any caller that does not opt in keeps the wide read and
	 * therefore keeps its changeset.
	 *
	 * @param string $class The class holding the method.
	 * @param string $method The method that must carry the flag.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('plumbedMethods')]
	public function testTheFlagIsCarriedAndDefaultsToTheSafeValue(string $class, string $method): void {
		$parameters = (new ReflectionMethod($class, $method))->getParameters();

		$found = null;
		foreach ($parameters as $parameter) {
			if ($parameter->getName() === 'needsPreUpdateState') {
				$found = $parameter;
				break;
			}
		}

		$this->assertNotNull($found, "$class::$method does not carry needsPreUpdateState");
		$this->assertTrue(
			$found->isDefaultValueAvailable() && $found->getDefaultValue() === true,
			"$class::$method must default to TRUE — a caller that says nothing keeps its changeset."
		);

		// APPENDED, not inserted. A named argument needs the parameter to exist;
		// a positional caller needs every earlier slot to stay where it was.
		$this->assertSame(
			(count($parameters) - 1),
			$found->getPosition(),
			"$class::$method must append the flag last, or every positional caller shifts."
		);
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function plumbedMethods(): array {
		return [
			'handler bulkUpsert' => [MagicBulkHandler::class, 'bulkUpsert'],
			'handler executeUpsertChunk' => [MagicBulkHandler::class, 'executeUpsertChunk'],
			'mapper bulkUpsert' => [\OCA\OpenRegister\Db\MagicMapper::class, 'bulkUpsert'],
			'mapper ultraFastBulkSave' => [\OCA\OpenRegister\Db\MagicMapper::class, 'ultraFastBulkSave'],
			'mapper ultraFastBulkSaveSingleSchema' => [\OCA\OpenRegister\Db\MagicMapper::class, 'ultraFastBulkSaveSingleSchema'],
			'saveObjects persistChunk' => [\OCA\OpenRegister\Service\Object\SaveObjects::class, 'persistChunk'],
		];
	}

	/**
	 * The narrow read must select the uuid COLUMN, not everything. Asserted on
	 * the source because the query is built inline and running it would need a
	 * live magic table — and the thing worth pinning is which columns are asked
	 * for, which is a property of the statement, not of the database.
	 */
	public function testTheNarrowPathSelectsOnlyTheUuid(): void {
		$source = file_get_contents(
			(new \ReflectionClass(MagicBulkHandler::class))->getFileName()
		);

		$this->assertStringContainsString(
			'if ($needsPreUpdateState === false) {',
			$source,
			'The narrow branch is gone; every caller is back to SELECT *.'
		);
		// `$existsColumns`, NOT `$columns`: that name already holds the table's
		// column list for the INSERT below, and shadowing it with a string made
		// `implode()` a TypeError — a 500 on every bulk save, from a variable
		// name. This assertion is what keeps the rename from being undone.
		$this->assertStringContainsString('$existsColumns = \'*\';', $source);
		$this->assertStringNotContainsString('$columns = \'*\';', $source);
	}

	/**
	 * ...and the wide path must still exist. Losing it would silently empty every
	 * audit changeset on this instance — a far worse outcome than the read it
	 * saves, and one nothing would report.
	 */
	public function testTheWidePathIsStillReachable(): void {
		$source = file_get_contents(
			(new \ReflectionClass(MagicBulkHandler::class))->getFileName()
		);

		$this->assertStringContainsString(
			'if ($needsPreUpdateState === true) {',
			$source,
			'Pre-update rows are no longer collected even when a caller asked for them.'
		);
	}
}
