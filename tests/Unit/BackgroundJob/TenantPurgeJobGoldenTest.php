<?php

/**
 * Characterisation (golden) test for TenantPurgeJob::run().
 *
 * Pins, in order, every log line and every delete the purge performs for each
 * branch: the query failing, a row not in the purgeable state, no
 * deprovisionedAt, inside retention, no uuid (null and empty), a failing
 * delete, and a purge. Retention boundaries are relative to now (days, not a
 * pinned clock). Written against the code before run() was split; it must
 * keep passing unchanged afterwards.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTime;
use Exception;
use OCA\OpenRegister\BackgroundJob\TenantPurgeJob;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\TenantUsageMapper;
use OCA\OpenRegister\Service\TenantLifecycleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Golden outcomes of the tenant purge, allowed and refused.
 */
class TenantPurgeJobGoldenTest extends TestCase {

	/**
	 * Ordered log of log lines and deletes.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * An organisation row.
	 *
	 * @param string|null   $uuid   The uuid.
	 * @param string        $status The status.
	 * @param DateTime|null $at     The deprovisionedAt.
	 *
	 * @return Organisation The row.
	 */
	private function org(?string $uuid, string $status, ?DateTime $at): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid($uuid);
		$organisation->setStatus($status);
		$organisation->setDeprovisionedAt($at);

		return $organisation;
	}//end org()

	/**
	 * A date some days ago.
	 *
	 * @param int $days Days ago.
	 *
	 * @return DateTime The date.
	 */
	private function daysAgo(int $days): DateTime {
		return (new DateTime())->modify('-'.$days.' days');
	}//end daysAgo()

	/**
	 * Run the purge over scripted rows.
	 *
	 * @param array<int, Organisation>|Exception $rows        What the tenant read returns or throws.
	 * @param string                             $retention   The configured retention days.
	 * @param array<int, string>                 $failUsageOf Uuids whose usage delete throws.
	 *
	 * @return array<int, string> The call log.
	 */
	private function runPurge(array|Exception $rows, string $retention='30', array $failUsageOf=[]): array {
		$this->calls = [];

		$mapper = $this->createMock(OrganisationMapper::class);
		if ($rows instanceof Exception) {
			$mapper->method('findLocalTenants')->willThrowException($rows);
		} else {
			$mapper->method('findLocalTenants')->willReturnCallback(
				function (int $limit, int $offset, ?array $filters) use ($rows): array {
					$this->calls[] = 'findLocalTenants '.$limit.' '.$offset.' '.json_encode($filters);
					return $rows;
				}
			);
		}

		$mapper->method('delete')->willReturnCallback(
			function (Organisation $org): Organisation {
				$this->calls[] = 'delete org '.$org->getUuid();
				return $org;
			}
		);

		$usage = $this->createMock(TenantUsageMapper::class);
		$usage->method('deleteByOrganisation')->willReturnCallback(
			function (string $organisationUuid) use ($failUsageOf): int {
				$this->calls[] = 'delete usage '.$organisationUuid;
				if (in_array($organisationUuid, $failUsageOf, true) === true) {
					throw new Exception('usage locked');
				}

				return 1;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default) use ($retention): string {
				$this->calls[] = 'config '.$app.'.'.$key.' default='.$default;
				return $retention;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context=[]) use ($level): void {
					unset($context['deprovisionedAt']);
					$this->calls[] = $level.' '.$message.' '.json_encode($context);
				}
			);
		}

		$job = new TenantPurgeJob(new \OCA\OpenRegister\BackgroundJob\Support\ScheduledJobRuntime($this->createMock(ITimeFactory::class), $appConfig, $logger), new \OCA\OpenRegister\BackgroundJob\Support\TenantRecords($mapper, $usage, $this->createMock(\OCA\OpenRegister\Service\TenantLifecycleServiceInterface::class)));
		$run = new ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);

		return $this->calls;
	}//end runPurge()

	/**
	 * A failing tenant read stops the run.
	 *
	 * @return void
	 */
	public function testQueryFailureStops(): void {
		self::assertSame(
			[
				'info [TenantPurgeJob] Starting purge check []',
				'config openregister.tenantRetentionDays default=90',
				'error [TenantPurgeJob] Failed to query archived organisations {"error":"db gone"}',
			],
			$this->runPurge(new Exception('db gone'))
		);
	}//end testQueryFailureStops()

	/**
	 * Every refusal branch, a failed purge, and a purge, in order.
	 *
	 * @return void
	 */
	public function testMatrix(): void {
		$archived = TenantLifecycleService::PURGEABLE_STATUS;
		$rows     = [
			$this->org('active-1', 'active', $this->daysAgo(400)),
			$this->org('no-date', $archived, null),
			$this->org('recent', $archived, $this->daysAgo(10)),
			$this->org(null, $archived, $this->daysAgo(400)),
			$this->org('', $archived, $this->daysAgo(400)),
			$this->org('locked', $archived, $this->daysAgo(400)),
			$this->org('old', $archived, $this->daysAgo(31)),
		];

		self::assertSame(
			[
				'info [TenantPurgeJob] Starting purge check []',
				'config openregister.tenantRetentionDays default=90',
				'findLocalTenants 50 0 {"status":"'.$archived.'"}',
				'warning [TenantPurgeJob] Skipped an organisation that is not in the purgeable state {"uuid":"active-1","status":"active"}',
				'error [TenantPurgeJob] Skipped an archived organisation without a uuid []',
				'error [TenantPurgeJob] Skipped an archived organisation without a uuid []',
				'delete usage locked',
				'error [TenantPurgeJob] Failed to purge organisation {"uuid":"locked","error":"usage locked"}',
				'delete usage old',
				'delete org old',
				'info [TenantPurgeJob] Permanently deleted archived organisation {"uuid":"old"}',
				'info [TenantPurgeJob] Completed, purged 1 organisations []',
			],
			$this->runPurge($rows, '30', ['locked'])
		);
	}//end testMatrix()

	/**
	 * Mutation check: a longer retention refuses what a shorter one allows.
	 *
	 * @return void
	 */
	public function testRetentionDecides(): void {
		$rows = [$this->org('o', TenantLifecycleService::PURGEABLE_STATUS, $this->daysAgo(40))];

		self::assertNotContains('delete org o', $this->runPurge($rows, '60'));
		self::assertContains('delete org o', $this->runPurge($rows, '30'));
		// A non-numeric setting reads as zero days: everything archived is past retention.
		self::assertContains('info [TenantPurgeJob] Completed, purged 1 organisations []', $this->runPurge($rows, 'abc'));
	}//end testRetentionDecides()
}//end class
