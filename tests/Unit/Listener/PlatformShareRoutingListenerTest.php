<?php

/**
 * A platform share of an object folder passes the schema's opt-in and the permission mapping.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-share-provider/specs/platform-share-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Listener\PlatformShareRoutingListener;
use OCA\OpenRegister\Service\Rbac\ObjectSharingServiceInterface;
use OCA\OpenRegister\Service\Sharing\PlatformShareGate;
use OCP\Constants;
use OCP\EventDispatcher\Event;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IRequest;
use OCP\Share\Events\BeforeShareCreatedEvent;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Listener\PlatformShareRoutingListener
 * @uses   \OCA\OpenRegister\Service\Sharing\PlatformShareGate
 * @uses   \OCA\OpenRegister\Service\Sharing\PlatformSharePermissions
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Schema\AnnotationVocabulary
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValueRules
 * @uses   \OCA\OpenRegister\Db\Schema\LinkedTypesRule
 * @uses   \OCA\OpenRegister\Db\Schema\VocabularyEntries
 * @uses   \OCA\OpenRegister\Db\Schema\WriteOnlyPathsDeclaration
 * @uses   \OCA\OpenRegister\Service\Calendar\ObjectDateDeclaration
 * @uses   \OCA\OpenRegister\Service\Calendar\CalendarDateDeclarationCheck
 */
final class PlatformShareRoutingListenerTest extends TestCase {

	private const UUID = '710d44a4-0000-4000-8000-000000000002';

	private const FOLDER_ID = 4242;

	/**
	 * The request path the share is created on.
	 *
	 * @var string
	 */
	private string $path = '/ocs/v2.php/apps/files_sharing/api/v1/shares';

	/**
	 * The object's schema configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $configuration = ['platformSharing' => true];

	/**
	 * The objects the uuid lookup answers.
	 *
	 * @var list<ObjectEntity>
	 */
	private array $found = [];

	/**
	 * The uuid lookup.
	 *
	 * @var MagicMapperInterface&MockObject
	 */
	private MagicMapperInterface&MockObject $objects;

	protected function setUp(): void {
		parent::setUp();
		$this->found   = [$this->object(folder: (string)self::FOLDER_ID)];
		$this->objects = $this->createMock(MagicMapperInterface::class);
		$this->objects->method('findStoredAcrossAllMagicTables')->willReturnCallback(
			fn (array $uuids): array => $uuids === [self::UUID] ? $this->found : []
		);
	}

	private function object(string $folder): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid(self::UUID);
		$object->setSchema('7');
		$object->setFolder($folder);

		return $object;
	}

	private function listener(): PlatformShareRoutingListener {
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturnCallback(fn (): string => $this->path);

		$schema = new Schema();
		$schema->setSlug('zaken');
		$schema->setConfiguration($this->configuration);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			static fn (string|int $id): Schema => (string)$id === '7' ? $schema : throw new \RuntimeException('no schema ' . $id)
		);

		return new PlatformShareRoutingListener(
			request: $request,
			objects: $this->objects,
			schemas: $schemas,
			gate: new PlatformShareGate(sharing: $this->createMock(ObjectSharingServiceInterface::class)),
			logger: $this->createMock(LoggerInterface::class)
		);
	}

	private function folder(string $name, int $id = self::FOLDER_ID): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getName')->willReturn($name);
		$folder->method('getId')->willReturn($id);

		return $folder;
	}

	/**
	 * The share's error after the listener ran, or null when it let the share through.
	 */
	private function errorFor(int $permissions, ?\OCP\Files\Node $node = null): ?string {
		$share = $this->createMock(IShare::class);
		$share->method('getNode')->willReturn($node ?? $this->folder(name: self::UUID));
		$share->method('getPermissions')->willReturn($permissions);

		$event = new BeforeShareCreatedEvent($share);
		$this->listener()->handle($event);

		return $event->getError();
	}

	public function testAReadShareOfAnOptedInObjectIsLetThrough(): void {
		$this->assertNull($this->errorFor(permissions: Constants::PERMISSION_READ));
	}

	public function testAReadAndUpdateShareIsLetThrough(): void {
		$this->assertNull($this->errorFor(permissions: Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE));
	}

	public function testAnOptedOutSchemaIsNotOffered(): void {
		$this->configuration = [];

		$this->assertSame('This record is not offered for sharing here', $this->errorFor(permissions: Constants::PERMISSION_READ));
	}

	public function testAnUnmappedBitIsRefusedNamingTheBit(): void {
		$this->assertSame(
			'Permission bit 8 (delete) maps to no object verb',
			$this->errorFor(permissions: Constants::PERMISSION_READ | Constants::PERMISSION_DELETE)
		);
	}

	public function testTheFilesDefaultFolderShareIsRefusedOnItsFirstUnmappedBit(): void {
		$this->assertSame('Permission bit 4 (create) maps to no object verb', $this->errorFor(permissions: Constants::PERMISSION_ALL));
	}

	public function testTheOptInIsCheckedBeforeTheBits(): void {
		$this->configuration = ['platformSharing' => false];

		$this->assertSame('This record is not offered for sharing here', $this->errorFor(permissions: Constants::PERMISSION_DELETE));
	}

	public function testAnUnloadableSchemaIsRefused(): void {
		$this->found = [$this->object(folder: (string)self::FOLDER_ID)];
		$this->found[0]->setSchema('99');

		$this->assertSame('This record is not offered for sharing here', $this->errorFor(permissions: Constants::PERMISSION_READ));
	}

	public function testOpenRegistersOwnShareWritesAreNotGated(): void {
		$this->path          = '/apps/openregister/api/objects/zaken/case/' . self::UUID . '/shares';
		$this->configuration = [];

		$this->assertNull($this->errorFor(permissions: Constants::PERMISSION_ALL));
	}

	public function testAFolderNotNamedByAUuidIsNotAnObjectFolder(): void {
		$this->objects->expects($this->never())->method('findStoredAcrossAllMagicTables');

		$this->assertNull($this->errorFor(permissions: Constants::PERMISSION_ALL, node: $this->folder(name: 'Projects')));
	}

	public function testAUuidNamedFolderTheObjectDoesNotRecordIsNotGated(): void {
		$this->assertNull($this->errorFor(permissions: Constants::PERMISSION_ALL, node: $this->folder(name: self::UUID, id: 99)));
	}

	public function testAUuidWithNoObjectIsNotGated(): void {
		$this->found = [];

		$this->assertNull($this->errorFor(permissions: Constants::PERMISSION_ALL));
	}

	public function testAFileIsNotAnObjectFolder(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn(self::UUID);

		$this->assertNull($this->errorFor(permissions: Constants::PERMISSION_ALL, node: $file));
	}

	public function testAnotherEventIsIgnored(): void {
		$this->objects->expects($this->never())->method('findStoredAcrossAllMagicTables');

		$this->listener()->handle(new Event());
		$this->addToAssertionCount(1);
	}
}
