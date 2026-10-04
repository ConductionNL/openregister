<?php

/**
 * The configuration preview names what an import would change.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\FetchHandler;
use OCA\OpenRegister\Service\Configuration\ImportSelection;
use OCA\OpenRegister\Service\Configuration\PreviewHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Before this, every object row of the preview was an empty array and every
 * update carried an empty change list: the administrator saw "update" with no
 * diff, and could not select a single object (the row had no register, schema
 * or slug to build the selection key from).
 */
final class PreviewHandlerChangesTest extends TestCase {

	private RegisterMapper $registerMapper;

	private SchemaMapper $schemaMapper;

	private FetchHandler $fetchHandler;

	private MagicMapper $objectMapper;

	private PreviewHandler $handler;

	private Register $register;

	private Schema $schema;

	/**
	 * Set up a handler over one local register `zaken` and schema `zaaktype`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->register = new Register();
		$this->register->setId(4);
		$this->register->setSlug('zaken');
		$this->register->setVersion('1.0.0');
		$this->register->setTitle('Zaken');

		$this->schema = new Schema();
		$this->schema->setId(9);
		$this->schema->setSlug('zaaktype');
		$this->schema->setVersion('1.0.0');
		$this->schema->setTitle('Zaaktype');

		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->registerMapper->method('findAll')->willReturn([$this->register]);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->schemaMapper->method('findAll')->willReturn([$this->schema]);
		$this->fetchHandler = $this->createMock(FetchHandler::class);
		$this->objectMapper = $this->createMock(MagicMapper::class);

		$this->handler = new PreviewHandler(
			$this->registerMapper,
			$this->schemaMapper,
			$this->createMock(LoggerInterface::class),
			$this->fetchHandler,
			$this->objectMapper
		);
	}//end setUp()

	/**
	 * Preview a remote document holding the given objects.
	 *
	 * @param array<int, array<string, mixed>> $objects Remote objects.
	 *
	 * @return array<string, mixed>
	 */
	private function previewObjects(array $objects): array {
		$this->fetchHandler->method('fetchRemoteConfiguration')->willReturn(['components' => ['objects' => $objects]]);

		$configuration = new Configuration();
		$configuration->setId(1);

		return $this->handler->previewConfigurationChanges($configuration);
	}//end previewObjects()

	/**
	 * A remote object.
	 *
	 * @param string $slug    Object slug.
	 * @param string $version Object version.
	 * @param string $title   Object title.
	 *
	 * @return array<string, mixed>
	 */
	private function remote(string $slug, string $version, string $title): array {
		return [
			'title' => $title,
			'@self' => ['register' => 'zaken', 'schema' => 'zaaktype', 'slug' => $slug, 'version' => $version],
		];
	}//end remote()

	/**
	 * A local object as the mapper returns it.
	 *
	 * @param string $slug    Object slug.
	 * @param string $version Object version.
	 * @param string $title   Object title.
	 *
	 * @return ObjectEntity
	 */
	private function local(string $slug, string $version, string $title): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('11111111-2222-3333-4444-555555555555');
		$object->setSlug($slug);
		$object->setVersion($version);
		$object->setRegister('4');
		$object->setSchema('9');
		$object->setObject(['title' => $title]);
		return $object;
	}//end local()

	/**
	 * An object that does not exist locally is offered for creation, keyed the way the selection reads it.
	 *
	 * @return void
	 */
	public function testMissingObjectIsACreateRowWithItsSelectionKey(): void {
		$this->objectMapper->method('find')->willThrowException(new DoesNotExistException('none'));

		$remote = $this->remote(slug: 'omgevingsvergunning', version: '1.0.0', title: 'Omgevingsvergunning');
		$preview = $this->previewObjects(objects: [$remote]);

		$row = $preview['objects'][0];
		self::assertSame('object', $row['type']);
		self::assertSame('create', $row['action']);
		self::assertSame('Omgevingsvergunning', $row['title']);
		self::assertSame(['zaken', 'zaaktype', 'omgevingsvergunning'], [$row['register'], $row['schema'], $row['slug']]);

		// The key the modal builds from this row picks exactly this object.
		$key = $row['register'] . ':' . $row['schema'] . ':' . $row['slug'];
		$picked = ImportSelection::filter(document: ['components' => ['objects' => [$remote]]], selection: ['objects' => [$key]]);
		self::assertSame([$remote], $picked['components']['objects']);
	}//end testMissingObjectIsACreateRowWithItsSelectionKey()

	/**
	 * The lookup is scoped to the local register and schema and bypasses RBAC and tenancy, as the import does.
	 *
	 * @return void
	 */
	public function testLookupIsScopedToTheLocalRegisterAndSchema(): void {
		$this->objectMapper->expects(self::once())
			->method('find')
			->with('omgevingsvergunning', $this->register, $this->schema, false, false, false)
			->willThrowException(new DoesNotExistException('none'));

		$this->previewObjects(objects: [$this->remote(slug: 'omgevingsvergunning', version: '1.0.0', title: 'X')]);
	}//end testLookupIsScopedToTheLocalRegisterAndSchema()

	/**
	 * A newer remote object is an update that lists the fields it changes.
	 *
	 * @return void
	 */
	public function testNewerObjectIsAnUpdateWithItsChanges(): void {
		$this->objectMapper->method('find')->willReturn($this->local(slug: 'bouw', version: '1.0.0', title: 'Bouw'));

		$preview = $this->previewObjects(objects: [$this->remote(slug: 'bouw', version: '1.1.0', title: 'Bouwen')]);

		$row = $preview['objects'][0];
		self::assertSame('update', $row['action']);
		self::assertNotNull($row['current']);
		self::assertContains(['field' => 'title', 'current' => 'Bouw', 'proposed' => 'Bouwen'], $row['changes']);
		self::assertContains(['field' => '@self.version', 'current' => '1.0.0', 'proposed' => '1.1.0'], $row['changes']);
	}//end testNewerObjectIsAnUpdateWithItsChanges()

	/**
	 * A remote object that is not newer is skipped with the reason, as the import skips it.
	 *
	 * @return void
	 */
	public function testObjectThatIsNotNewerIsSkipped(): void {
		$this->objectMapper->method('find')->willReturn($this->local(slug: 'bouw', version: '1.1.0', title: 'Bouw'));

		$preview = $this->previewObjects(objects: [$this->remote(slug: 'bouw', version: '1.1.0', title: 'Bouwen')]);

		self::assertSame('skip', $preview['objects'][0]['action']);
		self::assertStringContainsString('1.1.0', $preview['objects'][0]['reason']);
		self::assertSame([], $preview['objects'][0]['changes']);
	}//end testObjectThatIsNotNewerIsSkipped()

	/**
	 * An object naming a register or schema this instance lacks is skipped, and so is one without a slug.
	 *
	 * @return void
	 */
	public function testObjectWithoutLocalTargetOrSlugIsSkipped(): void {
		$this->objectMapper->expects(self::never())->method('find');

		$unknown = $this->remote(slug: 'x', version: '1.0.0', title: 'X');
		$unknown['@self']['schema'] = 'unknown';
		$noSlug = $this->remote(slug: '', version: '1.0.0', title: 'Y');

		$preview = $this->previewObjects(objects: [$unknown, $noSlug]);

		self::assertSame(['skip', 'skip'], array_column($preview['objects'], 'action'));
		self::assertSame('unknown', $preview['objects'][0]['schema']);
	}//end testObjectWithoutLocalTargetOrSlugIsSkipped()

	/**
	 * A register update lists the fields it changes, nested ones by path, and ignores the row's own ids and dates.
	 *
	 * @return void
	 */
	public function testRegisterUpdateListsItsChanges(): void {
		$this->registerMapper->method('find')->willReturn($this->register);

		$row = $this->handler->previewRegisterChange(
			slug: 'zaken',
			registerData: ['title' => 'Zaken en meldingen', 'version' => '1.2.0', 'id' => 99, 'configuration' => ['tile' => 'blue']]
		);

		self::assertSame('update', $row['action']);
		self::assertContains(['field' => 'title', 'current' => 'Zaken', 'proposed' => 'Zaken en meldingen'], $row['changes']);
		self::assertSame([], array_values(array_filter($row['changes'], static fn (array $c): bool => $c['field'] === 'id')));
	}//end testRegisterUpdateListsItsChanges()

	/**
	 * Nested maps are compared by path; lists are compared whole.
	 *
	 * @return void
	 */
	public function testCompareArraysRecursesIntoMapsAndComparesListsWhole(): void {
		$changes = $this->handler->compareArrays(
			current: ['a' => ['b' => 1, 'c' => 2], 'tags' => ['x', 'y'], 'same' => 's', 'uuid' => 'old'],
			proposed: ['a' => ['b' => 1, 'c' => 3], 'tags' => ['x'], 'same' => 's', 'uuid' => 'new', 'added' => true]
		);

		self::assertSame(
			[
				['field' => 'a.c', 'current' => 2, 'proposed' => 3],
				['field' => 'tags', 'current' => ['x', 'y'], 'proposed' => ['x']],
				['field' => 'added', 'current' => null, 'proposed' => true],
			],
			$changes
		);
	}//end testCompareArraysRecursesIntoMapsAndComparesListsWhole()
}//end class
