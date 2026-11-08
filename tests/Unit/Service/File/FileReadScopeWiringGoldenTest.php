<?php

/**
 * Golden test for the constructor wiring of FileReadScope.
 *
 * Pins the allowed AND refused verdicts of the file-search read scope with
 * every collaborator handed in through the constructor: the caller's tree, the
 * object read rule for an object's file, and the file restriction guard. A
 * change to how the scope is built that drops one of them would let a search
 * serve the text of a file the caller may not open; each refused case below
 * names the collaborator that refuses it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PEAR.Commenting.FunctionComment.WrongStyle -- the section banners above tests are banners, not doc comments.
// phpcs:disable PEAR.Commenting.FunctionComment.MissingReturn -- PHPUnit fixtures and tests; the signature IS the contract.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Service\File\FileReadScope;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuard;
use OCA\OpenRegister\Files\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class FileReadScopeWiringGoldenTest extends TestCase {

	/**
	 * A file in the caller's own tree.
	 *
	 * @var int
	 */
	private const OWN = 1;

	/**
	 * A file that is neither in the caller's tree nor an object's file.
	 *
	 * @var int
	 */
	private const FOREIGN = 2;

	/**
	 * A file in the caller's tree that carries a restriction the caller is not admitted to.
	 *
	 * @var int
	 */
	private const RESTRICTED = 3;

	/**
	 * An object's file whose object the caller may read.
	 *
	 * @var int
	 */
	private const OBJECT_READABLE = 901;

	/**
	 * An object's file whose object the caller may not read.
	 *
	 * @var int
	 */
	private const OBJECT_HIDDEN = 902;

	/**
	 * Build the scope the way the container does: every collaborator through the constructor.
	 *
	 * @param bool $signedIn Whether a caller is signed in.
	 */
	private function scope(bool $signedIn = true): FileReadScope {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		if ($signedIn === true) {
			$session = $this->createMock(IUserSession::class);
			$session->method('getUser')->willReturn($user);
		}

		$ownTree = $this->createMock(Folder::class);
		$ownTree->method('getFirstNodeById')->willReturnCallback(
			function (int $id): ?File {
				if (in_array($id, [self::OWN, self::RESTRICTED], true) === true) {
					return $this->createMock(File::class);
				}

				return null;
			}
		);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($ownTree);

		$readable = new ObjectEntity();
		$readable->setUuid('readable');
		$hidden = new ObjectEntity();
		$hidden->setUuid('hidden');
		$files = [
			self::OBJECT_READABLE => [$this->createMock(File::class), $readable],
			self::OBJECT_HIDDEN   => [$this->createMock(File::class), $hidden],
		];

		$fileService = $this->createMock(FileService::class);
		$fileService->method('getFileById')->willReturnCallback(
			static fn (int $id): ?File => ($files[$id][0] ?? null)
		);
		$fileService->method('isManagedFile')->willReturn(true);
		$fileService->method('findObjectForFile')->willReturnCallback(
			static function (File $file) use ($files): ?ObjectEntity {
				foreach ($files as $pair) {
					if ($pair[0] === $file) {
						return $pair[1];
					}
				}

				return null;
			}
		);

		$access = $this->createMock(ObjectFileAccess::class);
		$access->method('mayRead')->willReturnCallback(static fn (ObjectEntity $object): bool => $object->getUuid() === 'readable');

		$guard = $this->createMock(FileRestrictionGuard::class);
		$guard->method('mayRead')->willReturnCallback(static fn (int $id): bool => $id !== self::RESTRICTED);

		return new FileReadScope($root, $session, new NullLogger(), $fileService, $access, $guard);
	}//end scope()

	private function hit(int $fileId): array {
		return ['entity_type' => 'file', 'entity_id' => $fileId, 'text' => 'chunk ' . $fileId];
	}//end hit()

	// ---- Allowed. ----------------------------------------------------------

	public function testOnlyTheReadableHitsSurviveInTheirOriginalOrder(): void {
		$hits = [
			$this->hit(self::FOREIGN),
			$this->hit(self::OWN),
			$this->hit(self::RESTRICTED),
			$this->hit(self::OBJECT_HIDDEN),
			$this->hit(self::OBJECT_READABLE),
			['entity_type' => 'object', 'entity_id' => 'readable', 'text' => 'object chunk'],
		];

		$this->assertSame([$hits[1], $hits[4]], $this->scope()->readableResults($hits));
	}//end testOnlyTheReadableHitsSurviveInTheirOriginalOrder()

	public function testAFileInTheCallersTreeIsReadable(): void {
		$this->assertTrue($this->scope()->mayReadFile(self::OWN));
	}//end testAFileInTheCallersTreeIsReadable()

	public function testAnObjectsFileFollowsTheObjectsReadRuleWhenItAdmits(): void {
		$this->assertTrue($this->scope()->mayReadFile(self::OBJECT_READABLE));
	}//end testAnObjectsFileFollowsTheObjectsReadRuleWhenItAdmits()

	// ---- Refused. ----------------------------------------------------------

	public function testARestrictedFileIsRefusedEvenInTheCallersTree(): void {
		$this->assertFalse($this->scope()->mayReadFile(self::RESTRICTED));
	}//end testARestrictedFileIsRefusedEvenInTheCallersTree()

	public function testAnObjectsFileFollowsTheObjectsReadRuleWhenItRefuses(): void {
		$this->assertFalse($this->scope()->mayReadFile(self::OBJECT_HIDDEN));
	}//end testAnObjectsFileFollowsTheObjectsReadRuleWhenItRefuses()

	public function testAFileOutsideTheCallersTreeIsRefused(): void {
		$this->assertFalse($this->scope()->mayReadFile(self::FOREIGN));
	}//end testAFileOutsideTheCallersTreeIsRefused()

	public function testNoCallerReadsNothing(): void {
		$this->assertFalse($this->scope(signedIn: false)->mayReadFile(self::OWN));
		$this->assertSame([], $this->scope(signedIn: false)->readableResults([$this->hit(self::OWN)]));
	}//end testNoCallerReadsNothing()
}//end class
