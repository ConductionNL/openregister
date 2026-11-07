<?php

/**
 * Golden outcomes of the selectielijst endpoints' archivist gate and file intake.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\SelectielijstController;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Service\Archival\SelectielijstImportService;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins who may call each selectielijst action, and what each intake path answers.
 */
class SelectielijstControllerA24GoldenTest extends TestCase {
	/**
	 * The request of the case.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;
	/**
	 * The service of the case.
	 *
	 * @var SelectielijstImportService&MockObject
	 */
	private SelectielijstImportService&MockObject $service;
	/**
	 * The settings of the case.
	 *
	 * @var ObjectRetentionHandler&MockObject
	 */
	private ObjectRetentionHandler&MockObject $settings;
	/**
	 * The userSession of the case.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;
	/**
	 * The groups of the case.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groups;
	/**
	 * The logger of the case.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;
	/**
	 * The controller of the case.
	 *
	 * @var SelectielijstController
	 */
	private SelectielijstController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(SelectielijstImportService::class);
		$this->settings = $this->createMock(ObjectRetentionHandler::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->controller = new SelectielijstController(
			'openregister',
			$this->request,
			$this->service,
			$this->settings,
			new ControllerSession($this->userSession, $this->groups, $this->logger)
		);
	}

	private function signIn(bool $archivist, bool $admin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groups->method('isInGroup')->with('bob', 'archivaris')->willReturn($archivist);
		$this->groups->method('isAdmin')->with('bob')->willReturn($admin);
	}

	private function params(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function actions(): array {
		return ['import' => ['import'], 'versions' => ['versions'], 'diff' => ['diff']];
	}

	/**
	 * @param string $action The action.
	 *
	 * @return void
	 *
	 * @dataProvider actions
	 */
	public function testAnAnonymousCallerIsRefused401(string $action): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->service->expects($this->never())->method($this->anything());

		$response = $this->controller->{$action}();

		$this->assertSame([401, ['error' => 'Niet geauthenticeerd']], [$response->getStatus(), $response->getData()]);
	}

	/**
	 * @param string $action The action.
	 *
	 * @return void
	 *
	 * @dataProvider actions
	 */
	public function testANonArchivistNonAdminIsRefused403(string $action): void {
		$this->signIn(archivist: false, admin: false);
		$this->service->expects($this->never())->method($this->anything());
		$this->request->expects($this->never())->method('getParam');

		$response = $this->controller->{$action}();

		$this->assertSame(
			[403, ['error' => 'Onvoldoende rechten: archivaris rol is vereist']],
			[$response->getStatus(), $response->getData()]
		);
	}

	/**
	 * @return array<string, array{0: bool, 1: bool}>
	 */
	public static function allowed(): array {
		return ['archivist' => [true, false], 'admin' => [false, true], 'both' => [true, true]];
	}

	/**
	 * @param bool $archivist In the archivaris group.
	 * @param bool $admin     An administrator.
	 *
	 * @return void
	 *
	 * @dataProvider allowed
	 */
	public function testAnArchivistOrAdminReadsTheVersions(bool $archivist, bool $admin): void {
		$this->signIn(archivist: $archivist, admin: $admin);
		$this->service->method('versions')->willReturn([['version' => '2020']]);
		$this->settings->method('getArchivalSettingsOnly')->willReturn(['selectielijstVersion' => ' 2020 ']);

		$response = $this->controller->versions();

		$this->assertSame([200, ['versions' => [['version' => '2020']], 'inUse' => '2020']], [$response->getStatus(), $response->getData()]);
	}

	public function testUnreadableSettingsReportNoVersionInUseAndWarn(): void {
		$this->signIn(archivist: true, admin: false);
		$this->service->method('versions')->willReturn([]);
		$this->settings->method('getArchivalSettingsOnly')->willThrowException(new RuntimeException('db'));
		$this->logger->expects($this->once())->method('warning')
			->with('[SelectielijstController] Could not read the archival settings: db');

		$this->assertNull($this->controller->versions()->getData()['inUse']);
	}

	public function testAnUploadedFileIsImportedUnderItsOwnName(): void {
		$this->signIn(archivist: true, admin: false);
		$path = tempnam(sys_get_temp_dir(), 'sl');
		file_put_contents($path, "a;b\n1;2\n");
		$this->params(['filename' => 'ignored.csv', 'version' => '2024']);
		$this->request->method('getUploadedFile')->with('file')->willReturn(['tmp_name' => $path, 'name' => 'lijst.csv']);
		$this->service->expects($this->once())->method('parse')->with("a;b\n1;2\n", 'lijst.csv')->willReturn([['r']]);
		$this->service->expects($this->once())->method('import')->with([['r']], '2024', 'lijst.csv')->willReturn(['stored' => 1]);

		$response = $this->controller->import();
		unlink($path);

		$this->assertSame([200, ['stored' => 1]], [$response->getStatus(), $response->getData()]);
	}

	public function testInlineContentsWithoutAFilenameImportWithoutASource(): void {
		$this->signIn(archivist: false, admin: true);
		$this->params(['contents' => 'x;y']);
		$this->service->method('parse')->with('x;y', '')->willReturn([]);
		$this->service->expects($this->once())->method('import')->with([], '', null)->willReturn([]);

		$this->assertSame(200, $this->controller->import()->getStatus());
	}

	/**
	 * @return array<string, array{0: mixed, 1: array<string, mixed>}>
	 */
	public static function nothingSent(): array {
		return [
			'empty upload'   => ['empty', []],
			'missing upload' => ['missing', []],
			'blank inline'   => [null, ['contents' => '   ']],
			'non-string'     => [null, ['contents' => ['x']]],
		];
	}

	/**
	 * @param mixed                $upload What the upload answers.
	 * @param array<string, mixed> $params The request params.
	 *
	 * @return void
	 *
	 * @dataProvider nothingSent
	 */
	public function testNothingUsableSentSaysWhatToSend(mixed $upload, array $params): void {
		$this->signIn(archivist: true, admin: false);
		$this->params($params);
		$file = null;
		if ($upload === 'empty') {
			$file = tempnam(sys_get_temp_dir(), 'sl');
			file_put_contents($file, "  \n");
			$upload = ['tmp_name' => $file];
		} elseif ($upload === 'missing') {
			$upload = ['tmp_name' => sys_get_temp_dir() . '/does-not-exist-' . uniqid()];
		}
		$this->request->method('getUploadedFile')->willReturn($upload);
		$this->service->expects($this->never())->method('parse');

		$response = $this->controller->import();
		if ($file !== null) {
			unlink($file);
		}

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(
			['error' => 'Send the selectielijst as an uploaded "file", or inline as "contents" with a "filename"'],
			$response->getData()
		);
	}

	public function testAnUnexpectedImportFailureIsLoggedAndAnswered500(): void {
		$this->signIn(archivist: true, admin: false);
		$this->params(['contents' => 'x', 'filename' => 'f.csv']);
		$this->service->method('parse')->willThrowException(new RuntimeException('disk'));
		$this->logger->expects($this->once())->method('error')->with('[SelectielijstController] Import failed: disk');

		$response = $this->controller->import();

		$this->assertSame([500, ['error' => 'The selectielijst could not be imported']], [$response->getStatus(), $response->getData()]);
	}

	public function testADiffWithExplicitVersionsAnswersTheServiceDiff(): void {
		$this->signIn(archivist: true, admin: false);
		$this->params(['from' => '2020', 'to' => '2024']);
		$this->settings->expects($this->never())->method('getArchivalSettingsOnly');
		$this->service->method('diff')->with('2020', '2024')->willReturn(['changed' => []]);

		$response = $this->controller->diff();

		$this->assertSame([200, ['changed' => []]], [$response->getStatus(), $response->getData()]);
	}
}
