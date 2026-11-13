<?php

/**
 * The disclosures of a file are listed to a caller who may read it, and refused with 403 to anyone else.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-external-call-that-carries-a-documents-text-is-recorded-per-document-req-adi-005
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use DateTime;
use OCA\OpenRegister\Controller\FileDisclosuresController;
use OCA\OpenRegister\Files\Db\TextDisclosure;
use OCA\OpenRegister\Files\Db\TextDisclosureMapper;
use OCA\OpenRegister\Files\Service\File\CallerFileAccess;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * A reader and a non-reader asking for a file's disclosures.
 */
#[CoversClass(FileDisclosuresController::class)]
#[UsesClass(CallerFileAccess::class)]
#[UsesClass(TextDisclosure::class)]
final class FileDisclosuresControllerTest extends TestCase {
	/**
	 * The controller, with `jan` signed in, whose folder holds the given files.
	 *
	 * @param list<int>            $readable The file ids `jan` can open.
	 * @param TextDisclosureMapper $mapper   The disclosure store.
	 *
	 * @return FileDisclosuresController
	 */
	private function controller(array $readable, TextDisclosureMapper $mapper): FileDisclosuresController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturnCallback(
			fn (int $id): array => in_array($id, $readable, true) === true ? [$this->createMock(Node::class)] : []
		);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('jan')->willReturn($folder);

		return new FileDisclosuresController(
			request: $this->createMock(IRequest::class),
			mapper: $mapper,
			fileAccess: new CallerFileAccess(fileReadScope: null, userSession: $session, rootFolder: $root)
		);
	}//end controller()

	/**
	 * A reader gets every disclosure of the file: service, host, moment and bytes.
	 *
	 * @return void
	 */
	public function testAReaderGetsTheDisclosures(): void {
		$disclosure = new TextDisclosure();
		$disclosure->setFileId(31);
		$disclosure->setService('Presidio');
		$disclosure->setHost('presidio.example:5002');
		$disclosure->setAt(new DateTime('2026-11-13T10:15:00+00:00'));
		$disclosure->setBytes(42);

		$mapper = $this->createMock(TextDisclosureMapper::class);
		$mapper->expects($this->once())->method('findByFileId')->with(31)->willReturn([$disclosure]);

		$response = $this->controller(readable: [31], mapper: $mapper)->index(31);

		$this->assertSame(
			[
				200,
				[
					'fileId' => 31,
					'disclosures' => [
						['service' => 'Presidio', 'host' => 'presidio.example:5002', 'at' => '2026-11-13T10:15:00+00:00', 'bytes' => 42],
					],
				],
			],
			[$response->getStatus(), $response->getData()]
		);
	}//end testAReaderGetsTheDisclosures()

	/**
	 * A caller who may not read the file gets 403 and the store is never read.
	 *
	 * @return void
	 */
	public function testANonReaderGets403(): void {
		$mapper = $this->createMock(TextDisclosureMapper::class);
		$mapper->expects($this->never())->method('findByFileId');

		$response = $this->controller(readable: [8], mapper: $mapper)->index(31);

		$this->assertSame(
			[403, ['error' => 'You may not read this file']],
			[$response->getStatus(), $response->getData()]
		);
	}//end testANonReaderGets403()
}//end class
