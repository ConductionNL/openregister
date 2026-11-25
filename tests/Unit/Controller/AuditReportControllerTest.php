<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use DateTime;
use OCA\OpenRegister\Controller\AuditReportController;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailCriteria;
use OCA\OpenRegister\Db\AuditTrailPageQueryInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Audit\AuditReport;
use OCA\OpenRegister\Service\Audit\AuditReportFile;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * GET /api/reports/audit: administrators only, a register by slug or id, the format checked before
 * anything is read, and a bad period answered as 400.
 *
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-audit-report-generation
 */
class AuditReportControllerTest extends TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $filters = [];

	/** @var array<string, mixed> */
	private array $params = [];

	private ?\Throwable $trailFailure = null;

	private function controller(?bool $admin): AuditReportController {
		$session = $this->createMock(IUserSession::class);
		$groups = $this->createMock(IGroupManager::class);
		if ($admin === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('someone');
			$session->method('getUser')->willReturn($user);
			$groups->method('isAdmin')->with('someone')->willReturn($admin);
		}

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $name, mixed $default = null): mixed => ($this->params[$name] ?? $default)
		);

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturnCallback(
			function (string|int $id): Register {
				if ($id !== 'zaken') {
					throw new DoesNotExistException('no');
				}

				$register = new Register();
				$register->setId(7);

				return $register;
			}
		);

		$trail = $this->createMock(AuditTrailPageQueryInterface::class);
		$trail->method('collect')->willReturnCallback(
			function (AuditTrailCriteria $criteria): array {
				if ($this->trailFailure !== null) {
					throw $this->trailFailure;
				}

				$this->filters[] = $criteria->filters;
				$entry = new AuditTrail();
				$entry->setId(1);
				$entry->setCreated(new DateTime('2026-03-05T09:00:00+00:00'));
				$entry->setAction('update');
				$entry->setUser('medewerker-1');

				return ['results' => [$entry], 'truncated' => false];
			}
		);

		return new AuditReportController(
			request: $request,
			userSession: $session,
			groupManager: $groups,
			registers: $registers,
			report: new AuditReport(trail: $trail, objects: $this->createMock(ObjectOperationsInterface::class)),
			file: new AuditReportFile(),
			logger: new NullLogger()
		);
	}

	public function testNobodySignedInGets401AndNothingIsRead(): void {
		$response = $this->controller(admin: null)->audit();

		$this->assertSame(401, $response->getStatus());
		$this->assertSame([], $this->filters);
	}

	public function testANonAdministratorGets403AndNothingIsRead(): void {
		$response = $this->controller(admin: false)->audit();

		$this->assertSame([403, ['error' => 'Only administrators may generate audit reports.']], [$response->getStatus(), $response->getData()]);
		$this->assertSame([], $this->filters);
	}

	public function testAnAdministratorGetsAnExcelFileByDefault(): void {
		$this->params = ['register' => 'zaken', 'from' => '2026-03-01', 'to' => '2026-03-31'];

		$response = $this->controller(admin: true)->audit();

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->getHeaders()['Content-Type']);
		$this->assertStringStartsWith('PK', $response->render());
		$this->assertSame([['register' => '7', 'from' => '2026-03-01', 'to' => '2026-03-31']], $this->filters);
	}

	public function testOneUsersActivityAsCsv(): void {
		$this->params = ['userId' => 'medewerker-1', 'format' => 'CSV'];

		$response = $this->controller(admin: true)->audit();

		$this->assertSame('text/csv', $response->getHeaders()['Content-Type']);
		$this->assertStringContainsString('2026-03-05T09:00:00+00:00,update,,,medewerker-1', $response->render());
		$this->assertSame([['actor' => 'medewerker-1']], $this->filters);
	}

	public function testAnUnknownFormatIs400BeforeAnythingIsRead(): void {
		$this->params = ['format' => 'pdf'];

		$response = $this->controller(admin: true)->audit();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('Unsupported format "pdf"; use xlsx, ods, csv.', $response->getData()['error']);
		$this->assertSame([], $this->filters);
	}

	public function testAnUnknownRegisterIs404(): void {
		$this->params = ['register' => 'nope'];

		$response = $this->controller(admin: true)->audit();

		$this->assertSame([404, ['error' => 'Register "nope" not found.']], [$response->getStatus(), $response->getData()]);
		$this->assertSame([], $this->filters);
	}

	public function testABadPeriodIs400WithItsReason(): void {
		$this->trailFailure = new \InvalidArgumentException('"from" is not a date: soon');

		$response = $this->controller(admin: true)->audit();

		$this->assertSame([400, ['error' => '"from" is not a date: soon']], [$response->getStatus(), $response->getData()]);
	}

	public function testAnyOtherFailureIsAGeneric500(): void {
		$this->trailFailure = new RuntimeException('SQLSTATE secret');

		$response = $this->controller(admin: true)->audit();

		$this->assertSame([500, ['error' => 'The audit report could not be generated.']], [$response->getStatus(), $response->getData()]);
	}
}
