<?php

/**
 * Unit tests for ImportPreviewController — the refusals that happen before a
 * file is read.
 *
 * The decisions themselves are tested in
 * tests/Unit/Service/Import/ImportPreviewServiceTest.php. What is tested here
 * is the surface: a caller who is not signed in, a register that does not
 * exist, a register the caller may not manage, a preview belonging to someone
 * else, and a commit the service refuses. Each has to be answered with the
 * right status and nothing else happening.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\ImportPreviewController;
use OCA\OpenRegister\Db\ImportPreview;
use OCA\OpenRegister\Db\ImportPreviewMapper;
use OCA\OpenRegister\Db\ImportPreviewRow;
use OCA\OpenRegister\Db\ImportPreviewRowMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\Import\ConflictPolicy;
use OCA\OpenRegister\Service\Import\ImportPreviewRefusedException;
use OCA\OpenRegister\Service\Import\ImportPreviewService;
use OCA\OpenRegister\Service\Import\SourceRowReader;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class ImportPreviewControllerTest extends TestCase {

	/**
	 * The preview lifecycle the controller delegates to.
	 *
	 * @var ImportPreviewService
	 */
	private ImportPreviewService $service;

	/**
	 * Preview persistence.
	 *
	 * @var ImportPreviewMapper
	 */
	private ImportPreviewMapper $previewMapper;

	/**
	 * Per-row decision persistence.
	 *
	 * @var ImportPreviewRowMapper
	 */
	private ImportPreviewRowMapper $rowMapper;

	/**
	 * Register lookup.
	 *
	 * @var RegisterMapper
	 */
	private RegisterMapper $registerMapper;

	/**
	 * The current-user session.
	 *
	 * @var IUserSession
	 */
	private IUserSession $userSession;

	/**
	 * The group manager, for the administrator branch.
	 *
	 * @var IGroupManager
	 */
	private IGroupManager $groupManager;

	/**
	 * The request, whose parameters each test sets.
	 *
	 * @var IRequest
	 */
	private IRequest $request;

	/**
	 * The request parameters for the test in hand.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * The uploaded file for the test in hand.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $upload = null;

	protected function setUp(): void {
		parent::setUp();

		$this->params = [];
		$this->upload = null;

		$this->service = $this->createMock(ImportPreviewService::class);
		$this->previewMapper = $this->createMock(ImportPreviewMapper::class);
		$this->rowMapper = $this->createMock(ImportPreviewRowMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->request = $this->createMock(IRequest::class);

		$this->request->method('getParam')->willReturnCallback(
			function (string $key, $default = null) {
				return ($this->params[$key] ?? $default);
			}
		);
		$this->request->method('getUploadedFile')->willReturnCallback(
			function (): ?array {
				return $this->upload;
			}
		);
	}

	private function signIn(?string $uid, bool $admin = false): void {
		if ($uid === null) {
			$this->userSession->method('getUser')->willReturn(null);

			return;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn($admin);
	}

	private function controller(): ImportPreviewController {
		return new ImportPreviewController(
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

	/**
	 * A register with no manage rule, which is the default-secure case.
	 *
	 * @return Register The register.
	 */
	private function lockedRegister(): Register {
		$register = new Register();
		$register->setId(1);
		$register->setAuthorization([]);

		return $register;
	}

	public function testThePolicyCatalogueNamesTheFourPoliciesAndTheDefault(): void {
		$this->signIn('alice');

		$body = $this->controller()->policies()->getData();
		$ids = array_column($body['results'], 'id');

		$this->assertSame(ConflictPolicy::POLICIES, $ids);
		$this->assertSame(ConflictPolicy::UPSERT, $body['default']);
	}

	public function testAnUnauthenticatedCallerIsRefused(): void {
		$this->signIn(null);

		$this->assertSame(401, $this->controller()->policies()->getStatus());
	}

	public function testAPreviewWithNoFileIsRefused(): void {
		$this->signIn('alice');
		$this->service->expects($this->never())->method('preview');

		$response = $this->controller()->create();

		$this->assertSame(400, $response->getStatus());
	}

	public function testAPreviewIntoAnUnknownRegisterIsRefused(): void {
		$this->signIn('alice');
		$this->upload = ['tmp_name' => '/dev/null', 'name' => 'personen.csv'];
		$this->params['register'] = 'nergens';
		$this->registerMapper->method('find')->willThrowException(new DoesNotExistException('nope'));

		$this->service->expects($this->never())->method('preview');

		$this->assertSame(404, $this->controller()->create()->getStatus());
	}

	/**
	 * A preview is the first half of a write, so it is gated like one. A
	 * caller who may not manage the register never reaches the file.
	 *
	 * @return void
	 */
	public function testAPreviewIntoARegisterTheCallerMayNotManageIsRefused(): void {
		$this->signIn('alice');
		$this->upload = ['tmp_name' => '/dev/null', 'name' => 'personen.csv'];
		$this->params['register'] = 'migratie';
		$this->registerMapper->method('find')->willReturn($this->lockedRegister());

		$this->service->expects($this->never())->method('preview');

		$this->assertSame(403, $this->controller()->create()->getStatus());
	}

	public function testAnAdminMayPreviewIntoARegisterWithNoManageRule(): void {
		$this->signIn('root', true);
		$this->upload = ['tmp_name' => '/dev/null', 'name' => 'personen.csv'];
		$this->params['register'] = 'migratie';
		$this->registerMapper->method('find')->willReturn($this->lockedRegister());

		$preview = new ImportPreview();
		$preview->setId(7);
		$preview->setState(ImportPreview::STATE_PREVIEWED);
		$this->service->expects($this->once())->method('preview')->willReturn($preview);

		$response = $this->controller()->create();

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(7, $response->getData()['id']);
	}

	public function testSomeoneElsesPreviewReadsAsMissingRatherThanForbidden(): void {
		$this->signIn('alice');

		$preview = new ImportPreview();
		$preview->setId(7);
		$preview->setCreatedBy('bob');
		$this->previewMapper->method('find')->willReturn($preview);

		$this->assertSame(404, $this->controller()->show(7)->getStatus());
	}

	/**
	 * The per-row decisions are the thing an operator reads before deciding
	 * whether to commit, so the route has to hand back the reason with the
	 * decision, and the filter has to reach the mapper.
	 *
	 * @return void
	 */
	public function testTheRowsRouteReturnsTheDecisionsAndTheirReasons(): void {
		$this->signIn('alice');
		$this->params['decision'] = 'refuse';

		$preview = new ImportPreview();
		$preview->setId(7);
		$preview->setCreatedBy('alice');
		$preview->setTotal(3);
		$this->previewMapper->method('find')->willReturn($preview);

		$refused = new ImportPreviewRow();
		$refused->setPreviewId(7);
		$refused->setRowNumber(2);
		$refused->setDecision(ImportPreviewRow::DECISION_REFUSE);
		$refused->setReason('The match key hits more than one object: object-a, object-b.');
		$refused->setCandidates(['object-a', 'object-b']);

		$this->rowMapper->expects($this->once())
			->method('findByPreview')
			->with(7, 'refuse', 100, 0)
			->willReturn([$refused]);

		$response = $this->controller()->rows(7);
		$body = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(3, $body['total']);
		$this->assertCount(1, $body['results']);
		$this->assertSame('refuse', $body['results'][0]->jsonSerialize()['decision']);
		$this->assertStringContainsString('more than one object', $body['results'][0]->jsonSerialize()['reason']);
	}

	/**
	 * An empty `decision` is not a filter on the empty string. Passing it
	 * through would answer with no rows and look exactly like a preview that
	 * decided nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyDecisionFilterIsReadAsNoFilter(): void {
		$this->signIn('alice');
		$this->params['decision'] = '';

		$preview = new ImportPreview();
		$preview->setId(7);
		$preview->setCreatedBy('alice');
		$this->previewMapper->method('find')->willReturn($preview);

		$this->rowMapper->expects($this->once())
			->method('findByPreview')
			->with(7, null, 100, 0)
			->willReturn([]);

		$this->assertSame(200, $this->controller()->rows(7)->getStatus());
	}

	public function testACommitTheServiceRefusesAnswersWithAConflict(): void {
		$this->signIn('alice');
		$this->params['sourceHash'] = 'not-the-file-that-was-previewed';

		$preview = new ImportPreview();
		$preview->setId(7);
		$preview->setCreatedBy('alice');
		$preview->setState(ImportPreview::STATE_PREVIEWED);
		$this->previewMapper->method('find')->willReturn($preview);

		$this->service->method('commit')->willThrowException(
			new ImportPreviewRefusedException('The source file changed since it was previewed.')
		);

		$response = $this->controller()->commit(7);

		$this->assertSame(409, $response->getStatus());
		$this->assertStringContainsString('changed since it was previewed', $response->getData()['error']);
	}

	public function testACommitAppliesThePreviewAndReportsIt(): void {
		$this->signIn('alice');
		$this->params['sourceHash'] = 'the-same-file';

		$preview = new ImportPreview();
		$preview->setId(7);
		$preview->setCreatedBy('alice');
		$preview->setState(ImportPreview::STATE_PREVIEWED);
		$this->previewMapper->method('find')->willReturn($preview);

		$committed = new ImportPreview();
		$committed->setId(7);
		$committed->setState(ImportPreview::STATE_COMMITTED);
		$committed->setApplied(3);
		$this->service->expects($this->once())->method('commit')->willReturn($committed);

		$response = $this->controller()->commit(7);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('committed', $response->getData()['state']);
		$this->assertSame(3, $response->getData()['applied']);
	}
}
