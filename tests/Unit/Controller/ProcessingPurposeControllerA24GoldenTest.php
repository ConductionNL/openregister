<?php

/**
 * Golden outcomes of the administered purpose list, allowed and refused.
 *
 * Pins status and body of every access path of ProcessingPurposeController
 * (authentication, the admin gate on writes and the report, the 404 and the
 * 422 refusals) so a restructuring cannot move what is allowed or refused.
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

use OCA\OpenRegister\Controller\ProcessingPurposeController;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ProcessingPurpose;
use OCA\OpenRegister\Db\ProcessingPurposeMapper;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Service\Audit\PurposeRegistry;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Allowed and refused outcomes of the purpose API.
 */
final class ProcessingPurposeControllerA24GoldenTest extends TestCase {

	/**
	 * The purpose mapper of the controller under test.
	 *
	 * @var ProcessingPurposeMapper&MockObject
	 */
	private $purposes;

	/**
	 * The audit trail of the controller under test.
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private $audit;

	protected function setUp(): void {
		$this->purposes = $this->createMock(ProcessingPurposeMapper::class);
		$this->audit = $this->createMock(AuditTrailMapper::class);
	}//end setUp()

	private function purpose(int $id = 7, string $code = 'brp'): ProcessingPurpose {
		$purpose = new ProcessingPurpose();
		$purpose->setId($id);
		$purpose->setUuid('purpose-' . $id);
		$purpose->setCode($code);
		$purpose->setName('Naam');
		$purpose->setActivity('VA-01');
		$purpose->setStatus(ProcessingPurpose::STATUS_ACTIVE);

		return $purpose;
	}//end purpose()

	private function activity(): Verwerkingsactiviteit {
		$activity = new Verwerkingsactiviteit();
		$activity->setUuid('activity-uuid');
		$activity->setName('Adresonderzoek');

		return $activity;
	}//end activity()

	/**
	 * The controller for one caller.
	 *
	 * @param string|null $uid    The caller, null when anonymous.
	 * @param bool        $admin  Whether the caller is in the admin group.
	 * @param array       $params Request parameters.
	 *
	 * @return ProcessingPurposeController The controller.
	 */
	private function controller(?string $uid, bool $admin = false, array $params = []): ProcessingPurposeController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));

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
			$memberships = ['users', 'admin'];
		}

		$groups->method('getUserGroupIds')->willReturn($memberships);
		$groups->method('isAdmin')->willReturn(false);

		$registry = $this->createMock(PurposeRegistry::class);
		$registry->method('boundActivity')->willReturnCallback(
			function (ProcessingPurpose $purpose): ?Verwerkingsactiviteit {
				if ($purpose->getActivity() !== 'VA-01') {
					return null;
				}

				return $this->activity();
			}
		);

		return new ProcessingPurposeController(
			$request,
			new \OCA\OpenRegister\Controller\Support\ProcessingPurposeControllerServices(
				$this->purposes,
				$registry,
				$this->audit,
				new ControllerSession($session, $groups, $this->createMock(LoggerInterface::class)),
			)
		);
	}//end controller()

	private function expected(ProcessingPurpose $purpose, bool $bound): array {
		$body = $purpose->jsonSerialize();
		$body['bound'] = $bound;
		$body['activityUuid'] = null;
		$body['activityName'] = null;
		if ($bound === true) {
			$body['activityUuid'] = 'activity-uuid';
			$body['activityName'] = 'Adresonderzoek';
		}

		$body['usable'] = ($bound === true && $purpose->getStatus() === ProcessingPurpose::STATUS_ACTIVE);

		return $body;
	}//end expected()

	public function testAnonymousReadsAreRefusedWith401(): void {
		foreach (['index', 'show', 'report'] as $action) {
			$controller = $this->controller(null);
			$response = $controller->{$action}('7');
			$this->assertSame(401, $response->getStatus(), $action);
			$this->assertSame(['error' => 'Authentication required'], $response->getData(), $action);
		}
	}//end testAnonymousReadsAreRefusedWith401()

	public function testAnonymousAndNonAdminWritesAreRefusedWith403(): void {
		$this->purposes->expects($this->never())->method('insert');
		$this->purposes->expects($this->never())->method('update');
		$refusal = ['error' => 'Administering the purpose list requires the admin group'];
		foreach ([null, 'jan'] as $uid) {
			foreach (['create', 'update', 'destroy'] as $action) {
				$controller = $this->controller($uid, false, ['code' => 'x']);
				$response = $controller->{$action}('7');
				$this->assertSame(403, $response->getStatus(), $action);
				$this->assertSame($refusal, $response->getData(), $action);
			}
		}
	}//end testAnonymousAndNonAdminWritesAreRefusedWith403()

	public function testIndexListsEveryPurposeWithItsBinding(): void {
		$bound = $this->purpose(1, 'a');
		$unbound = $this->purpose(2, 'b');
		$unbound->setActivity('VA-99');
		$this->purposes->expects($this->once())->method('findAll')->with('active', 'org-1')->willReturn([$bound, $unbound]);

		$response = $this->controller('jan', false, ['status' => ' active ', 'organisation' => 'org-1'])->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['count' => 2, 'results' => [$this->expected($bound, true), $this->expected($unbound, false)]],
			$response->getData()
		);
	}//end testIndexListsEveryPurposeWithItsBinding()

	public function testIndexPassesBlankFiltersAsNull(): void {
		$this->purposes->expects($this->once())->method('findAll')->with(null, null)->willReturn([]);

		$response = $this->controller('jan', false, ['status' => '  ', 'organisation' => 5])->index();

		$this->assertSame(['count' => 0, 'results' => []], $response->getData());
	}//end testIndexPassesBlankFiltersAsNull()

	public function testShowResolvesByIdOrReferenceAndRefusesUnknownWith404(): void {
		$purpose = $this->purpose();
		$this->purposes->method('find')->willReturnCallback(
			static function (int $id) use ($purpose): ProcessingPurpose {
				if ($id === 7) {
					return $purpose;
				}

				throw new RuntimeException('missing');
			}
		);
		$this->purposes->method('resolveReference')->willReturnMap([['brp', $purpose], ['nope', null]]);

		$byId = $this->controller('jan')->show('7');
		$byCode = $this->controller('jan')->show('brp');
		$missingId = $this->controller('jan')->show('8');
		$missingRef = $this->controller('jan')->show('nope');

		$this->assertSame([200, $this->expected($purpose, true)], [$byId->getStatus(), $byId->getData()]);
		$this->assertSame([200, $this->expected($purpose, true)], [$byCode->getStatus(), $byCode->getData()]);
		$this->assertSame([404, ['error' => 'Not Found', 'identifier' => '8']], [$missingId->getStatus(), $missingId->getData()]);
		$this->assertSame([404, ['error' => 'Not Found', 'identifier' => 'nope']], [$missingRef->getStatus(), $missingRef->getData()]);
	}//end testShowResolvesByIdOrReferenceAndRefusesUnknownWith404()

	public function testCreateRefusesAMissingCodeWith422(): void {
		$this->purposes->expects($this->never())->method('insert');

		$response = $this->controller('root', true, ['code' => '   '])->create();

		$this->assertSame([422, ['error' => 'code is required']], [$response->getStatus(), $response->getData()]);
	}//end testCreateRefusesAMissingCodeWith422()

	public function testCreateRefusesADuplicateCodeWith422(): void {
		$this->purposes->method('findByCode')->willReturn($this->purpose());
		$this->purposes->expects($this->never())->method('insert');

		$response = $this->controller('root', true, ['code' => ' brp '])->create();

		$this->assertSame(
			[422, ['error' => 'A purpose with that code already exists', 'code' => 'brp']],
			[$response->getStatus(), $response->getData()]
		);
	}//end testCreateRefusesADuplicateCodeWith422()

	public function testCreateReportsAFailedInsertWith422(): void {
		$this->purposes->method('findByCode')->willReturn(null);
		$this->purposes->method('insert')->willThrowException(new RuntimeException('duplicate key'));

		$response = $this->controller('root', true, ['code' => 'new'])->create();

		$this->assertSame([422, ['error' => 'duplicate key']], [$response->getStatus(), $response->getData()]);
	}//end testCreateReportsAFailedInsertWith422()

	public function testAnAdminCreatesAPurposeWith201(): void {
		$this->purposes->method('findByCode')->willReturn(null);
		$this->purposes->expects($this->once())->method('insert')->willReturnCallback(
			static function (ProcessingPurpose $purpose): ProcessingPurpose {
				$purpose->setId(11);

				return $purpose;
			}
		);

		$response = $this->controller(
			'root',
			true,
			['code' => 'new', 'name' => 'Nieuw', 'activity' => 'VA-01', 'status' => ProcessingPurpose::STATUS_RETIRED, 'description' => '']
		)->create();

		$this->assertSame(201, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(
			['new', 'Nieuw', null, 'VA-01', ProcessingPurpose::STATUS_RETIRED, 'activity-uuid', true, false],
			[$data['code'], $data['name'], $data['description'], $data['activity'], $data['status'], $data['activityUuid'], $data['bound'], $data['usable']]
		);
	}//end testAnAdminCreatesAPurposeWith201()

	public function testAnInvalidStatusIsIgnoredOnWrite(): void {
		$purpose = $this->purpose();
		$this->purposes->method('find')->willReturn($purpose);
		$this->purposes->method('update')->willReturnArgument(0);

		$response = $this->controller('root', true, ['status' => 'bogus', 'activity' => 'VA-99'])->update('7');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(ProcessingPurpose::STATUS_ACTIVE, $response->getData()['status']);
		$this->assertNull($purpose->getActivityUuid());
		$this->assertFalse($response->getData()['bound']);
	}//end testAnInvalidStatusIsIgnoredOnWrite()

	public function testUpdateRefusesUnknownWith404AndAFailedWriteWith422(): void {
		$this->purposes->method('resolveReference')->willReturn(null);
		$missing = $this->controller('root', true)->update('nope');
		$this->assertSame([404, ['error' => 'Not Found', 'identifier' => 'nope']], [$missing->getStatus(), $missing->getData()]);

		$this->purposes->method('find')->willReturn($this->purpose());
		$this->purposes->method('update')->willThrowException(new RuntimeException('conflict'));
		$failed = $this->controller('root', true)->update('7');
		$this->assertSame([422, ['error' => 'conflict']], [$failed->getStatus(), $failed->getData()]);
	}//end testUpdateRefusesUnknownWith404AndAFailedWriteWith422()

	public function testDestroyRetiresAndRefusesUnknownWith404(): void {
		$purpose = $this->purpose();
		$this->purposes->method('find')->willReturnCallback(
			static function (int $id) use ($purpose): ProcessingPurpose {
				if ($id === 7) {
					return $purpose;
				}

				throw new RuntimeException('missing');
			}
		);
		$this->purposes->expects($this->once())->method('update')->with($purpose);

		$missing = $this->controller('root', true)->destroy('9');
		$done = $this->controller('root', true)->destroy('7');

		$this->assertSame([404, ['error' => 'Not Found', 'identifier' => '9']], [$missing->getStatus(), $missing->getData()]);
		$this->assertSame([204, []], [$done->getStatus(), $done->getData()]);
		$this->assertSame(ProcessingPurpose::STATUS_RETIRED, $purpose->getStatus());
	}//end testDestroyRetiresAndRefusesUnknownWith404()

	public function testTheReportIsRefusedToANonAdminWith403(): void {
		$this->audit->expects($this->never())->method('countByPurpose');

		$response = $this->controller('jan')->report();

		$this->assertSame(
			[403, ['error' => 'The purpose report counts the whole trail and requires the admin group']],
			[$response->getStatus(), $response->getData()]
		);
	}//end testTheReportIsRefusedToANonAdminWith403()

	public function testAnAdminReadsTheReport(): void {
		$this->audit->expects($this->once())->method('countByPurpose')
			->with($this->isInstanceOf(\DateTime::class), null)
			->willReturn(['brp' => 3, 'null' => 2]);

		$response = $this->controller('root', true, ['from' => '2026-01-01', 'to' => 'not a date'])->report();

		$this->assertSame(
			[200, ['counts' => ['brp' => 3, 'null' => 2], 'total' => 5, 'unattributed' => 2]],
			[$response->getStatus(), $response->getData()]
		);
	}//end testAnAdminReadsTheReport()

	public function testTheReportWithoutUnattributedRowsReportsZero(): void {
		$this->audit->method('countByPurpose')->willReturn(['brp' => 1]);

		$response = $this->controller('root', true)->report();

		$this->assertSame(['counts' => ['brp' => 1], 'total' => 1, 'unattributed' => 0], $response->getData());
	}//end testTheReportWithoutUnattributedRowsReportsZero()
}//end class
