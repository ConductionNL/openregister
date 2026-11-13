<?php

/**
 * A queued recompute runs the selection with the actor's update rights and logs the counts.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\CalculationRecomputeJob;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Calculation\RecomputeAccess;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Service\Operations\JobRunRecorderInterface;
use OCA\OpenRegister\Tests\Unit\Service\Calculation\Support\RecomputeHarness;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\BackgroundJob\CalculationRecomputeJob
 * @uses   \OCA\OpenRegister\Service\Calculation\CalculationRematerialiser
 * @uses   \OCA\OpenRegister\Service\Calculation\RecomputeAccess
 * @uses   \OCA\OpenRegister\Service\Calculation\RecomputeSelection
 * @uses   \OCA\OpenRegister\Service\Calculation\RecomputeResult
 * @uses   \OCA\OpenRegister\Command\RematerialiseRun
 */
final class CalculationRecomputeJobTest extends TestCase {
	use RecomputeHarness;

	/**
	 * The ids run with the actor's rights: a case the actor may not update fails, the rest is written and logged.
	 *
	 * @return void
	 */
	public function testTheSelectionRunsWithTheActorsRights(): void {
		$asked = [];
		$permissions = $this->createMock(PermissionCheckInterface::class);
		$permissions->method('hasPermission')->willReturnCallback(
			static function ($schema, string $action, ?string $userId = null, ?string $objectOwner = null) use (&$asked): bool {
				$asked[] = $userId;
				return $objectOwner !== 'owner-B';
			}
		);

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->expects($this->once())->method('find')->with(5, false, false)->willReturn($this->register());
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->expects($this->once())->method('find')->with(7, [], false, false)->willReturn($this->caseSchema());

		$logged = null;
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(static function (string $message, array $context) use (&$logged): void {
			$logged = $context['result'];
		});

		$job = new CalculationRecomputeJob(
			time: $this->createMock(ITimeFactory::class),
			recorder: $this->createMock(JobRunRecorderInterface::class),
			rematerialiser: $this->rematerialiser(),
			access: new RecomputeAccess(permissions: $permissions),
			registers: $registers,
			schemas: $schemas,
			logger: $logger
		);

		(new ReflectionMethod(CalculationRecomputeJob::class, 'runRecorded'))->invoke(
			$job,
			['register' => 5, 'schema' => 7, 'ids' => ['case-A', 'case-B'], 'filters' => [], 'dryRun' => false, 'actor' => 'jan']
		);

		$this->assertSame(['touched' => 1, 'unchanged' => 0, 'failed' => [['id' => 'case-B', 'reason' => 'forbidden']]], $logged);
		$this->assertSame(['case-A'], $this->savedUuids);
		$this->assertSame(['jan', 'jan'], $asked);
	}//end testTheSelectionRunsWithTheActorsRights()
}//end class
