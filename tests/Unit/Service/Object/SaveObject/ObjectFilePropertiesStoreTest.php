<?php

/**
 * ObjectFileProperties: file properties are stored, written back as ids, and a failed create is rolled back.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectFileProperties;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectFileWrite;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectUpdateRun;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The create path, the update path and the image reset.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\ObjectFileProperties
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectFileWrite
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectUpdateRun
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValueRules
 */
class ObjectFilePropertiesStoreTest extends TestCase {

	/**
	 * The file property handler.
	 *
	 * @var FilePropertyHandler&MockObject
	 */
	private FilePropertyHandler $files;

	/**
	 * The object mapper.
	 *
	 * @var MagicMapperInterface&MockObject
	 */
	private MagicMapperInterface $objects;

	/**
	 * The schema: "photo" is a file property and the image field.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * Build the boundaries; the handler stores "photo" as file id 42.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->files = $this->createMock(FilePropertyHandler::class);
		$this->files->method('isFileInSchema')->willReturnCallback(fn (?Schema $schema, ?string $name): bool => $name === 'photo');
		$this->files->method('handleFileProperty')->willReturnCallback(
			function (ObjectEntity $entity, array &$object, string $name): void {
				$object[$name] = 42;
			}
		);
		$this->objects = $this->createMock(MagicMapperInterface::class);
		$this->schema = new Schema();
		$this->schema->setConfiguration(['objectImageField' => 'photo']);
		$this->schema->setProperties(['photo' => ['type' => 'file'], 'name' => ['type' => 'string']]);
	}//end setUp()

	/**
	 * The handler under test.
	 *
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return ObjectFileProperties
	 */
	private function properties(?LoggerInterface $logger = null): ObjectFileProperties {
		return new ObjectFileProperties($this->files, $this->objects, $logger ?? $this->createMock(LoggerInterface::class));
	}//end properties()

	/**
	 * A write for a new entity.
	 *
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectFileWrite
	 */
	private function write(array $data): ObjectFileWrite {
		$entity = new ObjectEntity();
		$entity->setUuid('o-1');
		$entity->setImage('old.png');
		$write = new ObjectFileWrite();
		$write->entity = $entity;
		$write->data = $data;
		$write->schema = $this->schema;

		return $write;
	}//end write()

	/**
	 * On create a stored file property is written back as its id, the derived image is cleared, and the updated entity returned.
	 *
	 * @return void
	 */
	public function testCreateWritesTheFileIdsBack(): void {
		$saved = new ObjectEntity();
		$write = $this->write(data: ['photo' => 'data:image/png;base64,AA', 'name' => 'Ann']);
		$entity = $write->entity;
		$this->objects->expects($this->once())->method('update')->with($this->identicalTo($entity), null, $this->identicalTo($this->schema))->willReturn($saved);

		$this->assertSame($saved, $this->properties()->storeForCreate($write));
		$this->assertSame(['photo' => 42, 'name' => 'Ann'], array_intersect_key($entity->getObject(), ['photo' => 1, 'name' => 1]));
		$this->assertNull($entity->getImage());
		$this->assertSame($saved, $write->entity, 'the write carries the saved entity onward');
	}//end testCreateWritesTheFileIdsBack()

	/**
	 * Without file properties nothing is written and the entity comes back as it was.
	 *
	 * @return void
	 */
	public function testCreateWithoutFilesWritesNothing(): void {
		$write = $this->write(data: ['name' => 'Ann']);
		$this->objects->expects($this->never())->method('update');

		$this->assertSame($write->entity, $this->properties()->storeForCreate($write));
		$this->assertSame('old.png', $write->entity->getImage());
	}//end testCreateWithoutFilesWritesNothing()

	/**
	 * A file that cannot be stored rolls the new object back and rethrows.
	 *
	 * @return void
	 */
	public function testAFailedCreateIsRolledBack(): void {
		$files = $this->createMock(FilePropertyHandler::class);
		$files->method('isFileInSchema')->willReturn(true);
		$files->method('handleFileProperty')->willThrowException(new RuntimeException('quota'));
		$this->files = $files;
		$write = $this->write(data: ['photo' => 'x']);
		$this->objects->expects($this->once())->method('delete')->with($this->identicalTo($write->entity));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[SaveObject] File processing failed, rolling back object creation',
			['uuid' => 'o-1', 'error' => 'quota']
		);

		$this->expectExceptionMessage('quota');
		$this->properties(logger: $logger)->storeForCreate($write);
	}//end testAFailedCreateIsRolledBack()

	/**
	 * On update only the stored file properties replace values on the object; without any the object is returned unsaved.
	 *
	 * @return void
	 */
	public function testUpdateReplacesOnlyTheStoredProperties(): void {
		$updated = new ObjectEntity();
		$updated->setObject(['name' => 'Kept', 'photo' => 7]);
		$updated->setImage('old.png');
		$run = new ObjectUpdateRun();
		$run->schema = $this->schema;
		$run->data = ['photo' => 'new upload', 'name' => 'Not applied here'];
		$this->objects->expects($this->once())->method('update')->with($this->identicalTo($updated))->willReturnArgument(0);

		$result = $this->properties()->storeForUpdate($run, $updated);

		$this->assertSame(['name' => 'Kept', 'photo' => 42], array_intersect_key($result->getObject(), ['name' => 1, 'photo' => 1]));
		$this->assertSame(42, $run->data['photo'], 'the run carries the stored id onward');
		$this->assertNull($result->getImage());

		$plain = new ObjectUpdateRun();
		$plain->schema = $this->schema;
		$plain->data = ['name' => 'x'];
		$untouched = new ObjectEntity();
		$this->assertSame($untouched, $this->properties()->storeForUpdate($plain, $untouched));
	}//end testUpdateReplacesOnlyTheStoredProperties()

	/**
	 * The image is cleared only when the image field is a file property.
	 *
	 * @return void
	 */
	public function testTheImageIsClearedOnlyForAFileImageField(): void {
		$object = new ObjectEntity();
		$object->setImage('keep.png');
		$schema = new Schema();
		$schema->setConfiguration(['objectImageField' => 'name']);
		$schema->setProperties(['name' => ['type' => 'string']]);
		$this->properties()->clearImage($object, $schema);
		$this->assertSame('keep.png', $object->getImage());

		$schema->setConfiguration([]);
		$this->properties()->clearImage($object, $schema);
		$this->assertSame('keep.png', $object->getImage());

		$this->properties()->clearImage($object, $this->schema);
		$this->assertNull($object->getImage());
	}//end testTheImageIsClearedOnlyForAFileImageField()
}//end class
