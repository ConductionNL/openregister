<?php

/**
 * Contract tests for ExportProfilesController.
 *
 * Every endpoint this change exposes is exercised here: the wire shape of the
 * listing, the read, the write, the delete, the run and the published contract.
 * The run is the one that carries the control, so it is asserted twice: once
 * that the refusal reaches the caller as a 403 naming the verb, and once that a
 * granted run hands back the file with its mode on the headers.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed PHPUnit doubles, the type IS the documentation.

use OCA\OpenRegister\Controller\ExportProfilesController;
use OCA\OpenRegister\Db\ExportProfile;
use OCA\OpenRegister\Service\Export\ExportProfileService;
use OCA\OpenRegister\Service\Export\ExportProfileWriter;
use OCA\OpenRegister\Service\Export\ExportRefusedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ExportProfilesControllerTest extends TestCase {
	private ExportProfileService&MockObject $service;

	private IRequest&MockObject $request;

	protected function setUp(): void {
		$this->service = $this->createMock(ExportProfileService::class);
		$this->request = $this->createMock(IRequest::class);
	}//end setUp()

	private function controller(?string $uid = 'eigenaar-1', bool $isAdmin = false): ExportProfilesController {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);

		return new ExportProfilesController('openregister', $this->request, $this->service, $session, $groups);
	}//end controller()

	private function profile(string $format = 'csv'): ExportProfile {
		$profile = new ExportProfile();
		$profile->setUuid('profile-uuid-1');
		$profile->setOwner('eigenaar-1');
		$profile->setName('Maandelijkse aanlevering');
		$profile->setFormat($format);
		$profile->setValueMode(ExportProfile::MODE_RENDERED);
		$profile->setFields((string)json_encode(['zaaknummer', 'status']));

		return $profile;
	}//end profile()

	public function testTheListingIsResultsAndTotal(): void {
		$this->service->method('listFor')->willReturn([$this->profile()]);

		$response = $this->controller()->index();
		$data = $response->getData();

		self::assertSame(200, $response->getStatus());
		self::assertSame(1, $data['total']);
		self::assertSame('Maandelijkse aanlevering', $data['results'][0]['name']);
		self::assertSame(['zaaknummer', 'status'], $data['results'][0]['fields']);
	}//end testTheListingIsResultsAndTotal()

	public function testAnAnonymousCallerGets401OnEveryEndpoint(): void {
		$controller = $this->controller(null);

		self::assertSame(401, $controller->index()->getStatus());
		self::assertSame(401, $controller->show(1)->getStatus());
		self::assertSame(401, $controller->create()->getStatus());
		self::assertSame(401, $controller->update(1)->getStatus());
		self::assertSame(401, $controller->destroy(1)->getStatus());

		$run = $controller->run(1);
		self::assertInstanceOf(JSONResponse::class, $run);
		self::assertSame(401, $run->getStatus());
	}//end testAnAnonymousCallerGets401OnEveryEndpoint()

	public function testReadingSomebodyElsesProfileIsRefused(): void {
		$this->service->method('find')->willReturn($this->profile());
		$this->service->method('assertOwnerOrAdmin')->willThrowException(
			new ExportRefusedException('profile-not-yours', 'This export profile belongs to another user.', 403)
		);

		$response = $this->controller('andere-gebruiker')->show(1);

		self::assertSame(403, $response->getStatus());
		self::assertSame('profile-not-yours', $response->getData()['rule']);
	}//end testReadingSomebodyElsesProfileIsRefused()

	public function testAMissingProfileIs404(): void {
		$this->service->method('find')->willThrowException(new DoesNotExistException('gone'));

		self::assertSame(404, $this->controller()->show(9)->getStatus());
	}//end testAMissingProfileIs404()

	public function testCreatingAProfileAnswers201(): void {
		$this->request->method('getParams')->willReturn(
			['name' => 'Maandelijkse aanlevering', 'registerId' => 7, 'fields' => ['zaaknummer']]
		);
		$this->service->method('create')->willReturn($this->profile());

		$response = $this->controller()->create();

		self::assertSame(201, $response->getStatus());
		self::assertSame('profile-uuid-1', $response->getData()['uuid']);
	}//end testCreatingAProfileAnswers201()

	public function testAnInvalidSubmissionIs400WithTheReason(): void {
		$this->request->method('getParams')->willReturn(['name' => 'x']);
		$this->service->method('create')->willThrowException(
			new \InvalidArgumentException('An export profile needs at least one field.')
		);

		$response = $this->controller()->create();

		self::assertSame(400, $response->getStatus());
		self::assertStringContainsString('at least one field', $response->getData()['error']);
	}//end testAnInvalidSubmissionIs400WithTheReason()

	public function testUpdatingAProfileAnswersTheProfile(): void {
		$this->request->method('getParams')->willReturn(['valueMode' => 'rendered']);
		$this->service->method('find')->willReturn($this->profile());
		$this->service->method('update')->willReturn($this->profile());

		$response = $this->controller()->update(1);

		self::assertSame(200, $response->getStatus());
		self::assertSame('rendered', $response->getData()['valueMode']);
	}//end testUpdatingAProfileAnswersTheProfile()

	public function testDeletingAProfileAnswersDeleted(): void {
		$this->service->method('find')->willReturn($this->profile());

		$response = $this->controller()->destroy(1);

		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['deleted']);
	}//end testDeletingAProfileAnswersDeleted()

	public function testTheApiIsGatedToo(): void {
		// The same principal, the same refusal, through the endpoint an
		// integration calls. Hiding a button would not have produced this.
		$this->service->method('find')->willReturn($this->profile());
		$this->service->method('run')->willThrowException(
			new ExportRefusedException(
				'export-right-missing',
				'User behandelaar-1 does not hold the export right on schema zaken.',
				403,
				['evaluated' => 'export']
			)
		);

		$response = $this->controller('behandelaar-1', true)->run(1);

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(403, $response->getStatus());
		self::assertSame('export', $response->getData()['verb']);
		self::assertSame('export-right-missing', $response->getData()['rule']);
	}//end testTheApiIsGatedToo()

	public function testAGrantedRunHandsBackTheFileWithItsModeOnTheHeaders(): void {
		$this->service->method('find')->willReturn($this->profile());
		$this->service->method('run')->willReturn(
			[
				'bytes' => "#openregister-export valueMode=rendered\n\"zaaknummer\"\n\"Z-001\"\n",
				'rowCount' => 1,
				'metadata' => ['valueMode' => 'rendered', 'profileUuid' => 'profile-uuid-1'],
				'filename' => 'maandelijkse-aanlevering_2026-09-15_203000.csv',
			]
		);

		$response = $this->controller()->run(1);

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame('rendered', $response->getHeaders()['X-OpenRegister-Export-Value-Mode']);
		self::assertSame('1', $response->getHeaders()['X-OpenRegister-Export-Row-Count']);
		self::assertSame('profile-uuid-1', $response->getHeaders()['X-OpenRegister-Export-Profile']);
	}//end testAGrantedRunHandsBackTheFileWithItsModeOnTheHeaders()

	public function testTheContractPublishesWhatAConsumerNeedsToReadTheFile(): void {
		$data = $this->controller()->contract()->getData();

		self::assertSame(ExportProfileWriter::CSV_METADATA_PREFIX, $data['csvMetadataPrefix']);
		self::assertSame(['stored', 'rendered'], $data['valueModes']);
		self::assertSame(['csv', 'json'], $data['formats']);
		self::assertSame('X-OpenRegister-Export-Value-Mode', $data['headers']['valueMode']);
	}//end testTheContractPublishesWhatAConsumerNeedsToReadTheFile()
}//end class
