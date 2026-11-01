<?php

/**
 * MoveObject::move() validates, writes, removes and records exactly as recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\MoveObject;
use OCA\OpenRegister\Service\Object\ValidateObject;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Info\DataInfo;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden record of move() over every branch: the same-address refusal, the target validation and
 * its error sentences, the generated properties excused from it, the write and its in-memory
 * rollback, the hard removal of the old row without events, the stranded-row report, the audit
 * entry and the actor it names (the caller's actor, never an owner from the object), and a failed
 * audit write. Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\MoveObject
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\MoveAddress
 * @uses   \OCA\OpenRegister\Service\Object\ObjectMove
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
class MoveObjectMoveGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/move-object-move-golden.json';

	/** @var array<int, array<string, mixed>> */
	private array $calls = [];

	/** @var array<string, mixed> */
	private array $setup = [];

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$generated = ['number' => ['type' => 'string', MoveObject::GENERATED => ['sequence' => 'case']], 'plain' => ['type' => 'string'], 'odd' => 'not-an-array', 'nulled' => [MoveObject::GENERATED => null], 7 => [MoveObject::GENERATED => true]];
		$actual = [
			'moves'                           => $this->runScenario([]),
			'same address is refused'         => $this->runScenario(['to' => [1, 10]]),
			'same register, other schema'     => $this->runScenario(['to' => [1, 20]]),
			'other register, same schema'     => $this->runScenario(['to' => [2, 10]]),
			'generated properties excused'    => $this->runScenario(['targetProps' => $generated]),
			'no data on the object'           => $this->runScenario(['data' => null]),
			'validator throws'                => $this->runScenario(['validation' => 'throw']),
			'invalid, path and message'       => $this->runScenario(['validation' => [['path' => ['a', 'b'], 'message' => 'is required'], ['path' => [], 'message' => 'too long'], ['path' => ['c'], 'message' => ''], ['path' => null, 'message' => null]]]),
			'invalid, top error only'         => $this->runScenario(['validation' => ['top' => ['path' => ['x'], 'message' => 'must match']]]),
			'invalid, bare top error'         => $this->runScenario(['validation' => ['top' => ['path' => null, 'message' => null]]]),
			'invalid, no error at all'        => $this->runScenario(['validation' => 'invalid-null']),
			'write fails, rolled back'        => $this->runScenario(['writeFails' => true]),
			'removal fails, stranded'         => $this->runScenario(['deleteFails' => true]),
			'removal and audit fail'          => $this->runScenario(['deleteFails' => true, 'auditFails' => true]),
			'audit fails'                     => $this->runScenario(['auditFails' => true]),
			'empty actor'                     => $this->runScenario(['actor' => '']),
			'actor is the caller, not owner'  => $this->runScenario(['actor' => 'alice', 'owner' => 'mallory', 'data' => ['title' => 'x', 'owner' => 'mallory', '@self' => ['owner' => 'mallory'], 'actor' => 'mallory']]),
			'zero actor'                      => $this->runScenario(['actor' => '0']),
		];

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: json_decode((string) json_encode($actual, JSON_UNESCAPED_SLASHES), true));
	}//end testScenariosMatchTheGolden()

	/**
	 * Run one move() and record what happened.
	 *
	 * @param array<string, mixed> $setup The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function runScenario(array $setup): array {
		$this->calls = [];
		$this->setup = $setup + ['validation' => 'valid', 'actor' => 'alice', 'to' => [2, 20]];

		$object = new ObjectEntity();
		$object->setUuid('0b3f2c1a-1111-4222-8333-944455556666');
		$object->setRegister('1');
		$object->setSchema('10');
		$object->setOwner(($setup['owner'] ?? 'owner-1'));
		$object->setObject(array_key_exists('data', $setup) === true ? $setup['data'] : ['title' => 'x', 'number' => '2026-0042']);

		$service  = new MoveObject($this->objects(), $this->validator(), $this->audit(), $this->logger());
		$returned = $service->move(
			object: $object,
			sourceRegister: $this->register(id: 1),
			sourceSchema: $this->schema(id: 10, properties: []),
			targetRegister: $this->register(id: $this->setup['to'][0]),
			targetSchema: $this->schema(id: $this->setup['to'][1], properties: ($setup['targetProps'] ?? [])),
			actor: $this->setup['actor']
		);

		return ['returned' => $returned, 'after' => ['register' => $object->getRegister(), 'schema' => $object->getSchema(), 'uuid' => $object->getUuid()], 'calls' => $this->calls];
	}//end runScenario()

	/**
	 * A register entity.
	 *
	 * @param int|string $id The id.
	 *
	 * @return Register
	 */
	private function register(int|string $id): Register {
		$register = new Register();
		$register->setId((int) $id);
		return $register;
	}//end register()

	/**
	 * A schema entity.
	 *
	 * @param int|string           $id         The id.
	 * @param array<string, mixed> $properties The properties.
	 *
	 * @return Schema
	 */
	private function schema(int|string $id, array $properties): Schema {
		$schema = new Schema();
		$schema->setId((int) $id);
		$schema->setProperties($properties);
		return $schema;
	}//end schema()

	/**
	 * The object store double.
	 *
	 * @return MagicMapper
	 */
	private function objects(): MagicMapper {
		$objects = $this->createMock(MagicMapper::class);
		$objects->method('updateObjectEntity')->willReturnCallback(
			function (ObjectEntity $entity, Register $register, Schema $schema, ?ObjectEntity $oldEntity = null): ObjectEntity {
				$this->calls[] = ['update' => $entity->getUuid(), 'entity' => [$entity->getRegister(), $entity->getSchema()], 'register' => $register->getId(), 'schema' => $schema->getId(), 'old' => $oldEntity?->getUuid()];
				if (($this->setup['writeFails'] ?? false) === true) {
					throw new RuntimeException('duplicate key');
				}

				return $entity;
			}
		);
		$objects->method('deleteObjectEntity')->willReturnCallback(
			function (ObjectEntity $entity, Register $register, Schema $schema, bool $hardDelete = false, bool $dispatchEvents = true): ObjectEntity {
				$this->calls[] = ['delete' => $entity->getUuid(), 'register' => $register->getId(), 'schema' => $schema->getId(), 'hard' => $hardDelete, 'events' => $dispatchEvents];
				if (($this->setup['deleteFails'] ?? false) === true) {
					throw new RuntimeException('row locked');
				}

				return $entity;
			}
		);
		return $objects;
	}//end objects()

	/**
	 * The validator double.
	 *
	 * @return ValidateObject
	 */
	private function validator(): ValidateObject {
		$validator = $this->createMock(ValidateObject::class);
		$validator->method('validateObject')->willReturnCallback(
			function (array $object, $schema = null, $schemaObject = null, int $_depth = 0, array $notSupplied = []): ValidationResult {
				$this->calls[] = ['validate' => $object, 'schema' => $schema?->getId(), 'notSupplied' => $notSupplied];
				$spec = $this->setup['validation'];
				if ($spec === 'throw') {
					throw new RuntimeException('schema has a broken $ref');
				}

				$result = $this->createMock(ValidationResult::class);
				$result->method('isValid')->willReturn($spec === 'valid');
				if ($spec === 'valid' || $spec === 'invalid-null') {
					$result->method('error')->willReturn(null);
					return $result;
				}

				$top = $this->error(spec: ($spec['top'] ?? ['path' => null, 'message' => 'summary']));
				$subs = [];
				foreach ($spec as $key => $sub) {
					if ($key !== 'top') {
						$subs[] = $this->error(spec: $sub);
					}
				}

				$top->method('subErrors')->willReturn($subs);
				$result->method('error')->willReturn($top);
				return $result;
			}
		);
		return $validator;
	}//end validator()

	/**
	 * A validation error double.
	 *
	 * @param array<string, mixed> $spec Its path and message.
	 *
	 * @return ValidationError&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function error(array $spec): ValidationError {
		$error = $this->createMock(ValidationError::class);
		$error->method('message')->willReturn((string) ($spec['message'] ?? ''));
		$info = $this->createMock(DataInfo::class);
		$info->method('path')->willReturn(($spec['path'] ?? []));
		$error->method('data')->willReturn($info);
		return $error;
	}//end error()

	/**
	 * The audit trail double.
	 *
	 * @return AuditTrailMapper
	 */
	private function audit(): AuditTrailMapper {
		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('createAuditTrailEntry')->willReturnCallback(
			function (ObjectEntity $object, string $action, array $context = [], ?string $actorId = null, ?string $actorName = null, ?string $ipAddress = null): AuditTrail {
				$this->calls[] = ['audit' => $action, 'object' => [$object->getUuid(), $object->getRegister(), $object->getSchema()], 'context' => $context, 'actorId' => $actorId, 'actorName' => $actorName, 'ip' => $ipAddress];
				if (($this->setup['auditFails'] ?? false) === true) {
					throw new RuntimeException('audit table full');
				}

				return new AuditTrail();
			}
		);
		return $audit;
	}//end audit()

	/**
	 * A logger that records every line without file and line.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					$this->calls[] = ['log' => $level, 'message' => (string) $message, 'context' => $context];
				}
			);
		}

		return $logger;
	}//end logger()
}//end class
