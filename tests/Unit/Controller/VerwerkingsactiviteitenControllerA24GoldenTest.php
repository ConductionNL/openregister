<?php

/**
 * Golden outcomes of the processing register API, allowed and refused.
 *
 * Pins status and body of every access path of VerwerkingsactiviteitenController:
 * authentication, tenant scoping of the reads and the accountability report,
 * the admin gate on writes, and the 404 and 422 refusals.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Controller\VerwerkingsactiviteitenController;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Allowed and refused outcomes of the verwerkingsactiviteiten API.
 */
final class VerwerkingsactiviteitenControllerA24GoldenTest extends TestCase {

	/**
	 * The catalog mapper of the controller under test.
	 *
	 * @var VerwerkingsactiviteitMapper&MockObject
	 */
	private $mapper;

	/**
	 * The catalog: org-a, org-b and one without an organisation.
	 *
	 * @var array<int, Verwerkingsactiviteit>
	 */
	private array $catalog = [];

	/**
	 * The uuid lists the aggregation was asked for.
	 *
	 * @var array<int, mixed>
	 */
	private array $aggregated = [];

	protected function setUp(): void {
		foreach ([[1, 'va-a', 'org-a'], [2, 'va-b', 'org-b'], [3, 'va-none', null]] as [$id, $uuid, $org]) {
			$activity = new Verwerkingsactiviteit();
			$activity->setId($id);
			$activity->setUuid($uuid);
			$activity->setCode('VA-' . $id);
			$activity->setName('Activiteit ' . $id);
			$activity->setOrganisationId($org);
			$activity->setStatus('published');
			$this->catalog[$id] = $activity;
		}

		$this->mapper = $this->createMock(VerwerkingsactiviteitMapper::class);
		$this->mapper->method('findAll')->willReturnCallback(fn (): array => array_values($this->catalog));
		$this->mapper->method('find')->willReturnCallback(
			function (int $id): Verwerkingsactiviteit {
				if (isset($this->catalog[$id]) === false) {
					throw new DoesNotExistException('missing');
				}

				return $this->catalog[$id];
			}
		);
		$this->mapper->method('resolveReference')->willReturnCallback(
			function (string $reference): ?Verwerkingsactiviteit {
				foreach ($this->catalog as $activity) {
					if ($activity->getUuid() === $reference || $activity->getCode() === $reference) {
						return $activity;
					}
				}

				return null;
			}
		);
	}//end setUp()

	private function database(bool $fails = false): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		if ($fails === true) {
			$db->method('getQueryBuilder')->willThrowException(new \Exception('db down'));

			return $db;
		}

		$asked = [];
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'selectAlias', 'from', 'where', 'groupBy'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		$functions = $this->createMock(IFunctionBuilder::class);
		$functions->method('count')->willReturn($this->createMock(IQueryFunction::class));
		$qb->method('func')->willReturn($functions);
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value) use (&$asked): string {
				$asked = $value;
				$this->aggregated[] = $value;

				return ':uuids';
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			function () use (&$asked): IResult {
				$rows = [];
				foreach ($asked as $uuid) {
					$rows[] = ['processing_activity_id' => $uuid, 'action' => 'read', 'cnt' => '4'];
					$rows[] = ['processing_activity_id' => $uuid, 'action' => 'update', 'cnt' => '1'];
				}

				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($rows);

				return $result;
			}
		);
		$db->method('getQueryBuilder')->willReturn($qb);

		return $db;
	}//end database()

	/**
	 * The controller for one caller.
	 *
	 * @param string|null $uid    The caller, null when anonymous.
	 * @param bool        $admin  Whether the caller is in the admin group.
	 * @param string|null $active The caller's active organisation.
	 * @param array       $params Request parameters.
	 * @param bool        $dbFail Whether the aggregation query fails.
	 *
	 * @return VerwerkingsactiviteitenController The controller.
	 */
	private function controller(
		?string $uid,
		bool $admin = false,
		?string $active = null,
		array $params = [],
		bool $dbFail = false,
	): VerwerkingsactiviteitenController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));
		$request->method('getParams')->willReturn($params);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$memberships = ['users'];
		if ($admin === true) {
			$memberships = ['admin'];
		}

		$groups->method('getUserGroupIds')->willReturn($memberships);
		$groups->method('isAdmin')->willReturn(false);

		$organisations = $this->createMock(OrganisationService::class);
		$organisation = null;
		if ($active !== null) {
			$organisation = new Organisation();
			$organisation->setUuid($active);
		}

		$organisations->method('getActiveOrganisation')->willReturn($organisation);

		return new VerwerkingsactiviteitenController(
			'openregister',
			$request,
			$this->mapper,
			new ControllerSession($session, $groups, $this->createMock(LoggerInterface::class)),
			$this->database($dbFail),
			$organisations
		);
	}//end controller()

	private function outcome($response): array {
		return [$response->getStatus(), $response->getData()];
	}//end outcome()

	public function testAnonymousReadsAreRefusedWith401(): void {
		$refusal = [401, ['error' => 'Authentication required']];
		$this->assertSame($refusal, $this->outcome($this->controller(null)->index()));
		$this->assertSame($refusal, $this->outcome($this->controller(null)->show('1')));
		$this->assertSame($refusal, $this->outcome($this->controller(null)->accountability()));
	}//end testAnonymousReadsAreRefusedWith401()

	public function testAnonymousAndNonAdminWritesAreRefusedWith403(): void {
		$this->mapper->expects($this->never())->method('insert');
		$this->mapper->expects($this->never())->method('update');
		$refusal = [403, ['error' => 'Admin privileges required to manage verwerkingsactiviteiten']];
		foreach ([null, 'jan'] as $uid) {
			$this->assertSame($refusal, $this->outcome($this->controller($uid, false, 'org-a', ['name' => 'x'])->create()));
			$this->assertSame($refusal, $this->outcome($this->controller($uid, false, 'org-a')->update('1')));
			$this->assertSame($refusal, $this->outcome($this->controller($uid, false, 'org-a')->destroy('1')));
		}
	}//end testAnonymousAndNonAdminWritesAreRefusedWith403()

	public function testIndexIsTenantScoped(): void {
		$admin = $this->controller('root', true)->index()->getData();
		$member = $this->controller('jan', false, 'org-a', ['organisation' => 'org-b'])->index();
		$orphan = $this->controller('jan', false, null)->index();

		$all = array_map(static fn (Verwerkingsactiviteit $a): array => $a->jsonSerialize(), array_values($this->catalog));
		$this->assertSame(['count' => 3, 'results' => $all], $admin);
		$this->assertSame([200, ['count' => 1, 'results' => [$this->catalog[1]->jsonSerialize()]]], $this->outcome($member));
		$this->assertSame([200, ['count' => 0, 'results' => []]], $this->outcome($orphan));
	}//end testIndexIsTenantScoped()

	public function testShowHidesOtherTenantsBehind404(): void {
		$notFound = static fn (string $id): array => [404, ['error' => 'Verwerkingsactiviteit not found', 'identifier' => $id]];

		$this->assertSame([200, $this->catalog[1]->jsonSerialize()], $this->outcome($this->controller('jan', false, 'org-a')->show('va-a')));
		$this->assertSame($notFound('2'), $this->outcome($this->controller('jan', false, 'org-a')->show('2')));
		$this->assertSame($notFound('VA-3'), $this->outcome($this->controller('jan', false, 'org-a')->show('VA-3')));
		$this->assertSame($notFound('va-a'), $this->outcome($this->controller('jan', false, null)->show('va-a')));
		$this->assertSame([200, $this->catalog[3]->jsonSerialize()], $this->outcome($this->controller('root', true)->show('3')));
		$this->assertSame($notFound('99'), $this->outcome($this->controller('root', true)->show('99')));
		$this->assertSame($notFound(''), $this->outcome($this->controller('root', true)->show('')));
	}//end testShowHidesOtherTenantsBehind404()

	public function testAnAdminCreatesFromThePayload(): void {
		$this->mapper->expects($this->once())->method('insert')->willReturnArgument(0);
		$payload = [
			'name' => 'Nieuw',
			'purpose' => 7,
			'legalBasis' => null,
			'recipients' => ['x'],
			'controller' => 'not an array',
			'unknown' => 'ignored',
		];

		$response = $this->controller('root', true, null, $payload)->create();

		$this->assertSame(201, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(
			['Nieuw', '7', null, ['x'], null],
			[$data['name'], $data['purpose'], $data['legalBasis'], $data['recipients'], $data['controller']]
		);
	}//end testAnAdminCreatesFromThePayload()

	public function testAnInvalidArgumentIsRefusedWith422AndOtherFailuresPropagate(): void {
		$this->mapper->method('insert')->willThrowException(new InvalidArgumentException('name is required'));
		$this->assertSame([422, ['error' => 'name is required']], $this->outcome($this->controller('root', true)->create()));

		$this->mapper->method('update')->willThrowException(new RuntimeException('db down'));
		$this->expectException(RuntimeException::class);
		$this->controller('root', true)->update('1');
	}//end testAnInvalidArgumentIsRefusedWith422AndOtherFailuresPropagate()

	public function testUpdateAndDestroy(): void {
		$this->mapper->method('update')->willReturnArgument(0);

		$missing = [404, ['error' => 'Verwerkingsactiviteit not found', 'identifier' => 'nope']];
		$this->assertSame($missing, $this->outcome($this->controller('root', true)->update('nope')));
		$this->assertSame($missing, $this->outcome($this->controller('root', true)->destroy('nope')));

		$updated = $this->controller('root', true, null, ['description' => 'Nieuw', 'status' => 'draft'])->update('VA-2');
		$this->assertSame(200, $updated->getStatus());
		$data = $updated->getData();
		$this->assertSame(['Nieuw', 'draft', 'org-b'], [$data['description'], $data['status'], $data['organisationId']]);

		$this->assertSame([204, []], $this->outcome($this->controller('root', true)->destroy('1')));
		$this->assertSame('archived', $this->catalog[1]->getStatus());
	}//end testUpdateAndDestroy()

	public function testTheAccountabilityReportIsTenantScoped(): void {
		$counts = ['totalEvents' => 5, 'byAction' => ['read' => 4, 'update' => 1]];

		$member = $this->controller('jan', false, 'org-b')->accountability();
		$expected = $this->catalog[2]->jsonSerialize();
		$expected['activity'] = $counts;
		$this->assertSame([200, ['count' => 1, 'activities' => [$expected]]], $this->outcome($member));
		$this->assertSame([['va-b']], $this->aggregated);

		$this->assertSame([200, ['count' => 0, 'activities' => []]], $this->outcome($this->controller('jan', false, null)->accountability()));
		$this->assertSame([['va-b']], $this->aggregated, 'Without an organisation nothing is aggregated.');

		$admin = $this->controller('root', true)->accountability()->getData();
		$this->assertSame(3, $admin['count']);
		$this->assertSame([$counts, $counts, $counts], array_column($admin['activities'], 'activity'));
	}//end testTheAccountabilityReportIsTenantScoped()

	public function testAFailedAggregationReportsZeroCounts(): void {
		$data = $this->controller('jan', false, 'org-a', [], true)->accountability()->getData();

		$this->assertSame(['totalEvents' => 0, 'byAction' => []], $data['activities'][0]['activity']);
	}//end testAFailedAggregationReportsZeroCounts()
}//end class
