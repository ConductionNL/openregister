<?php

/**
 * Golden tests for ValidationHandler::validateObjectsBySchema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationHandler;
use OCA\OpenRegister\Db\SchemaCatalog;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the silent save call per object and how each outcome is sorted into valid and invalid.
 *
 * @covers \OCA\OpenRegister\Service\Object\ValidationHandler
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Exception\CustomValidationException
 * @uses   \OCA\OpenRegister\Exception\ValidationException
 * @uses   \OCA\OpenRegister\Db\SchemaCatalog
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 * @uses   \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ListQuery
 */
class ValidationHandlerBySchemaGoldenTest extends TestCase {

	public function testEachObjectIsSavedSilentlyAndSortedByOutcome(): void {
		$objects = [];
		foreach (['ok', 'bad', 'custom', 'boom', 'ok2'] as $i => $name) {
			$object = new ObjectEntity();
			$object->setId($i + 1);
			$object->setUuid('u-' . $name);
			$object->setName('Name ' . $name);
			$object->setRegister('3');
			$object->setObject(['title' => $name]);
			$objects[] = $object;
		}

		$mapper = $this->createMock(MagicMapper::class);
		$mapper->expects($this->once())->method('findBySchema')->with(9)->willReturn($objects);
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []) use (&$logged): void {
				$logged[] = [$message, $context['objectId'] ?? null, $context['error'] ?? null, $context['app'] ?? null];
			}
		);
		$handler = new ValidationHandler(
			validateHandler: $this->createMock(ValidateObject::class),
			catalog: new SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $mapper),
			logger: $logger
		);

		$calls = [];
		$result = $handler->validateObjectsBySchema(
			9,
			function (...$args) use (&$calls): void {
				$options = $args[1];
				$calls[] = [
					$args[0],
					$options->target->register,
					$options->target->schema,
					$options->uuid,
					$options->policy->silent,
					$args[2],
					$args[3],
				];
				match ($args[0]['title']) {
					'bad' => throw new ValidationException('title too short'),
					'custom' => throw new CustomValidationException('custom rule', ['x' => 'y']),
					'boom' => throw new \RuntimeException('db down'),
					default => null,
				};
			}
		);

		$this->assertSame(
			[
				[['id' => 'u-ok', 'title' => 'ok'], '3', 9, 'u-ok', true, false, false],
				[['id' => 'u-bad', 'title' => 'bad'], '3', 9, 'u-bad', true, false, false],
				[['id' => 'u-custom', 'title' => 'custom'], '3', 9, 'u-custom', true, false, false],
				[['id' => 'u-boom', 'title' => 'boom'], '3', 9, 'u-boom', true, false, false],
				[['id' => 'u-ok2', 'title' => 'ok2'], '3', 9, 'u-ok2', true, false, false],
			],
			$calls
		);
		$this->assertSame(
			[
				'valid' => [
					['id' => 1, 'uuid' => 'u-ok', 'name' => 'Name ok', 'data' => ['id' => 'u-ok', 'title' => 'ok']],
					['id' => 5, 'uuid' => 'u-ok2', 'name' => 'Name ok2', 'data' => ['id' => 'u-ok2', 'title' => 'ok2']],
				],
				'invalid' => [
					['id' => 2, 'uuid' => 'u-bad', 'name' => 'Name bad', 'data' => ['id' => 'u-bad', 'title' => 'bad'], 'error' => 'title too short'],
					['id' => 3, 'uuid' => 'u-custom', 'name' => 'Name custom', 'data' => ['id' => 'u-custom', 'title' => 'custom'], 'error' => 'custom rule'],
					['id' => 4, 'uuid' => 'u-boom', 'name' => 'Name boom', 'data' => ['id' => 'u-boom', 'title' => 'boom'], 'error' => 'Unexpected error: db down'],
				],
			],
			$result
		);
		$this->assertSame([['[ValidationHandler] Unexpected error during validation', 4, 'db down', 'openregister']], $logged);
	}//end testEachObjectIsSavedSilentlyAndSortedByOutcome()

	/**
	 * The callback is ObjectService::saveObject itself, so the call must fit that signature.
	 */
	public function testTheCallFitsTheSaveObjectSignature(): void {
		$object = new ObjectEntity();
		$object->setId(1);
		$object->setUuid('u-1');
		$object->setRegister('3');
		$object->setObject(['title' => 'one']);

		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('findBySchema')->willReturn([$object]);
		$handler = new ValidationHandler(
			validateHandler: $this->createMock(ValidateObject::class),
			catalog: new SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $mapper),
			logger: $this->createMock(LoggerInterface::class)
		);

		$received = [];
		$save = function (
			array|ObjectEntity $object,
			ObjectSaveOptions $options=new ObjectSaveOptions(),
			bool $_rbac=true,
			bool $_multitenancy=true
		) use (&$received): ObjectEntity {
			$received = [$options->uuid, $options->policy->silent, $_rbac, $_multitenancy];
			return new ObjectEntity();
		};

		$saveObject = new \ReflectionMethod(ObjectService::class, 'saveObject');
		$this->assertSame(
			array_map(static fn (\ReflectionParameter $p): string => $p->getName() . ':' . $p->getType(), $saveObject->getParameters()),
			array_map(static fn (\ReflectionParameter $p): string => $p->getName() . ':' . $p->getType(), (new \ReflectionFunction($save))->getParameters())
		);

		$result = $handler->validateObjectsBySchema(9, $save);

		$this->assertSame([], $result['invalid']);
		$this->assertCount(1, $result['valid']);
		$this->assertSame(['u-1', true, false, false], $received);
	}//end testTheCallFitsTheSaveObjectSignature()
}//end class
