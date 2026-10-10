<?php

/**
 * The 409 body a file write on a frozen or archived object answers with (REQ-OAS-007, task W.3).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-file-writes-honour-the-frozen-and-archived-marker-req-oas-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FilesController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Service\File\FileAuditHandler;
use OCA\OpenRegister\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\FileWriteGuard;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * One body shape for every write action: error, state, by, at, reason.
 */
class FilesFrozenResponseTest extends TestCase {

	private const KEYS = ['error', 'state', 'by', 'at', 'reason'];

	private function frozenObject(?string $state = null): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('d00d0000-0000-4000-8000-000000000409');
		$object->setRegister('7');
		$object->setSchema('42');
		$object->setFrozen(['by' => 'dossiq-publicatie', 'at' => '2026-10-10T09:00:00+00:00', 'reason' => 'Woo-levering', 'state' => $state]);

		return $object;
	}//end frozenObject()

	private function controller(ObjectEntity $object): FilesController {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('setObject')->willReturnSelf();
		$objectService->method('getObject')->willReturn($object);

		$access = $this->createMock(ObjectFileAccess::class);
		$access->method('changeable')->willReturn($object);

		$fileService = $this->createMock(FileService::class);
		$fileService->expects($this->never())->method('deleteFile');
		$fileService->expects($this->never())->method('updateFile');

		return new FilesController(
			'openregister',
			$this->createMock(IRequest::class),
			$fileService,
			$objectService,
			$this->createMock(IRootFolder::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IEventDispatcher::class),
			null,
			$this->createMock(FileAuditHandler::class),
			null,
			null,
			$access,
			null,
			null,
			new FileWriteGuard()
		);
	}//end controller()

	public function testTheBodyCarriesTheMarker(): void {
		$body = ObjectStateWriteException::frozen($this->frozenObject(state: 'geleverd'))->toResponseBody();

		$this->assertSame(self::KEYS, array_keys($body));
		$this->assertSame('frozen', $body['state']);
		$this->assertSame('dossiq-publicatie', $body['by']);
		$this->assertSame('2026-10-10T09:00:00+00:00', $body['at']);
		$this->assertSame('Woo-levering', $body['reason']);
		$this->assertStringContainsString('on entering the state "geleverd"', $body['error']);
	}//end testTheBodyCarriesTheMarker()

	public function testAnArchiveWithoutAReasonAnswersNull(): void {
		$object = new ObjectEntity();
		$object->setArchived(['by' => 'archivaris', 'at' => '2026-10-01T10:00:00+00:00']);

		$body = ObjectStateWriteException::archived($object)->toResponseBody();

		$this->assertSame('archived', $body['state']);
		$this->assertNull($body['reason']);
	}//end testAnArchiveWithoutAReasonAnswersNull()

	public function testADeleteAndAnUpdateAnswer409WithTheSameBody(): void {
		$object = $this->frozenObject();

		$delete = $this->controller($object)->delete(register: '7', schema: '42', id: (string)$object->getUuid(), fileId: 12);
		$update = $this->controller($object)->update(register: '7', schema: '42', id: (string)$object->getUuid(), fileId: 12);

		foreach ([$delete, $update] as $response) {
			$this->assertSame(409, $response->getStatus());
			$this->assertSame(self::KEYS, array_keys($response->getData()));
			$this->assertSame('Woo-levering', $response->getData()['reason']);
		}
	}//end testADeleteAndAnUpdateAnswer409WithTheSameBody()
}//end class
