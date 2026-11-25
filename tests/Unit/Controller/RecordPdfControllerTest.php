<?php

/**
 * Unit tests for RecordPdfController.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\RecordPdfController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\OfficeServerRequiredException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\Export\ExportGateInterface;
use OCA\OpenRegister\Service\ExportService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * GET /api/objects/{register}/{schema}/{id}/pdf: the checks run in order and the PDF is the record's.
 *
 * @covers \OCA\OpenRegister\Controller\RecordPdfController
 * @uses   \OCA\OpenRegister\Service\RegisterScopedSchemaResolvers
 * @uses   \OCA\OpenRegister\Service\RegisterScopedSchemaResolver
 * @uses   \OCA\OpenRegister\Exception\RegisterNotFoundException
 * @uses   \OCA\OpenRegister\Exception\OfficeServerRequiredException
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 *
 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-single-record-exports-as-a-pdf
 */
final class RecordPdfControllerTest extends TestCase {

	/**
	 * The signed-in user, or null.
	 *
	 * @var IUser|null
	 */
	private ?IUser $user = null;

	/**
	 * The object service double.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface&MockObject $objects;

	/**
	 * The export service double.
	 *
	 * @var ExportService&MockObject
	 */
	private ExportService&MockObject $export;

	/**
	 * The export gate double.
	 *
	 * @var ExportGateInterface&MockObject
	 */
	private ExportGateInterface&MockObject $gate;

	/**
	 * The register reference no register answers to.
	 *
	 * @var string|null
	 */
	private ?string $missingRegister = null;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The schema the register carries.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * Build the doubles: register 1 carrying schema 2.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('caseworker');

		$register = new Register();
		$register->setId(1);
		$register->setSchemas([2]);
		$this->schema = new Schema();
		$this->schema->setId(2);

		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->objects->method('setRegister')->willReturnCallback(function (Register|string|int $ref): ObjectOperationsInterface {
			if ($ref === $this->missingRegister) {
				throw new DoesNotExistException('no register');
			}

			return $this->objects;
		});
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('getCurrentRegisterEntity')->willReturn($register);
		$this->objects->method('getCurrentSchemaEntity')->willReturnCallback(fn (): Schema => $this->schema);
		$this->export = $this->createMock(ExportService::class);
		$this->gate = $this->createMock(ExportGateInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return RecordPdfController
	 */
	private function controller(): RecordPdfController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->user);

		return new RecordPdfController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			objectService: $this->objects,
			exportService: $this->export,
			exportGate: $this->gate,
			logger: $this->logger
		);
	}//end controller()

	/**
	 * A record with a name.
	 *
	 * @return ObjectEntity
	 */
	private function record(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('uuid-42');
		$object->setName('Z-2026-0042 Bouw/vergunning');
		return $object;
	}//end record()

	/**
	 * The record is read with the user's rights in its register and schema and returned as a PDF download.
	 *
	 * @return void
	 */
	public function testTheRecordIsReturnedAsAPdf(): void {
		$record = $this->record();
		$this->gate->expects($this->once())->method('refusalFor')->with($this->schema, 'record-pdf', 1)->willReturn(null);
		$this->objects->expects($this->once())->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true) use ($record): ObjectEntity {
				$this->assertSame(['uuid-42', 1, 2, true, true], [$id, $options->register, $options->schema, $_rbac, $_multitenancy]);
				return $record;
			}
		);
		$this->export->expects($this->once())->method('recordToPdf')
			->with($record, $this->schema, $this->user)
			->willReturn('%PDF-1.7 record');

		$response = $this->controller()->show(register: 'zaken', schema: 'zaak', id: 'uuid-42');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame('%PDF-1.7 record', $response->render());
		$this->assertSame('application/pdf', $response->getHeaders()['Content-Type']);
		$this->assertStringContainsString('filename=Z-2026-0042-Bouw-vergunning.pdf', $response->getHeaders()['Content-Disposition']);
	}//end testTheRecordIsReturnedAsAPdf()

	/**
	 * Without a signed-in user nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutAUserTheAnswerIs401(): void {
		$this->user = null;
		$this->objects->expects($this->never())->method('setRegister');
		$this->objects->expects($this->never())->method('find');

		$response = $this->controller()->show(register: 'zaken', schema: 'zaak', id: 'uuid-42');

		$this->assertSame(401, $response->getStatus());
	}//end testWithoutAUserTheAnswerIs401()

	/**
	 * Without the export right the gate's refusal is the answer, before the record is read.
	 *
	 * @return void
	 */
	public function testWithoutTheExportRightTheRecordIsNotRead(): void {
		$refusal = new JSONResponse(data: ['error' => 'EXPORT_REFUSED'], statusCode: 403);
		$this->gate->method('refusalFor')->willReturn($refusal);
		$this->objects->expects($this->never())->method('find');
		$this->export->expects($this->never())->method('recordToPdf');

		$this->assertSame($refusal, $this->controller()->show(register: 'zaken', schema: 'zaak', id: 'uuid-42'));
	}//end testWithoutTheExportRightTheRecordIsNotRead()

	/**
	 * A record the user cannot read, an absent one and an unknown register all answer 404.
	 *
	 * @return void
	 */
	public function testARecordOutOfReachIs404(): void {
		$this->gate->method('refusalFor')->willReturn(null);
		$this->objects->method('find')->willReturnCallback(static function (int|string $id): ?ObjectEntity {
			if ($id === 'gone') {
				throw new DoesNotExistException('gone');
			}

			return null;
		});
		$this->export->expects($this->never())->method('recordToPdf');

		$this->assertSame(404, $this->controller()->show(register: 'zaken', schema: 'zaak', id: 'hidden')->getStatus());
		$this->assertSame(404, $this->controller()->show(register: 'zaken', schema: 'zaak', id: 'gone')->getStatus());

		$this->missingRegister = 'elders';
		$this->assertSame(404, $this->controller()->show(register: 'elders', schema: 'zaak', id: 'uuid-42')->getStatus());
	}//end testARecordOutOfReachIs404()

	/**
	 * The register is set before the schema, so the schema resolves inside it; a
	 * schema the register does not carry answers 404 without reading the record.
	 *
	 * @return void
	 */
	public function testTheSchemaIsResolvedInsideTheRegister(): void {
		$calls = [];
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('setRegister')->willReturnCallback(static function (Register|string|int $ref) use (&$calls, &$objects): ObjectOperationsInterface {
			$calls[] = 'register:' . $ref;
			return $objects;
		});
		$objects->method('setSchema')->willReturnCallback(static function (Schema|string|int $ref) use (&$calls, &$objects): ObjectOperationsInterface {
			$calls[] = 'schema:' . $ref;
			return $objects;
		});
		$objects->method('getCurrentRegisterEntity')->willReturn(new Register());
		$objects->method('getCurrentSchemaEntity')->willReturn(null);
		$objects->expects($this->never())->method('find');
		$this->objects = $objects;
		$this->gate->expects($this->never())->method('refusalFor');

		$response = $this->controller()->show(register: 'zaken', schema: 'zaak', id: 'uuid-42');

		$this->assertSame(['register:zaken', 'schema:zaak'], $calls);
		$this->assertSame(404, $response->getStatus());
	}//end testTheSchemaIsResolvedInsideTheRegister()

	/**
	 * Without an office server the answer names it; any other failure is logged and answers 500.
	 *
	 * @return void
	 */
	public function testRenderFailuresAreAnswered(): void {
		$this->gate->method('refusalFor')->willReturn(null);
		$this->objects->method('find')->willReturn($this->record());
		$calls = 0;
		$this->export->method('recordToPdf')->willReturnCallback(static function () use (&$calls): string {
			$calls++;
			if ($calls === 1) {
				throw new OfficeServerRequiredException();
			}

			throw new RuntimeException('converter crashed');
		});
		$this->logger->expects($this->once())->method('error');

		$office = $this->controller()->show(register: 'zaken', schema: 'zaak', id: 'uuid-42');
		$this->assertSame(OfficeServerRequiredException::HTTP_STATUS, $office->getStatus());
		$this->assertSame((new OfficeServerRequiredException())->toResponseData(), $office->getData());

		$crash = $this->controller()->show(register: 'zaken', schema: 'zaak', id: 'uuid-42');
		$this->assertSame(500, $crash->getStatus());
		$this->assertSame(['error' => 'The record could not be exported.'], $crash->getData());
	}//end testRenderFailuresAreAnswered()
}//end class
