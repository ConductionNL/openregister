<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\TaggingHandlerInterface;
use OCA\OpenRegister\Service\FileService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * FileService turns an object into the identifier its tag is built from.
 *
 * @covers \OCA\OpenRegister\Service\FileService
 */
class FileServiceObjectTagTest extends TestCase {

	/**
	 * The tag of the identifier the tagging handler receives.
	 *
	 * @param ObjectEntity|string $object   The object or identifier.
	 * @param string              $expected The identifier the handler must get.
	 *
	 * @return void
	 */
	private function assertTagIsBuiltFrom(ObjectEntity|string $object, string $expected): void {
		$handler = $this->createMock(TaggingHandlerInterface::class);
		$handler->expects($this->once())
			->method('generateObjectTag')
			->with($expected)
			->willReturn('object:' . $expected);

		$reflection = new ReflectionClass(FileService::class);
		$service    = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('taggingHandler')->setValue($service, $handler);

		$this->assertSame('object:' . $expected, $service->generateObjectTag($object));
	}//end assertTagIsBuiltFrom()

	public function testAnEntityWithAUuidUsesTheUuid(): void {
		$object = new ObjectEntity();
		$object->setUuid('3f2c-uuid');
		$object->setId(7);

		$this->assertTagIsBuiltFrom($object, '3f2c-uuid');
	}//end testAnEntityWithAUuidUsesTheUuid()

	public function testAnEntityWithoutAUuidUsesTheId(): void {
		$object = new ObjectEntity();
		$object->setId(7);

		$this->assertTagIsBuiltFrom($object, '7');
	}//end testAnEntityWithoutAUuidUsesTheId()

	public function testAStringIsPassedOn(): void {
		$this->assertTagIsBuiltFrom('3f2c-uuid', '3f2c-uuid');
	}//end testAStringIsPassedOn()
}//end class
