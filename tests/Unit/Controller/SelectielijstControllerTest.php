<?php

declare(strict_types=1);

/**
 * The selectielijst endpoints, from the caller's side.
 *
 * The service tests cover parsing, versioning and the diff. What these cover is
 * the contract an archivist meets: who may call, what a missing file or a
 * missing version answers, and that a diff with no `from` falls back to the
 * version actually in use rather than guessing.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */

namespace Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\SelectielijstController;
use OCA\OpenRegister\Service\Archival\SelectielijstImportService;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests SelectielijstController.
 */
class SelectielijstControllerTest extends TestCase {

	private IRequest&MockObject $request;
	private SelectielijstImportService&MockObject $selectielijst;
	private ObjectRetentionHandler&MockObject $settingsHandler;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;
	private SelectielijstController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->selectielijst = $this->createMock(SelectielijstImportService::class);
		$this->settingsHandler = $this->createMock(ObjectRetentionHandler::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->controller = new SelectielijstController(
			'openregister',
			$this->request,
			$this->selectielijst,
			$this->settingsHandler,
			$this->userSession,
			$this->groupManager,
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * Sign somebody in.
	 *
	 * @param bool $isArchivist Whether they are in the archivaris group.
	 *
	 * @return void
	 */
	private function signIn(bool $isArchivist = true): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('archivaris');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isInGroup')->willReturn($isArchivist);
		$this->groupManager->method('isAdmin')->willReturn(false);
	}

	/**
	 * Answer the request params.
	 *
	 * @param array<string, mixed> $params The params.
	 *
	 * @return void
	 */
	private function withParams(array $params): void {
		$this->request->method('getParam')
			->willReturnCallback(
				static function (string $key, $default = null) use ($params) {
					return ($params[$key] ?? $default);
				}
			);
	}

	public function testImportingNeedsTheArchivistRole(): void {
		$this->signIn(isArchivist: false);

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller->import()->getStatus()
		);
	}

	public function testAnImportWithNoFileAndNoContentsSaysWhatToSend(): void {
		$this->signIn();
		$this->withParams([]);
		$this->request->method('getUploadedFile')->willReturn(null);

		$response = $this->controller->import();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('uploaded "file"', $response->getData()['error']);
	}

	public function testAnInlineImportIsParsedAndStored(): void {
		$this->signIn();
		$this->withParams(
			[
				'version' => '2026',
				'filename' => 'lijst.json',
				'contents' => '[{"categorie":"11.1.2"}]',
			]
		);
		$this->request->method('getUploadedFile')->willReturn(null);

		$this->selectielijst->method('parse')->willReturn([['categorie' => '11.1.2']]);
		$this->selectielijst->expects($this->once())
			->method('import')
			->willReturn(['version' => '2026', 'imported' => 1, 'failed' => 0]);

		$response = $this->controller->import();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['imported']);
	}

	/**
	 * A file the import cannot read answers 400 with the reason, not a 500.
	 */
	public function testAFileTheImportRefusesAnswersWithTheReason(): void {
		$this->signIn();
		$this->withParams(['version' => '2026', 'filename' => 'lijst.xlsx', 'contents' => 'x']);
		$this->request->method('getUploadedFile')->willReturn(null);

		$this->selectielijst->method('parse')
			->willThrowException(new InvalidArgumentException('not a ".xlsx"'));

		$response = $this->controller->import();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('.xlsx', $response->getData()['error']);
	}

	public function testVersionsReportsWhatIsStoredAndWhatIsApplied(): void {
		$this->signIn();
		$this->selectielijst->method('versions')->willReturn(['2020' => 40, '2026' => 42]);
		$this->settingsHandler->method('getArchivalSettingsOnly')
			->willReturn(['selectielijstVersion' => '2020']);

		$data = $this->controller->versions()->getData();

		$this->assertSame(['2020' => 40, '2026' => 42], $data['versions']);
		$this->assertSame('2020', $data['inUse']);
	}

	/**
	 * Comparing against what is actually applied is the question an archivist
	 * has before switching, so `from` falls back to the version in use.
	 */
	public function testADiffWithNoFromComparesAgainstTheVersionInUse(): void {
		$this->signIn();
		$this->withParams(['to' => '2026']);
		$this->settingsHandler->method('getArchivalSettingsOnly')
			->willReturn(['selectielijstVersion' => '2020']);

		$this->selectielijst->expects($this->once())
			->method('diff')
			->with('2020', '2026')
			->willReturn(['from' => '2020', 'to' => '2026', 'added' => [], 'removed' => [], 'changed' => []]);

		$this->assertSame(Http::STATUS_OK, $this->controller->diff()->getStatus());
	}

	public function testADiffWithNothingToCompareAgainstSaysSo(): void {
		$this->signIn();
		$this->withParams(['to' => '2026']);
		$this->settingsHandler->method('getArchivalSettingsOnly')->willReturn([]);

		$this->selectielijst->expects($this->never())->method('diff');

		$this->assertSame(
			Http::STATUS_BAD_REQUEST,
			$this->controller->diff()->getStatus()
		);
	}

	public function testADiffAgainstAVersionThatIsNotStoredAnswersWithTheReason(): void {
		$this->signIn();
		$this->withParams(['from' => '2020', 'to' => '2030']);
		$this->selectielijst->method('diff')
			->willThrowException(new InvalidArgumentException('No selectielijst rows are stored under version "2030"'));

		$response = $this->controller->diff();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('2030', $response->getData()['error']);
	}
}
