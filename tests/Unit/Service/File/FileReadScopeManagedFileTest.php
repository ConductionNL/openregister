<?php

/**
 * File search hits on an object's file follow the object's read rule.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FileReadScope;
use OCA\OpenRegister\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A handler gets the hit on the intake's attachment, a non-member does not.
 */
class FileReadScopeManagedFileTest extends TestCase {

	/**
	 * A read scope for a caller whose object read verdict is $mayRead.
	 *
	 * @param bool $mayRead The object read rule's answer.
	 *
	 * @return FileReadScope
	 */
	private function scopeWhereObjectIs(bool $mayRead): FileReadScope {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('caller');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		// The file is not in the caller's own tree: it is in the openregister home.
		$ownTree = $this->createMock(Folder::class);
		$ownTree->method('getFirstNodeById')->willReturn(null);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($ownTree);

		$attachment = $this->createMock(File::class);
		$fileService = $this->createMock(FileService::class);
		$fileService->method('getFileById')->willReturnCallback(
			static fn (int $id): ?File => ($id === 901 ? $attachment : null)
		);
		$fileService->method('isManagedFile')->willReturn(true);
		$fileService->method('findObjectForFile')->willReturn(new ObjectEntity());

		$access = $this->createMock(ObjectFileAccess::class);
		$access->method('mayRead')->willReturn($mayRead);

		return new FileReadScope($root, $session, new NullLogger(), $fileService, $access);
	}//end scopeWhereObjectIs()

	public function testAHandlerKeepsTheHitOnTheIntakesAttachment(): void {
		$hits = [['entity_type' => 'file', 'entity_id' => 901, 'text' => 'aanvraag']];

		$this->assertSame($hits, $this->scopeWhereObjectIs(true)->readableResults($hits));
	}//end testAHandlerKeepsTheHitOnTheIntakesAttachment()

	public function testANonMemberLosesTheHit(): void {
		$hits = [['entity_type' => 'file', 'entity_id' => 901, 'text' => 'aanvraag']];

		$this->assertSame([], $this->scopeWhereObjectIs(false)->readableResults($hits));
	}//end testANonMemberLosesTheHit()
}//end class
