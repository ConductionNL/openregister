<?php

/**
 * The Art 30 accountability report answers within the caller's organisation.
 *
 * Audit rows carry no reliable organisation, so the report is scoped through
 * the processing activities: a non-admin is given the activities of their
 * active organisation, and audit rows are counted for those activities only.
 * A non-admin without an active organisation is given nothing. An
 * administrator is given every activity, as recorded in the golden fixture.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/specs/verwerkingsregister-api/spec.md#requirement-the-system-must-provide-an-art-30-4-accountability-report-aggregating-audit-events-per-processing-activity
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\VerwerkingsactiviteitenController;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
use OCA\OpenRegister\Service\OrganisationService;
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
use PHPUnit\Framework\TestCase;

/**
 * Who is given which activities and counts in the accountability report.
 */
final class AccountabilityReportScopeTest extends TestCase {

	/**
	 * The recording of the administrator's report.
	 *
	 * @var string
	 */
	private const FIXTURE = __DIR__ . '/fixtures/accountability-admin-golden.json';

	/**
	 * The audit rows the report may count: activity uuid => action => count.
	 *
	 * @var array<string, array<string, int>>
	 */
	private const TRAIL = [
		'va-a1' => ['create' => 3, 'read' => 5],
		'va-a2' => ['update' => 2],
		'va-b1' => ['create' => 7, 'delete' => 1],
		'va-none' => ['read' => 4],
	];

	/**
	 * The activity uuids each aggregation was asked for.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $counted = [];

	/**
	 * How often the activity catalog was read.
	 *
	 * @var int
	 */
	private int $catalogReads = 0;

	/**
	 * One activity.
	 *
	 * @param int         $id           Its id.
	 * @param string      $uuid         Its uuid.
	 * @param string|null $organisation The organisation it belongs to.
	 *
	 * @return Verwerkingsactiviteit The activity.
	 */
	private function activity(int $id, string $uuid, ?string $organisation): Verwerkingsactiviteit {
		$activity = new Verwerkingsactiviteit();
		$activity->setId($id);
		$activity->setUuid($uuid);
		$activity->setCode('VA-' . $id);
		$activity->setName('Activiteit ' . $id);
		$activity->setOrganisationId($organisation);
		$activity->setStatus('published');

		return $activity;
	}//end activity()

	/**
	 * An organisation with a uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return Organisation The organisation.
	 */
	private function organisation(string $uuid): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid($uuid);

		return $organisation;
	}//end organisation()

	/**
	 * A database that answers the aggregation from TRAIL for the uuids asked for.
	 *
	 * @return IDBConnection The connection.
	 */
	private function database(): IDBConnection {
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
				$this->counted[] = $value;

				return ':uuids';
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			function () use (&$asked): IResult {
				$rows = [];
				foreach ($asked as $uuid) {
					foreach ((self::TRAIL[$uuid] ?? []) as $action => $count) {
						$rows[] = ['processing_activity_id' => $uuid, 'action' => $action, 'cnt' => (string)$count];
					}
				}

				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($rows);

				return $result;
			}
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return $db;
	}//end database()

	/**
	 * The controller for one caller.
	 *
	 * @param string|null $uid    The caller, or null when anonymous.
	 * @param array       $world  `admin` (bool), `active` (?string), `memberOf` (string[]).
	 *
	 * @return VerwerkingsactiviteitenController The controller.
	 */
	private function controller(?string $uid, array $world = []): VerwerkingsactiviteitenController {
		$mapper = $this->createMock(VerwerkingsactiviteitMapper::class);
		$mapper->method('findAll')->willReturnCallback(
			function (): array {
				$this->catalogReads++;

				return [
					$this->activity(id: 1, uuid: 'va-a1', organisation: 'org-a'),
					$this->activity(id: 2, uuid: 'va-a2', organisation: 'org-a'),
					$this->activity(id: 3, uuid: 'va-b1', organisation: 'org-b'),
					$this->activity(id: 4, uuid: 'va-none', organisation: null),
				];
			}
		);

		$session = $this->createMock(IUserSession::class);
		$groups = $this->createMock(IGroupManager::class);
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$groups->method('getUserGroupIds')->willReturn((($world['admin'] ?? false) === true) ? ['admin'] : ['users']);

		$organisations = $this->createMock(OrganisationService::class);
		$active = ($world['active'] ?? null);
		$organisations->method('getActiveOrganisation')->willReturn(($active === null) ? null : $this->organisation($active));
		$organisations->method('getUserOrganisations')->willReturn(
			array_map(fn (string $uuid): Organisation => $this->organisation($uuid), ($world['memberOf'] ?? []))
		);

		return new VerwerkingsactiviteitenController(
			$this->createMock(IRequest::class),
			new \OCA\OpenRegister\Controller\Support\VerwerkingsactiviteitenControllerServices(
				$mapper,
				new \OCA\OpenRegister\Controller\Support\ControllerSession($session, $groups, $this->createMock(\Psr\Log\LoggerInterface::class)),
				$this->database(),
				$organisations,
			)
		);
	}//end controller()

	public function testAnAdministratorIsGivenEveryActivityAndItsCountsAsRecorded(): void {
		$actual = $this->controller(uid: 'admin', world: ['admin' => true])->accountability()->getData();
		$actual = json_decode((string)json_encode($actual), true);

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		self::assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $actual);
		self::assertSame([['va-a1', 'va-a2', 'va-b1', 'va-none']], $this->counted);
	}//end testAnAdministratorIsGivenEveryActivityAndItsCountsAsRecorded()

	public function testANonAdminIsGivenTheActivitiesOfTheirActiveOrganisationOnly(): void {
		$data = $this->controller(
			uid: 'jan',
			world: ['active' => 'org-a', 'memberOf' => ['org-a', 'org-b']]
		)->accountability()->getData();

		self::assertSame(2, $data['count']);
		self::assertSame(['va-a1', 'va-a2'], array_column($data['activities'], 'uuid'));
		self::assertSame(['totalEvents' => 8, 'byAction' => ['create' => 3, 'read' => 5]], $data['activities'][0]['activity']);
		self::assertSame([['va-a1', 'va-a2']], $this->counted);
	}//end testANonAdminIsGivenTheActivitiesOfTheirActiveOrganisationOnly()

	public function testANonAdminSwitchingOrganisationIsGivenThatOrganisationsActivities(): void {
		$data = $this->controller(
			uid: 'jan',
			world: ['active' => 'org-b', 'memberOf' => ['org-a', 'org-b']]
		)->accountability()->getData();

		self::assertSame(['va-b1'], array_column($data['activities'], 'uuid'));
		self::assertSame([['va-b1']], $this->counted);
	}//end testANonAdminSwitchingOrganisationIsGivenThatOrganisationsActivities()

	public function testANonAdminWithoutAnActiveOrganisationIsGivenNothing(): void {
		$data = $this->controller(uid: 'jan', world: ['active' => null, 'memberOf' => ['org-a']])->accountability()->getData();

		self::assertSame(['count' => 0, 'activities' => []], $data);
		self::assertSame([], $this->counted);
		self::assertSame(0, $this->catalogReads, 'Without an active organisation the catalog is not read at all.');
	}//end testANonAdminWithoutAnActiveOrganisationIsGivenNothing()

	public function testAnActivityWithoutAnOrganisationIsNotGivenToANonAdmin(): void {
		$data = $this->controller(uid: 'jan', world: ['active' => 'org-c', 'memberOf' => ['org-c']])->accountability()->getData();

		self::assertSame(['count' => 0, 'activities' => []], $data);
		self::assertSame([], $this->counted);
	}//end testAnActivityWithoutAnOrganisationIsNotGivenToANonAdmin()

	public function testAnAnonymousCallerIsRefused(): void {
		$response = $this->controller(uid: null)->accountability();

		self::assertSame(401, $response->getStatus());
		self::assertSame([], $this->counted);
	}//end testAnAnonymousCallerIsRefused()
}//end class
