<?php

/**
 * OriginalRetentionJobTest
 *
 * The daily job deletes an original past its date through the file delete
 * path with an audit row, and leaves one whose object is under a legal hold
 * or nominated to be kept (REQ-RRS-005). Every case runs through `run()`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-unredacted-originals-custody-is-stated-and-its-deletion-scheduled-req-rrs-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\BackgroundJob\OriginalRetentionJob;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OriginalCustody;
use OCA\OpenRegister\Db\OriginalCustodyMapper;
use OCA\OpenRegister\Files\Service\File\FileAuditHandlerInterface;
use OCA\OpenRegister\Service\File\DeleteFileHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Originals past their retention, deleted or held.
 *
 * @covers \OCA\OpenRegister\BackgroundJob\OriginalRetentionJob
 * @uses   \OCA\OpenRegister\Db\OriginalCustody
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class OriginalRetentionJobTest extends TestCase {

	/**
	 * The files deleted, by id.
	 *
	 * @var list<int>
	 */
	private array $deleted = [];

	/**
	 * The audit rows written: [fileId, action, data].
	 *
	 * @var list<array{int, string, array<string, mixed>}>
	 */
	private array $audits = [];

	/**
	 * The rows updated.
	 *
	 * @var list<OriginalCustody>
	 */
	private array $updated = [];

	/**
	 * The info context of the run.
	 *
	 * @var array<string, mixed>
	 */
	private array $report = [];

	/**
	 * A due row: original 6, copy 7, object `obj-uuid`, deletion 2026-12-30.
	 *
	 * @return OriginalCustody
	 */
	private static function dueRow(): OriginalCustody {
		$row = new OriginalCustody();
		$row->setOriginalFileId(6);
		$row->setCopyFileId(7);
		$row->setObjectUuid('obj-uuid');
		$row->setRetention('P90D');
		$row->setPublishedAt(new DateTime('2026-10-01T10:00:00+00:00'));
		$row->setDeleteAfter(new DateTime('2026-12-30T10:00:00+00:00'));
		return $row;
	}//end dueRow()

	/**
	 * Run the job once on 2026-12-31 over $row, the object being $object
	 * (null: it cannot be read) and the original existing when $originalExists.
	 *
	 * @param OriginalCustody   $row            The due row.
	 * @param ObjectEntity|null $object         The object.
	 * @param bool              $originalExists Whether file 6 still exists.
	 *
	 * @return void
	 */
	private function runJob(OriginalCustody $row, ?ObjectEntity $object, bool $originalExists = true): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-12-31T03:00:00+00:00'));

		$mapper = $this->createMock(OriginalCustodyMapper::class);
		$mapper->method('findDue')->willReturnCallback(
			static function (\DateTimeInterface $now) use ($row): array {
				return $now->format('Y-m-d') === '2026-12-31' ? [$row] : [];
			}
		);
		$mapper->method('update')->willReturnCallback(function (OriginalCustody $entity): OriginalCustody {
			$this->updated[] = $entity;
			return $entity;
		});

		$objects = $this->createMock(ObjectOperationsInterface::class);
		if ($object === null) {
			$objects->method('findSilent')->willThrowException(new DoesNotExistException('gone'));
		} else {
			$objects->method('findSilent')->with('obj-uuid')->willReturn($object);
		}

		$original = $this->createMock(File::class);
		$original->method('getId')->willReturn(6);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getById')->with(6)->willReturn($originalExists === true ? [$original] : []);

		/** @var DeleteFileHandler&MockObject $deleter */
		$deleter = $this->createMock(DeleteFileHandler::class);
		$deleter->method('deleteFile')->willReturnCallback(function (File $file, ?ObjectEntity $owner) use ($object): bool {
			$this->assertSame($object, $owner);
			$this->deleted[] = $file->getId();
			return true;
		});

		$audit = $this->createMock(FileAuditHandlerInterface::class);
		$audit->method('logFileAction')->willReturnCallback(function (ObjectEntity $owner, int $fileId, string $action, array $data = []) {
			$this->audits[] = [$fileId, $action, $data];
			return null;
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(function (string $message, array $context = []): void {
			$this->report = $context;
		});

		$job = new OriginalRetentionJob(
			time: $time,
			custody: $mapper,
			objects: $objects,
			rootFolder: $root,
			deleter: $deleter,
			audit: $audit,
			logger: $logger
		);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * An object with the given retention block.
	 *
	 * @param array<string, mixed> $retention The retention.
	 * @param array<string, mixed> $tmlo      The TMLO metadata.
	 *
	 * @return ObjectEntity
	 */
	private static function object(array $retention = [], array $tmlo = []): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-uuid');
		$object->setRetention($retention);
		$object->setTmlo($tmlo);
		return $object;
	}//end object()

	/**
	 * Past its date and nothing holding it: deleted, with an audit row naming
	 * the original, the copy and the rule.
	 *
	 * @return void
	 */
	public function testAnOriginalPastItsDateIsDeletedWithAnAuditRow(): void {
		$row = self::dueRow();
		$this->runJob(row: $row, object: self::object());

		$this->assertSame([6], $this->deleted);
		$this->assertCount(1, $this->audits);
		[$fileId, $action, $data] = $this->audits[0];
		$this->assertSame(6, $fileId);
		$this->assertSame('file.original_retention_deleted', $action);
		$this->assertSame(6, $data['originalFileId']);
		$this->assertSame(7, $data['redactedCopyFileId']);
		$this->assertSame('anonymisation.originalRetention', $data['rule']);
		$this->assertSame('P90D', $data['retention']);
		$this->assertSame(OriginalCustody::STATUS_DELETED, $row->getStatus());
		$this->assertSame('2026-12-31', $row->getOutcomeAt()?->format('Y-m-d'));
		$this->assertSame(1, $this->report['deleted']);
	}//end testAnOriginalPastItsDateIsDeletedWithAnAuditRow()

	/**
	 * Under a legal hold: the original stays, the run reports it as held,
	 * and it stays scheduled for a later run.
	 *
	 * @return void
	 */
	public function testALegalHoldStopsTheDeletion(): void {
		$row = self::dueRow();
		$this->runJob(row: $row, object: self::object(retention: ['legalHold' => ['active' => true, 'reason' => 'WOB-verzoek']]));

		$this->assertSame([], $this->deleted);
		$this->assertSame([], $this->audits);
		$this->assertSame([['originalFileId' => 6, 'reason' => 'legal-hold']], $this->report['held']);
		$this->assertSame(OriginalCustody::STATUS_SCHEDULED, $row->getStatus());
		$this->assertSame('legal-hold', $row->getOutcomeReason());
	}//end testALegalHoldStopsTheDeletion()

	/**
	 * A released hold does not stop the deletion.
	 *
	 * @return void
	 */
	public function testAReleasedHoldDoesNotStopTheDeletion(): void {
		$this->runJob(row: self::dueRow(), object: self::object(retention: ['legalHold' => ['active' => false]]));

		$this->assertSame([6], $this->deleted);
	}//end testAReleasedHoldDoesNotStopTheDeletion()

	/**
	 * Nominated to be kept (`bewaren`, in the retention block or the TMLO
	 * metadata): the original stays and is reported.
	 *
	 * @return void
	 */
	public function testABewarenNominationStopsTheDeletion(): void {
		$this->runJob(row: self::dueRow(), object: self::object(retention: ['archiefnominatie' => 'bewaren']));
		$this->runJob(row: self::dueRow(), object: self::object(tmlo: ['archiefnominatie' => 'blijvend_bewaren']));

		$this->assertSame([], $this->deleted);
		$this->assertSame([['originalFileId' => 6, 'reason' => 'nomination-keep']], $this->report['held']);
	}//end testABewarenNominationStopsTheDeletion()

	/**
	 * A destroy nomination does not stop it.
	 *
	 * @return void
	 */
	public function testADestroyNominationDoesNotStopTheDeletion(): void {
		$this->runJob(row: self::dueRow(), object: self::object(retention: ['archiefnominatie' => 'vernietigen']));

		$this->assertSame([6], $this->deleted);
	}//end testADestroyNominationDoesNotStopTheDeletion()

	/**
	 * An object that cannot be read holds the original: a hold that cannot be
	 * checked is not assumed absent.
	 *
	 * @return void
	 */
	public function testAnUnreadableObjectStopsTheDeletion(): void {
		$this->runJob(row: self::dueRow(), object: null);

		$this->assertSame([], $this->deleted);
		$this->assertSame([['originalFileId' => 6, 'reason' => 'object-unreadable']], $this->report['held']);
	}//end testAnUnreadableObjectStopsTheDeletion()

	/**
	 * An original already gone closes its row without a deletion or an audit row.
	 *
	 * @return void
	 */
	public function testAnOriginalAlreadyGoneIsClosed(): void {
		$row = self::dueRow();
		$this->runJob(row: $row, object: self::object(), originalExists: false);

		$this->assertSame([], $this->deleted);
		$this->assertSame([], $this->audits);
		$this->assertSame(OriginalCustody::STATUS_GONE, $row->getStatus());
		$this->assertSame(1, $this->report['gone']);
	}//end testAnOriginalAlreadyGoneIsClosed()
}//end class
