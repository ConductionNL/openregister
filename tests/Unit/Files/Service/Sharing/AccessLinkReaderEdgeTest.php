<?php

/**
 * AccessLinkReader at its edges: a subject the lookup does not return, a view
 * link without a view id or whose search yields no list, deleted and foreign
 * rows in a view, an object without a schema, and file links whose file id is
 * empty, whose object lists no files, or whose file list holds stray entries.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service\Sharing;

use DateTime;
use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\StoredEntity;
use OCA\OpenRegister\Files\Service\Sharing\AccessLinkReader;
use OCA\OpenRegister\Files\Service\Sharing\AccessLinkReaderSources;
use OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults;
use OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\SaveActor;
use OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy;
use OCA\OpenRegister\Notifications\Service\Timeline\PublicTimeline;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCA\OpenRegister\Service\Rbac\ExplicitRevealMasks;
use OCA\OpenRegister\Service\Sharing\AccessLinkReaderFilters;
use OCA\OpenRegister\Service\Sharing\AccessLinkSubject;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(AccessLinkReader::class)]
#[UsesClass(AccessLink::class)]
#[UsesClass(AccessLinkReaderFilters::class)]
#[UsesClass(AccessLinkReaderSources::class)]
#[UsesClass(AccessLinkSubject::class)]
#[UsesClass(BulkBatchDefaults::class)]
#[UsesClass(BulkSaveOptions::class)]
#[UsesClass(ExplicitReveal::class)]
#[UsesClass(ExplicitRevealMasks::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(ObjectFindOptions::class)]
#[UsesClass(ObjectFindTarget::class)]
#[UsesClass(ObjectSaveOptions::class)]
#[UsesClass(SaveActor::class)]
#[UsesClass(SavePolicy::class)]
#[UsesClass(StoredEntity::class)]
class AccessLinkReaderEdgeTest extends TestCase {

	/** @var ObjectService&MockObject */
	private ObjectService $objects;

	/** @var SchemaMapper&MockObject */
	private SchemaMapper $schemas;

	/** @var PropertyRbacHandler&MockObject */
	private PropertyRbacHandler $properties;

	/** @var IUserManager&MockObject */
	private IUserManager $users;

	/** @var AccessLinkReader The subject under test. */
	private AccessLinkReader $reader;

	protected function setUp(): void {
		$this->objects    = $this->createMock(ObjectService::class);
		$this->schemas    = $this->createMock(SchemaMapper::class);
		$this->properties = $this->createMock(PropertyRbacHandler::class);
		$this->users      = $this->createMock(IUserManager::class);
		$timeline         = $this->createMock(PublicTimeline::class);
		$timeline->method('forObject')->willReturn([]);
		$this->schemas->method('find')->willReturn($this->createMock(Schema::class));
		$this->properties->method('filterReadableProperties')->willReturnArgument(1);

		$this->reader = new AccessLinkReader(
			sources: new AccessLinkReaderSources(objects: $this->objects, subjects: new AccessLinkSubject(), users: $this->users),
			filters: new AccessLinkReaderFilters(schemas: $this->schemas, properties: $this->properties, timeline: $timeline),
			logger: new NullLogger()
		);
	}//end setUp()

	/**
	 * A link minted by `owner`.
	 *
	 * @param string $subjectType The subject type.
	 * @param string $subjectId   The subject id.
	 *
	 * @return AccessLink
	 */
	private static function link(string $subjectType, string $subjectId): AccessLink {
		$link = new AccessLink();
		$link->setUuid('7a1f0f2e-0000-4000-8000-000000000002');
		$link->setSubjectType($subjectType);
		$link->setSubjectId($subjectId);
		$link->setCapabilities('read');
		$link->setCreatedBy('owner');
		$link->setExpiresAt(new DateTime('+1 day'));

		return $link;
	}//end link()

	/**
	 * A live object of schema 2.
	 *
	 * @param string      $uuid   The uuid.
	 * @param string|null $schema The schema id.
	 *
	 * @return ObjectEntity
	 */
	private static function object(string $uuid = 'object-uuid', ?string $schema = '2'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setName('Melding ' . $uuid);
		$object->setRegister('1');
		$object->setSchema($schema);
		$object->setObject(['onderwerp' => 'Melding']);

		return $object;
	}//end object()

	/**
	 * The enabled minter `owner`, with the object path running as them.
	 *
	 * @return void
	 */
	private function minter(): void {
		$minter = $this->createMock(IUser::class);
		$minter->method('isEnabled')->willReturn(true);
		$this->users->method('get')->with('owner')->willReturn($minter);
		$this->objects->method('runAs')->willReturnCallback(static fn (IUser $user, callable $operation) => $operation());
	}//end minter()

	public function testASubjectTheLookupDoesNotReturnServesNothing(): void {
		$this->objects->expects($this->once())->method('find')->willReturn(null);

		$this->assertNull($this->reader->read(link: self::link(AccessLink::SUBJECT_OBJECT, 'object-uuid')));
	}//end testASubjectTheLookupDoesNotReturnServesNothing()

	public function testAViewLinkWithoutAViewIdServesNothingAndSearchesNothing(): void {
		$this->minter();
		$this->objects->expects($this->never())->method('searchObjects');

		$this->assertNull($this->reader->read(link: self::link(AccessLink::SUBJECT_VIEW, '   ')));
	}//end testAViewLinkWithoutAViewIdServesNothingAndSearchesNothing()

	public function testAViewSearchThatYieldsACountInsteadOfRowsServesNothing(): void {
		$this->minter();
		$this->objects->expects($this->once())->method('searchObjects')->willReturn(3);

		$this->assertNull($this->reader->read(link: self::link(AccessLink::SUBJECT_VIEW, 'view-uuid')));
	}//end testAViewSearchThatYieldsACountInsteadOfRowsServesNothing()

	public function testDeletedAndForeignRowsOfAViewAreLeftOutOfTheResultsAndTheTotal(): void {
		$this->minter();
		$deleted = self::object(uuid: 'deleted-uuid');
		$deleted->setDeleted(['deleted' => '2026-11-01T10:00:00+00:00']);
		$this->objects->method('searchObjects')->willReturn([$deleted, ['uuid' => 'raw-row'], self::object(uuid: 'live-uuid')]);

		$body = $this->reader->read(link: self::link(AccessLink::SUBJECT_VIEW, 'view-uuid'));

		$this->assertSame(1, $body['total']);
		$this->assertCount(1, $body['results']);
		$this->assertSame('live-uuid', $body['results'][0]['id']);
	}//end testDeletedAndForeignRowsOfAViewAreLeftOutOfTheResultsAndTheTotal()

	public function testAnObjectWithoutASchemaPublishesNoPropertiesOnlyItsIdentity(): void {
		$this->schemas->expects($this->never())->method('find');
		$this->properties->expects($this->never())->method('filterReadableProperties');

		$published = $this->reader->publish(object: self::object(schema: ''));

		$this->assertArrayNotHasKey('onderwerp', $published);
		$this->assertSame('object-uuid', $published['id']);
		$this->assertSame('Melding object-uuid', $published['@self']['name']);
	}//end testAnObjectWithoutASchemaPublishesNoPropertiesOnlyItsIdentity()

	public function testAFileLinkWithAnEmptyFileIdServesNothing(): void {
		$object = self::object();
		$object->setFiles([['id' => '', 'name' => 'leeg.pdf']]);
		$this->objects->method('find')->willReturn($object);

		$this->assertNull($this->reader->read(link: self::link(AccessLink::SUBJECT_FILE, 'object-uuid/ ')));
	}//end testAFileLinkWithAnEmptyFileIdServesNothing()

	public function testAFileLinkOnAnObjectWithoutAFileListServesNothing(): void {
		$object = self::object();
		$object->setFiles(null);
		$this->objects->method('find')->willReturn($object);

		$this->assertNull($this->reader->read(link: self::link(AccessLink::SUBJECT_FILE, 'object-uuid/12')));
	}//end testAFileLinkOnAnObjectWithoutAFileListServesNothing()

	public function testStrayEntriesInTheFileListAreSkippedAndTheFileIsMatchedByUuid(): void {
		$object = self::object();
		$object->setFiles(['not-a-descriptor', 42, ['uuid' => 'file-uuid', 'name' => 'besluit.pdf']]);
		$this->objects->method('find')->willReturn($object);

		$body = $this->reader->read(link: self::link(AccessLink::SUBJECT_FILE, 'object-uuid/file-uuid'));

		$this->assertSame(['uuid' => 'file-uuid', 'name' => 'besluit.pdf'], $body['file']);
		$this->assertSame([], $body['timeline']);
	}//end testStrayEntriesInTheFileListAreSkippedAndTheFileIsMatchedByUuid()
}//end class
