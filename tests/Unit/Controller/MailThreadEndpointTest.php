<?php

/**
 * GET /api/files/{fileId}/thread: the conversation of a collected mail, through the real assembler.
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
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-a-message-is-read-with-the-messages-around-it-req-rth-011
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Files\Controller\FileSidebarController;
use OCA\OpenRegister\Files\Controller\Support\FileSidebarControllerServices;
use OCA\OpenRegister\Files\Db\MailHeader;
use OCA\OpenRegister\Files\Db\MailHeaderMapper;
use OCA\OpenRegister\Files\Service\FileSidebarService;
use OCA\OpenRegister\Service\Mail\MailThreadAssembler;
use OCA\OpenRegister\Service\Mail\MailThreadService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Files\Controller\FileSidebarController
 * @covers \OCA\OpenRegister\Service\Mail\MailThreadService
 * @uses   \OCA\OpenRegister\Service\Mail\MailThreadAssembler
 * @uses   \OCA\OpenRegister\Files\Db\MailHeader
 * @uses   \OCA\OpenRegister\Files\Service\File\CallerFileAccess
 * @uses   \OCA\OpenRegister\Files\Controller\Support\FileSidebarControllerServices
 */
class MailThreadEndpointTest extends TestCase {

	/**
	 * The controller over a folder of mail files, their header rows and the caller's readable files.
	 *
	 * @param array<int, string>                $names    The file names in the folder, by file id.
	 * @param array<int, array<string, mixed>>  $headers  The parsed headers of each file, by file id.
	 * @param int[]                             $readable The file ids the caller may read.
	 */
	private function controller(array $names, array $headers, array $readable): FileSidebarController {
		$nodes = [];
		$folder = $this->createMock(Folder::class);
		foreach ($names as $id => $name) {
			$file = $this->createMock(File::class);
			$file->method('getId')->willReturn($id);
			$file->method('getName')->willReturn($name);
			$file->method('getParent')->willReturn($folder);
			$nodes[$id] = $file;
		}

		$folder->method('getDirectoryListing')->willReturn(array_values($nodes));

		$root = $this->createMock(IRootFolder::class);
		$root->method('getById')->willReturnCallback(static fn (int $id): array => isset($nodes[$id]) ? [$nodes[$id]] : []);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturnCallback(
			static fn (int $id): array => (in_array($id, $readable, true) && isset($nodes[$id])) ? [$nodes[$id]] : []
		);
		$root->method('getUserFolder')->with('reviewer')->willReturn($userFolder);

		$rows = $this->createMock(MailHeaderMapper::class);
		$rows->method('findByFileIds')->willReturnCallback(
			static function (array $fileIds) use ($headers): array {
				$found = [];
				foreach ($fileIds as $id) {
					if (isset($headers[$id]) === true) {
						$found[] = MailHeader::fromHeaders(fileId: $id, headers: $headers[$id]);
					}
				}

				return $found;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('reviewer');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new FileSidebarController(
			request: $this->createMock(IRequest::class),
			services: new FileSidebarControllerServices(
				fileSidebarService: $this->createMock(FileSidebarService::class),
				rootFolder: $root,
				userSession: $session,
				logger: $this->createMock(LoggerInterface::class),
				mailThreads: new MailThreadService(headers: $rows, rootFolder: $root, assembler: new MailThreadAssembler())
			)
		);
	}

	/**
	 * Five mails of one conversation: the third answers the first, the fourth's subject was edited.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function conversation(): array {
		return [
			11 => ['messageId' => 'a1@gemeente.nl', 'date' => 'Mon, 02 Mar 2026 09:00:00 +0000'],
			12 => ['messageId' => 'a2@burger.nl', 'inReplyTo' => 'a1@gemeente.nl', 'references' => ['a1@gemeente.nl'], 'date' => 'Mon, 02 Mar 2026 10:00:00 +0000'],
			13 => ['messageId' => 'a3@gemeente.nl', 'inReplyTo' => 'a1@gemeente.nl', 'date' => 'Mon, 02 Mar 2026 11:00:00 +0000'],
			14 => ['messageId' => 'a4@burger.nl', 'references' => ['a1@gemeente.nl', 'a3@gemeente.nl'], 'date' => 'Mon, 02 Mar 2026 12:00:00 +0000'],
			15 => ['messageId' => 'a5@gemeente.nl', 'inReplyTo' => 'a4@burger.nl', 'date' => 'Mon, 02 Mar 2026 13:00:00 +0000'],
		];
	}

	public function testTheThreadIsReturnedInDateOrder(): void {
		$names = [11 => 'verzoek.eml', 12 => 'Re verzoek.eml', 13 => 'Re verzoek (2).eml', 14 => 'andere titel.eml', 15 => 'Re andere titel.eml', 16 => 'notes.txt'];
		$response = $this->controller(names: $names, headers: self::conversation(), readable: [11, 12, 13, 14, 15, 16])->getThread(fileId: 14);

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame([11, 12, 13, 14, 15], array_column($data['thread'], 'fileId'));
		$this->assertSame(3, $data['position']);
		$this->assertSame('a3@gemeente.nl', $data['thread'][3]['parent'], 'the fourth sits under the message it answers');
		$this->assertSame('andere titel', $data['thread'][3]['subject']);
		$this->assertSame(['fileId', 'messageId', 'parent', 'sentAt', 'from', 'subject', 'gap'], array_keys($data['thread'][0]));
		$this->assertSame([false, false, false, false, false], array_column($data['thread'], 'gap'));
	}

	public function testAnUnreadableMemberIsAGapWithoutHeaders(): void {
		$names = [11 => 'verzoek.eml', 13 => 'intern.eml', 14 => 'andere titel.eml'];
		$headers = array_intersect_key(self::conversation(), $names);
		$response = $this->controller(names: $names, headers: $headers, readable: [11, 14])->getThread(fileId: 14);

		$thread = $response->getData()['thread'];
		$gap = array_values(array_filter($thread, static fn (array $node): bool => $node['gap'] === true));
		$this->assertCount(1, $gap);
		$this->assertSame(['fileId' => null, 'messageId' => 'a3@gemeente.nl', 'parent' => '', 'sentAt' => null, 'from' => '', 'subject' => '', 'gap' => true], $gap[0]);
		$this->assertStringNotContainsString('intern', (string) json_encode($thread));
		$this->assertStringNotContainsString('11:00', (string) json_encode($thread));
	}

	public function testAFileTheCallerMayNotReadIsNotFound(): void {
		$response = $this->controller(names: [11 => 'verzoek.eml'], headers: self::conversation(), readable: [])->getThread(fileId: 11);

		$this->assertSame(404, $response->getStatus());
	}

	public function testAFileWithoutThreadHeadersHasAnEmptyThread(): void {
		$response = $this->controller(names: [20 => 'losse.eml'], headers: [], readable: [20])->getThread(fileId: 20);

		$this->assertSame(['thread' => [], 'position' => null], $response->getData());
	}
}
