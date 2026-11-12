<?php

/**
 * MigrateNotificationSubscriptionsToUserConfig refuses services it cannot use and survives a marker it cannot write.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\NotificationPreferencesInterface;
use OCA\OpenRegister\Repair\MigrateNotificationSubscriptionsToUserConfig;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * The service-resolution and marker branches the golden and unit tests leave.
 */
class MigrateNotificationSubscriptionsResolutionTest extends TestCase {

	private LoggerInterface&MockObject $logger;
	private IOutput&MockObject $output;

	/** @var list<string> */
	private array $info = [];

	/** @var list<string> */
	private array $warnings = [];

	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->output = $this->createMock(IOutput::class);
		$this->output->method('info')->willReturnCallback(function (string $m): void {
			$this->info[] = $m;
		});
		$this->output->method('warning')->willReturnCallback(function (string $m): void {
			$this->warnings[] = $m;
		});
	}

	private function db(array $rows): IDBConnection {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return $db;
	}

	/**
	 * @param array<string, mixed> $services Container id => service.
	 */
	private function runWith(array $services): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services) {
				if (array_key_exists($id, $services) === false) {
					throw new RuntimeException('no ' . $id);
				}
				return $services[$id];
			}
		);

		(new MigrateNotificationSubscriptionsToUserConfig($container, $this->logger))->run($this->output);
	}

	private function schemaMapper(): SchemaMapper {
		$schema = new Schema();
		$schema->setSlug('zaak');
		$schema->setConfiguration(['x-openregister-notifications' => ['created' => [], 'updated' => []]]);
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('find')->willReturn($schema);

		return $mapper;
	}

	public function testAPreferenceServiceOfTheWrongTypeSkipsTheMigrationWithoutMarkingItDone(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(false);
		$appConfig->expects($this->never())->method('setValueBool');

		$this->runWith(
			[
				IAppConfig::class => $appConfig,
				IDBConnection::class => $this->db([['user_id' => 'alice', 'schema_id' => 3]]),
				NotificationPreferencesInterface::class => new stdClass(),
				'OCA\\OpenRegister\\Db\\SchemaMapper' => $this->schemaMapper(),
			]
		);

		$this->assertSame(['[OpenRegister] Preference service / schema mapper unavailable — migration skipped.'], $this->warnings);
	}

	public function testAnAppConfigOfTheWrongTypeIsTreatedAsAbsentSoTheMigrationStillRuns(): void {
		$prefs = $this->createMock(NotificationPreferencesInterface::class);
		$prefs->expects($this->exactly(2))->method('storeOverride');

		$this->runWith(
			[
				IAppConfig::class => new stdClass(),
				IDBConnection::class => $this->db([['user_id' => 'alice', 'schema_id' => 3]]),
				NotificationPreferencesInterface::class => $prefs,
				'OCA\\OpenRegister\\Db\\SchemaMapper' => $this->schemaMapper(),
			]
		);

		$this->assertContains('[OpenRegister] Migrated 2 notification subscription override(s).', $this->info);
	}

	public function testAMarkerThatCannotBeWrittenIsLoggedAndDoesNotFailTheStep(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(false);
		$appConfig->expects($this->once())->method('setValueBool')
			->with('openregister', 'notification_subscription_migration_done', true)
			->willThrowException(new RuntimeException('read-only config'));
		$this->logger->expects($this->once())->method('debug')
			->with('[OpenRegister] Could not persist notification-migration marker', $this->arrayHasKey('exception'));

		$this->runWith([IAppConfig::class => $appConfig, IDBConnection::class => $this->db([])]);

		$this->assertContains('[OpenRegister] No notification subscriptions to migrate.', $this->info);
	}
}
