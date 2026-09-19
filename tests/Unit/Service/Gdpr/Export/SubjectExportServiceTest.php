<?php

/**
 * Unit tests for the data subject's own export.
 *
 * Covers both scenarios of REQ-DSR-003: article 20 answered without a database
 * export, and the delivered file that does not live forever. The expiry case is
 * the one the spec delta marks `@e2e exclude {expiry over time, covered by unit
 * tests with a clock fixture}`, and this is that clock fixture.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Export
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Gdpr\Export;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\BackgroundJob\SubjectExportJob;
use OCA\OpenRegister\Db\SubjectExport;
use OCA\OpenRegister\Db\SubjectExportMapper;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCA\OpenRegister\Service\Gdpr\Export\SubjectExportService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class SubjectExportServiceTest extends TestCase {
	private const SUBJECT = 'jan@example.org';

	/** @var array<int, array{0: string, 1: mixed}> Every job queued. */
	private array $queued = [];

	/** @var array<int, SubjectExport> Every row written. */
	private array $saved = [];

	protected function setUp(): void {
		$this->queued = [];
		$this->saved = [];
	}//end setUp()

	/**
	 * An export row in a given state.
	 *
	 * @param string        $status    The lifecycle status.
	 * @param DateTime|null $expiresAt When the delivery stops working.
	 * @param string        $owner     Who asked for it.
	 *
	 * @return SubjectExport The row.
	 */
	private function export(
		string $status = SubjectExport::STATUS_READY,
		?DateTime $expiresAt = null,
		string $owner = 'handler',
	): SubjectExport {
		$export = new SubjectExport();
		$export->setUuid('export-1');
		$export->setSubject(self::SUBJECT);
		$export->setStatus($status);
		$export->setRequestedBy($owner);
		$export->setExpiresAt($expiresAt);

		return $export;
	}//end export()

	/**
	 * Assemble the service over staged collaborators.
	 *
	 * @param SubjectExport|null   $row      The row findByUuid returns, or null to miss.
	 * @param array<string, mixed> $bundle   What the assembler returns.
	 * @param string               $uid      The acting principal.
	 * @param bool                 $admin    Whether that principal is an administrator.
	 * @param bool                 $throwing Whether the assembler throws.
	 *
	 * @return SubjectExportService The assembled service.
	 */
	private function service(
		?SubjectExport $row = null,
		array $bundle = ['subject' => self::SUBJECT, 'objectCount' => 3, 'objects' => [], 'generatedAt' => 'now'],
		string $uid = 'handler',
		bool $admin = false,
		bool $throwing = false,
	): SubjectExportService {
		$mapper = $this->createMock(SubjectExportMapper::class);
		if ($row === null) {
			$mapper->method('findByUuid')->willThrowException(new DoesNotExistException('no such row'));
		} else {
			$mapper->method('findByUuid')->willReturn($row);
		}

		$mapper->method('createFromArray')->willReturnCallback(
			function (array $data): SubjectExport {
				$created = new SubjectExport();
				$created->hydrate($data);
				$created->setUuid('export-new');

				return $created;
			}
		);
		$mapper->method('save')->willReturnCallback(
			function (SubjectExport $export): SubjectExport {
				$this->saved[] = $export;

				return $export;
			}
		);

		$subjects = $this->createMock(DataSubjectRequestService::class);
		if ($throwing === true) {
			$subjects->method('assembleAccessExport')->willThrowException(new RuntimeException('register unreadable'));
		} else {
			$subjects->method('assembleAccessExport')->willReturn($bundle);
		}

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function (string $job, mixed $argument): void {
				$this->queued[] = [$job, $argument];
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		return new SubjectExportService($mapper, $subjects, $jobList, $session, $groups, new NullLogger());
	}//end service()

	public function testArticle20IsAnsweredWithoutADatabaseExport(): void {
		$service = $this->service();

		$export = $service->request(self::SUBJECT, 'email', 'DSR-2026-14');

		self::assertSame(self::SUBJECT, $export->getSubject());
		self::assertSame('email', $export->getSubjectType());
		self::assertSame('DSR-2026-14', $export->getRequestId());
		self::assertSame(SubjectExport::STATUS_PENDING, $export->getStatus());
		self::assertSame('handler', $export->getRequestedBy());

		// D-4: it runs as a background job, because walking every register for
		// one person is not something a request can wait for.
		self::assertCount(1, $this->queued);
		self::assertSame(SubjectExportJob::class, $this->queued[0][0]);
		self::assertSame(['uuid' => 'export-new'], $this->queued[0][1]);
	}//end testArticle20IsAnsweredWithoutADatabaseExport()

	public function testAssemblyMakesItReadyWithAnExpiryAndACount(): void {
		$row = $this->export(SubjectExport::STATUS_PENDING);
		$service = $this->service(row: $row);

		$assembled = $service->assemble('export-1');

		self::assertSame(SubjectExport::STATUS_READY, $assembled->getStatus());
		self::assertSame(3, $assembled->getObjectCount());
		self::assertNotNull($assembled->getReadyAt());
		// AN EXPORT WITH NO EXPIRY NEVER STOPS BEING REACHABLE, which is the
		// thing REQ-DSR-003 exists to prevent.
		self::assertNotNull($assembled->getExpiresAt());
		self::assertTrue($assembled->isDownloadableAt());
		self::assertNotSame('', (string)$assembled->getContentHash());
	}//end testAssemblyMakesItReadyWithAnExpiryAndACount()

	public function testTheFileDoesNotLiveForever(): void {
		$expired = $this->export(SubjectExport::STATUS_READY, new DateTime('2026-09-01T00:00:00+00:00'));
		$service = $this->service(row: $expired);

		$bytes = $service->download('export-1', new DateTime('2026-09-15T00:00:00+00:00'));

		// The link is used past the expiry, and it is refused.
		self::assertNull($bytes);
		self::assertFalse($expired->isDownloadableAt(new DateTime('2026-09-15T00:00:00+00:00')));
		// NOTHING WAS ASSEMBLED AND NOTHING WAS WRITTEN. The expiry is asked
		// before the work, so an expired link costs nothing and reveals nothing.
		self::assertSame([], $this->saved);
	}//end testTheFileDoesNotLiveForever()

	public function testALiveExportIsDeliveredAsMachineReadableBytes(): void {
		$live = $this->export(SubjectExport::STATUS_READY, new DateTime('+7 days'));
		$service = $this->service(row: $live);

		$bytes = $service->download('export-1');

		self::assertNotNull($bytes);
		$decoded = json_decode($bytes, true);
		self::assertIsArray($decoded, 'the export must be machine readable');
		self::assertSame(self::SUBJECT, $decoded['subject']);
		self::assertNotNull($live->getDeliveredAt());
	}//end testALiveExportIsDeliveredAsMachineReadableBytes()

	public function testAnExportStillAssemblingIsNotDownloadable(): void {
		$pending = $this->export(SubjectExport::STATUS_PENDING, new DateTime('+7 days'));
		$service = $this->service(row: $pending);

		// A link handed out before the job finished must not serve a
		// half-assembled answer.
		self::assertNull($service->download('export-1'));
	}//end testAnExportStillAssemblingIsNotDownloadable()

	public function testAFailedAssemblyIsRecordedAsFailedAndSaysWhy(): void {
		$row = $this->export(SubjectExport::STATUS_PENDING);
		$service = $this->service(row: $row, throwing: true);

		$assembled = $service->assemble('export-1');

		// Leaving it pending would make a request nobody can act on look like
		// one that is merely slow.
		self::assertSame(SubjectExport::STATUS_FAILED, $assembled->getStatus());
		self::assertStringContainsString('register unreadable', (string)$assembled->getError());
		self::assertFalse($assembled->isDownloadableAt());
	}//end testAFailedAssemblyIsRecordedAsFailedAndSaysWhy()

	public function testAnotherAccountsExportIsNotReachable(): void {
		$other = $this->export(SubjectExport::STATUS_READY, new DateTime('+7 days'), 'anja');
		$service = $this->service(row: $other, uid: 'bram');

		// Answered as absent, not forbidden: confirming the row exists confirms
		// somebody asked about this data subject.
		self::assertNull($service->load('export-1'));
		self::assertNull($service->download('export-1'));
	}//end testAnotherAccountsExportIsNotReachable()

	public function testAnAdministratorReachesIt(): void {
		$other = $this->export(SubjectExport::STATUS_READY, new DateTime('+7 days'), 'anja');
		$service = $this->service(row: $other, uid: 'root', admin: true);

		self::assertSame('export-1', $service->load('export-1')?->getUuid());
	}//end testAnAdministratorReachesIt()

	public function testAnUnknownExportIsRefused(): void {
		$service = $this->service(row: null);

		self::assertNull($service->load('nope'));
		self::assertNull($service->download('nope'));
	}//end testAnUnknownExportIsRefused()
}//end class
