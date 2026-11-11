<?php

/**
 * Characterisation (golden) test for MigrateNotificationSubscriptionsToUserConfig::run().
 *
 * Pins the ordered output lines, override writes and marker writes for: already
 * done, table unavailable, no rows, services unavailable, and a run over rows
 * with no user, no schema, an unknown schema, a non-schema result, a schema
 * without the annotation and schemas with it (slug and id fallback). Written
 * against the code before run() was split; it must keep passing unchanged.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
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

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\NotificationPreferencesInterface;
use OCA\OpenRegister\Kernel\Contracts\PreferenceWrite;
use OCA\OpenRegister\Repair\MigrateNotificationSubscriptionsToUserConfig;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Golden outcomes of the notification subscription migration.
 */
class MigrateNotificationSubscriptionsToUserConfigGoldenTest extends TestCase {

	/**
	 * Ordered call log.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * A schema with an id, slug and configuration.
	 *
	 * @param int                  $id     The id.
	 * @param string|null          $slug   The slug.
	 * @param array<string, mixed> $config The configuration.
	 *
	 * @return Schema The schema.
	 */
	private function schema(int $id, ?string $slug, array $config): Schema {
		$schema = new Schema();
		$prop   = (new ReflectionClass($schema))->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($schema, $id);
		$schema->setSlug($slug);
		$schema->setConfiguration($config);

		return $schema;
	}//end schema()

	/**
	 * Run the step over a scripted container.
	 *
	 * @param array<string, mixed> $services Container entries; absent ones throw.
	 * @param mixed                $rows     Rows the query returns, or a Throwable it throws.
	 * @param bool                 $done     The marker value.
	 *
	 * @return array<int, string> The call log.
	 */
	private function runStep(array $services, mixed $rows=[], bool $done=false): array {
		$this->calls = [];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(
			function (string $app, string $key, bool $default) use ($done): bool {
				$this->calls[] = 'getBool '.$app.'.'.$key;
				return $done;
			}
		);
		$appConfig->method('setValueBool')->willReturnCallback(
			function (string $app, string $key, bool $value): bool {
				$this->calls[] = 'setBool '.$app.'.'.$key.'='.var_export($value, true);
				return true;
			}
		);

		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturnCallback(
			function () use ($rows): mixed {
				if ($rows instanceof \Throwable) {
					throw $rows;
				}

				return $rows;
			}
		);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$prefs = $this->createMock(NotificationPreferencesInterface::class);
		$prefs->method('storeOverride')->willReturnCallback(
			function (PreferenceWrite $write): void {
				$this->calls[] = 'override '.$write->party.' '.$write->address->schemaSlug.' '.$write->address->notificationKey.' '.json_encode($write->value);
			}
		);

		$known = [
			IAppConfig::class                       => $appConfig,
			IDBConnection::class                    => $db,
			NotificationPreferencesInterface::class => $prefs,
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($services, $known): mixed {
				if (array_key_exists($id, $services) === false) {
					throw new RuntimeException('not found: '.$id);
				}

				return ($services[$id] ?? $known[$id] ?? null);
			}
		);

		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->calls[] = $level.' '.$message;
				}
			);
		}

		(new MigrateNotificationSubscriptionsToUserConfig($container, $this->createMock(LoggerInterface::class)))->run($output);

		return $this->calls;
	}//end runStep()

	/**
	 * Every early exit.
	 *
	 * @return void
	 */
	public function testEarlyExits(): void {
		$start = 'info [OpenRegister] Migrating legacy notification subscriptions to user-config...';
		$all   = [IAppConfig::class => null, IDBConnection::class => null];

		self::assertSame(
			[$start, 'getBool openregister.notification_subscription_migration_done', 'info [OpenRegister] Notification subscription migration already completed — skipping.'],
			$this->runStep($all, [], true)
		);
		self::assertSame(
			[$start, 'info [OpenRegister] Subscription table unavailable — migration skipped (normal on first install).'],
			$this->runStep([])
		);
		self::assertSame(
			[$start, 'getBool openregister.notification_subscription_migration_done', 'info [OpenRegister] Subscription table unavailable — migration skipped (normal on first install).'],
			$this->runStep($all, new RuntimeException('no table'))
		);
		self::assertSame(
			[
				$start,
				'getBool openregister.notification_subscription_migration_done',
				'info [OpenRegister] No notification subscriptions to migrate.',
				'setBool openregister.notification_subscription_migration_done=true',
			],
			$this->runStep($all, [])
		);
		self::assertSame(
			[$start, 'info [OpenRegister] No notification subscriptions to migrate.'],
			$this->runStep([IDBConnection::class => null], [])
		);
		self::assertSame(
			[$start, 'warning [OpenRegister] Preference service / schema mapper unavailable — migration skipped.'],
			$this->runStep([IDBConnection::class => null, NotificationPreferencesInterface::class => null], [['user_id' => 'u']])
		);
		self::assertSame(
			[$start, 'warning [OpenRegister] Preference service / schema mapper unavailable — migration skipped.'],
			$this->runStep([IDBConnection::class => null, 'OCA\\OpenRegister\\Db\\SchemaMapper' => new \stdClass()], [['user_id' => 'u']])
		);
	}//end testEarlyExits()

	/**
	 * A full run over every row shape.
	 *
	 * @return void
	 */
	public function testMigratesRows(): void {
		$annotated = ['x-openregister-notifications' => ['created' => [], 7 => []]];
		$schemas   = [
			1 => $this->schema(1, 'case', $annotated),
			2 => $this->schema(2, null, ['x-openregister-notifications' => ['updated' => []]]),
			3 => $this->schema(3, 'plain', []),
			4 => $this->schema(4, 'bad', ['x-openregister-notifications' => 'yes']),
		];

		$mapper = new class($schemas) {
			/**
			 * Construct.
			 *
			 * @param array<int, mixed> $schemas Schemas by id.
			 */
			public function __construct(private array $schemas) {
			}

			/**
			 * Find.
			 *
			 * @param int $id The id.
			 *
			 * @return mixed The schema or a non-schema.
			 */
			public function find(int $id): mixed {
				if ($id === 99) {
					throw new RuntimeException('gone');
				}

				return ($this->schemas[$id] ?? 'not-a-schema');
			}
		};

		$calls = $this->runStep(
			[
				IAppConfig::class => null,
				IDBConnection::class => null,
				NotificationPreferencesInterface::class => null,
				'OCA\\OpenRegister\\Db\\SchemaMapper' => $mapper,
			],
			[
				['user_id' => '', 'schema_id' => 1],
				['user_id' => 'u0', 'schema_id' => null],
				['schema_id' => 1],
				['user_id' => 'u1', 'schema_id' => 99],
				['user_id' => 'u1', 'schema_id' => 50],
				['user_id' => 'u1', 'schema_id' => 3],
				['user_id' => 'u1', 'schema_id' => 4],
				['user_id' => 'u1', 'schema_id' => '1'],
				['user_id' => 'u2', 'schema_id' => 2],
			]
		);

		self::assertSame(
			[
				'info [OpenRegister] Migrating legacy notification subscriptions to user-config...',
				'getBool openregister.notification_subscription_migration_done',
				'override u1 case created {"enabled":true}',
				'override u1 case 7 {"enabled":true}',
				'override u2 2 updated {"enabled":true}',
				'info [OpenRegister] Migrated 3 notification subscription override(s).',
				'setBool openregister.notification_subscription_migration_done=true',
			],
			$calls
		);
	}//end testMigratesRows()
}//end class
