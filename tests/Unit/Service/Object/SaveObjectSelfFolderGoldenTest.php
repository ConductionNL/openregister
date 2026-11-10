<?php

/**
 * Golden: the `@self.folder` bind of a save, allowed and refused.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\FolderAccessDeniedException;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * Pins what setSelfMetadata() does with `@self.folder`: which binds it lets
 * through (and in which normalised form), which it refuses, and which user it
 * asks the folder question for. The guard itself is the real
 * FolderManagementHandler over a fake file tree.
 */
final class SaveObjectSelfFolderGoldenTest extends TestCase {

	/**
	 * The user ids the folder guard was asked about.
	 *
	 * @var string[]
	 */
	private array $askedFor = [];

	/**
	 * Run setSelfMetadata on an object with the given stored folder.
	 *
	 * @param array       $selfData     The @self metadata sent.
	 * @param string|null $storedFolder The folder the object already has.
	 * @param array       $nodes        What the acting user's folder answers for the id.
	 *
	 * @return array{folder: string|null, asked: string[], refused: bool}
	 */
	private function bind(array $selfData, ?string $storedFolder, array $nodes): array {
		$this->askedFor = [];
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn($nodes);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(function (string $uid) use ($userFolder) {
			$this->askedFor[] = $uid;
			return $userFolder;
		});

		$guardClass = new ReflectionClass(FolderManagementHandler::class);
		$guard = $guardClass->newInstanceWithoutConstructor();
		$guardClass->getProperty('rootFolder')->setValue($guard, $root);
		$guardClass->getProperty('logger')->setValue($guard, new NullLogger());
		$guardClass->getProperty('auditTrailMapper')->setValue($guard, $this->createMock(AuditTrailMapperInterface::class));

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$saveClass = new ReflectionClass(SaveObject::class);
		$handler = $saveClass->newInstanceWithoutConstructor();
		$saveClass->getProperty('folderManagementHandler')->setValue($handler, $guard);
		$saveClass->getProperty('userSession')->setValue($handler, $session);

		$acting = $this->createMock(IUser::class);
		$acting->method('getUID')->willReturn('alice');

		$entity = new ObjectEntity();
		$entity->setUuid('o-1');
		$entity->setFolder($storedFolder);

		$refused = false;
		try {
			$saveClass->getMethod('setSelfMetadata')->invoke($handler, $entity, $selfData, [], $acting);
		} catch (FolderAccessDeniedException $e) {
			$refused = true;
		}

		return ['folder' => $entity->getFolder(), 'asked' => $this->askedFor, 'refused' => $refused];
	}//end bind()

	/**
	 * A readable folder.
	 *
	 * @param bool $readable Whether the acting user may read it.
	 *
	 * @return Folder The folder.
	 */
	private function folder(bool $readable): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('isReadable')->willReturn($readable);
		return $folder;
	}//end folder()

	public function testAReadableFolderIdIsBoundForTheActingUser(): void {
		$this->assertSame(
			['folder' => '42', 'asked' => ['alice'], 'refused' => false],
			$this->bind(['folder' => '42'], null, [$this->folder(true)])
		);
	}

	public function testALeadingZeroIdIsCheckedAndNormalised(): void {
		$this->assertSame(
			['folder' => '42', 'asked' => ['alice'], 'refused' => false],
			$this->bind(['folder' => '042'], null, [$this->folder(true)])
		);
	}

	public function testAnIntegerFolderIsCheckedToo(): void {
		$this->assertSame(
			['folder' => '7', 'asked' => ['alice'], 'refused' => false],
			$this->bind(['folder' => 7], '42', [$this->folder(true)])
		);
	}

	public function testAFolderOutsideTheUsersMountIsRefusedAndNotBound(): void {
		$this->assertSame(
			['folder' => '9', 'asked' => ['alice'], 'refused' => true],
			$this->bind(['folder' => '42'], '9', [])
		);
	}

	public function testAnUnreadableFolderIsRefused(): void {
		$this->assertSame(
			['folder' => null, 'asked' => ['alice'], 'refused' => true],
			$this->bind(['folder' => '42'], null, [$this->folder(false)])
		);
	}

	public function testAFileIdIsRefused(): void {
		$this->assertSame(
			['folder' => null, 'asked' => ['alice'], 'refused' => true],
			$this->bind(['folder' => '42'], null, [$this->createMock(\OCP\Files\File::class)])
		);
	}

	public function testEchoingTheStoredFolderIsNotAskedAgain(): void {
		$this->assertSame(
			['folder' => '42', 'asked' => [], 'refused' => false],
			$this->bind(['folder' => '42'], '42', [])
		);
	}

	public function testASignedOrFractionalValueIsNotTreatedAsAnId(): void {
		$this->assertSame(
			['folder' => '42.5', 'asked' => [], 'refused' => false],
			$this->bind(['folder' => '42.5'], null, [])
		);
	}

	public function testAnEmptyOrAbsentFolderLeavesTheStoredOne(): void {
		$this->assertSame(
			[
				['folder' => '9', 'asked' => [], 'refused' => false],
				['folder' => '9', 'asked' => [], 'refused' => false],
			],
			[
				$this->bind(['folder' => ''], '9', []),
				$this->bind([], '9', []),
			]
		);
	}
}//end class
