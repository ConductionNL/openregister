<?php

/**
 * AppHost scheduling — regression tests for the ObjectService I/O seams.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Scheduling;

use JsonSerializable;
use OCA\OpenRegister\AppHost\Scheduling\CronScheduleEvaluator;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleActionAllowList;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleManifestLoader;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleReconciler;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/FakeSlugResolver.php';

/**
 * Exposes the protected loadVirtualApplications() seam for direct testing.
 */
class IoProbeReconciler extends ScheduleReconciler {
	/**
	 * Public passthrough to the protected seam under test.
	 *
	 * @return array<int, array<string, mixed>> The normalised application rows.
	 */
	public function callLoadVirtualApplications(): array {
		return $this->loadVirtualApplications();
	}//end callLoadVirtualApplications()

	/**
	 * Public passthrough to the managed-job read seam.
	 *
	 * @return array<string, array<string, mixed>>|null Managed jobs, or null.
	 */
	public function callLoadManagedJobs(): ?array {
		return $this->loadManagedJobs();
	}//end callLoadManagedJobs()
}//end class

/**
 * Regression tests for the real ObjectService I/O seams (three live-found bugs).
 */
class ScheduleReconcilerIoTest extends TestCase {
	/**
	 * An allow-list bound to a fake instance that has the connector installed.
	 *
	 * `openconnector` rather than `integriq`, because these fixtures assert the
	 * literal `OCA\OpenConnector\…` jobClass and this keeps that assertion
	 * about the RECONCILER rather than about which id the resolver picked —
	 * that choice is covered in ScheduleActionAllowListTest.
	 *
	 * @return ScheduleActionAllowList The configured allow-list.
	 */
	private function allowList(): ScheduleActionAllowList {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $id): bool => ($id === 'openconnector')
		);

		return new ScheduleActionAllowList($appManager);
	}

	/**
	 * Build an IoProbeReconciler around a given ObjectService mock.
	 *
	 * @param ObjectService $objectService The mocked OR facade.
	 *
	 * @return IoProbeReconciler
	 */
	private function makeReconciler(ObjectService $objectService, ?RegisterSlugResolverInterface $slugResolver=null): IoProbeReconciler {
		$loader = $this->createMock(originalClassName: ScheduleManifestLoader::class);
		$userManager = $this->createMock(originalClassName: IUserManager::class);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		return new IoProbeReconciler(
			$objectService,
			$loader,
			new CronScheduleEvaluator(),
			$this->allowList(),
			$userManager,
			$logger,
			($slugResolver ?? new FakeSlugResolver(['integriq', 'buildiq']))
		);
	}//end makeReconciler()

	/**
	 * findAll rows that are JsonSerializable entities are normalised to arrays.
	 *
	 * @return void
	 */
	public function testEntityRowsAreNormalisedToArrays(): void {
		$entity = new class implements JsonSerializable {
			/**
			 * Serialise a fixture application row.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return [
					'slug' => 'demo',
					'@self' => ['uuid' => '00000000-0000-0000-0000-000000000000', 'owner' => 'admin'],
				];
			}//end jsonSerialize()
		};

		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('findAll')->willReturn([$entity]);

		$rows = $this->makeReconciler(objectService: $objectService)->callLoadVirtualApplications();

		$this->assertCount(1, $rows);
		$this->assertIsArray($rows[0]);
		$this->assertSame('demo', $rows[0]['slug']);
		$this->assertSame('admin', $rows[0]['@self']['owner']);
	}//end testEntityRowsAreNormalisedToArrays()

	/**
	 * The sweep passes an explicit limit and only _rbac:false (never both-false).
	 *
	 * @return void
	 */
	public function testSweepPassesLimitAndRbacFalseOnly(): void {
		$captured = [];

		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use (&$captured): array {
				$captured = [
					'config' => $config,
					'rbac' => $_rbac,
					'multitenancy' => $_multitenancy,
				];
				return [];
			}
		);

		$this->makeReconciler(objectService: $objectService)->callLoadVirtualApplications();

		$this->assertArrayHasKey('limit', $captured['config']);
		$this->assertGreaterThan(25, $captured['config']['limit']);
		$this->assertFalse($captured['rbac']);
		$this->assertTrue($captured['multitenancy']);
	}//end testSweepPassesLimitAndRbacFalseOnly()
	/**
	 * The read uses the slug this instance carries, not a compiled-in literal.
	 *
	 * The unmigrated case: the register row still says `openbuild`, so that is
	 * what must reach ObjectService. A reconciler pinned to the canonical
	 * `buildiq` would pass a slug no register carries, get an empty set back,
	 * and report zero virtual applications, which is exactly what an instance
	 * with no virtual applications reports.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function testAnUnmigratedInstanceIsReadWithItsOldSlug(): void {
		$seen = null;
		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			/**
			 * @param array<string, mixed> $config Read config.
			 *
			 * @return array<int, mixed>
			 */
			function (array $config) use (&$seen): array {
				$seen = ($config['filters']['register'] ?? null);
				return [];
			}
		);

		$reconciler = $this->makeReconciler(
			objectService: $objectService,
			slugResolver: new FakeSlugResolver(['openbuild'])
		);
		$reconciler->callLoadVirtualApplications();

		$this->assertSame('openbuild', $seen, 'The read must name the slug this instance actually carries.');
	}//end testAnUnmigratedInstanceIsReadWithItsOldSlug()

	/**
	 * A migrated instance is read with the new slug.
	 *
	 * The other half of the estate, and the half a literal `openbuild` breaks.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function testAMigratedInstanceIsReadWithItsNewSlug(): void {
		$seen = null;
		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			/**
			 * @param array<string, mixed> $config Read config.
			 *
			 * @return array<int, mixed>
			 */
			function (array $config) use (&$seen): array {
				$seen = ($config['filters']['register'] ?? null);
				return [];
			}
		);

		$reconciler = $this->makeReconciler(
			objectService: $objectService,
			slugResolver: new FakeSlugResolver(['buildiq'])
		);
		$reconciler->callLoadVirtualApplications();

		$this->assertSame('buildiq', $seen);
	}//end testAMigratedInstanceIsReadWithItsNewSlug()

	/**
	 * An absent register means no read at all, not an empty read.
	 *
	 * The distinction is the whole point. An empty read returns `[]`, and `[]`
	 * is indistinguishable from a register that genuinely holds nothing. Not
	 * reading is what lets the `info` line say the register is missing rather
	 * than that there is nothing to schedule.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function testAnAbsentRegisterIsNotReadAtAll(): void {
		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->expects($this->never())->method('findAll');

		$reconciler = $this->makeReconciler(
			objectService: $objectService,
			slugResolver: new FakeSlugResolver([])
		);

		$this->assertSame([], $reconciler->callLoadVirtualApplications());
		$this->assertNull($reconciler->callLoadManagedJobs());
	}//end testAnAbsentRegisterIsNotReadAtAll()

	/**
	 * The job register is resolved too, not only the application register.
	 *
	 * Two registers were pinned in this class and they are renamed by two
	 * different apps' repair steps, so an instance can be migrated for one and
	 * not the other. Fixing one and not the other leaves the reconciler just as
	 * inert on half the cases it was inert on before.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function testTheJobRegisterIsResolvedIndependently(): void {
		$seen = null;
		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			/**
			 * @param array<string, mixed> $config Read config.
			 *
			 * @return array<int, mixed>
			 */
			function (array $config) use (&$seen): array {
				$seen = ($config['filters']['register'] ?? null);
				return [];
			}
		);

		// Migrated for buildiq, NOT migrated for integriq.
		$reconciler = $this->makeReconciler(
			objectService: $objectService,
			slugResolver: new FakeSlugResolver(['buildiq', 'openconnector'])
		);
		$reconciler->callLoadManagedJobs();

		$this->assertSame('openconnector', $seen);
	}//end testTheJobRegisterIsResolvedIndependently()
}//end class
