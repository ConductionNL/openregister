<?php

/**
 * Access golden for GdprEntitiesController show/destroy and the 401 gate.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\GdprEntitiesController;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\Decision\DecisionVisibility;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\GdprEntitiesControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins who may read and delete a detected-PII row, and the exact answers.
 *
 * Allowed: an admin (any row), a member whose organisation owns the row.
 * Refused with 404: a member for a row of another organisation, for a row
 * without organisation, and for a missing row. Refused with 401: anonymous,
 * on every action, before any mapper or query is touched.
 */
class GdprEntitiesControllerAccessGoldenTest extends TestCase {

	/** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
	private array $logs = [];

	/** @var list<string> */
	private array $mapperCalls = [];

	/**
	 * Caller uid; null is anonymous.
	 *
	 * @var string|null
	 */
	private ?string $uid = 'admin';

	/**
	 * Whether the caller is an admin.
	 *
	 * @var bool
	 */
	private bool $admin = true;

	/**
	 * Organisation of the stored row; null means no row.
	 *
	 * @var string|null
	 */
	private ?string $rowOrg = 'org-a';

	/**
	 * Database override.
	 *
	 * @var IDBConnection|null
	 */
	private ?IDBConnection $db = null;

	/**
	 * Request override.
	 *
	 * @var IRequest|null
	 */
	private ?IRequest $request = null;

	/**
	 * @return array<string, array{0: array{uid: string, admin: bool, rowOrg: string, allowed: bool}}>
	 */
	public static function cases(): array {
		return [
			'admin, foreign row' => [['uid' => 'admin', 'admin' => true, 'rowOrg' => 'org-b', 'allowed' => true]],
			'admin, row without organisation' => [['uid' => 'admin', 'admin' => true, 'rowOrg' => '', 'allowed' => true]],
			'member, own row' => [['uid' => 'bob', 'admin' => false, 'rowOrg' => 'org-a', 'allowed' => true]],
			'member, row of second own organisation' => [['uid' => 'bob', 'admin' => false, 'rowOrg' => 'org-c', 'allowed' => true]],
			'member, foreign row' => [['uid' => 'bob', 'admin' => false, 'rowOrg' => 'org-b', 'allowed' => false]],
			'member, row without organisation' => [['uid' => 'bob', 'admin' => false, 'rowOrg' => '', 'allowed' => false]],
		];
	}

	#[DataProvider('cases')]
	public function testShowAnswersPerCaller(array $case): void {
		$this->given(case: $case);
		$uid = $case['uid'];
		$rowOrg = $case['rowOrg'];
		$allowed = $case['allowed'];
		$controller = $this->controller();

		$response = $controller->show(7);

		if ($allowed === true) {
			$this->assertSame(200, $response->getStatus());
			$data = $response->getData();
			$this->assertSame(['success', 'data', 'relations'], array_keys($data));
			$this->assertTrue($data['success']);
			$this->assertSame('uuid-7', $data['data']['uuid']);
			$this->assertSame([['seen-by' => $uid]], $data['relations']);
			$this->assertSame(['find:7', 'relations:7'], $this->mapperCalls);
			return;
		}

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['success' => false, 'message' => 'Entity not found'], $response->getData());
		$this->assertSame(['find:7'], $this->mapperCalls);
		$this->assertSame([], $this->logs);
	}

	#[DataProvider('cases')]
	public function testDestroyAnswersPerCaller(array $case): void {
		$this->given(case: $case);
		$uid = $case['uid'];
		$rowOrg = $case['rowOrg'];
		$allowed = $case['allowed'];
		$controller = $this->controller();

		$response = $controller->destroy(7);

		if ($allowed === true) {
			$this->assertSame(200, $response->getStatus());
			$this->assertSame(['success' => true, 'message' => 'Entity deleted successfully'], $response->getData());
			$this->assertSame(['find:7', 'delete:uuid-7'], $this->mapperCalls);
			$this->assertCount(1, $this->logs);
			[$level, $message, $context] = $this->logs[0];
			$this->assertSame('info', $level);
			$this->assertSame('[GdprEntitiesController] GDPR entity deleted', $message);
			$this->assertSame(
				['audit', 'entityId', 'entityUuid', 'type', 'category', 'organisation', 'deletedBy', 'deletedAt'],
				array_keys($context)
			);
			$this->assertSame('gdpr-entity-delete', $context['audit']);
			$this->assertSame(7, $context['entityId']);
			$this->assertSame('uuid-7', $context['entityUuid']);
			$this->assertSame('EMAIL', $context['type']);
			$this->assertSame('pii', $context['category']);
			$this->assertSame($rowOrg, $context['organisation']);
			$this->assertSame($uid, $context['deletedBy']);
			$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $context['deletedAt']);
			return;
		}

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['success' => false, 'message' => 'Entity not found'], $response->getData());
		$this->assertSame(['find:7'], $this->mapperCalls);
		$this->assertSame([], $this->logs);
	}

	public function testMissingRowIsNotFoundWithoutLogging(): void {
		foreach (['show', 'destroy'] as $action) {
			$this->logs = [];
			$this->uid = 'bob';
			$this->admin = false;
			$this->rowOrg = null;
			$controller = $this->controller();

			$response = $controller->$action(7);

			$this->assertSame(404, $response->getStatus());
			$this->assertSame(['success' => false, 'message' => 'Entity not found'], $response->getData());
			$this->assertSame([], $this->logs);
		}
	}

	public function testFailuresLogTheIdAndReportTheMessage(): void {
		$expected = [
			'show' => ['[GdprEntitiesController] Failed to get entity', 'Failed to get entity: boom'],
			'destroy' => ['[GdprEntitiesController] Failed to delete entity', 'Failed to delete entity: boom'],
		];
		foreach ($expected as $action => [$logMessage, $answer]) {
			$this->logs = [];
			$this->uid = 'admin';
			$this->admin = true;
			$this->rowOrg = 'boom';
			$controller = $this->controller();

			$response = $controller->$action(7);

			$this->assertSame(500, $response->getStatus());
			$this->assertSame(['success' => false, 'message' => $answer], $response->getData());
			$this->assertCount(1, $this->logs);
			$this->assertSame('error', $this->logs[0][0]);
			$this->assertSame($logMessage, $this->logs[0][1]);
			$this->assertSame(['file', 'line', 'id', 'error'], array_keys($this->logs[0][2]));
			$this->assertSame(7, $this->logs[0][2]['id']);
			$this->assertSame('boom', $this->logs[0][2]['error']);
		}
	}

	public function testListFailuresLogAndAnswer(): void {
		$expected = [
			'index' => ['[GdprEntitiesController] Failed to list entities', 'Failed to list entities: db down', ['file', 'line', 'error', 'trace']],
			'getTypes' => ['[GdprEntitiesController] Failed to get entity types', 'Failed to get entity types', ['file', 'line', 'error']],
			'getCategories' => ['[GdprEntitiesController] Failed to get entity categories', 'Failed to get entity categories', ['file', 'line', 'error']],
			'getStats' => ['[GdprEntitiesController] Failed to get entity stats', 'Failed to get entity statistics', ['file', 'line', 'error']],
		];
		foreach ($expected as $action => [$logMessage, $answer, $keys]) {
			$this->logs = [];
			$db = $this->createMock(IDBConnection::class);
			$db->method('getQueryBuilder')->willThrowException(new Exception('db down'));
			$request = $this->createMock(IRequest::class);
			$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $default);
			$this->uid = 'admin';
			$this->admin = true;
			$this->rowOrg = 'org-a';
			$this->db = $db;
			$this->request = $request;
			$controller = $this->controller();

			$response = $controller->$action();

			$this->assertSame(500, $response->getStatus(), $action);
			$this->assertSame(['success' => false, 'message' => $answer], $response->getData(), $action);
			$this->assertCount(1, $this->logs, $action);
			$this->assertSame(['error', $logMessage], [$this->logs[0][0], $this->logs[0][1]], $action);
			$this->assertSame($keys, array_keys($this->logs[0][2]), $action);
			$this->assertSame('db down', $this->logs[0][2]['error'], $action);
		}
	}

	public function testMemberWithoutOrganisationGetsEmptyAnswersWithoutQueries(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([['limit', 50, '5'], ['offset', 0, '10']]);
		$this->uid = 'carol';
		$this->admin = false;
		$this->rowOrg = 'org-a';
		$this->db = $db;
		$this->request = $request;
		$controller = $this->controller();

		$this->assertSame(
			['success' => true, 'data' => [], 'count' => 0, 'limit' => 5, 'offset' => 10],
			$controller->index()->getData()
		);
		$this->assertSame(['success' => true, 'data' => []], $controller->getTypes()->getData());
		$this->assertSame(['success' => true, 'data' => []], $controller->getCategories()->getData());
		$this->assertSame(
			['success' => true, 'data' => ['totalEntities' => 0, 'totalRelations' => 0, 'byType' => [], 'byCategory' => []]],
			$controller->getStats()->getData()
		);
	}

	public function testAnonymousIsRefusedEverywhereBeforeAnyLookup(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');
		$this->uid = null;
		$this->admin = true;
		$this->rowOrg = 'org-a';
		$this->db = $db;
		$controller = $this->controller();

		$responses = [
			$controller->index(),
			$controller->show(7),
			$controller->getTypes(),
			$controller->getCategories(),
			$controller->getStats(),
			$controller->destroy(7),
		];

		foreach ($responses as $response) {
			$this->assertSame(401, $response->getStatus());
			$this->assertSame(['success' => false, 'message' => 'Authentication required'], $response->getData());
		}
		$this->assertSame([], $this->mapperCalls);
		$this->assertSame([], $this->logs);
	}

	/**
	 * Take one data-provider case as the world to build.
	 *
	 * @param array{uid: string, admin: bool, rowOrg: string, allowed: bool} $case The case
	 *
	 * @return void
	 */
	private function given(array $case): void {
		$this->uid = $case['uid'];
		$this->admin = $case['admin'];
		$this->rowOrg = $case['rowOrg'];
	}

	/**
	 * Build the controller around one stored row.
	 *
	 * A null $this->rowOrg means the row does not exist; 'boom' makes the mapper fail.
	 *
	 * @return GdprEntitiesController The controller under test
	 */
	private function controller(): GdprEntitiesController {
		$uid = $this->uid;
		$admin = $this->admin;
		$rowOrg = $this->rowOrg;
		$db = $this->db;
		$request = $this->request;
		$entityMapper = $this->createMock(GdprEntityMapper::class);
		$entityMapper->method('find')->willReturnCallback(
			function (int $id) use ($rowOrg): GdprEntity {
				$this->mapperCalls[] = 'find:' . $id;
				if ($rowOrg === null) {
					throw new DoesNotExistException('missing');
				}

				if ($rowOrg === 'boom') {
					throw new Exception('boom');
				}

				$entity = new GdprEntity();
				$entity->setUuid('uuid-' . $id);
				$entity->setType('EMAIL');
				$entity->setCategory('pii');
				$entity->setOrganisation($rowOrg);
				return $entity;
			}
		);
		$entityMapper->method('delete')->willReturnCallback(
			function (GdprEntity $entity): GdprEntity {
				$this->mapperCalls[] = 'delete:' . $entity->getUuid();
				return $entity;
			}
		);
		$relationMapper = $this->createMock(EntityRelationMapper::class);
		$relationMapper->method('findByEntityId')->willReturnCallback(
			function (int $id): array {
				$this->mapperCalls[] = 'relations:' . $id;
				return [];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		$userSession = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($admin);

		$orgs = [];
		if ($uid === 'bob') {
			foreach (['org-a', '', 'org-c', 'org-a'] as $orgUuid) {
				$org = new Organisation();
				$org->setUuid($orgUuid);
				$orgs[] = $org;
			}
		}

		$organisationService = $this->createMock(OrganisationService::class);
		$organisationService->method('getUserOrganisations')->willReturn($orgs);

		$visibility = $this->createMock(DecisionVisibility::class);
		$visibility->method('presentAll')->willReturnCallback(
			static fn (array $relations, string $userId): array => [['seen-by' => $userId]]
		);

		return GdprEntitiesControllerFactory::create(
			'openregister',
			$request ?? $this->createMock(IRequest::class),
			$entityMapper,
			$relationMapper,
			$db ?? $this->createMock(IDBConnection::class),
			$logger,
			$userSession,
			$groupManager,
			$organisationService,
			$visibility
		);
	}
}
