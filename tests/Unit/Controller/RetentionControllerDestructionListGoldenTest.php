<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Tests\Support\AuditTrailEvents;
use OCA\OpenRegister\Tests\Unit\Controller\Support\RetentionControllerFactory;
use Exception;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\DestructionService;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Golden test for RetentionController::approveDestructionList() and rejectDestructionList().
 *
 * Every case records each collaborator call (request, both mappers, the
 * retention and destruction services, the job list, the audit trail, the
 * logger), the stored list and the response, and compares the recording with
 * fixtures/retention-destruction-list-golden.json. Timestamps are replaced by
 * a placeholder. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\RetentionController
 * @uses \OCA\OpenRegister\Controller\Support\DestructionListApproval
 * @uses \OCA\OpenRegister\Controller\Support\RetentionControllerServices
 * @uses \OCA\OpenRegister\Controller\Support\RetentionDomain
 * @uses \OCA\OpenRegister\Controller\Support\RetentionMappers
 * @uses \OCA\OpenRegister\Controller\Support\RetentionPlatform
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\AuditTrail
 */
class RetentionControllerDestructionListGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/retention-destruction-list-golden.json';

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$objects = [
			['uuid' => 'a', 'schema' => null],
			['uuid' => 'b', 'schema' => 7, 'decision' => 'retain'],
			['uuid' => 'c'],
		];
		$review = ['status' => 'in_review', 'objects' => $objects];
		$second = ['status' => 'awaiting_second_approval', 'objects' => $objects, 'approvals' => [['userId' => 'alice', 'timestamp' => 'x']]];
		$exclusions = [
			['uuid' => 'a', 'reason' => 'still needed'],
			['uuid' => 'c'],
			['reason' => 'no uuid'],
			['uuid' => 'gone-1', 'reason' => 'missing'],
		];
		return [
			'approve: list not found' => [['action' => 'approve', 'list' => null]],
			'approve: status draft' => [['action' => 'approve', 'list' => ['status' => 'draft']]],
			'approve: status missing' => [['action' => 'approve', 'list' => []]],
			'approve: plain in review' => [['action' => 'approve', 'list' => $review]],
			'approve: no session user' => [['action' => 'approve', 'list' => $review, 'user' => null]],
			'approve: exclusions' => [['action' => 'approve', 'list' => $review, 'excluded' => $exclusions]],
			'approve: exclusions not an array' => [['action' => 'approve', 'list' => $review, 'excluded' => 'a']],
			'approve: exclusions empty' => [['action' => 'approve', 'list' => $review, 'excluded' => []]],
			'approve: exclusion extend throws' => [['action' => 'approve', 'list' => $review, 'excluded' => [['uuid' => 'b']], 'extendThrows' => true]],
			'approve: exclusions and existing excluded' => [['action' => 'approve', 'list' => $review + ['excluded' => [['uuid' => 'z']]], 'excluded' => [['uuid' => 'a']]]],
			'approve: dual approval first step' => [['action' => 'approve', 'list' => $review, 'dual' => true]],
			'approve: dual approval string flag' => [['action' => 'approve', 'list' => $review, 'dual' => 'true']],
			'approve: schema lookup throws' => [['action' => 'approve', 'list' => $review, 'schemaThrows' => true]],
			'approve: in review after an approval by the same user' => [['action' => 'approve', 'list' => $review + ['approvals' => [['userId' => 'alice', 'timestamp' => 'x']]]]],
			'approve: second approval by first approver' => [['action' => 'approve', 'list' => $second]],
			'approve: second approval by another archivist' => [['action' => 'approve', 'list' => $second, 'user' => 'bob']],
			'approve: second step without earlier approvals' => [['action' => 'approve', 'list' => ['status' => 'awaiting_second_approval', 'objects' => $objects]]],
			'approve: second step with dual schema' => [['action' => 'approve', 'list' => $second, 'user' => 'bob', 'dual' => true]],
			'approve: second step first approver without uid' => [['action' => 'approve', 'list' => ['status' => 'awaiting_second_approval', 'objects' => $objects, 'approvals' => [['timestamp' => 'x']]], 'user' => null]],
			'approve: update throws' => [['action' => 'approve', 'list' => $review, 'updateThrows' => true]],
			'reject: list not found' => [['action' => 'reject', 'list' => null]],
			'reject: no reason' => [['action' => 'reject', 'list' => $review, 'reason' => null]],
			'reject: empty reason' => [['action' => 'reject', 'list' => $review, 'reason' => '']],
			'reject: status approved' => [['action' => 'reject', 'list' => ['status' => 'approved'], 'reason' => 'no']],
			'reject: in review' => [['action' => 'reject', 'list' => $review + ['objects' => []], 'reason' => 'keep']],
			'reject: objects extended' => [['action' => 'reject', 'list' => ['status' => 'in_review', 'objects' => [['uuid' => 'a'], ['schema' => 1], ['uuid' => 'gone-2']]], 'reason' => 'keep']],
			'reject: second step without user' => [['action' => 'reject', 'list' => $second, 'reason' => 'keep', 'user' => null]],
			'reject: extend throws' => [['action' => 'reject', 'list' => $review, 'reason' => 'keep', 'extendThrows' => true]],
		];
	}

	#[DataProvider('cases')]
	public function testDestructionListActionMatchesTheRecording(array $case): void {
		$this->calls = [];
		$controller = RetentionControllerFactory::create(
			'openregister',
			$this->request($case),
			$this->retentionService($case),
			$this->createMock(ObjectRetentionHandler::class),
			$this->createMock(SaveObject::class),
			$this->objectMapper($case),
			$this->schemaMapper($case),
			$this->auditMapper(),
			$this->destructionService(),
			$this->jobList(),
			$this->userSession($case),
			$this->logger()
		);

		try {
			if ($case['action'] === 'approve') {
				$response = $controller->approveDestructionList('list-1');
			} else {
				$response = $controller->rejectDestructionList('list-1');
			}

			$result = ['status' => $response->getStatus(), 'data' => $response->getData()];
		} catch (Throwable $e) {
			$result = ['thrown' => get_class($e)];
		}

		$this->compare($this->dataName(), ['calls' => $this->calls, 'result' => $result]);
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function request(array $case): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(function (string $key, $default = null) use ($case) {
			$this->calls[] = ['request.getParam' => [$key, $default]];
			if ($key === 'reason') {
				return array_key_exists('reason', $case) === true ? $case['reason'] : $default;
			}

			return $case[$key] ?? $default;
		});
		return $request;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function userSession(array $case): IUserSession {
		$uid = array_key_exists('user', $case) === true ? $case['user'] : 'alice';
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(function () use ($user) {
			$this->calls[] = ['userSession.getUser' => []];
			return $user;
		});
		return $session;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function objectMapper(array $case): MagicMapper {
		$list = null;
		if ($case['list'] !== null) {
			$list = new ObjectEntity();
			$list->setUuid('list-uuid-1');
			$list->setObject($case['list']);
		}

		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('find')->willReturnCallback(function (...$args) use ($list) {
			$this->calls[] = ['objectMapper.find' => $args];
			if ($args[0] === 'list-1' && $list !== null) {
				return $list;
			}

			if ($args[0] === 'list-1' || str_starts_with((string)$args[0], 'gone') === true) {
				throw new Exception('not found: ' . $args[0]);
			}

			$object = new ObjectEntity();
			$object->setUuid((string)$args[0]);
			return $object;
		});
		$mapper->method('update')->willReturnCallback(function (ObjectEntity $entity) use ($case) {
			$this->calls[] = ['objectMapper.update' => $this->describe($entity)];
			if (($case['updateThrows'] ?? false) === true) {
				throw new Exception('update failed');
			}

			return $entity;
		});
		return $mapper;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function schemaMapper(array $case): SchemaMapper {
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('find')->willReturnCallback(function (...$args) use ($case) {
			$this->calls[] = ['schemaMapper.find' => $args[0]];
			if (($case['schemaThrows'] ?? false) === true) {
				throw new Exception('no schema');
			}

			$schema = new Schema();
			$schema->setArchive(['requireDualApproval' => $case['dual'] ?? false]);
			return $schema;
		});
		return $mapper;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function retentionService(array $case): RetentionService {
		$service = $this->createMock(RetentionService::class);
		$service->method('extendArchiveActionDate')->willReturnCallback(function (ObjectEntity $object) use ($case) {
			$this->calls[] = ['retention.extendArchiveActionDate' => $object->getUuid()];
			if (($case['extendThrows'] ?? false) === true) {
				throw new Exception('cannot extend');
			}

			return $object;
		});
		return $service;
	}

	private function destructionService(): DestructionService {
		$service = $this->createMock(DestructionService::class);
		$service->method('withholdDecidedEntries')->willReturnCallback(function (array $list) {
			$this->calls[] = ['destruction.withholdDecidedEntries' => $this->normalise($list)];
			if (isset($list['objects']) === false) {
				return $list;
			}

			$list['objects'] = array_values(array_filter($list['objects'], static fn (array $o): bool => ($o['decision'] ?? '') !== 'retain'));
			return $list;
		});
		return $service;
	}

	private function jobList(): IJobList {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(function (...$args) {
			$this->calls[] = ['jobList.add' => $args];
		});
		return $jobList;
	}

	private function auditMapper(): AuditTrailMapper {
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('recordObjectEvent')->willReturnCallback(AuditTrailEvents::positional(function (ObjectEntity $object, ...$rest) {
			$this->calls[] = ['audit.createAuditTrailEntry' => [$object->getUuid(), ...$rest]];
			return new AuditTrail();
		}));
		return $mapper;
	}

	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (...$args) {
			$this->calls[] = ['logger.warning' => $args];
		});
		return $logger;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function describe(ObjectEntity $entity): array {
		return ['uuid' => $entity->getUuid(), 'object' => $this->normalise($entity->getObject())];
	}

	/**
	 * @param array<mixed> $data
	 *
	 * @return array<mixed>
	 */
	private function normalise(array $data): array {
		foreach ($data as $key => $value) {
			if (in_array($key, ['timestamp', 'rejectedAt'], true) === true && $value !== 'x') {
				$data[$key] = '<time>';
			} else if (is_array($value) === true) {
				$data[$key] = $this->normalise($value);
			}
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $actual
	 */
	private function compare(string $key, array $actual): void {
		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			if (self::$recorded === null) {
				self::$recorded = [];
			}

			self::$recorded[$key] = $actual;
			ksort(self::$recorded);
			file_put_contents(
				self::FIXTURE,
				json_encode(self::$recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
			);
			self::$golden = self::$recorded;
		}

		if (self::$golden === []) {
			self::$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$this->assertArrayHasKey($key, self::$golden);
		$this->assertSame(self::$golden[$key], $actual);
	}
}
