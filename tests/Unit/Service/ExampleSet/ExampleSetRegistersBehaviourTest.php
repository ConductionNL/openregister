<?php

/**
 * Unit test for ExampleSetRegisters: whether a register is there or behind,
 * what an import or mock import reports and records, and removing an import.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ShippedRegister;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Configuration\AppImportJobRecorder;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRegisters;
use OCA\OpenRegister\Service\RegisterDescriptorService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRegisters
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\AuditTrail\AuditedObject
 */
class ExampleSetRegistersBehaviourTest extends TestCase {

	/**
	 * The register mapper.
	 *
	 * @var RegisterMapperInterface&MockObject
	 */
	private RegisterMapperInterface&MockObject $mapper;

	/**
	 * The descriptor service.
	 *
	 * @var RegisterDescriptorService&MockObject
	 */
	private RegisterDescriptorService&MockObject $descriptors;

	/**
	 * The audit trail.
	 *
	 * @var AuditTrailMapperInterface&MockObject
	 */
	private AuditTrailMapperInterface&MockObject $audit;

	/**
	 * The object operations.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface&MockObject $objects;

	/**
	 * The import job recorder.
	 *
	 * @var AppImportJobRecorder&MockObject
	 */
	private AppImportJobRecorder&MockObject $jobs;

	/**
	 * Services the container cannot hand out.
	 *
	 * @var array<int, string>
	 */
	private array $missing = [];

	/**
	 * The class under test.
	 *
	 * @var ExampleSetRegisters
	 */
	private ExampleSetRegisters $subject;

	/**
	 * A container over the mocked services.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper      = $this->createMock(RegisterMapperInterface::class);
		$this->descriptors = $this->createMock(RegisterDescriptorService::class);
		$this->audit       = $this->createMock(AuditTrailMapperInterface::class);
		$this->objects     = $this->createMock(ObjectOperationsInterface::class);
		$this->jobs        = $this->createMock(AppImportJobRecorder::class);
		$map               = [
			RegisterMapperInterface::class   => $this->mapper,
			RegisterDescriptorService::class => $this->descriptors,
			AuditTrailMapperInterface::class => $this->audit,
			ObjectOperationsInterface::class => $this->objects,
			AppImportJobRecorder::class      => $this->jobs,
		];
		$container         = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($map): object {
				if (in_array($id, $this->missing, true) === true) {
					throw new RuntimeException($id.' is not there');
				}

				return $map[$id];
			}
		);
		$this->subject = new ExampleSetRegisters(container: $container);
	}//end setUp()

	/**
	 * A register is there when the mapper finds it without RBAC or multitenancy, and not when it throws.
	 *
	 * @return void
	 */
	public function testExistsAsksTheMapperUnscoped(): void {
		$this->mapper->expects($this->exactly(2))->method('find')->with('cases', false, false)
			->willReturnOnConsecutiveCalls(new Register(), $this->throwException(new RuntimeException('not found')));

		$this->assertTrue($this->subject->exists(slug: 'cases'));
		$this->assertFalse($this->subject->exists(slug: 'cases'));
	}//end testExistsAsksTheMapperUnscoped()

	/**
	 * An import reimports OpenRegister's descriptor via the example set and records its job.
	 *
	 * @return void
	 */
	public function testAnImportRecordsItsJob(): void {
		$this->descriptors->expects($this->once())->method('reimportShipped')->with(new ShippedRegister('openregister', 'cases'), 'example set install')
			->willReturn(['outcome' => 'imported', 'importJobId' => 'job-1', 'importAppId' => 'alpha']);

		$this->assertSame(['error' => '', 'jobs' => [['appId' => 'alpha', 'jobId' => 'job-1']]], $this->subject->import(slug: 'cases'));
	}//end testAnImportRecordsItsJob()

	/**
	 * A mock import without an app id records the job under OpenRegister, and one without a job records none.
	 *
	 * @return void
	 */
	public function testAMockImportRecordsUnderOpenRegister(): void {
		$this->descriptors->expects($this->exactly(2))->method('reimportMock')->with('openregister', 'cases')
			->willReturnOnConsecutiveCalls(['importJobId' => 'job-2'], ['importJobId' => '']);

		$this->assertSame(['error' => '', 'jobs' => [['appId' => 'openregister', 'jobId' => 'job-2']]], $this->subject->importMock(slug: 'cases'));
		$this->assertSame(['error' => '', 'jobs' => []], $this->subject->importMock(slug: 'cases'));
	}//end testAMockImportRecordsUnderOpenRegister()

	/**
	 * A failed import reports its reason, or a default one; a throwing import reports the message and no jobs.
	 *
	 * @return void
	 */
	public function testAFailedImportReportsWhy(): void {
		$this->descriptors->method('reimportShipped')->willReturnOnConsecutiveCalls(
			['outcome' => 'failed', 'reason' => 'schema conflict', 'importJobId' => 'job-3'],
			['outcome' => 'failed'],
			$this->throwException(new RuntimeException('descriptor unreadable'))
		);

		$this->assertSame(['error' => 'schema conflict', 'jobs' => [['appId' => 'openregister', 'jobId' => 'job-3']]], $this->subject->import(slug: 'x'));
		$this->assertSame(['error' => 'the import failed', 'jobs' => []], $this->subject->import(slug: 'x'));
		$this->assertSame(['error' => 'descriptor unreadable', 'jobs' => []], $this->subject->import(slug: 'x'));
	}//end testAFailedImportReportsWhy()

	/**
	 * Whether a register is behind comes from the descriptor service; a failure reads as current.
	 *
	 * @return void
	 */
	public function testIsBehindAsksTheDescriptorService(): void {
		$this->descriptors->expects($this->exactly(2))->method('isShippedBehind')->with(new ShippedRegister('openregister', 'cases'), true)
			->willReturnOnConsecutiveCalls(true, $this->throwException(new RuntimeException('x')));

		$this->assertTrue($this->subject->isBehind(slug: 'cases', mock: true));
		$this->assertFalse($this->subject->isBehind(slug: 'cases', mock: true));
	}//end testIsBehindAsksTheDescriptorService()

	/**
	 * Removing an import deletes every object it made, unscoped, and then forgets the job.
	 *
	 * @return void
	 */
	public function testRemovingAnImportDeletesItsObjectsAndForgetsTheJob(): void {
		$this->audit->method('objectUuidsByImportJobId')->with('job-1')->willReturn(['u1', 'u2']);
		$deleted = [];
		$this->objects->method('deleteObject')->willReturnCallback(
			function (string $uuid, mixed $register=null, mixed $schema=null, bool $_rbac=true, bool $_multitenancy=true) use (&$deleted): bool {
				$deleted[] = [$uuid, $_rbac, $_multitenancy];
				return true;
			}
		);
		$this->jobs->expects($this->once())->method('forget')->with('alpha', 'job-1');

		$this->assertTrue($this->subject->removeImport(appId: 'alpha', jobId: 'job-1'));
		$this->assertSame([['u1', false, false], ['u2', false, false]], $deleted);
	}//end testRemovingAnImportDeletesItsObjectsAndForgetsTheJob()

	/**
	 * An object that cannot be deleted keeps the job, and the other objects are still deleted.
	 *
	 * @return void
	 */
	public function testAnUndeletableObjectKeepsTheJob(): void {
		$this->audit->method('objectUuidsByImportJobId')->willReturn(['u1', 'u2']);
		$this->objects->expects($this->exactly(2))->method('deleteObject')
			->willReturnOnConsecutiveCalls($this->throwException(new RuntimeException('locked')), true);
		$this->jobs->expects($this->never())->method('forget');

		$this->assertFalse($this->subject->removeImport(appId: 'alpha', jobId: 'job-1'));
	}//end testAnUndeletableObjectKeepsTheJob()

	/**
	 * Without the audit trail nothing is deleted and the removal fails.
	 *
	 * @return void
	 */
	public function testWithoutTheAuditTrailNothingIsRemoved(): void {
		$this->missing = [AuditTrailMapperInterface::class];
		$this->objects->expects($this->never())->method('deleteObject');
		$this->jobs->expects($this->never())->method('forget');

		$this->assertFalse($this->subject->removeImport(appId: 'alpha', jobId: 'job-1'));
	}//end testWithoutTheAuditTrailNothingIsRemoved()
}//end class
