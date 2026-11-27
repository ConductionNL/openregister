<?php

/**
 * Tests for ReviewPolicyFileOwners.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Decision
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Decision;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Decision\ReviewPolicyFileOwners;
use OCA\OpenRegister\Service\File\ManagedFileOwnerLookup;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;

/**
 * The object owning a file is found in the openregister home, not the acting user's mounts.
 */
class ReviewPolicyFileOwnersTest extends TestCase {
	/**
	 * A file in the openregister home names the object that owns it.
	 *
	 * @return void
	 */
	public function testTheOwningObjectIsFoundInTheOpenRegisterHome(): void {
		$file = $this->createMock(File::class);
		$object = new ObjectEntity();
		$owners = $this->ownersOver(node: $file, object: $object);

		$this->assertSame($object, $owners->objectOwningFile(fileId: 7));
	}//end testTheOwningObjectIsFoundInTheOpenRegisterHome()

	/**
	 * A file not in the openregister home has no owning object, and the object lookup is never asked.
	 *
	 * @return void
	 */
	public function testAFileNotThereHasNoOwner(): void {
		$owners = $this->ownersOver(node: null, object: new ObjectEntity());

		$this->assertNull($owners->objectOwningFile(fileId: 7));
	}//end testAFileNotThereHasNoOwner()

	/**
	 * A folder by that id is not a file, and has no owning object.
	 *
	 * @return void
	 */
	public function testAFolderHasNoOwner(): void {
		$owners = $this->ownersOver(node: $this->createMock(Folder::class), object: new ObjectEntity());

		$this->assertNull($owners->objectOwningFile(fileId: 7));
	}//end testAFolderHasNoOwner()

	/**
	 * The lookups over an openregister home holding `$node` as id 7.
	 *
	 * @param File|Folder|null $node   What the home holds by id 7.
	 * @param ObjectEntity     $object The object the owner lookup names.
	 *
	 * @return ReviewPolicyFileOwners
	 */
	private function ownersOver(File|Folder|null $node, ObjectEntity $object): ReviewPolicyFileOwners {
		$home = $this->createMock(Folder::class);
		$home->method('getFirstNodeById')->with(7)->willReturn($node);
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->once())->method('getUserFolder')->with('openregister')->willReturn($home);
		$lookup = $this->createMock(ManagedFileOwnerLookup::class);
		$lookup->expects($node instanceof File ? $this->once() : $this->never())->method('find')->willReturn($object);

		return new ReviewPolicyFileOwners(rootFolder: $root, owners: $lookup);
	}//end ownersOver()
}//end class
