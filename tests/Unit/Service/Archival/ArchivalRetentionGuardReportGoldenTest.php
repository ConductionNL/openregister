<?php

/**
 * Golden test for the reports ArchivalRetentionGuard answers with.
 *
 * Pinned on the code before report() and its callers took a DeletionCandidate:
 * the whole report, key for key and in order, for every branch (allowed,
 * archival, legal hold, unresolved, no schema, cascade) and the warning an
 * unresolved schema logs. Allowed answers are null; refused ones a report.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Archival;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard
 */
final class ArchivalRetentionGuardReportGoldenTest extends TestCase {

	private const ERASE_ARCHIVAL = [
		'message' => 'The law requires us to keep this record, so we did not erase it.',
		'basis' => 'GDPR art. 17(3)(b) and the Archiefwet.',
		'action' => 'Name this record in your answer to the requester. A records officer decides when it may be destroyed.',
	];

	private const ERASE_HOLD = [
		'message' => 'This record is under a legal hold, so we did not erase it.',
		'basis' => 'A hold placed by a records officer, usually for a case or an investigation.',
		'action' => 'Name this record in your answer to the requester. It can be erased once the hold is released.',
	];

	private const ERASE_UNRESOLVED = [
		'message' => 'We could not read the retention rules for this record, so we left it alone.',
		'basis' => 'Precaution, because a retention rule we cannot read may be a legal one.',
		'action' => 'Ask an administrator to repair the schema, then run the request again.',
	];

	private const CASCADE_ARCHIVAL = [
		'message' => 'The law requires us to keep this record. Its parent is gone, this record stays.',
		'basis' => 'GDPR art. 17(3)(b) and the Archiefwet.',
		'action' => 'Point this record at a live parent, or record why the reference may dangle.',
	];

	private const CASCADE_UNRESOLVED = [
		'message' => 'We could not read the retention rules for this record, so we left it alone.',
		'basis' => 'Precaution, because a retention rule we cannot read may be a legal one.',
		'action' => 'Ask an administrator to repair the schema, then clean up this record by hand.',
	];

	/**
	 * The warnings the guard logged.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * A guard over `besluit` (archival), `contact` (ordinary) and schema 7 (archival, no slug).
	 *
	 * @return ArchivalRetentionGuard
	 */
	private function guard(): ArchivalRetentionGuard {
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('find')->willReturnCallback(
			static function ($id): Schema {
				$key = (string)$id;
				if (in_array($key, ['besluit', 'contact', '7'], true) === false) {
					throw new DoesNotExistException('no such schema');
				}

				$schema = new Schema();
				$schema->setId(7);
				if ($key !== '7') {
					$schema->setSlug($key);
				}

				$configuration = [];
				if ($key !== 'contact') {
					$configuration['x-openregister-archival'] = ['retention' => ['default' => 'P10Y']];
				}

				$schema->setConfiguration($configuration);

				return $schema;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context): void {
				unset($context['file'], $context['line']);
				$this->warnings[] = [$message, $context];
			}
		);

		return new ArchivalRetentionGuard($mapper, $logger);
	}//end guard()

	/**
	 * A record.
	 *
	 * @param string|null $schema   Schema identifier.
	 * @param string|null $register Register identifier.
	 * @param bool        $held     Whether it carries an active legal hold.
	 *
	 * @return ObjectEntity
	 */
	private static function record(?string $schema, ?string $register, bool $held): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('u-1');
		$object->setRegister($register);
		$object->setSchema($schema);
		if ($held === true) {
			$object->setRetention(['legalHold' => ['active' => true, 'reason' => 'case']]);
		}

		return $object;
	}//end record()

	/**
	 * Every erasure branch, report for report.
	 *
	 * @return void
	 */
	public function testErasureReports(): void {
		$guard = $this->guard();

		$this->assertNull($guard->erasureRefusal(self::record('contact', 'reg-1', false)));

		$this->assertSame(
			array_merge(['uuid' => 'u-1', 'schema' => 'besluit', 'ground' => 'ARCHIVAL_RETENTION_OBLIGATION', 'operation' => 'erasure'], self::ERASE_ARCHIVAL, ['register' => 'reg-1']),
			$guard->erasureRefusal(self::record('besluit', 'reg-1', false))
		);
		$this->assertSame(
			array_merge(['uuid' => 'u-1', 'schema' => '7', 'ground' => 'ARCHIVAL_RETENTION_OBLIGATION', 'operation' => 'erasure'], self::ERASE_ARCHIVAL),
			$guard->erasureRefusal(self::record('7', '', false))
		);
		$this->assertSame(
			array_merge(['uuid' => 'u-1', 'schema' => 'contact', 'ground' => 'ACTIVE_LEGAL_HOLD', 'operation' => 'erasure'], self::ERASE_HOLD, ['register' => 'reg-1']),
			$guard->erasureRefusal(self::record('contact', 'reg-1', true))
		);
		$this->assertSame(
			array_merge(['uuid' => 'u-1', 'schema' => 'unknown', 'ground' => 'ACTIVE_LEGAL_HOLD', 'operation' => 'erasure'], self::ERASE_HOLD),
			$guard->erasureRefusal(self::record(null, null, true))
		);
		$this->assertSame(
			array_merge(['uuid' => 'u-1', 'schema' => 'gone', 'ground' => 'SCHEMA_UNRESOLVED', 'operation' => 'erasure'], self::ERASE_UNRESOLVED, ['register' => 'reg-2']),
			$guard->erasureRefusal(self::record('gone', 'reg-2', false))
		);
		$this->assertSame(
			array_merge(['uuid' => 'u-1', 'schema' => 'unknown', 'ground' => 'SCHEMA_UNRESOLVED', 'operation' => 'erasure'], self::ERASE_UNRESOLVED),
			$guard->erasureRefusal(self::record(null, null, false))
		);

		$this->assertSame(
			[
				['[ArchivalRetentionGuard] Left a record alone because its schema could not be resolved', ['uuid' => 'u-1', 'schema' => 'gone', 'operation' => 'erasure']],
				['[ArchivalRetentionGuard] Left a record alone because its schema could not be resolved', ['uuid' => 'u-1', 'schema' => null, 'operation' => 'erasure']],
			],
			$this->warnings
		);
	}//end testErasureReports()

	/**
	 * Every cascade branch, report for report: no register is ever named.
	 *
	 * @return void
	 */
	public function testCascadeReports(): void {
		$guard = $this->guard();

		$this->assertNull($guard->cascadeRefusal('c-1', 'contact'));
		$this->assertSame(
			array_merge(['uuid' => 'c-1', 'schema' => 'besluit', 'ground' => 'ARCHIVAL_RETENTION_OBLIGATION', 'operation' => 'cascade'], self::CASCADE_ARCHIVAL),
			$guard->cascadeRefusal('c-1', 'besluit')
		);
		$this->assertSame(
			array_merge(['uuid' => 'c-1', 'schema' => '7', 'ground' => 'ARCHIVAL_RETENTION_OBLIGATION', 'operation' => 'cascade'], self::CASCADE_ARCHIVAL),
			$guard->cascadeRefusal('c-1', 7)
		);
		$this->assertSame(
			array_merge(['uuid' => 'c-1', 'schema' => '404', 'ground' => 'SCHEMA_UNRESOLVED', 'operation' => 'cascade'], self::CASCADE_UNRESOLVED),
			$guard->cascadeRefusal('c-1', 404)
		);
		$this->assertSame(
			array_merge(['uuid' => 'c-1', 'schema' => 'unknown', 'ground' => 'SCHEMA_UNRESOLVED', 'operation' => 'cascade'], self::CASCADE_UNRESOLVED),
			$guard->cascadeRefusal('c-1', null)
		);
		$this->assertSame(
			[
				['[ArchivalRetentionGuard] Left a record alone because its schema could not be resolved', ['uuid' => 'c-1', 'schema' => 404, 'operation' => 'cascade']],
				['[ArchivalRetentionGuard] Left a record alone because its schema could not be resolved', ['uuid' => 'c-1', 'schema' => null, 'operation' => 'cascade']],
			],
			$this->warnings
		);
	}//end testCascadeReports()
}//end class
