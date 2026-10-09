<?php

/**
 * A Talk conversation as a text file (files-leaf-save-to-object task 1.2).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use DateTimeImmutable;
use OCA\OpenRegister\Controller\FilesController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Integration\TalkChatExporter;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The transcript, and the route that attaches it.
 */
class TalkChatExporterTest extends TestCase {

	/**
	 * Three messages become three lines, oldest first, each with its author
	 * and time (spec scenario "a chat becomes a file on the object").
	 *
	 * @return void
	 */
	public function testThreeMessagesBecomeThreeLinesOldestFirst(): void {
		$file = (new TalkChatExporter())->export(
			conversation: 'Bezwaar 2026-17',
			messages: [
				['actorDisplayName' => 'Piet', 'timestamp' => 1791540060, 'message' => 'Akkoord.'],
				['actorDisplayName' => 'Anna', 'timestamp' => 1791540000, 'message' => "Kun je kijken?\nGraag vandaag."],
				['actorDisplayName' => 'Anna', 'timestamp' => 1791540120, 'message' => 'Dank je.'],
			],
			savedBy: 'Anna de Vries',
			savedAt: new DateTimeImmutable('2026-10-09 12:00:00 UTC')
		);

		$lines = explode("\n", trim($file['content']));
		$this->assertSame('Bezwaar 2026-17', $lines[0]);
		$this->assertStringContainsString('Saved by Anna de Vries', $lines[1]);
		$messages = array_values(array_filter($lines, static fn (string $l): bool => str_starts_with($l, '[')));
		$this->assertCount(3, $messages);
		$this->assertStringContainsString('Anna: Kun je kijken?', $messages[0]);
		$this->assertStringContainsString('Piet: Akkoord.', $messages[1]);
		$this->assertStringContainsString('Anna: Dank je.', $messages[2]);
		$this->assertStringContainsString('    Graag vandaag.', $file['content']);
		$this->assertSame('Bezwaar-2026-17-2026-10-09-120000.txt', $file['filename']);
	}//end testThreeMessagesBecomeThreeLinesOldestFirst()

	/**
	 * A conversation with no messages is refused, not saved as an empty file.
	 *
	 * @return void
	 */
	public function testAnEmptyConversationIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		(new TalkChatExporter())->export('Leeg', [], 'Anna', new DateTimeImmutable());
	}//end testAnEmptyConversationIsRefused()

	/**
	 * The route attaches the transcript through addFile().
	 *
	 * @return void
	 */
	public function testTheRouteAttachesTheTranscript(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$user->method('getDisplayName')->willReturn('Anna de Vries');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn ($key, $default = null) => match ($key) {
				'conversation' => 'Overleg',
				'messages' => [['actorDisplayName' => 'Anna', 'timestamp' => 1791540000, 'message' => 'Hallo']],
				default => $default,
			}
		);

		$object = new ObjectEntity();
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('getObject')->willReturn($object);
		$fileService = $this->createMock(FileService::class);
		$fileService->expects($this->once())->method('addFile')
			->with($object, $this->stringStartsWith('Overleg-'), $this->stringContains('Anna: Hallo'))
			->willReturn($this->createMock(File::class));
		$fileService->method('formatFile')->willReturn(['title' => 'Overleg.txt']);

		$controller = new FilesController(
			'openregister',
			$request,
			$fileService,
			$objectService,
			$this->createMock(IRootFolder::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IEventDispatcher::class),
			null,
			null,
			$session,
			null,
			$this->createMock(ObjectFileAccess::class)
		);

		$response = $controller->saveChat('zaken', 'zaak', 'obj-1');

		$this->assertSame(200, $response->getStatus());
	}//end testTheRouteAttachesTheTranscript()
}//end class
