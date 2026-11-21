<?php

/**
 * Tests for AccessLinkActs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Sharing;

use OCA\OpenRegister\Kernel\Contracts\Value\NoteDraft;
use OCA\OpenRegister\Kernel\Contracts\Value\NoteAuthor;
use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Service\File\FileUpload;
use OCA\OpenRegister\Service\File\FileTarget;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\NoteServiceInterface;
use OCA\OpenRegister\Service\ObjectFilesInterface;
use OCA\OpenRegister\Service\Sharing\AccessLinkActs;
use OCA\OpenRegister\Service\Sharing\LinkAct;
use OCA\OpenRegister\Service\Sharing\LinkFile;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;

/**
 * The comment and the upload a link makes.
 *
 * @covers \OCA\OpenRegister\Service\Sharing\AccessLinkActs
 * @uses \OCA\OpenRegister\Db\AccessLink
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\NoteAuthor
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\NoteDraft
 * @uses \OCA\OpenRegister\Service\Sharing\LinkAct
 * @uses \OCA\OpenRegister\Service\File\FileTarget
 * @uses \OCA\OpenRegister\Service\File\FileUpload
 * @uses \OCA\OpenRegister\Service\Sharing\LinkFile
 */
class AccessLinkActsTest extends TestCase {

	/**
	 * A comment is written as the link, publicly visible, on the object's uuid.
	 *
	 * @return void
	 */
	public function testCommentIsWrittenAsTheLink(): void {
		$link = new AccessLink();
		$link->setUuid('l-1');
		$object = new ObjectEntity();
		$object->setUuid('o-1');

		$notes = $this->createMock(NoteServiceInterface::class);
		$notes->expects($this->once())->method('postNote')
			->with(
				new NoteDraft(objectUuid: 'o-1', message: 'hello', visibility: 'public'),
				new NoteAuthor(type: 'openregister_links', id: 'link:l-1')
			)
			->willReturn(['id' => 9]);
		$files = $this->createMock(ObjectFilesInterface::class);
		$files->expects($this->never())->method('addFile');

		$acts = new AccessLinkActs(notes: $notes, files: $files);

		$this->assertSame(['id' => 9], $acts->comment(act: new LinkAct(link: $link, object: $object), message: 'hello'));
	}//end testCommentIsWrittenAsTheLink()

	/**
	 * An upload is stored unshared and returned formatted.
	 *
	 * @return void
	 */
	public function testUploadIsStoredWithoutSharing(): void {
		$object = new ObjectEntity();
		$stored = $this->createMock(File::class);

		$files = $this->createMock(ObjectFilesInterface::class);
		$files->expects($this->once())->method('addFile')
			->with(
				$this->callback(fn (FileTarget $target): bool => $target->object === $object),
				$this->callback(fn (FileUpload $upload): bool => $upload->fileName === 'a.pdf' && $upload->content === 'bytes' && $upload->share === false)
			)
			->willReturn($stored);
		$files->expects($this->once())->method('formatFile')->with($stored)->willReturn(['name' => 'a.pdf', 'shared' => false]);
		$notes = $this->createMock(NoteServiceInterface::class);
		$notes->expects($this->never())->method('postNote');

		$acts = new AccessLinkActs(notes: $notes, files: $files);

		$this->assertSame(
			['name' => 'a.pdf', 'shared' => false],
			$acts->upload(act: new LinkAct(link: new AccessLink(), object: $object), file: new LinkFile(fileName: 'a.pdf', content: 'bytes'))
		);
	}//end testUploadIsStoredWithoutSharing()
}//end class
