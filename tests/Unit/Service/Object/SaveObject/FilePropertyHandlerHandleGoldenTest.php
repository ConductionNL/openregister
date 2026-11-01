<?php

/**
 * FilePropertyHandler::handleFileProperty() deletes, stores and rewrites file properties exactly as recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden record of handleFileProperty() over every branch: the object data after the call,
 * the files deleted, the files stored (with the file config they were stored with), the log
 * lines and any exception. Storing one file is stubbed; it is processSingleFileProperty()'s
 * own concern. Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\FilePropertyTarget
 */
class FilePropertyHandlerHandleGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/file-property-handler-handle-golden.json';

	private const FILE = 'data:text/plain;base64,aGVsbG8=';

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$single = ['doc' => ['type' => 'file']];
		$array  = ['docs' => ['type' => 'array', 'items' => ['type' => 'file', 'allowedTypes' => ['text/plain']]]];

		$actual = [
			'not in schema'           => $this->scenario(properties: [], stored: [], value: self::FILE, property: 'doc'),
			'not a file'              => $this->scenario(properties: ['doc' => ['type' => 'string']], stored: [], value: self::FILE, property: 'doc'),
			'array of non-file'       => $this->scenario(properties: ['docs' => ['type' => 'array', 'items' => ['type' => 'string']]], stored: [], value: [self::FILE], property: 'docs'),
			'delete single'           => $this->scenario(properties: $single, stored: ['doc' => 42], value: null, property: 'doc'),
			'delete single numeric s' => $this->scenario(properties: $single, stored: ['doc' => '43'], value: null, property: 'doc'),
			'delete single fails'     => $this->scenario(properties: $single, stored: ['doc' => 13], value: null, property: 'doc'),
			'delete single non-num'   => $this->scenario(properties: $single, stored: ['doc' => 'abc'], value: null, property: 'doc'),
			'delete single empty'     => $this->scenario(properties: $single, stored: ['doc' => ''], value: null, property: 'doc'),
			'delete nothing stored'   => $this->scenario(properties: $single, stored: [], value: null, property: 'doc'),
			'delete array'            => $this->scenario(properties: $array, stored: ['docs' => [10, 'x', 13, '20']], value: [], property: 'docs'),
			'delete array via null'   => $this->scenario(properties: $array, stored: ['docs' => [11]], value: null, property: 'docs'),
			'array non-array value'   => $this->scenario(properties: $array, stored: [], value: self::FILE, property: 'docs'),
			'array upload'            => $this->scenario(properties: $array, stored: [], value: [self::FILE, 'plain text', self::FILE], property: 'docs'),
			'single upload'           => $this->scenario(properties: $single, stored: [], value: self::FILE, property: 'doc'),
			'single not a file value' => $this->scenario(properties: $single, stored: [], value: 'plain text', property: 'doc'),
			'schema autoShare'        => $this->scenario(properties: $single, stored: [], value: self::FILE, property: 'doc', configuration: ['autoShare' => true]),
			'schema autoPublish'      => $this->scenario(properties: $single, stored: [], value: self::FILE, property: 'doc', configuration: ['autoPublish' => true]),
			'property autoShare wins' => $this->scenario(properties: ['doc' => ['type' => 'file', 'autoShare' => false]], stored: [], value: self::FILE, property: 'doc', configuration: ['autoShare' => true]),
		];

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: json_decode((string) json_encode($actual, JSON_UNESCAPED_SLASHES), true));
	}//end testScenariosMatchTheGolden()

	/**
	 * Run handleFileProperty() once and record what it did.
	 *
	 * @param array<string, mixed>      $properties    The schema properties.
	 * @param array<string, mixed>      $stored        The object data as stored before the save.
	 * @param mixed                     $value         The incoming property value.
	 * @param string                    $property      The property name.
	 * @param array<string, mixed>|null $configuration The schema configuration.
	 *
	 * @return array<string, mixed>
	 */
	private function scenario(array $properties, array $stored, mixed $value, string $property, ?array $configuration = null): array {
		$calls  = [];
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use (&$calls, $level): void {
					unset($context['file'], $context['line']);
					$calls[] = ['log' => $level, 'message' => $message, 'context' => $context];
				}
			);
		}

		$fileService = $this->createMock(FileService::class);
		$fileService->method('deleteFile')->willReturnCallback(
			function (mixed $file, ?ObjectEntity $object = null) use (&$calls): bool {
				$calls[] = ['deleteFile' => $file, 'object' => $object?->getUuid()];
				if ($file === 13) {
					throw new Exception('already gone');
				}

				return true;
			}
		);

		$handler = $this->getMockBuilder(FilePropertyHandler::class)
			->setConstructorArgs([$logger, $fileService])
			->onlyMethods(['processSingleFileProperty'])
			->getMock();
		$nextId = 100;
		$handler->method('processSingleFileProperty')->willReturnCallback(
			function (ObjectEntity $objectEntity, mixed $fileInput, string $propertyName, array $fileConfig, ?int $index = null) use (&$calls, &$nextId): int {
				$nextId++;
				$calls[] = ['store' => $fileInput, 'object' => $objectEntity->getUuid(), 'property' => $propertyName, 'fileConfig' => $fileConfig, 'index' => $index];
				return $nextId;
			}
		);

		$entity = new ObjectEntity();
		$entity->setUuid('obj-1');
		$entity->setObject($stored);

		$schema = new Schema();
		$schema->setId(7);
		$schema->setProperties($properties);
		$schema->setConfiguration($configuration);

		$object    = ['other' => 'kept', $property => $value];
		$exception = null;
		try {
			$handler->handleFileProperty($entity, $object, $property, $schema);
		} catch (Exception $e) {
			$exception = $e->getMessage();
		}

		return ['object' => $object, 'calls' => $calls, 'exception' => $exception];
	}//end scenario()
}//end class
