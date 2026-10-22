<?php

/**
 * Unit tests for schema-aware date/date-time normalization in the bulk
 * upsert path of MagicBulkHandler.
 *
 * Regression coverage for the gap where `prepareObjectsForDynamicTable()`
 * normalized only the three fixed metadata columns (`_created`/`_updated`/
 * `_expires`) and passed every other schema property through unformatted —
 * so a `date-time` property given as an ISO-8601-with-offset string (the
 * convention every caller of `ObjectService::saveObjects()` uses, e.g.
 * `DateTime::ATOM`) reached MySQL's DATETIME column unconverted and failed
 * outright (SQLSTATE[22007]). The non-bulk path
 * (`MagicMapper::prepareObjectDataForTable()`) already normalized
 * schema-declared `date`/`date-time` properties; this pins the bulk path to
 * the same behaviour.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Db;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

class MagicBulkHandlerDateNormalizationTest extends TestCase {

	private IDBConnection&MockObject $db;

	private MagicBulkHandler $handler;

	private Register $register;

	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(LoggerInterface::class);

		// A REAL normaliser: the point of this test is the stored format.
		$this->handler = new MagicBulkHandler(
			$this->db,
			$logger,
			$this->createMock(IEventDispatcher::class),
			new DateTimeNormalizer($logger),
			$this->createMock(IConfig::class)
		);

		$this->register = new Register();
		$this->register->setId(11);
	}

	/**
	 * Run the private row preparation on one object, against a schema
	 * declaring the given properties.
	 */
	private function prepare(array $object, array $properties): array {
		$schema = new Schema();
		$schema->setId(22);
		$schema->setProperties($properties);

		$method = new ReflectionMethod(MagicBulkHandler::class, 'prepareObjectsForDynamicTable');
		$method->setAccessible(true);

		$prepared = $method->invoke($this->handler, [$object], $this->register, $schema);

		return $prepared[0];
	}

	public function testADateTimePropertyGivenAsAnIsoOffsetStringIsNormalizedToMysqlFormat(): void {
		// Exactly the shape every ObjectService::saveObjects() caller uses
		// (DateTime::ATOM) — the string that previously reached MySQL's
		// DATETIME column unconverted and failed with SQLSTATE[22007].
		$row = $this->prepare(
			['uuid' => 'u-1', 'sourceLastChecked' => '2026-10-13T14:50:03+00:00'],
			['sourceLastChecked' => ['type' => 'string', 'format' => 'date-time']]
		);

		$this->assertSame('2026-10-13 14:50:03', $row['source_last_checked']);
	}

	public function testADateTimePropertyIsConvertedToUtcBeforeStorage(): void {
		// A non-UTC offset must be APPLIED before storing: a date-time names
		// an instant, so 15:50 at +02:00 is 13:50 UTC.
		$row = $this->prepare(
			['uuid' => 'u-2', 'sourceLastChecked' => '2026-10-13T15:50:03+02:00'],
			['sourceLastChecked' => ['type' => 'string', 'format' => 'date-time']]
		);

		$this->assertSame('2026-10-13 13:50:03', $row['source_last_checked']);
	}

	public function testADatePropertyIsNotShiftedByTimezoneConversion(): void {
		// A `date` names a calendar DAY with no instant. Converting it through
		// a timezone is a category error that can move it to a different day
		// — exactly the regression a date-time fix must not introduce. The
		// offset is dropped, not applied: the wall-clock date survives as
		// given rather than shifting to 2026-10-21 (what a date-time's
		// timezone conversion would do to this same value).
		$row = $this->prepare(
			['uuid' => 'u-3', 'dueDate' => '2026-10-20T23:30:00-05:00'],
			['dueDate' => ['type' => 'string', 'format' => 'date']]
		);

		$this->assertSame('2026-10-20 23:30:00', $row['due_date']);
	}

	public function testADateTimeInterfaceValueIsAlsoNormalized(): void {
		$row = $this->prepare(
			['uuid' => 'u-4', 'sourceLastChecked' => new DateTimeImmutable('2026-10-13 14:50:03', new DateTimeZone('UTC'))],
			['sourceLastChecked' => ['type' => 'string', 'format' => 'date-time']]
		);

		$this->assertSame('2026-10-13 14:50:03', $row['source_last_checked']);
	}

	public function testAPlainStringPropertyWithNoDateFormatIsLeftUntouched(): void {
		// Only properties the SCHEMA declares as date/date-time are touched —
		// an ordinary string that happens to look date-like must not be
		// reformatted or rejected.
		$row = $this->prepare(
			['uuid' => 'u-5', 'reference' => '2026-10-13T14:50:03+00:00'],
			['reference' => ['type' => 'string']]
		);

		$this->assertSame('2026-10-13T14:50:03+00:00', $row['reference']);
	}

	public function testANullDatePropertyStaysNull(): void {
		$row = $this->prepare(
			['uuid' => 'u-6', 'sourceLastChecked' => null],
			['sourceLastChecked' => ['type' => 'string', 'format' => 'date-time']]
		);

		$this->assertArrayHasKey('source_last_checked', $row);
		$this->assertNull($row['source_last_checked']);
	}
}
