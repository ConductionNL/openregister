<?php

/**
 * Characterisation of the run record ExportProfilesController writes.
 *
 * Pins what the served export is recorded as: the source, the caller as the
 * actor, the profile, the served status, the format, the row count, the file
 * name and a download count of one, with no retention. A bookkeeping failure
 * never costs the caller the export.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\ExportProfilesController;
use OCA\OpenRegister\Db\ExportProfile;
use OCA\OpenRegister\Db\ExportRun;
use OCA\OpenRegister\Service\Export\ExportProfileService;
use OCA\OpenRegister\Service\Export\ExportRunContent;
use OCA\OpenRegister\Service\Export\ExportRunFile;
use OCA\OpenRegister\Service\Export\ExportRunOrigin;
use OCA\OpenRegister\Service\Export\ExportRunRecorder;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ExportProfilesControllerFactory;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExportProfilesRunRecordTest extends TestCase {

	/**
	 * A controller for the caller `eigenaar-1`, recording through the given recorder.
	 *
	 * @param ExportRunRecorder $recorder The recorder double.
	 *
	 * @return ExportProfilesController
	 */
	private function controller(ExportRunRecorder $recorder): ExportProfilesController {
		$profile = new ExportProfile();
		$profile->setUuid('profile-uuid-1');
		$profile->setOwner('eigenaar-1');
		$profile->setName('Maandelijkse aanlevering');
		$profile->setFormat('json');
		$profile->setValueMode(ExportProfile::MODE_RENDERED);
		$profile->setFields((string)json_encode(['zaaknummer']));

		$service = $this->createMock(ExportProfileService::class);
		$service->method('find')->willReturn($profile);
		$service->method('run')->willReturn(
			[
				'bytes' => '[]',
				'rowCount' => 3,
				'metadata' => ['valueMode' => 'rendered', 'profileUuid' => 'profile-uuid-1'],
				'filename' => 'maandelijkse-aanlevering.json',
			]
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('eigenaar-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);

		return ExportProfilesControllerFactory::create(
			'openregister',
			$this->createMock(IRequest::class),
			$service,
			$session,
			$groups,
			$recorder
		);
	}//end controller()

	public function testAServedRunIsRecordedWithTheCallerTheProfileAndTheFile(): void {
		$seen = [];
		$recorder = $this->createMock(ExportRunRecorder::class);
		$recorder->expects($this->once())->method('record')->willReturnCallback(
			function (ExportRunOrigin $origin, ExportRunContent $content, ExportRunFile $file, ?int $retention) use (&$seen): ExportRun {
				$seen = [
					$origin->source, $origin->actor, $origin->profile, $origin->status,
					$content->format, $content->rowCount, $file->filename, $file->downloadCount, $retention,
				];
				return new ExportRun();
			}
		);

		$response = $this->controller($recorder)->run(1);

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame(
			['export-profile', 'eigenaar-1', 'profile-uuid-1', ExportRun::STATUS_SERVED, 'json', 3, 'maandelijkse-aanlevering.json', 1, null],
			$seen
		);
	}//end testAServedRunIsRecordedWithTheCallerTheProfileAndTheFile()

	public function testARecorderFailureStillServesTheExport(): void {
		$recorder = $this->createMock(ExportRunRecorder::class);
		$recorder->method('record')->willThrowException(new RuntimeException('database gone'));

		$response = $this->controller($recorder)->run(1);

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame('3', $response->getHeaders()['X-OpenRegister-Export-Row-Count']);
	}//end testARecorderFailureStillServesTheExport()
}//end class
