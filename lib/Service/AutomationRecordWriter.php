<?php

/**
 * OpenRegister AutomationRecordWriter
 *
 * Writes automation run records, quietly: a record that cannot be written
 * never stops the step it records.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/automation-run-records/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\AutomationRecord;
use OCA\OpenRegister\Db\AutomationRecordMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps and stores automation run records (ADR-022: OpenRegister runs the step, so it records it).
 *
 * @spec openspec/changes/automation-run-records/design.md
 */
class AutomationRecordWriter {

	/**
	 * Constructor.
	 *
	 * @param AutomationRecordMapper $mapper The record store.
	 * @param LoggerInterface        $logger Logs a record that could not be written.
	 *
	 * @spec openspec/changes/automation-run-records/design.md
	 */
	public function __construct(
		private readonly AutomationRecordMapper $mapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * A new record of a kind for an object, not yet written.
	 *
	 * @param string            $kind   The record kind.
	 * @param mixed             $key    The declaration's key (anything but text counts as none).
	 * @param string            $name   The notification slug or the action name.
	 * @param ObjectEntity|null $object The object the run was for.
	 *
	 * @return AutomationRecord The record.
	 *
	 * @spec openspec/changes/automation-run-records/design.md
	 */
	public static function draft(string $kind, mixed $key, string $name, ?ObjectEntity $object): AutomationRecord {
		$record = new AutomationRecord();
		$record->setKind($kind);
		$record->setAutomationKey(self::keyOf(key: $key));
		$record->setName($name);
		if ($object !== null) {
			$record->setSchemaId(self::idOf(value: $object->getSchema()));
			$record->setRegisterId(self::idOf(value: $object->getRegister()));
			$record->setObjectUuid(self::idOf(value: $object->getUuid()));
		}

		return $record;
	}//end draft()

	/**
	 * Stamp and store a record. A failure is logged, never thrown.
	 *
	 * @param AutomationRecord $record The record.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/automation-run-records/design.md
	 */
	public function write(AutomationRecord $record): void {
		$record->setRecordedAt(new DateTime('now', new DateTimeZone('UTC')));
		try {
			$this->mapper->insert($record);
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf(
					'[AutomationRecordWriter] could not record %s "%s" for object %s: %s',
					$record->getKind(),
					$record->getName(),
					(string)$record->getObjectUuid(),
					$e->getMessage()
				)
			);
		}
	}//end write()

	/**
	 * A declaration's key as stored: text, trimmed, at most 191 characters.
	 *
	 * @param mixed $key The declared key.
	 *
	 * @return string The key, '' when none.
	 */
	private static function keyOf(mixed $key): string {
		if (is_string($key) === false) {
			return '';
		}

		return mb_substr(trim($key), 0, 191);
	}//end keyOf()

	/**
	 * An id as stored, or null when empty.
	 *
	 * @param mixed $value The id.
	 *
	 * @return string|null The id.
	 */
	private static function idOf(mixed $value): ?string {
		if ($value === null || $value === '' || is_scalar($value) === false) {
			return null;
		}

		return (string)$value;
	}//end idOf()
}//end class
