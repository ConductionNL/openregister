<?php

/**
 * A file link finds its file in the object's folder, and hands it to the holder.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-publication-link-opens-one-object-view-or-file-as-its-own-principal-req-abl-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service\Sharing;

use DateTime;
use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\StoredEntity;
use OCA\OpenRegister\Files\Service\File\FileRestrictionStore;
use OCA\OpenRegister\Files\Service\Sharing\AccessLinkReader;
use OCA\OpenRegister\Files\Service\Sharing\AccessLinkReaderSources;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Notifications\Service\Timeline\PublicTimeline;
use OCA\OpenRegister\Service\FileServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Rbac\ExplicitRevealMasks;
use OCA\OpenRegister\Service\Sharing\AccessLinkReaderFilters;
use OCA\OpenRegister\Service\Sharing\AccessLinkSubject;
use OCP\Files\File;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * An attached file lives in the object's folder and is not recorded on the
 * object, so a file link that only read the object's own file list answered
 * every holder 404. The folder is the membership check: a file id that is not
 * in it is never served, by `read()` or by `file()`.
 */
#[CoversClass(AccessLinkReader::class)]
#[UsesClass(AccessLink::class)]
#[UsesClass(AccessLinkReaderFilters::class)]
#[UsesClass(AccessLinkReaderSources::class)]
#[UsesClass(AccessLinkSubject::class)]
#[UsesClass(ExplicitRevealMasks::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(ObjectFindOptions::class)]
#[UsesClass(StoredEntity::class)]
class AccessLinkReaderFolderFileTest extends TestCase {

	/** @var ObjectService&MockObject */
	private ObjectService $objects;

	/** @var FileServiceInterface&MockObject */
	private FileServiceInterface $files;

	/** @var FileRestrictionStore&MockObject */
	private FileRestrictionStore $restrictions;

	/** @var array<int, File> The files in the object's folder. */
	private array $folder = [];

	/**
	 * A reader over one live object whose folder holds `$this->folder`.
	 *
	 * @param bool $withFiles Whether the reader is given the file service.
	 *
	 * @return AccessLinkReader
	 */
	private function reader(bool $withFiles = true): AccessLinkReader {
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('find')->willReturn(self::object());

		$this->files = $this->createMock(FileServiceInterface::class);
		$this->files->method('getFiles')->willReturnCallback(fn (): array => $this->folder);
		$this->files->method('formatFile')->willReturnCallback(
			static fn (File $file): array => ['id' => $file->getId(), 'title' => $file->getName(), 'path' => '/internal/' . $file->getName()]
		);

		$this->restrictions = $this->createMock(FileRestrictionStore::class);

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($this->createMock(Schema::class));
		$properties = $this->createMock(PropertyRbacHandler::class);
		$properties->method('filterReadableProperties')->willReturnArgument(1);
		$timeline = $this->createMock(PublicTimeline::class);
		$timeline->method('forObject')->willReturn([]);

		$files = null;
		if ($withFiles === true) {
			$files = $this->files;
		}

		return new AccessLinkReader(
			sources: new AccessLinkReaderSources(
				objects: $this->objects,
				subjects: new AccessLinkSubject(),
				restrictions: $this->restrictions,
				files: $files
			),
			filters: new AccessLinkReaderFilters(schemas: $schemas, properties: $properties, timeline: $timeline),
			logger: new NullLogger()
		);
	}//end reader()

	/**
	 * A live object with no recorded file list, as an attached file leaves it.
	 *
	 * @return ObjectEntity
	 */
	private static function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('object-uuid');
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setObject(['onderwerp' => 'Bezwaar']);
		$object->setFiles(null);

		return $object;
	}//end object()

	/**
	 * A link of a subject type and id.
	 *
	 * @param string $subjectType The subject type.
	 * @param string $subjectId   The subject id.
	 *
	 * @return AccessLink
	 */
	private static function link(string $subjectType, string $subjectId): AccessLink {
		$link = new AccessLink();
		$link->setSubjectType($subjectType);
		$link->setSubjectId($subjectId);
		$link->setCapabilities('read');
		$link->setCreatedBy('owner');
		$link->setExpiresAt(new DateTime('+1 day'));

		return $link;
	}//end link()

	/**
	 * A file node in the folder.
	 *
	 * @param int    $id   Its id.
	 * @param string $name Its name.
	 *
	 * @return File&MockObject
	 */
	private function node(int $id, string $name): File {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn($id);
		$node->method('getName')->willReturn($name);

		return $node;
	}//end node()

	public function testAFileInTheObjectsFolderIsServedToTheHolder(): void {
		$reader       = $this->reader();
		$this->folder = [$this->node(id: 11, name: 'other.txt'), $this->node(id: 12, name: 'besluit.txt')];

		$body = $reader->read(link: self::link(AccessLink::SUBJECT_FILE, 'object-uuid/12'));

		$this->assertNotNull($body);
		$this->assertSame(12, $body['file']['id']);
		$this->assertSame('besluit.txt', $body['file']['title']);
		$this->assertSame('Bezwaar', $body['subject']['onderwerp']);
	}//end testAFileInTheObjectsFolderIsServedToTheHolder()

	public function testAFileIdThatIsNotInTheObjectsFolderIsServedNothing(): void {
		$reader       = $this->reader();
		$this->folder = [$this->node(id: 11, name: 'other.txt')];

		$this->assertNull($reader->read(link: self::link(AccessLink::SUBJECT_FILE, 'object-uuid/99')));
		$this->assertNull($reader->file(link: self::link(AccessLink::SUBJECT_FILE, 'object-uuid/99')));
	}//end testAFileIdThatIsNotInTheObjectsFolderIsServedNothing()

	public function testFileHandsTheHolderTheNodeTheLinkNames(): void {
		$reader       = $this->reader();
		$wanted       = $this->node(id: 12, name: 'besluit.txt');
		$this->folder = [$this->node(id: 11, name: 'other.txt'), $wanted];

		$this->assertSame($wanted, $reader->file(link: self::link(AccessLink::SUBJECT_FILE, 'object-uuid/12')));
	}//end testFileHandsTheHolderTheNodeTheLinkNames()

	public function testARestrictedFileIsNeitherDescribedNorHandedOut(): void {
		$reader       = $this->reader();
		$this->folder = [$this->node(id: 12, name: 'besluit.txt')];
		$this->restrictions->method('find')->with(12)->willReturn(['fileId' => 12, 'readers' => ['user:bea']]);

		$this->assertNull($reader->read(link: self::link(AccessLink::SUBJECT_FILE, 'object-uuid/12')));
		$this->assertNull($reader->file(link: self::link(AccessLink::SUBJECT_FILE, 'object-uuid/12')));
	}//end testARestrictedFileIsNeitherDescribedNorHandedOut()

	public function testFileAnswersNothingForAnObjectOrViewLink(): void {
		$reader       = $this->reader();
		$this->folder = [$this->node(id: 12, name: 'besluit.txt')];

		$this->files->expects($this->never())->method('getFiles');

		$this->assertNull($reader->file(link: self::link(AccessLink::SUBJECT_OBJECT, 'object-uuid')));
		$this->assertNull($reader->file(link: self::link(AccessLink::SUBJECT_VIEW, 'view-uuid')));
	}//end testFileAnswersNothingForAnObjectOrViewLink()

	public function testAFolderThatCannotBeListedServesNothing(): void {
		$this->reader();
		$this->files = $this->createMock(FileServiceInterface::class);
		$reader = new AccessLinkReader(
			sources: new AccessLinkReaderSources(
				objects: $this->objects,
				subjects: new AccessLinkSubject(),
				files: $this->files
			),
			filters: new AccessLinkReaderFilters(
				schemas: $this->createMock(SchemaMapper::class),
				properties: $this->createMock(PropertyRbacHandler::class),
				timeline: $this->createMock(PublicTimeline::class)
			),
			logger: new NullLogger()
		);
		$this->files->method('getFiles')->willThrowException(new RuntimeException('no folder'));

		$this->assertNull($reader->file(link: self::link(AccessLink::SUBJECT_FILE, 'object-uuid/12')));
	}//end testAFolderThatCannotBeListedServesNothing()

	public function testWithoutTheFileServiceOnlyTheRecordedFileListIsRead(): void {
		$reader       = $this->reader(withFiles: false);
		$this->folder = [$this->node(id: 12, name: 'besluit.txt')];

		$this->assertNull($reader->read(link: self::link(AccessLink::SUBJECT_FILE, 'object-uuid/12')));
		$this->assertNull($reader->file(link: self::link(AccessLink::SUBJECT_FILE, 'object-uuid/12')));
	}//end testWithoutTheFileServiceOnlyTheRecordedFileListIsRead()
}//end class
