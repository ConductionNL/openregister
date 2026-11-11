<?php

/**
 * Golden test for the constructor wiring of AccessLinkReader.
 *
 * Pins the allowed AND refused outcomes of the public read path with every
 * collaborator, the file restriction store included, handed in through the
 * constructor, so a change to how the reader is built cannot quietly drop a
 * collaborator. A restriction store that never reaches the reader would serve
 * a restricted file to whoever holds the link; the refused case below catches
 * exactly that.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Sharing
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

namespace OCA\OpenRegister\Tests\Unit\Service\Sharing;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PEAR.Commenting.FunctionComment.WrongStyle -- the section banners above tests are banners, not doc comments.
// phpcs:disable PEAR.Commenting.FunctionComment.MissingReturn -- PHPUnit fixtures and tests; the signature IS the contract.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Files\Service\File\FileRestrictionStore;
use OCA\OpenRegister\Files\Service\Sharing\AccessLinkReader;
use OCA\OpenRegister\Service\Sharing\AccessLinkReaderFilters;
use OCA\OpenRegister\Files\Service\Sharing\AccessLinkReaderSources;
use OCA\OpenRegister\Notifications\Service\Timeline\PublicTimeline;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Sharing\AccessLinkSubject;
use OCA\OpenRegister\Tests\Unit\Support\FindCallShape;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class AccessLinkReaderWiringGoldenTest extends TestCase {

	private const OPEN_FILE = 12;

	private const RESTRICTED_FILE = 13;

	private ObjectService&MockObject $objects;
	private PropertyRbacHandler&MockObject $properties;
	private PublicTimeline&MockObject $timeline;
	private AccessLinkReader $reader;

	protected function setUp(): void {
		parent::setUp();

		$this->objects = $this->createMock(ObjectService::class);
		$this->properties = $this->createMock(PropertyRbacHandler::class);
		$this->timeline = $this->createMock(PublicTimeline::class);

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($this->createMock(Schema::class));

		$restrictions = $this->createMock(FileRestrictionStore::class);
		$restrictions->method('find')->willReturnCallback(
			static function (int $fileId): ?array {
				if ($fileId === self::RESTRICTED_FILE) {
					return ['fileId' => $fileId];
				}

				return null;
			}
		);

		$this->properties->method('filterReadableProperties')->willReturn(['onderwerp' => 'Bezwaar']);
		$this->timeline->method('forObject')->willReturn([]);

		$this->reader = $this->makeReader(
			objects: $this->objects,
			schemas: $schemas,
			restrictions: $restrictions
		);
	}

	/**
	 * Build the reader the way the container does: every collaborator through the constructor.
	 *
	 * @param ObjectService        $objects      The object read path.
	 * @param SchemaMapper         $schemas      Resolves the schema whose rules apply.
	 * @param FileRestrictionStore $restrictions Refuses a restricted file to a link.
	 */
	private function makeReader(ObjectService $objects, SchemaMapper $schemas, FileRestrictionStore $restrictions): AccessLinkReader {
		return new AccessLinkReader(
			sources: new AccessLinkReaderSources(
				objects: $objects,
				subjects: new AccessLinkSubject(),
				restrictions: $restrictions
			),
			filters: new AccessLinkReaderFilters(
				schemas: $schemas,
				properties: $this->properties,
				timeline: $this->timeline
			),
			logger: $this->createMock(LoggerInterface::class)
		);
	}

	private function link(string $subjectType, string $subjectId): AccessLink {
		$link = new AccessLink();
		$link->setUuid('7a1f0f2e-0000-4000-8000-000000000002');
		$link->setAnchor('AnchorValueThatIsOpaque');
		$link->setSubjectType($subjectType);
		$link->setSubjectId($subjectId);
		$link->setCapabilities('read');
		$link->setCreatedBy('owner');

		return $link;
	}

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('object-uuid');
		$object->setName('Bezwaar 2026-002');
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setOwner('behandelaar');
		$object->setOrganisation('gemeente');
		$object->setObject(['onderwerp' => 'Bezwaar', 'bsn' => '123456782']);
		$object->setFiles(
			[
				['id' => (string)self::OPEN_FILE, 'name' => 'advies.pdf'],
				['id' => (string)self::RESTRICTED_FILE, 'name' => 'medisch.pdf'],
			]
		);

		return $object;
	}

	// ---- Allowed. ----------------------------------------------------------

	public function testAnObjectLinkIsServedFilteredWithoutRbacOrTenancy(): void {
		$this->objects->expects($this->once())
			->method('find')
			->with(...FindCallShape::findArgs('object-uuid', [], false, null, null, false, false, false, false))
			->willReturn($this->object());

		$body = $this->reader->read(link: $this->link(AccessLink::SUBJECT_OBJECT, 'object-uuid'));

		$this->assertIsArray($body);
		$this->assertSame('Bezwaar', $body['subject']['onderwerp']);
		$this->assertArrayNotHasKey('bsn', $body['subject']);
		$this->assertSame([], $body['timeline']);
	}

	public function testAnOpenFileTheObjectCarriesIsServed(): void {
		$this->objects->method('find')->willReturn($this->object());

		$body = $this->reader->read(
			link: $this->link(AccessLink::SUBJECT_FILE, 'object-uuid/' . self::OPEN_FILE)
		);

		$this->assertIsArray($body);
		$this->assertSame('advies.pdf', $body['file']['name']);
	}

	public function testAViewLinkIsServedOnlyWithTheViewScopeRequired(): void {
		$this->objects->expects($this->once())
			->method('searchObjects')
			->with(['_limit' => 200], false, false, null, null, ['view-uuid'], true)
			->willReturn([$this->object()]);

		$body = $this->reader->read(link: $this->link(AccessLink::SUBJECT_VIEW, 'view-uuid'));

		$this->assertIsArray($body);
		$this->assertSame(1, $body['total']);
	}

	// ---- Refused. ----------------------------------------------------------

	public function testARestrictedFileIsRefusedEvenThoughTheObjectCarriesIt(): void {
		$this->objects->method('find')->willReturn($this->object());

		$this->assertNull(
			$this->reader->read(link: $this->link(AccessLink::SUBJECT_FILE, 'object-uuid/' . self::RESTRICTED_FILE))
		);
	}

	public function testAFileTheObjectDoesNotCarryIsRefused(): void {
		$this->objects->method('find')->willReturn($this->object());

		$this->assertNull($this->reader->read(link: $this->link(AccessLink::SUBJECT_FILE, 'object-uuid/99')));
	}

	public function testADeletedObjectIsRefused(): void {
		$object = $this->object();
		$object->setDeleted(['deleted' => '2026-01-01T00:00:00+00:00']);
		$this->objects->method('find')->willReturn($object);

		$this->assertNull($this->reader->read(link: $this->link(AccessLink::SUBJECT_OBJECT, 'object-uuid')));
	}

	public function testAViewThatNoLongerResolvesIsRefused(): void {
		$this->objects->method('searchObjects')->willThrowException(new RuntimeException('gone'));

		$this->assertNull($this->reader->read(link: $this->link(AccessLink::SUBJECT_VIEW, 'view-uuid')));
	}
}//end class
