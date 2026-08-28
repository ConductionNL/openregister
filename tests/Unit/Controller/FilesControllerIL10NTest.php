<?php

/**
 * FilesControllerIL10NTest
 *
 * Proves the IL10N error-wrapping contract added to FilesController for the
 * file-actions change (Phase 10, "Verify i18n: all error messages use the
 * localization service"). The controller routes its self-authored error
 * messages through a private t() helper that:
 *   - delegates to IL10N::t() when an IL10N service is wired, and
 *   - returns the raw English source string unchanged when no IL10N is wired
 *     (backward-compatible, so the error shape never changes).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\FilesController;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FilesControllerIL10NTest extends TestCase {

	private IRequest&MockObject $request;

	private FileService&MockObject $fileService;

	private ObjectService&MockObject $objectService;

	private IRootFolder&MockObject $rootFolder;

	private IUserManager&MockObject $userManager;

	private IEventDispatcher&MockObject $eventDispatcher;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->fileService = $this->createMock(FileService::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);

		// Object resolution returns null so every method short-circuits to its
		// "Object not found" / "Source object not found" error path, which is
		// exactly the controller-authored string we want to assert on.
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setObject')->willReturnSelf();
		$this->objectService->method('getObject')->willReturn(null);
	}//end setUp()

	private function buildController(?IL10N $l10n): FilesController {
		return new FilesController(
			'openregister',
			$this->request,
			$this->fileService,
			$this->objectService,
			$this->rootFolder,
			$this->userManager,
			$this->eventDispatcher,
			null,
			null,
			null,
			$l10n
		);
	}//end buildController()

	/**
	 * When an IL10N service is wired, the controller routes its error message
	 * through IL10N::t() — proving the strings are translatable.
	 *
	 * @return void
	 */
	public function testErrorMessageIsTranslatedWhenL10nWired(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->expects($this->atLeastOnce())
			->method('t')
			->with('Object not found', [])
			->willReturn('Object niet gevonden');

		$controller = $this->buildController($l10n);

		$response = $controller->rename('reg', 'sch', 'missing-id', 42);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(404, $response->getStatus());
		$this->assertEquals(['error' => 'Object niet gevonden'], $response->getData());
	}//end testErrorMessageIsTranslatedWhenL10nWired()

	/**
	 * When no IL10N service is wired (legacy DI), the raw English source string
	 * is returned unchanged — the error shape and status code are preserved.
	 *
	 * @return void
	 */
	public function testErrorMessageFallsBackToSourceStringWhenNoL10n(): void {
		$controller = $this->buildController(null);

		$response = $controller->rename('reg', 'sch', 'missing-id', 42);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(404, $response->getStatus());
		$this->assertEquals(['error' => 'Object not found'], $response->getData());
	}//end testErrorMessageFallsBackToSourceStringWhenNoL10n()

	/**
	 * The localisation wrapping does NOT disturb the status-code mapping that
	 * keys off FileService exception substrings. A copy with a missing target
	 * object id still returns the controller-authored 400 with the localised
	 * message.
	 *
	 * @return void
	 */
	public function testCopyMissingTargetIsTranslatedWith400(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text): string {
				return 'NL:' . $text;
			}
		);

		// Source object must resolve so we reach the target-id validation.
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setObject')->willReturnSelf();
		$source = new \OCA\OpenRegister\Db\ObjectEntity();
		$source->setUuid('src-1');
		$this->objectService->method('getObject')->willReturn($source);
		$this->request->method('getParams')->willReturn(['targetObjectId' => '']);

		$controller = $this->buildController($l10n);

		$response = $controller->copy('reg', 'sch', 'src-1', 42);

		$this->assertEquals(400, $response->getStatus());
		$this->assertEquals(['error' => 'NL:Target object ID is required'], $response->getData());
	}//end testCopyMissingTargetIsTranslatedWith400()
}//end class
