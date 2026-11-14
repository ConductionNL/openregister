<?php

/**
 * Unit tests for the import run endpoints: the failed-row export, the pruned
 * run, and the retry that names its original.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Controller\ImportPreviewController;
use OCA\OpenRegister\Db\ImportPreview;
use OCA\OpenRegister\Db\ImportPreviewMapper;
use OCA\OpenRegister\Db\ImportPreviewRow;
use OCA\OpenRegister\Db\ImportPreviewRowMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\Import\ImportPreviewService;
use OCA\OpenRegister\Service\Import\SourceRowReader;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ImportPreviewControllerFactory;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class ImportRunControllerTest extends TestCase {

	private ImportPreviewService $service;

	private ImportPreviewMapper $previewMapper;

	private ImportPreviewRowMapper $rowMapper;

	private RegisterMapper $registerMapper;

	private IUserSession $userSession;

	private IGroupManager $groupManager;

	private IRequest $request;

	private array $params = [];

	private ?array $upload = null;

	protected function setUp(): void {
		parent::setUp();

		$this->service = $this->createMock(ImportPreviewService::class);
		$this->previewMapper = $this->createMock(ImportPreviewMapper::class);
		$this->rowMapper = $this->createMock(ImportPreviewRowMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->request = $this->createMock(IRequest::class);

		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => ($this->params[$key] ?? $default)
		);
		$this->request->method('getUploadedFile')->willReturnCallback(fn (): ?array => $this->upload);
	}

	private function signIn(string $uid, bool $admin = false): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn($admin);
	}

	private function controller(): ImportPreviewController {
		return ImportPreviewControllerFactory::create(
			'openregister',
			$this->request,
			$this->service,
			$this->previewMapper,
			$this->rowMapper,
			new SourceRowReader(),
			$this->registerMapper,
			$this->createMock(IJobList::class),
			$this->userSession,
			$this->groupManager
		);
	}

	private function runOf(string $owner, ?DateTime $prunedAt = null): ImportPreview {
		$run = new ImportPreview();
		$run->setId(7);
		$run->setUuid('run-7');
		$run->setCreatedBy($owner);
		$run->setRowsPrunedAt($prunedAt);

		return $run;
	}

	private function refusedRow(): ImportPreviewRow {
		$row = new ImportPreviewRow();
		$row->setSourceRow(4);
		$row->setDecision(ImportPreviewRow::DECISION_REFUSE);
		$row->setReason('Policy refuse-on-conflict, and the match key hits object-9.');
		$row->setSource(['bsn' => '999', 'naam' => 'Bakker']);

		return $row;
	}

	private function manageableRegister(): void {
		$register = new Register();
		$register->setId(1);
		$register->setAuthorization([]);
		$this->registerMapper->method('find')->willReturn($register);
	}

	public function testTheOwnerExportsTheFailedRowsAsCsv(): void {
		$this->signIn('alice');
		$this->previewMapper->method('find')->with(7)->willReturn($this->runOf('alice'));
		$this->rowMapper->method('findFailed')->willReturn([$this->refusedRow()]);

		$response = $this->controller()->failed(7);

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame(
			"_row,_reason,bsn,naam\n4,\"Policy refuse-on-conflict, and the match key hits object-9.\",999,Bakker\n",
			$response->render()
		);
		$this->assertStringContainsString('import-run-7-failed.csv', (string)$response->getHeaders()['Content-Disposition']);
	}

	public function testSomeoneElsesFailedRowsReadAsMissing(): void {
		$this->signIn('alice');
		$this->previewMapper->method('find')->willReturn($this->runOf('bob'));
		$this->rowMapper->expects($this->never())->method('findFailed');

		$response = $this->controller()->failed(7);

		$this->assertSame(404, $response->getStatus());
	}

	public function testAPrunedRunSaysSoRatherThanExportingNothing(): void {
		$this->signIn('alice');
		$this->previewMapper->method('find')->willReturn($this->runOf('alice', new DateTime('2026-11-01')));
		$this->rowMapper->expects($this->never())->method('findFailed');

		$response = $this->controller()->failed(7);

		$this->assertSame(410, $response->getStatus());
		$this->assertTrue($response->getData()['pruned']);
	}

	public function testTheRowsOfAPrunedRunSayTheyWerePruned(): void {
		$this->signIn('alice');
		$this->previewMapper->method('find')->willReturn($this->runOf('alice', new DateTime('2026-11-01')));
		$this->rowMapper->method('findByPreview')->willReturn([]);

		$data = $this->controller()->rows(7)->getData();

		$this->assertSame([], $data['results']);
		$this->assertTrue($data['pruned']);
	}

	public function testARetryNamesTheOriginalRunItMayRead(): void {
		$this->signIn('root', true);
		$this->manageableRegister();
		$this->upload = ['tmp_name' => '/tmp/corrected.csv', 'name' => 'corrected.csv'];
		$this->params = ['register' => 1, 'retryOf' => '7'];
		$this->previewMapper->method('find')->with(7)->willReturn($this->runOf('alice'));

		$this->service->expects($this->once())->method('preview')
			->with($this->callback(static fn (array $params): bool => ($params['causeRun'] ?? null) === 'run-7'))
			->willReturn($this->runOf('root'));

		$this->assertSame(201, $this->controller()->create()->getStatus());
	}

	public function testARetryOfSomeoneElsesRunIsRefusedBeforeAnythingIsRead(): void {
		$this->signIn('alice');
		$register = new Register();
		$register->setId(1);
		$register->setAuthorization(['manage' => ['editors']]);
		$this->registerMapper->method('find')->willReturn($register);
		$this->groupManager->method('getUserGroupIds')->willReturn(['editors']);
		$this->upload = ['tmp_name' => '/tmp/corrected.csv', 'name' => 'corrected.csv'];
		$this->params = ['register' => 1, 'retryOf' => '7'];
		$this->previewMapper->method('find')->willReturn($this->runOf('bob'));
		$this->service->expects($this->never())->method('preview');

		$response = $this->controller()->create();

		// Alice may manage the register, but bob's run reads as missing to her.
		$this->assertSame([404, ['error' => 'Import preview not found']], [$response->getStatus(), $response->getData()]);
	}

	public function testARetryNamedByAnythingButAnIdIsRefused(): void {
		$this->signIn('root', true);
		$this->manageableRegister();
		$this->upload = ['tmp_name' => '/tmp/corrected.csv', 'name' => 'corrected.csv'];
		$this->params = ['register' => 1, 'retryOf' => 'run-7'];
		$this->previewMapper->expects($this->never())->method('find');
		$this->service->expects($this->never())->method('preview');

		$response = $this->controller()->create();

		$this->assertSame(400, $response->getStatus());
	}
}
