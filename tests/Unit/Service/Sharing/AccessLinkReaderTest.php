<?php

/**
 * Unit tests for AccessLinkReader.
 *
 * The claim under test is the one a link is most likely to break: that it
 * cannot serve what a person could not. So each test removes one thing and
 * checks it is gone. A hidden property, an internal timeline entry, the
 * platform's own bookkeeping, a deleted object, a file the object does not
 * carry, and a schema that no longer resolves.
 *
 * The last one is the fail-closed test. A reader that published everything when
 * it could not read the rules would look identical to a working one on every
 * object whose schema happens to resolve.
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

use DateTime;
use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Timeline\PublicTimeline;
use OCA\OpenRegister\Service\Sharing\AccessLinkReader;
use OCA\OpenRegister\Service\Sharing\AccessLinkSubject;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class AccessLinkReaderTest extends TestCase {

	private ObjectService&MockObject $objects;
	private SchemaMapper&MockObject $schemas;
	private PropertyRbacHandler&MockObject $properties;
	private PublicTimeline&MockObject $timeline;
	private LoggerInterface&MockObject $logger;
	private AccessLinkReader $reader;

	protected function setUp(): void {
		parent::setUp();

		$this->objects = $this->createMock(ObjectService::class);
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->properties = $this->createMock(PropertyRbacHandler::class);
		$this->timeline = $this->createMock(PublicTimeline::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->schemas->method('find')->willReturn($this->createMock(Schema::class));

		$this->reader = new AccessLinkReader(
			objects: $this->objects,
			schemas: $this->schemas,
			properties: $this->properties,
			timeline: $this->timeline,
			subjects: new AccessLinkSubject(),
			logger: $this->logger
		);
	}

	private function link(string $subjectType = AccessLink::SUBJECT_OBJECT, string $subjectId = 'object-uuid'): AccessLink {
		$link = new AccessLink();
		$link->setUuid('7a1f0f2e-0000-4000-8000-000000000001');
		$link->setAnchor('AnchorValueThatIsOpaque');
		$link->setSubjectType($subjectType);
		$link->setSubjectId($subjectId);
		$link->setCapabilities('read');
		$link->setCreatedBy('owner');
		$link->setExpiresAt(new DateTime('+1 day'));

		return $link;
	}

	private function object(array $data = ['onderwerp' => 'Bezwaar', 'bsn' => '123456782']): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('object-uuid');
		$object->setName('Bezwaar 2026-001');
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setOwner('behandelaar');
		$object->setOrganisation('gemeente');
		$object->setFolder('/Bezwaren/2026-001');
		$object->setObject($data);

		return $object;
	}

	// ---- Task 4.1: a hidden property stays hidden. -------------------------

	public function testTheReadIsFilteredThroughThePropertyRulesAndNotServedRaw(): void {
		$this->objects->method('find')->willReturn($this->object());
		$this->timeline->method('forObject')->willReturn([]);
		$this->properties->expects($this->once())
			->method('filterReadableProperties')
			->willReturn(['onderwerp' => 'Bezwaar']);

		$body = $this->reader->read(link: $this->link());

		$this->assertIsArray($body);
		$this->assertSame('Bezwaar', $body['subject']['onderwerp']);
		$this->assertArrayNotHasKey('bsn', $body['subject'], 'a property the rules removed must not reappear');
	}

	/**
	 * Fail closed. A schema that no longer resolves means the rules cannot be
	 * read, and a reader that published the record anyway would be publishing
	 * under rules nobody checked.
	 */
	public function testAnObjectWhoseSchemaDoesNotResolvePublishesNoProperties(): void {
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willThrowException(new RuntimeException('gone'));
		$reader = new AccessLinkReader(
			objects: $this->objects,
			schemas: $schemas,
			properties: $this->properties,
			timeline: $this->timeline,
			subjects: new AccessLinkSubject(),
			logger: $this->logger
		);

		$this->objects->method('find')->willReturn($this->object());
		$this->timeline->method('forObject')->willReturn([]);
		$this->properties->expects($this->never())->method('filterReadableProperties');

		$body = $reader->read(link: $this->link());

		$this->assertIsArray($body);
		$this->assertArrayNotHasKey('onderwerp', $body['subject']);
		$this->assertArrayNotHasKey('bsn', $body['subject']);
	}

	// ---- Task 4.1: an internal note stays internal. ------------------------

	public function testOnlyThePublicHalfOfTheTimelineIsServed(): void {
		$this->objects->method('find')->willReturn($this->object());
		$this->properties->method('filterReadableProperties')->willReturn([]);
		// The public filter and the five-key projection are PublicTimeline's,
		// and PublicTimelineTest pins both by name. What this test pins is that
		// the link reader serves exactly that answer and adds nothing to it.
		$published = [[
			'id' => '1',
			'kind' => '',
			'message' => 'Published note',
			'fields' => [],
			'occurredAt' => '2026-05-04T09:12:00+02:00',
		]];
		$this->timeline->expects($this->once())
			->method('forObject')
			->willReturn($published);

		$body = $this->reader->read(link: $this->link());

		$this->assertIsArray($body);
		$this->assertSame($published, $body['timeline']);
	}

	// ---- Task 4.2: the platform's bookkeeping is not published. ------------

	public function testTheOwnerOrganisationFolderAndAuthorizationAreNotPublished(): void {
		$this->objects->method('find')->willReturn($this->object());
		$this->properties->method('filterReadableProperties')->willReturn(['onderwerp' => 'Bezwaar']);
		$this->timeline->method('forObject')->willReturn([]);

		$body = $this->reader->read(link: $this->link());

		$this->assertIsArray($body);
		foreach (['owner', 'organisation', 'folder', 'authorization', 'groups'] as $forbidden) {
			$this->assertArrayNotHasKey(
				$forbidden,
				$body['subject']['@self'],
				sprintf('a link must not publish @self.%s', $forbidden)
			);
		}

		$this->assertSame('object-uuid', $body['subject']['@self']['id']);
		$this->assertSame('Bezwaar 2026-001', $body['subject']['@self']['name']);
	}

	// ---- Task 3.1: a subject that is gone serves nothing. ------------------

	public function testADeletedObjectServesNothing(): void {
		$object = $this->object();
		$object->setDeleted(['deleted' => '2026-01-01T00:00:00+00:00']);
		$this->objects->method('find')->willReturn($object);

		$this->assertNull($this->reader->read(link: $this->link()));
	}

	public function testAnObjectThatNoLongerResolvesServesNothing(): void {
		$this->objects->method('find')->willThrowException(new RuntimeException('gone'));

		$this->assertNull($this->reader->read(link: $this->link()));
	}

	public function testAnEmptySubjectServesNothing(): void {
		$this->assertNull($this->reader->read(link: $this->link(subjectId: '  ')));
	}

	// ---- Task 4.1: a file the object does not carry. -----------------------

	public function testAFileTheObjectDoesNotCarryServesNothing(): void {
		$object = $this->object();
		$object->setFiles([['id' => '99', 'name' => 'advies.pdf']]);
		$this->objects->method('find')->willReturn($object);
		$this->properties->method('filterReadableProperties')->willReturn([]);
		$this->timeline->method('forObject')->willReturn([]);

		$this->assertNull(
			$this->reader->read(link: $this->link(subjectType: AccessLink::SUBJECT_FILE, subjectId: 'object-uuid/12'))
		);
	}

	public function testAFileTheObjectCarriesIsServed(): void {
		$object = $this->object();
		$object->setFiles([['id' => '12', 'name' => 'advies.pdf']]);
		$this->objects->method('find')->willReturn($object);
		$this->properties->method('filterReadableProperties')->willReturn([]);
		$this->timeline->method('forObject')->willReturn([]);

		$body = $this->reader->read(link: $this->link(subjectType: AccessLink::SUBJECT_FILE, subjectId: 'object-uuid/12'));

		$this->assertIsArray($body);
		$this->assertSame('advies.pdf', $body['file']['name']);
	}

	public function testAMalformedFileSubjectServesNothing(): void {
		$this->assertNull(
			$this->reader->read(link: $this->link(subjectType: AccessLink::SUBJECT_FILE, subjectId: 'no-slash-here'))
		);
	}

	// ---- Task 1.1: a view link. --------------------------------------------

	public function testAViewLinkServesTheViewsObjectsEachFiltered(): void {
		$this->objects->expects($this->once())
			->method('searchObjects')
			->with($this->anything(), false, false, null, null, ['view-uuid'])
			->willReturn([$this->object(), $this->object()]);
		$this->properties->method('filterReadableProperties')->willReturn(['onderwerp' => 'Bezwaar']);

		$body = $this->reader->read(link: $this->link(subjectType: AccessLink::SUBJECT_VIEW, subjectId: 'view-uuid'));

		$this->assertIsArray($body);
		$this->assertSame(2, $body['total']);
		$this->assertSame('Bezwaar', $body['results'][0]['onderwerp']);
		$this->assertArrayNotHasKey('owner', $body['results'][0]['@self']);
	}

	public function testAViewThatNoLongerResolvesServesNothing(): void {
		$this->objects->method('searchObjects')->willThrowException(new RuntimeException('gone'));

		$this->assertNull($this->reader->read(link: $this->link(subjectType: AccessLink::SUBJECT_VIEW, subjectId: 'view-uuid')));
	}

	public function testAViewLinkResolvesToNoSingleObject(): void {
		$this->assertNull(
			$this->reader->subjectObject(link: $this->link(subjectType: AccessLink::SUBJECT_VIEW, subjectId: 'view-uuid'))
		);
	}

	// ---- The read runs without a session, and says so. ---------------------

	public function testTheSubjectIsFetchedWithoutRbacBecauseThereIsNoPrincipalToJudge(): void {
		$this->objects->expects($this->once())
			->method('find')
			->with('object-uuid', [], false, null, null, false, false, false, false)
			->willReturn($this->object());
		$this->properties->method('filterReadableProperties')->willReturn([]);
		$this->timeline->method('forObject')->willReturn([]);

		$this->reader->read(link: $this->link());
	}
	// ---- Task 4.3: the timeline moved out, and its allow-list with it. -----

	// A public entry's text is public and the account that wrote it is not.
	// That projection now lives in Service/Timeline/PublicTimeline, which this
	// reader delegates to, and it is asserted there by
	// PublicTimelineTest::testANoteLeavesWithoutItsAuthor (it feeds an entry
	// carrying `actorId` and asserts the key is gone). The version of this
	// check that lived here projected notes only; that one reads records too,
	// so it is strictly the better home.

	// ---- Task 4.1/4.2: the projection the share token surface borrows. -----

	/**
	 * `publish()` is the same projection the link uses, for the share token.
	 *
	 * The share token surface answered with `jsonSerialize()`, which carried
	 * `@self.authorization` and every property regardless of the rules
	 * (openregister#3818). Two anonymous surfaces get one allow-list, so this
	 * test asserts what the OTHER surface now receives.
	 */
	public function testPublishReducesAnObjectTheWayALinkDoes(): void {
		$this->properties->method('filterReadableProperties')->willReturn(['onderwerp' => 'Bezwaar']);

		$published = $this->reader->publish(object: $this->object());

		$this->assertSame('Bezwaar', $published['onderwerp']);
		$this->assertArrayNotHasKey('bsn', $published, 'a property the rules removed must not reappear');
		foreach (['owner', 'organisation', 'folder', 'authorization', 'groups'] as $forbidden) {
			$this->assertArrayNotHasKey(
				$forbidden,
				$published['@self'],
				sprintf('an anonymous read must not publish @self.%s', $forbidden)
			);
		}
	}
}
