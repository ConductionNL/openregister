<?php

/**
 * The writer and the sweep are tested together, against one moving clock.
 *
 * 🔑 WHY THIS SUITE IS SHAPED THIS WAY. Every export ZIP in this fleet was once
 * born 22.5 million seconds expired, because one component wrote a
 * deterministic file timestamp and the purge decided expiry by reading that
 * timestamp. Both components had unit tests. Both passed. The cleanup test even
 * hand-set the timestamps it then asserted on, so it could never have seen the
 * defect.
 *
 * So nothing here hand-sets an expiry. Each test records a run through the real
 * `record()`, then asks the real `sweep()` about it, and the only thing that
 * moves between the two is the clock. A run recorded a moment ago must survive
 * the sweep; the same run, once its retention has passed, must lose its file
 * and keep its row.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Export;

use DateTime;
use OCA\OpenRegister\Db\ExportRun;
use OCA\OpenRegister\Db\ExportRunMapper;
use OCA\OpenRegister\Service\Export\ExportRunRecorder;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Export\ExportRunRecorder
 * @covers \OCA\OpenRegister\Db\ExportRun
 */
class ExportRunRecorderTest extends TestCase {

	/**
	 * The moment the fake clock reports, moved by the tests.
	 *
	 * @var int
	 */
	private int $clock = 1790000000;

	/**
	 * The rows the fake mapper holds, by uuid.
	 *
	 * @var array<string, ExportRun>
	 */
	private array $rows = [];

	/**
	 * The file ids the fake Files tree still holds.
	 *
	 * @var array<int, bool>
	 */
	private array $files = [];

	/**
	 * The recorder under test.
	 *
	 * @var ExportRunRecorder
	 */
	private ExportRunRecorder $recorder;

	/**
	 * Build a recorder over in-memory fakes and a clock this test moves.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->clock = 1790000000;
		$this->rows = [];
		$this->files = [4242 => true];

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(
			function (): DateTime {
				return new DateTime('@' . $this->clock);
			}
		);

		$mapper = $this->createMock(ExportRunMapper::class);
		$mapper->method('insert')->willReturnCallback(
			function (ExportRun $run): ExportRun {
				$this->rows[(string)$run->getUuid()] = $run;

				return $run;
			}
		);
		$mapper->method('update')->willReturnCallback(
			function (ExportRun $run): ExportRun {
				$this->rows[(string)$run->getUuid()] = $run;

				return $run;
			}
		);
		$mapper->method('findByUuid')->willReturnCallback(
			function (string $uuid): ExportRun {
				if (isset($this->rows[$uuid]) === false) {
					throw new DoesNotExistException('no such export run');
				}

				return $this->rows[$uuid];
			}
		);
		$mapper->method('findForActor')->willReturnCallback(
			function (?string $actor, array $filters = []): array {
				$found = [];
				foreach ($this->rows as $row) {
					if ($actor !== null && $row->getActor() !== $actor) {
						continue;
					}

					if (isset($filters['status']) === true && $row->getStatus() !== $filters['status']) {
						continue;
					}

					$found[] = $row;
				}

				return $found;
			}
		);
		// The sweep's predicate, expressed the way the SQL expresses it: a
		// deadline that is set and has passed, on a run whose file is still
		// there. Nothing here reads a file timestamp.
		$mapper->method('findDueForSweep')->willReturnCallback(
			function (DateTime $now, int $limit = 100): array {
				$found = [];
				foreach ($this->rows as $row) {
					$expires = $row->getExpiresAt();
					if ($expires === null || $expires > $now) {
						continue;
					}

					if ($row->getStatus() !== ExportRun::STATUS_AVAILABLE) {
						continue;
					}

					$found[] = $row;
					if (count($found) >= $limit) {
						break;
					}
				}

				return $found;
			}
		);

		$rootFolder = $this->createMock(IRootFolder::class);
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturnCallback(
			function (int $fileId): array {
				if (isset($this->files[$fileId]) === false) {
					return [];
				}

				$node = $this->createMock(File::class);
				$node->method('delete')->willReturnCallback(
					function () use ($fileId): void {
						unset($this->files[$fileId]);
					}
				);

				return [$node];
			}
		);
		$rootFolder->method('getUserFolder')->willReturn($folder);

		$this->recorder = new ExportRunRecorder(
			mapper: $mapper,
			rootFolder: $rootFolder,
			time: $time,
			logger: new NullLogger()
		);
	}//end setUp()

	/**
	 * Record one run through the real writer.
	 *
	 * @param int|null $retention How long its file is kept.
	 * @param int|null $fileId    The file it produced.
	 * @param string   $actor     Who made it.
	 *
	 * @return ExportRun The run.
	 */
	private function recordOne(?int $retention = null, ?int $fileId = 4242, string $actor = 'alice'): ExportRun {
		return $this->recorder->record(
			source: 'scheduled-report',
			actor: $actor,
			format: 'csv',
			rowCount: 12,
			profile: 'weekly-cases',
			filename: 'weekly-cases.csv',
			registerName: 'cases',
			schemaName: 'case',
			fileId: $fileId,
			filePath: 'Reports/weekly-cases.csv',
			retentionSeconds: $retention
		);
	}//end recordOne()

	/**
	 * THE JOINT ASSERTION, first half: a run recorded a moment ago is not
	 * swept by a sweep running at the same moment, and its file is still
	 * there.
	 *
	 * This is the assertion the old defect would have failed.
	 *
	 * @return void
	 */
	public function testAFreshRunSurvivesASweepRunningNow(): void {
		$run = $this->recordOne(retention: 3600);

		$swept = $this->recorder->sweep();

		$this->assertSame(0, $swept, 'A run recorded a moment ago was swept by the very next pass.');
		$this->assertSame(ExportRun::STATUS_AVAILABLE, $this->rows[(string)$run->getUuid()]->getStatus());
		$this->assertArrayHasKey(4242, $this->files, 'The file of a fresh run was deleted.');
	}//end testAFreshRunSurvivesASweepRunningNow()

	/**
	 * THE JOINT ASSERTION, second half: the same run, once its retention has
	 * passed, loses its file and keeps its row.
	 *
	 * Without this the first half would pass for a sweep that never removes
	 * anything at all.
	 *
	 * @return void
	 */
	public function testTheSameRunLosesItsFileAndKeepsItsRow(): void {
		$run = $this->recordOne(retention: 3600);
		$uuid = (string)$run->getUuid();

		$this->clock += 3601;

		$swept = $this->recorder->sweep();

		$this->assertSame(1, $swept, 'A run past its stored expiry survived the sweep.');
		$this->assertArrayNotHasKey(4242, $this->files, 'The retention passed and the file is still there.');
		$this->assertArrayHasKey($uuid, $this->rows, 'The sweep deleted the row; the row is what outlives the file.');
		$this->assertSame(ExportRun::STATUS_EXPIRED, $this->rows[$uuid]->getStatus());
		$this->assertNull($this->rows[$uuid]->getFileId());
		$this->assertSame(12, $this->rows[$uuid]->getRowCount(), 'The swept row forgot what it was a record of.');
	}//end testTheSameRunLosesItsFileAndKeepsItsRow()

	/**
	 * The stored expiry is the retention the run was produced under.
	 *
	 * @return void
	 */
	public function testTheStoredExpiryIsTheRetentionItWasProducedUnder(): void {
		$run = $this->recordOne(retention: 7200);

		$expires = $run->getExpiresAt();

		$this->assertNotNull($expires);
		$this->assertSame($this->clock + 7200, $expires->getTimestamp());
		$this->assertSame(7200, $run->getRetentionSeconds());
	}//end testTheStoredExpiryIsTheRetentionItWasProducedUnder()

	/**
	 * A run with no retention is kept, and says so.
	 *
	 * @return void
	 */
	public function testARunWithNoRetentionIsNeverSwept(): void {
		$run = $this->recordOne(retention: null);

		$this->clock += 31536000;
		$swept = $this->recorder->sweep();

		$this->assertSame(0, $swept, 'A run produced to be kept was swept anyway.');
		$this->assertTrue($run->isKept(), 'A run with no expiry does not say it is kept.');
		$this->assertNull($run->getRetentionSeconds());
		$this->assertArrayHasKey(4242, $this->files);
	}//end testARunWithNoRetentionIsNeverSwept()

	/**
	 * A run whose file somebody already deleted is still marked.
	 *
	 * If it were treated as a failure the row would sit past its own expiry
	 * for ever, because its file went first.
	 *
	 * @return void
	 */
	public function testARunWhoseFileIsAlreadyGoneIsNotAFailure(): void {
		$run = $this->recordOne(retention: 3600, fileId: 9999);
		$uuid = (string)$run->getUuid();

		$this->clock += 3601;

		$swept = $this->recorder->sweep();

		$this->assertSame(1, $swept);
		$this->assertSame(ExportRun::STATUS_EXPIRED, $this->rows[$uuid]->getStatus());
	}//end testARunWhoseFileIsAlreadyGoneIsNotAFailure()

	/**
	 * A second sweep does not sweep the same run again.
	 *
	 * @return void
	 */
	public function testASecondSweepDoesNotRepeatItself(): void {
		$this->recordOne(retention: 3600);

		$this->clock += 3601;

		$this->assertSame(1, $this->recorder->sweep());
		$this->assertSame(0, $this->recorder->sweep(), 'The sweep swept the same run twice.');
	}//end testASecondSweepDoesNotRepeatItself()

	/**
	 * A retention beyond the maximum is capped, not honoured.
	 *
	 * @return void
	 */
	public function testARetentionBeyondTheMaximumIsCapped(): void {
		$run = $this->recordOne(retention: (ExportRunRecorder::MAX_RETENTION_SECONDS * 4));

		$expires = $run->getExpiresAt();

		$this->assertNotNull($expires);
		$this->assertSame($this->clock + ExportRunRecorder::MAX_RETENTION_SECONDS, $expires->getTimestamp());
	}//end testARetentionBeyondTheMaximumIsCapped()

	/**
	 * The count belongs to the run, and moves once per hand-over.
	 *
	 * @return void
	 */
	public function testTheDownloadCountMovesOncePerHandover(): void {
		$run = $this->recordOne();
		$uuid = (string)$run->getUuid();

		$this->recorder->countDownload(uuid: $uuid);
		$this->recorder->countDownload(uuid: $uuid);

		$this->assertSame(2, $this->rows[$uuid]->getDownloadCount(), 'Two hand-overs did not count as two.');
	}//end testTheDownloadCountMovesOncePerHandover()

	/**
	 * Counting a run that is not there is answered, not thrown.
	 *
	 * @return void
	 */
	public function testCountingAnUnknownRunIsAnswered(): void {
		$this->assertNull($this->recorder->countDownload(uuid: 'no-such-run'));
	}//end testCountingAnUnknownRunIsAnswered()

	/**
	 * The area lists a caller's own runs, and names the expired ones.
	 *
	 * @return void
	 */
	public function testTheAreaNamesAnExpiredRunAsExpired(): void {
		$this->recordOne(retention: 3600);

		$before = $this->recorder->listFor(actor: 'alice');
		$this->assertCount(1, $before);
		$this->assertFalse($before[0]['expired'], 'A run inside its retention was listed as expired.');
		$this->assertTrue($before[0]['downloadable']);

		$this->clock += 3601;

		$after = $this->recorder->listFor(actor: 'alice');
		$this->assertTrue($after[0]['expired'], 'A run past its retention was listed as available.');
		$this->assertFalse($after[0]['downloadable'], 'An expired run was offered as a link to nothing.');
	}//end testTheAreaNamesAnExpiredRunAsExpired()

	/**
	 * A caller does not see another principal's runs.
	 *
	 * A scope that is accidentally a no-op returns exactly what an
	 * administrator sees, which is indistinguishable from a working page until
	 * two accounts are compared. So this asserts the absence, not the presence.
	 *
	 * @return void
	 */
	public function testACallerDoesNotSeeAnotherPrincipalsRuns(): void {
		$this->recordOne(actor: 'alice');
		$this->recordOne(actor: 'bob');

		$mine = $this->recorder->listFor(actor: 'alice');

		$actors = array_column($mine, 'actor');
		$this->assertNotContains('bob', $actors, "Another principal's export run was listed.");
		$this->assertSame(['alice'], array_values(array_unique($actors)));
	}//end testACallerDoesNotSeeAnotherPrincipalsRuns()

	/**
	 * An administrator sees every run.
	 *
	 * @return void
	 */
	public function testAnAdministratorSeesEveryRun(): void {
		$this->recordOne(actor: 'alice');
		$this->recordOne(actor: 'bob');

		$all = $this->recorder->listFor(actor: 'root', isAdmin: true);

		$this->assertCount(2, $all);
	}//end testAnAdministratorSeesEveryRun()

	/**
	 * An anonymous caller sees nothing.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerSeesNothing(): void {
		$this->recordOne();

		$this->assertSame([], $this->recorder->listFor(actor: null));
	}//end testAnAnonymousCallerSeesNothing()
}//end class
