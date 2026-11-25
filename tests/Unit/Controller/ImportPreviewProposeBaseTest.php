<?php

/**
 * `POST /api/import-previews/base`: read a whole base and propose its register (REQ-IPC-006).
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
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ImportPreviewController;
use OCA\OpenRegister\Db\ImportPreviewMapper;
use OCA\OpenRegister\Db\ImportPreviewRowMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\Import\BaseImportPlanner;
use OCA\OpenRegister\Service\Import\BaserowBaseReader;
use OCA\OpenRegister\Service\Import\BaseSchemaProposer;
use OCA\OpenRegister\Service\Import\BaseSetReader;
use OCA\OpenRegister\Service\Import\ImportPreviewService;
use OCA\OpenRegister\Service\Import\SourceRowReader;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ImportPreviewControllerFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The whole-base proposal for an administrator, a manager and an anonymous caller.
 */
#[CoversClass(ImportPreviewController::class)]
class ImportPreviewProposeBaseTest extends TestCase {

	private const FIXTURE = __DIR__.'/../../Fixtures/import/baserow-projecten-taken.json';

	/**
	 * An administrator uploading the two-table export gets the plan, and nothing is written.
	 *
	 * @return void
	 */
	public function testAnAdministratorGetsThePlan(): void {
		$registers = $this->createMock(RegisterMapper::class);
		$registers->expects($this->never())->method('insert');

		$response = $this->controller(uid: 'beheerder', admin: true, upload: ['tmp_name' => self::FIXTURE, 'name' => 'base.json'], registers: $registers)->proposeBase();

		self::assertSame(200, $response->getStatus());
		self::assertSame(['Projecten', 'Taken'], $response->getData()['order']);
		self::assertSame(['Projecten', 'Taken'], array_keys($response->getData()['proposal']['schemas']));
	}//end testAnAdministratorGetsThePlan()

	/**
	 * A signed-in non-administrator cannot create a register, so gets 403 before the upload is read.
	 *
	 * @return void
	 */
	public function testANonAdministratorIsRefused(): void {
		$response = $this->controller(uid: 'sanne', admin: false, upload: ['tmp_name' => self::FIXTURE])->proposeBase();

		self::assertSame(403, $response->getStatus());
	}//end testANonAdministratorIsRefused()

	/**
	 * No session is 401; no file and an unreadable format are 400.
	 *
	 * @return void
	 */
	public function testMissingSessionFileOrFormatAreRefused(): void {
		self::assertSame(401, $this->controller(uid: null, admin: false, upload: null)->proposeBase()->getStatus());
		self::assertSame(400, $this->controller(uid: 'beheerder', admin: true, upload: null)->proposeBase()->getStatus());

		$response = $this->controller(uid: 'beheerder', admin: true, upload: ['tmp_name' => self::FIXTURE], format: 'nocodb')->proposeBase();
		self::assertSame(400, $response->getStatus());
		self::assertStringContainsString('readable formats: baserow', $response->getData()['error']);
	}//end testMissingSessionFileOrFormatAreRefused()

	/**
	 * The controller for one caller and one upload.
	 *
	 * @param string|null              $uid       The signed-in user, or none.
	 * @param bool                     $admin     Whether they are an administrator.
	 * @param array<string, mixed>|null $upload    The uploaded file, or none.
	 * @param string                   $format    The format parameter.
	 * @param RegisterMapper|null      $registers The register mapper.
	 *
	 * @return ImportPreviewController The controller.
	 */
	private function controller(?string $uid, bool $admin, ?array $upload, string $format = 'baserow', ?RegisterMapper $registers = null): ImportPreviewController {
		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->with('file')->willReturn($upload);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $key === 'format' ? $format : $default);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		return ImportPreviewControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(ImportPreviewService::class),
			$this->createMock(ImportPreviewMapper::class),
			$this->createMock(ImportPreviewRowMapper::class),
			new SourceRowReader(),
			($registers ?? $this->createMock(RegisterMapper::class)),
			$this->createMock(IJobList::class),
			$session,
			$groups,
			basePlanner: new BaseImportPlanner(reader: new BaseSetReader(new BaserowBaseReader()), proposer: new BaseSchemaProposer())
		);
	}//end controller()
}//end class
