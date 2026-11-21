<?php

/**
 * CustodyControllerTest
 *
 * `GET /api/files/{fileId}/anonymisation/custody` answers a reader of the
 * file with the custody statement and refuses anyone else (REQ-RRS-005).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-unredacted-originals-custody-is-stated-and-its-deletion-scheduled-req-rrs-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\RedactionCustodyController;
use OCA\OpenRegister\Files\Service\File\CallerFileAccess;
use OCA\OpenRegister\Service\Anonymisation\OriginalCustodyService;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The custody endpoint applies the file read check.
 *
 * @covers \OCA\OpenRegister\Controller\RedactionCustodyController
 * @uses   \OCA\OpenRegister\Files\Service\File\CallerFileAccess
 */
class CustodyControllerTest extends TestCase {

	private const STATEMENT = [
		'original' => ['fileId' => 6, 'path' => '/openregister/files/objects/42/besluit.pdf'],
		'redactedCopy' => ['fileId' => 42, 'path' => '/openregister/files/objects/42/besluit_anonymized.pdf'],
		'retention' => 'P90D',
		'deletionDate' => '2026-12-30',
		'status' => 'scheduled',
		'heldBecause' => null,
	];

	/**
	 * The controller; the signed-in caller reads file 42 when $readable.
	 *
	 * @param bool      $readable Whether file 42 is in the caller's folder.
	 * @param Node|null $node     What the root folder resolves 42 to.
	 *
	 * @return RedactionCustodyController
	 */
	private function controller(bool $readable, ?Node $node = null): RedactionCustodyController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->with(42)->willReturn($readable === true ? [$this->createMock(Node::class)] : []);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);
		$root->method('getById')->with(42)->willReturn($node === null ? [] : [$node]);

		$custody = $this->createMock(OriginalCustodyService::class);
		$custody->method('statement')->willReturnCallback(
			static fn (File $file): array => $file->getId() === 42 ? self::STATEMENT : []
		);
		if ($readable === false) {
			$custody->expects($this->never())->method('statement');
		}

		return new RedactionCustodyController(
			appName: 'openregister',
			request: $this->createMock(IRequest::class),
			fileAccess: new CallerFileAccess(fileReadScope: null, userSession: $userSession, rootFolder: $root),
			rootFolder: $root,
			custody: $custody
		);
	}//end controller()

	/**
	 * A file 42 node.
	 *
	 * @return File
	 */
	private function copy(): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		return $file;
	}//end copy()

	/**
	 * A reader gets the statement.
	 *
	 * @return void
	 */
	public function testAReaderGetsTheStatement(): void {
		$response = $this->controller(readable: true, node: $this->copy())->show(fileId: 42);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(self::STATEMENT, $response->getData());
	}//end testAReaderGetsTheStatement()

	/**
	 * Someone who cannot read the file is refused before anything is resolved.
	 *
	 * @return void
	 */
	public function testANonReaderIsRefused(): void {
		$response = $this->controller(readable: false, node: $this->copy())->show(fileId: 42);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('forbidden', $response->getData()['error']);
	}//end testANonReaderIsRefused()

	/**
	 * A readable id that is not a file is not found.
	 *
	 * @return void
	 */
	public function testAFolderIsNotFound(): void {
		$response = $this->controller(readable: true, node: $this->createMock(Folder::class))->show(fileId: 42);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAFolderIsNotFound()
}//end class
