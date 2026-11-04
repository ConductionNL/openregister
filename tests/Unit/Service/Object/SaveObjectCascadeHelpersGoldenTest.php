<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden record of which ids the cascade keeps, which orphans it deletes and which targets it writes back to.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * Recorded on the code before the identifier test and the orphan scope were extracted.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture, then run again without it.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\ObjectHandling
 */
class SaveObjectCascadeHelpersGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/save-object-cascade-helpers-golden.json';

	private const IDS = [
		'22222222-2222-2222-2222-222222222222',
		'22222222222222222222222222222222',
		'abcdefabcdefabcdefabcdefabcdefab',
		'id-22222222-2222-2222-2222-222222222223',
		'id-22222222222222222222222222222224',
		'42',
		'not-an-id',
		'2222222-2222-2222-2222-222222222222',
		'',
		' ',
		'4.2',
	];

	/**
	 * What reached the mapper and the nested save.
	 *
	 * @var list<array>
	 */
	private array $trace = [];

	/**
	 * A save handler over a `note` schema whose collaborators record what they are asked.
	 *
	 * @return SaveObject
	 */
	private function handler(): SaveObject {
		$handler = $this->getMockBuilder(SaveObject::class)
			->disableOriginalConstructor()
			->onlyMethods(['saveObject'])
			->getMock();
		$handler->method('saveObject')->willReturnCallback(
			function ($register, $schema, array $data, ?string $uuid = null): ObjectEntity {
				$this->trace[] = ['save', $register, $schema, $data, $uuid];
				$saved = new ObjectEntity();
				$saved->setUuid('33333333-3333-3333-3333-333333333333');
				return $saved;
			}
		);

		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('find')->willReturnCallback(
			function (string|int $identifier, ?Register $register = null, ?Schema $schema = null): ObjectEntity {
				$this->trace[] = ['find', $identifier, $register?->getId(), $schema?->getId()];
				$found = new ObjectEntity();
				$found->setUuid((string)$identifier);
				$found->setObject(['incidents' => 'p-0']);
				return $found;
			}
		);
		$mapper->method('update')->willReturnCallback(
			function (ObjectEntity $entity): ObjectEntity {
				$this->trace[] = ['update', $entity->getUuid(), $entity->getDeleted()['reason'] ?? null];
				return $entity;
			}
		);

		$note = new Schema();
		$note->setId(2);
		$note->setSlug('note');
		$register = new Register();
		$register->setId(10);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($register);

		$reflection = new ReflectionClass(SaveObject::class);
		$reflection->getProperty('schemaReferenceCache')->setValue($handler, ['note' => '2']);
		$reflection->getProperty('schemaCache')->setValue($handler, ['2' => $note]);
		$reflection->getProperty('urlGenerator')->setValue($handler, $this->createMock(IURLGenerator::class));
		$reflection->getProperty('objectEntityMapper')->setValue($handler, $mapper);
		$reflection->getProperty('registerMapper')->setValue($handler, $registers);
		$reflection->getProperty('userSession')->setValue($handler, $this->createMock(IUserSession::class));
		$reflection->getProperty('logger')->setValue($handler, new NullLogger());
		if ($reflection->hasProperty('permissionHandler') === true) {
			$reflection->getProperty('permissionHandler')->setValue($handler, $this->createMock(PermissionHandler::class));
		}

		return $handler;
	}//end handler()

	/**
	 * Run one private method on parent `p-1` in register 10, and record its answer and trace.
	 *
	 * @param string $method     The method name.
	 * @param array  $properties The parent schema's properties.
	 * @param array  $data       The incoming data.
	 * @param array  $stored     The parent's stored data.
	 *
	 * @return array The answer and the trace.
	 */
	private function runOnParent(string $method, array $properties, array $data, array $stored = []): array {
		$this->trace = [];
		$handler = $this->handler();
		$parent = new ObjectEntity();
		$parent->setUuid('p-1');
		$parent->setRegister('10');
		$parent->setObject($stored);
		$schema = new Schema();
		$schema->setId(1);
		$schema->setProperties($properties);

		$answer = (new ReflectionClass(SaveObject::class))->getMethod($method)->invoke($handler, $parent, $schema, $data);

		return ['answer' => $answer, 'trace' => $this->trace];
	}//end run()

	/**
	 * The recorded cases.
	 *
	 * @return array<string, array>
	 */
	private function cases(): array {
		$related = ['notes' => ['type' => 'array', 'items' => ['$ref' => 'note', 'objectConfiguration' => ['handling' => 'related-object']]]];
		$cascade = ['notes' => ['type' => 'array', 'items' => ['$ref' => 'note', 'objectConfiguration' => ['handling' => 'cascade']]]];
		$writeBack = ['tags' => ['type' => 'array', 'items' => ['$ref' => 'note', 'inversedBy' => 'incidents', 'writeBack' => true]]];
		$single = ['note' => ['type' => 'object', '$ref' => 'note', 'inversedBy' => 'incidents', 'writeBack' => true]];
		$old = ['notes' => ['old-1', self::IDS[0], 'old-2', 7]];
		$mixed = array_merge(self::IDS, [['text' => 'new'], ['id' => ''], [], 7]);

		return [
			'related ids kept and orphans deleted' => $this->runOnParent(method: 'cascadeObjects', properties: $related, data: ['notes' => $mixed], stored: $old),
			'related list emptied deletes every orphan' => $this->runOnParent(method: 'cascadeObjects', properties: $related, data: ['notes' => []], stored: $old),
			'related with no stored list' => $this->runOnParent(method: 'cascadeObjects', properties: $related, data: ['notes' => self::IDS]),
			'cascade list emptied deletes every orphan' => $this->runOnParent(method: 'cascadeObjects', properties: $cascade, data: ['notes' => []], stored: $old),
			'cascade ids only' => $this->runOnParent(method: 'cascadeObjects', properties: $cascade, data: ['notes' => self::IDS], stored: $old),
			'cascade objects created' => $this->runOnParent(method: 'cascadeObjects', properties: $cascade, data: ['notes' => $mixed], stored: $old),
			'write-back to valid ids' => $this->runOnParent(method: 'handleInverseRelationsWriteBack', properties: $writeBack, data: ['tags' => $mixed]),
			'write-back single id' => $this->runOnParent(method: 'handleInverseRelationsWriteBack', properties: $single, data: ['note' => '42']),
			'write-back single non-id' => $this->runOnParent(method: 'handleInverseRelationsWriteBack', properties: $single, data: ['note' => 'not-an-id']),
		];
	}//end cases()

	public function testACallFrameRecordsTheRegisterIdAsAString(): void {
		$frameRegister = (new ReflectionClass(SaveObject::class))->getMethod('frameRegister');
		$register = new Register();
		$register->setId(10);

		$this->assertSame(expected: '10', actual: $frameRegister->invoke(null, $register));
		$this->assertNull($frameRegister->invoke(null, new Register()));
		$this->assertNull($frameRegister->invoke(null, null));
	}

	public function testTheCascadeMatchesTheGolden(): void {
		$actual = json_decode(json_encode($this->cases()), true);

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($golden as $name => $expected) {
			$this->assertSame(expected: $expected, actual: $actual[$name] ?? null, message: $name);
		}

		$this->assertSame(expected: array_keys($golden), actual: array_keys($actual));
	}
}//end class
