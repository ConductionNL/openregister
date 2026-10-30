<?php

/**
 * The real register import and removal of an example set, over doubles of the services behind them.
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
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Kernel\Contracts\AuditTrailMapperInterface;
use OCA\OpenRegister\Service\Configuration\AppImportJobRecorder;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRegisters;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\RegisterDescriptorService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRegisters
 */
class ExampleSetRegistersTest extends TestCase {
	/**
	 * A registers adapter over a container that hands out the given services.
	 *
	 * @param array<class-string, object> $services The services by class.
	 *
	 * @return ExampleSetRegisters
	 */
	private function registersOver(array $services): ExampleSetRegisters {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => ($services[$id] ?? throw new RuntimeException('no service '.$id))
		);

		return new ExampleSetRegisters(container: $container);
	}

	/**
	 * The job is the one the import reports under the id it ran under, and the instance's other jobs are never read:
	 * a job another process records meanwhile cannot be taken for the set's.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
	 */
	public function testTheImportClaimsExactlyTheJobItReports(): void {
		$descriptors = $this->createMock(RegisterDescriptorService::class);
		$descriptors->expects($this->once())->method('reimport')->willReturn(
			['outcome' => 'imported', 'importJobId' => 'job-mine', 'importAppId' => 'openregister.vocabulary']
		);
		$recorder = $this->createMock(AppImportJobRecorder::class);
		$recorder->expects($this->never())->method('jobs');

		$result = $this->registersOver([RegisterDescriptorService::class => $descriptors, AppImportJobRecorder::class => $recorder])->import(slug: 'brp');

		$this->assertSame(['error' => '', 'jobs' => [['appId' => 'openregister.vocabulary', 'jobId' => 'job-mine']]], $result);
	}

	/**
	 * An import that recorded no job claims none, and a failed one says why.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
	 */
	public function testAnImportWithoutAJobClaimsNothingAndAFailureIsNamed(): void {
		$descriptors = $this->createMock(RegisterDescriptorService::class);
		$descriptors->method('reimport')->willReturnOnConsecutiveCalls(
			['outcome' => 'imported'],
			['outcome' => 'failed', 'reason' => 'no descriptor']
		);
		$registers = $this->registersOver([RegisterDescriptorService::class => $descriptors]);

		$this->assertSame(['error' => '', 'jobs' => []], $registers->import(slug: 'brp'));
		$this->assertSame(['error' => 'no descriptor', 'jobs' => []], $registers->import(slug: 'kvk'));
	}

	/**
	 * Remove soft-deletes the objects of the recorded job and forgets it only when all of them went.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
	 */
	public function testRemoveImportForgetsTheJobOnlyWhenEveryObjectWent(): void {
		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('objectUuidsByImportJobId')->willReturn(['u1', 'u2']);
		$refuse  = true;
		$objects = $this->createMock(ObjectService::class);
		$objects->method('deleteObject')->willReturnCallback(static function (string $uuid) use (&$refuse): bool {
			if ($uuid === 'u2' && $refuse === true) {
				throw new RuntimeException('refused');
			}

			return true;
		});
		$recorder = $this->createMock(AppImportJobRecorder::class);
		$recorder->expects($this->once())->method('forget')->with('openregister.vocabulary', 'job-mine');
		$registers = $this->registersOver([AuditTrailMapperInterface::class => $audit, ObjectService::class => $objects, AppImportJobRecorder::class => $recorder]);

		$this->assertFalse($registers->removeImport(appId: 'openregister.vocabulary', jobId: 'job-mine'), 'a refused object leaves the job in place');
		$refuse = false;
		$this->assertTrue($registers->removeImport(appId: 'openregister.vocabulary', jobId: 'job-mine'));
	}
}
