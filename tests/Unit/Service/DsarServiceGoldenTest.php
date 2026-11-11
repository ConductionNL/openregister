<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\DsarGuards;
use OCA\OpenRegister\Service\DsarService;
use OCA\OpenRegister\Service\DsarStores;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Golden test for DsarService find, erase and rectify.
 *
 * Every query-builder call, object load and update (with the stamped deletion and
 * processing activity), refusal and log line, and the returned summary, per case,
 * in fixtures/dsar-service-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\DsarService
 * @covers \OCA\OpenRegister\Service\DsarErasureRun
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Verwerkingsactiviteit
 */
class DsarServiceGoldenTest extends TestCase {

	/**
	 * Recorded calls of the current case.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	public static function cases(): array {
		$rows = [
			['id' => 1, 'type' => 'email', 'value' => 'a@b.nl', 'category' => 'contact', 'detected_at' => '2026-01-01', 'object_id' => 10, 'object_uuid' => 'u-10'],
			['id' => 2, 'type' => 'name', 'value' => 'Ann', 'category' => 'person', 'detected_at' => null, 'object_id' => 10, 'object_uuid' => 'u-10'],
			['id' => 3, 'type' => 'email', 'value' => 'a@b.nl', 'object_id' => 11, 'object_uuid' => ''],
			['id' => 4, 'type' => 'email', 'value' => 'a@b.nl', 'object_id' => 0, 'object_uuid' => null],
			['id' => 5, 'type' => 'email', 'value' => 'a@b.nl', 'object_id' => 12, 'object_uuid' => 'held'],
			['id' => 6, 'type' => 'email', 'value' => 'a@b.nl', 'object_id' => 13, 'object_uuid' => 'gone'],
			['id' => 7, 'type' => 'email', 'value' => 'a@b.nl', 'object_id' => 14, 'object_uuid' => 'broken'],
			['id' => 8, 'type' => 'email', 'value' => 'a@b.nl', 'object_id' => 15, 'object_uuid' => 'readonly'],
		];
		return [
			'find exact' => [['call' => 'find', 'subject' => ' a@b.nl ', 'rows' => $rows]],
			'find ilike typed' => [['call' => 'find', 'subject' => '50%_x', 'type' => 'email', 'mode' => 'ilike', 'rows' => $rows]],
			'find blank' => [['call' => 'find', 'subject' => '  ', 'rows' => $rows]],
			'find no hits' => [['call' => 'find', 'subject' => 'x', 'rows' => []]],
			'find query fails' => [['call' => 'find', 'subject' => 'x', 'queryFails' => true]],
			'find unprivileged' => [['call' => 'find', 'subject' => 'x', 'admin' => false, 'rows' => $rows]],
			'erase all' => [['call' => 'erase', 'subject' => 'a@b.nl', 'rows' => $rows]],
			'erase typed empty type' => [['call' => 'erase', 'subject' => 'a@b.nl', 'type' => '', 'rows' => $rows]],
			'erase no activity' => [['call' => 'erase', 'subject' => 'a@b.nl', 'activity' => '', 'rows' => $rows]],
			'erase no user' => [['call' => 'erase', 'subject' => 'a@b.nl', 'user' => false, 'rows' => $rows]],
			'erase activity unresolved' => [['call' => 'erase', 'subject' => 'a@b.nl', 'activity' => 'unknown', 'rows' => $rows]],
			'erase config throws' => [['call' => 'erase', 'subject' => 'a@b.nl', 'activity' => 'throw', 'rows' => [$rows[0]]]],
			'erase dry run' => [['call' => 'erase', 'subject' => 'a@b.nl', 'dryRun' => true, 'rows' => $rows]],
			'erase nothing' => [['call' => 'erase', 'subject' => 'a@b.nl', 'rows' => []]],
			'erase only clean' => [['call' => 'erase', 'subject' => 'a@b.nl', 'rows' => [$rows[0], $rows[2]]]],
			'erase withheld only' => [['call' => 'erase', 'subject' => 'a@b.nl', 'rows' => [$rows[0], $rows[4]]]],
			'rectify' => [['call' => 'rectify', 'id' => 10, 'changes' => ['name' => 'Bea', 'new' => 1]]],
			'rectify missing' => [['call' => 'rectify', 'id' => 13, 'changes' => ['name' => 'Bea']]],
			'rectify update fails' => [['call' => 'rectify', 'id' => 15, 'changes' => []]],
		];
	}//end cases()

	#[DataProvider('cases')]
	public function testDsarMatchesGolden(array $case): void {
		$this->log = [];
		$service = $this->service($case);
		try {
			$result = match ($case['call']) {
				'find' => $service->findObjectsForSubject($case['subject'], $case['type'] ?? null, $case['mode'] ?? 'exact'),
				'erase' => $service->eraseObjectsForSubject($case['subject'], $case['type'] ?? null, $case['dryRun'] ?? false),
				default => $service->rectifyObjectForSubject($case['id'], $case['changes']),
			};
		} catch (Throwable $e) {
			$result = ['error' => [get_class($e), $e->getMessage()]];
		}

		$json = (string)json_encode(['result' => $result, 'log' => $this->log], JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
		$json = (string)preg_replace('/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d/', '<time>', $json);
		$actual = json_decode($json, true);
		$file = __DIR__ . '/fixtures/dsar-service-golden.json';
		$name = $this->dataName();
		$golden = is_file($file) === true ? json_decode((string)file_get_contents($file), true) : [];
		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents($file, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame($golden[$name], $actual);
	}//end testDsarMatchesGolden()

	private function service(array $case): DsarService {
		$db = $this->createMock(IDBConnection::class);
		$db->method('escapeLikeParameter')->willReturnCallback(fn (string $p) => addcslashes($p, '\\%_'));
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->queryBuilder($case));

		$objects = $this->createMock(MagicMapper::class);
		$objects->method('find')->willReturnCallback(
			function ($identifier, ...$rest) {
				$this->log[] = ['find', $identifier];
				if ($identifier === 'gone' || $identifier === 13) {
					throw new DoesNotExistException('none');
				}

				if ($identifier === 'broken') {
					throw new RuntimeException('db down');
				}

				$object = new ObjectEntity();
				$object->setUuid(is_string($identifier) === true ? $identifier : 'by-id-' . $identifier);
				$object->setRegister('1');
				$object->setSchema('2');
				$object->setObject(['name' => 'Ann', 'email' => 'a@b.nl']);
				return $object;
			}
		);
		$objects->method('update')->willReturnCallback(
			function (ObjectEntity $entity) {
				$this->log[] = ['update', $entity->getUuid(), $entity->getDeleted(), $entity->getProcessingActivityId(), $entity->getObject()];
				if ($entity->getUuid() === 'readonly' || $entity->getUuid() === 'by-id-15') {
					throw new RuntimeException('read only');
				}

				return $entity;
			}
		);

		$activities = $this->createMock(VerwerkingsactiviteitMapper::class);
		$activities->method('resolveReference')->willReturnCallback(
			function (string $reference) {
				$this->log[] = ['resolveReference', $reference];
				if ($reference === 'unknown') {
					return null;
				}

				$activity = new Verwerkingsactiviteit();
				$activity->setUuid('act-' . $reference);
				return $activity;
			}
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($case) {
				$this->log[] = ['config', $app, $key, $default];
				if (($case['activity'] ?? null) === 'throw') {
					throw new RuntimeException('config down');
				}

				return $case['activity'] ?? $default;
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer');
		$session->method('getUser')->willReturn(($case['user'] ?? true) === true ? $user : null);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($case['admin'] ?? true);
		if (($case['user'] ?? true) === false) {
			$groups->method('isAdmin')->willReturn(false);
		}

		$guard = $this->createMock(ArchivalRetentionGuard::class);
		$guard->method('erasureRefusal')->willReturnCallback(
			fn (ObjectEntity $object) => $object->getUuid() === 'held' ? ['uuid' => 'held', 'reason' => 'archival'] : null
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->log[] = [$level, $message, $context];
				}
			);
		}

		return new DsarService(
			stores: new DsarStores(db: $db, objectMapper: $objects, vrwMapper: $activities, appConfig: $config),
			guards: new DsarGuards(userSession: $session, groupManager: $groups, archivalGuard: $guard),
			logger: $logger
		);
	}//end service()

	private function queryBuilder(array $case): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'iLike', 'isNotNull'] as $method) {
			$expr->method($method)->willReturnCallback(fn (...$args) => $method . '(' . implode(',', array_map('strval', array_filter($args, fn ($a) => $a !== null))) . ')');
		}

		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(fn ($value) => ':' . $value);
		foreach (['selectDistinct', 'from', 'innerJoin', 'where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(
				function (...$args) use ($qb, $method) {
					$this->log[] = ['qb.' . $method, $args];
					return $qb;
				}
			);
		}

		$qb->method('executeQuery')->willReturnCallback(
			function () use ($case) {
				if (($case['queryFails'] ?? false) === true) {
					throw new RuntimeException('no table');
				}

				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($case['rows'] ?? []);
				$result->method('closeCursor')->willReturnCallback(
					function () {
						$this->log[] = ['closeCursor'];
						return true;
					}
				);
				return $result;
			}
		);
		return $qb;
	}//end queryBuilder()
}//end class
