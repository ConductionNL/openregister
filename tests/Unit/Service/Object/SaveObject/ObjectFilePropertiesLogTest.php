<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What storing file properties writes to the log.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectFileProperties;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectFileWrite;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\ObjectFileProperties
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectFileWrite
 */
final class ObjectFilePropertiesLogTest extends TestCase {

	private const PERSONAL = 'Jan Jansen, BSN 123456782';

	/**
	 * Every log record of one run, as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array}>
	 */
	private array $records = [];

	/**
	 * A saved object with a file property keeps its data out of the log.
	 *
	 * @return void
	 */
	public function testObjectDataStaysOutOfEveryLogRecord(): void {
		$this->storeOneFile();

		self::assertNotSame([], $this->records);
		self::assertStringNotContainsString(self::PERSONAL, (string)json_encode($this->records));
	}//end testObjectDataStaysOutOfEveryLogRecord()

	/**
	 * Storing a file is routine: nothing above debug level is written.
	 *
	 * @return void
	 */
	public function testARoutineStoreLogsAtDebugOnly(): void {
		$this->storeOneFile();

		self::assertSame(['debug'], array_values(array_unique(array_column($this->records, 0))));
	}//end testARoutineStoreLogsAtDebugOnly()

	/**
	 * The file ids still reach the saved object.
	 *
	 * @return void
	 */
	public function testTheFileIdsStillReachTheObject(): void {
		$saved = $this->storeOneFile();

		self::assertSame(42, $saved->getObject()['file']);
	}//end testTheFileIdsStillReachTheObject()

	/**
	 * Run storeForCreate over one file property, with a logger that keeps what it is given.
	 *
	 * @return ObjectEntity The saved object.
	 */
	private function storeOneFile(): ObjectEntity {
		$files = $this->createMock(FilePropertyHandler::class);
		$files->method('isFileProperty')->willReturnCallback(
			static fn ($value, $schema, $propertyName): bool => $propertyName === 'file'
		);
		$files->method('handleFileProperty')->willReturnCallback(
			static function ($objectEntity, array &$object, string $propertyName): void {
				$object[$propertyName] = 42;
			}
		);

		$objects = $this->createMock(MagicMapperInterface::class);
		$objects->expects(self::once())->method('update')->willReturnCallback(
			static fn (ObjectEntity $entity): ObjectEntity => $entity
		);

		$write = new ObjectFileWrite();
		$write->entity = new ObjectEntity();
		$write->entity->setUuid('8a3f1c52-6a1e-4d6e-9d55-2f1c3b4a5e60');
		$write->data = ['name' => self::PERSONAL, 'file' => 'data:text/plain;base64,aGVsbG8='];
		$write->register = new Register();
		$write->schema = new Schema();

		$properties = new ObjectFileProperties(files: $files, objects: $objects, logger: $this->recorder());

		return $properties->storeForCreate(write: $write);
	}//end storeOneFile()

	/**
	 * A logger writing into this test's records.
	 *
	 * @return AbstractLogger The logger.
	 */
	private function recorder(): AbstractLogger {
		$records = &$this->records;

		return new class($records) extends AbstractLogger {
			/**
			 * @param array $records Receives the records.
			 */
			public function __construct(private array &$records) {
			}

			/**
			 * @param mixed             $level   The level.
			 * @param string|Stringable $message The message.
			 * @param array             $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->records[] = [(string)$level, (string)$message, $context];
			}
		};
	}//end recorder()
}//end class
