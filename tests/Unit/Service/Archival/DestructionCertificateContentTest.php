<?php

declare(strict_types=1);

/**
 * Destruction-certificate content tests (openregister#393).
 *
 * Proves the Archiefwet compliance artefact — the *verklaring van vernietiging* —
 * is actually PRODUCED and actually POPULATED when an archivist approves a
 * destruction list through the /api/archival route.
 *
 * These tests exist because the capability was previously broken in a way that a
 * "did it return a non-empty object?" assertion would have happily passed:
 *
 *  - D1: `DestructionService::approveList()` queued `DestructionExecutionJob` under
 *        the key `destructionList`, while the job reads `destructionListUuid` and
 *        returns early when it is missing. The job therefore no-opped on every run —
 *        nothing was destroyed and no certificate was ever written.
 *  - D2: `approveList()` recorded approvals under `approvedBy`, while the certificate
 *        generator projects `array_column($approvals, 'userId')`. Even once the job
 *        ran, the certificate's approver list — the legal record of WHO authorised the
 *        destruction — came out EMPTY.
 *
 * So the assertions below are deliberately about CONTENT, not shape: an empty
 * certificate is the same bug wearing a different hat.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\Service\Archival;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\DestructionService;
use OCA\OpenRegister\Service\Archival\LegalHoldService;
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * End-to-end content test for the destruction certificate.
 *
 * @covers \OCA\OpenRegister\Service\Archival\DestructionService::approveList
 * @covers \OCA\OpenRegister\Service\RetentionService::generateDestructionCertificate
 */
class DestructionCertificateContentTest extends TestCase {
	private DestructionService $destructionService;
	private RetentionService $retentionService;
	private IJobList $jobList;

	protected function setUp(): void {
		parent::setUp();

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('archivaris-1');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = ''): string {
					return $default;
				}
			);

		$this->jobList = $this->createMock(IJobList::class);

		$objectMapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['update'])
			->getMock();

		$this->destructionService = new DestructionService(
			$objectMapper,
			$this->createMock(LegalHoldService::class),
			$appConfig,
			$this->jobList,
			$userSession,
			$this->createMock(LoggerInterface::class)
		);

		$this->retentionService = new RetentionService(
			$objectMapper,
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(ObjectRetentionHandler::class),
			$appConfig,
			$userSession,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IDBConnection::class)
		);
	}

	/**
	 * A destruction list approved via the archival route produces a certificate whose
	 * content is REAL: it names the approving archivist, counts what was destroyed,
	 * groups by schema + selectielijst category, cites the selectielijst source, and
	 * carries the Archiefwet compliance statement.
	 */
	public function testApprovedListProducesAFullyPopulatedCertificate(): void {
		$list = [
			'uuid' => 'list-uuid-42',
			'status' => DestructionService::STATUS_IN_REVIEW,
			'approvals' => [],
			'objects' => [
				[
					'uuid' => 'obj-1',
					'schema' => 5,
					'classification' => 'B1',
					'selectielijstBron' => 'Selectielijst gemeenten 2020',
				],
				[
					'uuid' => 'obj-2',
					'schema' => 5,
					'classification' => 'B1',
					'selectielijstBron' => 'Selectielijst gemeenten 2020',
				],
				[
					'uuid' => 'obj-3',
					'schema' => 8,
					'classification' => 'A1',
					'selectielijstBron' => 'Selectielijst gemeenten 2020',
				],
			],
		];

		// 1. The archivist approves through the route the ArchivalController drives.
		$approved = $this->destructionService->approveList(
			$list,
			'approve_all',
			[],
			[],
			false,
			'list-uuid-42'
		);

		$this->assertSame(DestructionService::STATUS_APPROVED, $approved['status']);

		// 2. The execution job destroys the objects and generates the certificate.
		//    This is the exact call DestructionExecutionJob makes (:212).
		$certificate = $this->retentionService->generateDestructionCertificate(
			$approved,
			3,
			'2026-07-14T12:00:00+00:00'
		);

		// 3. The artefact must be REAL and POPULATED — not merely non-empty.
		$this->assertSame('verklaring_van_vernietiging', $certificate['type']);
		$this->assertSame('2026-07-14T12:00:00+00:00', $certificate['destructionDate']);
		$this->assertSame('list-uuid-42', $certificate['destructionListUuid']);
		$this->assertSame(3, $certificate['totalDestroyed']);
		$this->assertTrue($certificate['immutable']);
		$this->assertStringContainsString('Archiefwet 1995', $certificate['complianceStatement']);

		// The legal core: WHO authorised this destruction. Empty here == the bug.
		$this->assertNotEmpty(
			$certificate['approvedBy'],
			'A verklaring van vernietiging with no approver is legally worthless.'
		);
		$this->assertSame(['archivaris-1'], $certificate['approvedBy']);

		// Provenance: what was destroyed, under which selectielijst category.
		$this->assertNotEmpty($certificate['groupedBySchema']);
		$this->assertEqualsCanonicalizing(
			[
				['schema' => 5, 'classification' => 'B1', 'count' => 2],
				['schema' => 8, 'classification' => 'A1', 'count' => 1],
			],
			$certificate['groupedBySchema']
		);

		$this->assertSame(['Selectielijst gemeenten 2020'], $certificate['selectielijstBron']);
	}

	/**
	 * Guards the D2 regression directly: had approveList kept writing `approvedBy`
	 * instead of the canonical `userId`, the certificate would still be returned as a
	 * populated-looking array — but with an EMPTY approver list.
	 */
	public function testCertificateApproverListIsNotSilentlyEmpty(): void {
		$approved = $this->destructionService->approveList(
			[
				'uuid' => 'list-uuid-7',
				'status' => DestructionService::STATUS_IN_REVIEW,
				'approvals' => [],
				'objects' => [['uuid' => 'obj-1', 'schema' => 1, 'classification' => 'V1']],
			],
			'approve_all',
			[],
			[],
			false,
			'list-uuid-7'
		);

		$certificate = $this->retentionService->generateDestructionCertificate(
			$approved,
			1,
			'2026-07-14T12:00:00+00:00'
		);

		$this->assertCount(1, $certificate['approvedBy']);
		$this->assertNotContains(null, $certificate['approvedBy']);
		$this->assertNotContains('', $certificate['approvedBy']);
	}
}
