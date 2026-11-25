<?php

/**
 * A file's entity relations are listed, masked and counted for a caller who may open the file, and refused to anyone else.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FileEntityRelationsController;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCA\OpenRegister\Files\Service\File\CallerFileAccess;
use OCA\OpenRegister\Service\Decision\DecisionVisibilityInterface;
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
 * A reader, a non-reader and a caller without a file id asking for a file's relations.
 */
#[CoversClass(FileEntityRelationsController::class)]
#[UsesClass(CallerFileAccess::class)]
#[UsesClass(EntityRelation::class)]
final class FileEntityRelationsControllerTest extends TestCase {
	/**
	 * The controller, with `jan` signed in, whose folder holds the given files.
	 *
	 * @param list<int>                     $readable   The file ids `jan` can open.
	 * @param EntityRelationMapperInterface $relations  The relation store.
	 * @param DecisionVisibilityInterface   $visibility The decision mask.
	 *
	 * @return FileEntityRelationsController
	 */
	private function controller(
		array $readable,
		EntityRelationMapperInterface $relations,
		DecisionVisibilityInterface $visibility
	): FileEntityRelationsController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$folder = $this->createMock(Folder::class);
		$node = $this->createMock(Node::class);
		$folder->method('getById')->willReturnCallback(
			static function (int $id) use ($readable, $node): array {
				if (in_array($id, $readable, true) === false) {
					return [];
				}

				return [$node];
			}
		);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('jan')->willReturn($folder);

		return new FileEntityRelationsController(
			request: $this->createMock(IRequest::class),
			relations: $relations,
			fileAccess: new CallerFileAccess(fileReadScope: null, userSession: $session, rootFolder: $root),
			decisionVisibility: $visibility,
			userSession: $session
		);
	}//end controller()

	/**
	 * A reader gets the relations as the mask presents them, counted kept, rejected or neither when masked.
	 *
	 * @return void
	 */
	public function testAReaderGetsThePresentedRelationsWithCounts(): void {
		$stored = [new EntityRelation(), new EntityRelation(), new EntityRelation(), new EntityRelation()];
		$relations = $this->createMock(EntityRelationMapperInterface::class);
		$relations->expects($this->once())->method('findByFileId')->with(31)->willReturn($stored);

		$presented = [
			['id' => 1, 'skipAnonymization' => false, 'bases' => ['5.1.2e']],
			['id' => 2, 'skipAnonymization' => false, 'bases' => ['5.1.2e']],
			['id' => 3, 'skipAnonymization' => true, 'bases' => null],
			['id' => 4, 'skipAnonymization' => null, 'decision' => 'hidden'],
		];
		$visibility = $this->createMock(DecisionVisibilityInterface::class);
		$visibility->expects($this->once())->method('presentAll')->with($stored, 'jan')->willReturn($presented);

		$response = $this->controller(readable: [31], relations: $relations, visibility: $visibility)->index(31);

		$this->assertSame(
			[200, ['fileId' => 31, 'results' => $presented, 'kept' => 2, 'rejected' => 1]],
			[$response->getStatus(), $response->getData()]
		);
	}//end testAReaderGetsThePresentedRelationsWithCounts()

	/**
	 * A caller who may not open the file gets 403 and the store is never read.
	 *
	 * @return void
	 */
	public function testANonReaderGets403(): void {
		$relations = $this->createMock(EntityRelationMapperInterface::class);
		$relations->expects($this->never())->method('findByFileId');
		$visibility = $this->createMock(DecisionVisibilityInterface::class);
		$visibility->expects($this->never())->method('presentAll');

		$response = $this->controller(readable: [8], relations: $relations, visibility: $visibility)->index(31);

		$this->assertSame(
			[403, ['error' => 'You may not read this file']],
			[$response->getStatus(), $response->getData()]
		);
	}//end testANonReaderGets403()

	/**
	 * Without a file id the answer is 400 and the store is never read.
	 *
	 * @return void
	 */
	public function testAMissingFileIdIs400(): void {
		$relations = $this->createMock(EntityRelationMapperInterface::class);
		$relations->expects($this->never())->method('findByFileId');

		$response = $this->controller(
			readable: [31],
			relations: $relations,
			visibility: $this->createMock(DecisionVisibilityInterface::class)
		)->index();

		$this->assertSame(
			[400, ['error' => 'The query parameter fileId is required']],
			[$response->getStatus(), $response->getData()]
		);
	}//end testAMissingFileIdIs400()
}//end class
