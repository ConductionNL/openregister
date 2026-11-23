<?php

/**
 * OriginalCustodyTest
 *
 * Publishing a redacted copy schedules its original's deletion at the
 * publication moment plus `anonymisation.originalRetention`; with `keep`
 * nothing is scheduled; the custody statement names both files, the
 * retention and the date (REQ-RRS-005).
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

namespace OCA\OpenRegister\Tests\Unit\Service\Anonymisation;

use DateTime;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Files\Db\PublicShareRequest;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OriginalCustody;
use OCA\OpenRegister\Db\OriginalCustodyMapper;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Kernel\Contracts\Core\FileReadRestrictionInterface;
use OCA\OpenRegister\Service\Anonymisation\OriginalCustodyService;
use OCA\OpenRegister\Service\Anonymisation\RedactionVerdictStore;
use OCA\OpenRegister\Service\File\FilePublishingHandler;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Kernel\Contracts\Core\PublicationRefusalInterface;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCA\OpenRegister\Tests\Unit\Service\File\PassThroughDigestsTrait;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\IAppConfig;
use OCP\IServerContainer;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The original's custody on publication, and its statement.
 *
 * @covers \OCA\OpenRegister\Service\Anonymisation\OriginalCustodyService
 * @covers \OCA\OpenRegister\Service\File\FilePublishingHandler
 * @covers \OCA\OpenRegister\Service\Settings\FileSettingsHandler
 * @covers \OCA\OpenRegister\Db\OriginalCustody
 * @uses   \OCA\OpenRegister\Service\Anonymisation\RedactionVerdictStore
 * @uses   \OCA\OpenRegister\Service\File\ObjectFileFinder
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Service\Anonymisation\CapacityPolicy
 * @uses   \OCA\OpenRegister\Service\Anonymisation\OfficialsSource
 */
class OriginalCustodyTest extends TestCase {
	use PassThroughDigestsTrait;

	/**
	 * The custody rows inserted.
	 *
	 * @var list<OriginalCustody>
	 */
	private array $inserted = [];

	/**
	 * The shares created, by file id.
	 *
	 * @var list<int>
	 */
	private array $shares = [];

	/**
	 * The file settings handler over a stored retention (null: none stored).
	 *
	 * @param string|null $retention The stored `anonymisation.originalRetention`.
	 *
	 * @return FileSettingsHandler
	 */
	private function settings(?string $retention): FileSettingsHandler {
		$anonymisation = [];
		if ($retention !== null) {
			$anonymisation['originalRetention'] = $retention;
		}

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => $key === 'fileManagement' ? (string) json_encode(['anonymisation' => $anonymisation]) : ''
		);

		return new FileSettingsHandler(appConfig: $config);
	}//end settings()

	/**
	 * The custody service, its mapper capturing inserts.
	 *
	 * @param string|null          $retention The stored retention.
	 * @param OriginalCustody|null $row       The row `findLatestForFile()` returns.
	 * @param bool                 $failing   Whether inserting fails.
	 *
	 * @return OriginalCustodyService
	 */
	private function service(?string $retention, ?OriginalCustody $row = null, bool $failing = false): OriginalCustodyService {
		$mapper = $this->createMock(OriginalCustodyMapper::class);
		$mapper->method('insert')->willReturnCallback(function (OriginalCustody $entity) use ($failing): OriginalCustody {
			if ($failing === true) {
				throw new RuntimeException('database down');
			}

			$this->inserted[] = $entity;
			return $entity;
		});
		$mapper->method('findLatestForFile')->willReturn($row);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-01T10:00:00+00:00'));

		$root = $this->createMock(IRootFolder::class);
		$root->method('getById')->willReturnCallback(fn (int $id): array => [$this->file(id: $id, name: $id === 6 ? 'besluit.pdf' : 'besluit_anonymized.pdf')]);

		return new OriginalCustodyService(
			custody: $mapper,
			settings: $this->settings(retention: $retention),
			rootFolder: $root,
			time: $time,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * A file in `/objects/42/`, whose folder holds `besluit.pdf` (6) and `besluit_anonymized.pdf` (7).
	 *
	 * @param int    $id   The file id.
	 * @param string $name The file name.
	 *
	 * @return File
	 */
	private function file(int $id, string $name): File {
		$siblings = ['besluit.pdf' => 6, 'besluit_anonymized.pdf' => 7];
		$folder   = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturnCallback(static fn (string $n): bool => isset($siblings[$n]));
		$folder->method('get')->willReturnCallback(function (string $n) use ($siblings): File {
			$sibling = $this->createMock(File::class);
			$sibling->method('getId')->willReturn($siblings[$n]);
			$sibling->method('getName')->willReturn($n);
			$sibling->method('getPath')->willReturn('/openregister/files/objects/42/' . $n);
			return $sibling;
		});

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn('/openregister/files/objects/42/' . $name);
		$file->method('getParent')->willReturn($folder);
		return $file;
	}//end file()

	/**
	 * Publish file 7 (`besluit_anonymized.pdf`, verdict clean) through `FilePublishingHandler::publishFile()`.
	 *
	 * @param OriginalCustodyService $custody The custody service the container gives.
	 * @param string                 $name    The published file's name.
	 *
	 * @return void
	 */
	private function publish(OriginalCustodyService $custody, string $name = 'besluit_anonymized.pdf'): void {
		$metadata = $this->createMock(IFilesMetadata::class);
		$metadata->method('hasKey')->willReturn(true);
		$metadata->method('getString')->willReturn((string) json_encode(['verdict' => 'clean', 'mayBePublished' => true]));
		$manager = $this->createMock(IFilesMetadataManager::class);
		$manager->method('getMetadata')->willReturn($metadata);
		$verdicts = new RedactionVerdictStore(metadataManager: $manager, logger: $this->createMock(LoggerInterface::class));

		$restrictions = $this->createMock(FileReadRestrictionInterface::class);
		$restrictions->method('isRestricted')->willReturn(false);
		$container = $this->createMock(IServerContainer::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				FileReadRestrictionInterface::class => $restrictions,
				RedactionVerdictStore::class => $verdicts,
				OriginalCustodyService::class => $custody,
				default => $this->createMock(PublicationRefusalInterface::class),
			}
		);

		$fileMapper = $this->createMock(FileMapper::class);
		$fileMapper->method('publishFile')->willReturnCallback(function (PublicShareRequest $request): array {
			$this->shares[] = $request->fileId;
			return ['id' => 3, 'token' => 'tok', 'accessUrl' => 'https://x/s/tok'];
		});

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('openregister');
		$fileService = $this->createMock(FileService::class);
		$fileService->method('getFile')->willReturn($this->file(id: 7, name: $name));
		$fileService->method('getUser')->willReturn($user);

		$handler = new FilePublishingHandler($this->createMock(MagicMapper::class), $fileMapper, $this->createMock(LoggerInterface::class), $container, $this->passThroughDigests());
		$handler->setFileService($fileService);

		$object = new ObjectEntity();
		$object->setUuid('obj-uuid');
		$handler->publishFile($object, 7);
	}//end publish()

	/**
	 * `P90D` and a copy published on 2026-10-01: the original goes on 2026-12-30.
	 *
	 * @return void
	 */
	public function testPublishingSchedulesTheOriginal(): void {
		$this->publish(custody: $this->service(retention: 'P90D'));

		$this->assertSame([7], $this->shares);
		$this->assertCount(1, $this->inserted);
		$row = $this->inserted[0];
		$this->assertSame(6, $row->getOriginalFileId());
		$this->assertSame(7, $row->getCopyFileId());
		$this->assertSame('obj-uuid', $row->getObjectUuid());
		$this->assertSame('P90D', $row->getRetention());
		$this->assertSame('2026-10-01', $row->getPublishedAt()?->format('Y-m-d'));
		$this->assertSame('2026-12-30', $row->getDeleteAfter()?->format('Y-m-d'));
		$this->assertSame(OriginalCustody::STATUS_SCHEDULED, $row->getStatus());
	}//end testPublishingSchedulesTheOriginal()

	/**
	 * The default `keep`, and a stored value that does not read, schedule nothing.
	 *
	 * @return void
	 */
	public function testKeepSchedulesNothing(): void {
		$this->publish(custody: $this->service(retention: null));
		$this->publish(custody: $this->service(retention: 'keep'));
		$this->publish(custody: $this->service(retention: '90 days'));

		$this->assertSame([7, 7, 7], $this->shares);
		$this->assertSame([], $this->inserted);
	}//end testKeepSchedulesNothing()

	/**
	 * Publishing a file that is not an anonymised copy schedules nothing.
	 *
	 * @return void
	 */
	public function testPublishingAnOriginalSchedulesNothing(): void {
		$this->publish(custody: $this->service(retention: 'P90D'), name: 'besluit.pdf');

		$this->assertSame([7], $this->shares);
		$this->assertSame([], $this->inserted);
	}//end testPublishingAnOriginalSchedulesNothing()

	/**
	 * A scheduling failure leaves the publication standing and the original kept.
	 *
	 * @return void
	 */
	public function testASchedulingFailureLeavesThePublicationStanding(): void {
		$this->publish(custody: $this->service(retention: 'P90D', failing: true));

		$this->assertSame([7], $this->shares);
		$this->assertSame([], $this->inserted);
	}//end testASchedulingFailureLeavesThePublicationStanding()

	/**
	 * The statement of a scheduled original names both files, `P90D` and 2026-12-30.
	 *
	 * @return void
	 */
	public function testTheStatementNamesTheOriginalTheRetentionAndTheDate(): void {
		$row = new OriginalCustody();
		$row->setOriginalFileId(6);
		$row->setCopyFileId(7);
		$row->setRetention('P90D');
		$row->setDeleteAfter(new DateTime('2026-12-30T10:00:00+00:00'));

		$statement = $this->service(retention: 'P90D', row: $row)->statement(file: $this->file(id: 7, name: 'besluit_anonymized.pdf'));

		$this->assertSame(
			[
				'original' => ['fileId' => 6, 'path' => '/openregister/files/objects/42/besluit.pdf'],
				'redactedCopy' => ['fileId' => 7, 'path' => '/openregister/files/objects/42/besluit_anonymized.pdf'],
				'retention' => 'P90D',
				'deletionDate' => '2026-12-30',
				'status' => 'scheduled',
				'heldBecause' => null,
			],
			$statement
		);
	}//end testTheStatementNamesTheOriginalTheRetentionAndTheDate()

	/**
	 * By default the statement says the original is kept as the record, asked
	 * of the original or of the copy.
	 *
	 * @return void
	 */
	public function testByDefaultTheStatementSaysTheOriginalIsKept(): void {
		$service = $this->service(retention: null);

		foreach ([[6, 'besluit.pdf'], [7, 'besluit_anonymized.pdf']] as [$id, $name]) {
			$statement = $service->statement(file: $this->file(id: $id, name: $name));
			$this->assertSame(['fileId' => 6, 'path' => '/openregister/files/objects/42/besluit.pdf'], $statement['original']);
			$this->assertSame(7, $statement['redactedCopy']['fileId']);
			$this->assertSame('keep', $statement['retention']);
			$this->assertNull($statement['deletionDate']);
			$this->assertSame('kept', $statement['status']);
		}
	}//end testByDefaultTheStatementSaysTheOriginalIsKept()

	/**
	 * Only an ISO 8601 duration that reads is kept as a retention.
	 *
	 * @return void
	 */
	public function testTheRetentionSettingReadsOnlyADuration(): void {
		$this->assertSame('P90D', FileSettingsHandler::normaliseRetention(value: 'P90D'));
		$this->assertSame('P1Y6M', FileSettingsHandler::normaliseRetention(value: 'P1Y6M'));
		$this->assertSame('PT12H', FileSettingsHandler::normaliseRetention(value: 'PT12H'));
		foreach (['keep', 'P', 'PT', '90 days', 'p90d', '', 90, null] as $unreadable) {
			$this->assertSame('keep', FileSettingsHandler::normaliseRetention(value: $unreadable));
		}

		$this->assertSame('keep', $this->settings(retention: null)->originalRetention());
		$this->assertSame('P90D', $this->settings(retention: 'P90D')->originalRetention());
	}//end testTheRetentionSettingReadsOnlyADuration()

	/**
	 * Copy and original names map onto each other.
	 *
	 * @return void
	 */
	public function testCopyAndOriginalNames(): void {
		$this->assertSame('besluit.pdf', OriginalCustodyService::originalNameFor(copyName: 'besluit_anonymized.pdf'));
		$this->assertSame('notities', OriginalCustodyService::originalNameFor(copyName: 'notities_anonymized'));
		$this->assertNull(OriginalCustodyService::originalNameFor(copyName: 'besluit.pdf'));
		$this->assertNull(OriginalCustodyService::originalNameFor(copyName: '_anonymized.pdf'));
		$this->assertSame('besluit_anonymized.pdf', OriginalCustodyService::copyNameFor(originalName: 'besluit.pdf'));
		$this->assertFalse(OriginalCustodyService::isCopy(name: 'besluit_anonymized_v2.pdf'));
	}//end testCopyAndOriginalNames()
}//end class
