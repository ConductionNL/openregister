<?php

/**
 * The queued audit export (audit-log-page task 2.2).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\AuditTrailExportJob;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailPageQuery;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\LogService;
use OCA\OpenRegister\Service\Operations\JobRunRecorder;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

/**
 * Renders with the REAL LogService, so the chain columns are asserted in the
 * file that is written, not in a double's answer.
 */
class AuditTrailExportJobTest extends TestCase {

	/**
	 * A row with its chain fields.
	 *
	 * @param int $id The id.
	 *
	 * @return AuditTrail The row.
	 */
	private function row(int $id): AuditTrail {
		$row = new AuditTrail();
		$row->setId($id);
		$row->setAction('update');
		$row->setHash('h' . $id);
		$row->setPreviousHash('h' . ($id - 1));
		return $row;
	}//end row()

	/**
	 * The file lands in the requester's "Audit exports" with `hash` and
	 * `previousHash` filled on every row, and the requester is notified.
	 *
	 * @return void
	 */
	public function testTheFileCarriesTheChainAndTheRequesterIsNotified(): void {
		$pageQuery = $this->createMock(AuditTrailPageQuery::class);
		$pageQuery->expects($this->once())->method('collect')
			->with(['actor' => 'anna'], null, AuditTrailExportJob::JOB_LIMIT)
			->willReturn(['results' => [$this->row(12), $this->row(11)], 'truncated' => false]);

		$logService = new LogService(
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class)
		);

		$written = [];
		$exports = $this->createMock(Folder::class);
		$exports->method('newFile')->willReturnCallback(
			function (string $name, $content) use (&$written): File {
				$written[$name] = $content;
				return $this->createMock(File::class);
			}
		);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(false);
		$userFolder->expects($this->once())->method('newFolder')->with('Audit exports');
		$userFolder->method('get')->willReturn($exports);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('admin')->willReturn($userFolder);

		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $method) {
			$notification->method($method)->willReturnSelf();
		}
		$notification->expects($this->once())->method('setSubject')
			->with('audit_export_ready', $this->callback(static fn (array $p): bool => $p['rows'] === 2 && $p['truncated'] === false));
		$notifications = $this->createMock(IManager::class);
		$notifications->method('createNotification')->willReturn($notification);
		$notifications->expects($this->once())->method('notify');

		$job = new AuditTrailExportJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(JobRunRecorder::class),
			$pageQuery,
			$logService,
			$root,
			$notifications
		);

		$path = $job->deliver(['actor' => 'admin', 'filters' => ['actor' => 'anna'], 'format' => 'csv']);

		$this->assertStringStartsWith('Audit exports/', $path);
		$csv = array_map('str_getcsv', array_filter(explode("\n", (string)reset($written))));
		$header = array_shift($csv);
		$this->assertContains('hash', $header);
		$this->assertContains('previousHash', $header);
		$this->assertCount(2, $csv);
		$hash = array_search('hash', $header, true);
		$previous = array_search('previousHash', $header, true);
		$this->assertSame(['h12', 'h11'], array_column($csv, $hash));
		$this->assertSame(['h11', 'h10'], array_column($csv, $previous));
	}//end testTheFileCarriesTheChainAndTheRequesterIsNotified()

	/**
	 * A job without a requester refuses rather than writing a file nobody owns.
	 *
	 * @return void
	 */
	public function testAJobWithoutARequesterRefuses(): void {
		$job = new AuditTrailExportJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(JobRunRecorder::class),
			$this->createMock(AuditTrailPageQuery::class),
			$this->createMock(LogService::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(IManager::class)
		);

		$this->expectException(\RuntimeException::class);
		$job->deliver(['format' => 'csv']);
	}//end testAJobWithoutARequesterRefuses()
}//end class
