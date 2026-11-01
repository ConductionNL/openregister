<?php

/**
 * Tests for ExportRunsController: the exports area's filters and scope.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use DateTime;
use OCA\OpenRegister\Controller\ExportRunsController;
use OCA\OpenRegister\Service\Export\ExportRightService;
use OCA\OpenRegister\Service\Export\ExportRunRecorder;
use OCA\OpenRegister\Db\ExportRun;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\File;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Controller\ExportRunsController
 */
class ExportRunsControllerTest extends TestCase {

	/**
	 * @var ExportRunRecorder&MockObject
	 */
	private ExportRunRecorder $runs;

	/**
	 * @var ExportRightService&MockObject
	 */
	private ExportRightService $rights;

	protected function setUp(): void {
		$this->runs = $this->createMock(ExportRunRecorder::class);
		$this->rights = $this->createMock(ExportRightService::class);
	}//end setUp()

	/**
	 * @param array<string, string> $params The query parameters.
	 */
	private function controller(array $params, ?string $uid = 'alice'): ExportRunsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key) => ($params[$key] ?? null));

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new ExportRunsController('openregister', $request, $this->runs, $session, $this->rights);
	}//end controller()

	/**
	 * The scope comes from the authorization layer, not from a rule written
	 * for this page: what ExportRightService answers is what listFor gets.
	 *
	 * @dataProvider scopes
	 */
	public function testTheScopeIsAnsweredByTheExportRight(bool $seesAll): void {
		$this->rights->expects($this->once())->method('seesEveryExportRun')->with('alice')->willReturn($seesAll);
		$this->runs->expects($this->once())
			->method('listFor')
			->with('alice', $seesAll, $this->anything(), 50, 0)
			->willReturn([]);

		$this->assertSame(200, $this->controller([])->index()->getStatus());
	}//end testTheScopeIsAnsweredByTheExportRight()

	/**
	 * @return array<string, array{0: bool}>
	 */
	public static function scopes(): array {
		return ['a handler sees their own' => [false], 'an administrator sees every run' => [true]];
	}//end scopes()

	/**
	 * The period bounds when the export was produced; actor narrows too.
	 */
	public function testThePeriodAndTheActorAreFilters(): void {
		$this->rights->method('seesEveryExportRun')->willReturn(true);
		$this->runs->expects($this->once())
			->method('listFor')
			->with(
				'alice',
				true,
				$this->callback(
					function (array $filters): bool {
						$this->assertSame('bob', $filters['actor']);
						$this->assertSame('5', $filters['register']);
						$this->assertInstanceOf(DateTime::class, $filters['from']);
						$this->assertSame('2026-10-01', $filters['from']->format('Y-m-d'));
						$this->assertSame('2026-10-09', $filters['until']->format('Y-m-d'));

						return true;
					}
				)
			)
			->willReturn([]);

		$response = $this->controller(['actor' => 'bob', 'register' => '5', 'from' => '2026-10-01', 'until' => '2026-10-09T23:59:59Z'])->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('2026-10-01', $response->getData()['filters']['from']);
	}//end testThePeriodAndTheActorAreFilters()

	/**
	 * A date that does not parse is refused: dropping it would widen the list.
	 */
	public function testADateThatDoesNotParseIsRefused(): void {
		$this->runs->expects($this->never())->method('listFor');

		$response = $this->controller(['from' => 'last tuesday-ish!!'])->index();

		$this->assertSame(400, $response->getStatus());
	}//end testADateThatDoesNotParseIsRefused()

	public function testAnAnonymousCallerIsRefused(): void {
		$this->runs->expects($this->never())->method('listFor');

		$this->assertSame(401, $this->controller([], null)->index()->getStatus());
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * A download streams the run's file and counts once on the run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md#requirement-downloads-are-counted-on-the-run
	 */
	public function testADownloadStreamsTheFileAndCountsOnTheRun(): void {
		$this->rights->method('seesEveryExportRun')->with('alice')->willReturn(false);
		$file = $this->createMock(File::class);
		$file->method('fopen')->willReturn(fopen('php://memory', 'r'));
		$file->method('getName')->willReturn('weekly-cases.csv');
		$file->method('getMimetype')->willReturn('text/csv');
		$this->runs->expects($this->once())->method('openForDownload')->with('run-1', 'alice', false)
			->willReturn(['status' => 200, 'run' => new ExportRun(), 'file' => $file]);
		$this->runs->expects($this->once())->method('countDownload')->with('run-1');

		$response = $this->controller([])->download(uuid: 'run-1');

		$this->assertInstanceOf(StreamResponse::class, $response);
		$this->assertStringContainsString('weekly-cases.csv', (string)($response->getHeaders()['Content-Disposition'] ?? ''));
	}//end testADownloadStreamsTheFileAndCountsOnTheRun()

	/**
	 * A refused or expired download counts nothing and says why.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md#requirement-an-exports-area-lists-the-runs
	 */
	public function testAnExpiredDownloadCountsNothing(): void {
		$this->runs->method('openForDownload')->willReturn(['status' => 410, 'error' => 'This export has expired.']);
		$this->runs->expects($this->never())->method('countDownload');

		$response = $this->controller([])->download(uuid: 'run-1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(410, $response->getStatus());
	}//end testAnExpiredDownloadCountsNothing()

	/**
	 * Nobody signed in, nothing served.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md#requirement-an-exports-area-lists-the-runs
	 */
	public function testAnAnonymousDownloadIsRefused(): void {
		$this->runs->expects($this->never())->method('openForDownload');

		$this->assertSame(401, $this->controller([], null)->download(uuid: 'run-1')->getStatus());
	}//end testAnAnonymousDownloadIsRefused()
}//end class
