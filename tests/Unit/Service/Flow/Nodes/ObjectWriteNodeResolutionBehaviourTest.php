<?php

/**
 * ObjectWriteNode: how the register and schema are resolved (and refused),
 * and which written object is recorded as the run's subject.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\SchemaNotInRegisterException;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowRunSubjectRecorder;
use OCA\OpenRegister\Service\Flow\FlowSubjectCandidates;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNode;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWritePlatform;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteStores;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNode
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Register\RegisterSchemaIds
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Flow\FlowItemShape
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses \OCA\OpenRegister\Service\Flow\FlowSubjectCandidates
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteConfigValidator
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteItem
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteOptions
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\ObjectWritePayload
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\ObjectWritePlan
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\ObjectWritePlatform
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteStores
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteTemplate
 * @uses \OCA\OpenRegister\Exception\SchemaNotInRegisterException
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteMatchFilters
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteMatcher
 */
class ObjectWriteNodeResolutionBehaviourTest extends TestCase {

	private ObjectService&MockObject $objects;

	private RegisterMapper&MockObject $registers;

	private SchemaMapper&MockObject $schemas;

	private FlowRunSubjectRecorder&MockObject $recorder;

	private Register $register;

	private Schema $schema;

	private ObjectWriteNode $node;

	protected function setUp(): void {
		parent::setUp();
		$this->register = new Register();
		$this->register->setId(1);
		$this->register->setSlug('cases');
		$this->register->setSchemas([2]);
		$this->schema = new Schema();
		$this->schema->setId(2);
		$this->schema->setSlug('case');

		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('runAs')->willReturnCallback(static fn (IUser $user, callable $operation) => $operation());
		$this->registers = $this->createMock(RegisterMapper::class);
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->recorder = $this->createMock(FlowRunSubjectRecorder::class);

		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$alice->method('isEnabled')->willReturn(true);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => ($uid === 'alice' ? $alice : null));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(static fn (string $app, string $key, int $default = 0): int => $default);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		$this->node = new ObjectWriteNode(
			stores: new ObjectWriteStores(objects: $this->objects, registers: $this->registers, schemas: $this->schemas),
			platform: new ObjectWritePlatform(userManager: $users, appConfig: $appConfig),
			presentation: new FlowNodePresentation(l10n: $l10n, urls: $this->createMock(IURLGenerator::class)),
			subjects: $this->recorder
		);
	}//end setUp()

	/**
	 * @param array<string, mixed> $overrides
	 *
	 * @return array<string, mixed>
	 */
	private function config(array $overrides = []): array {
		return array_merge(
			['register' => 'cases', 'schema' => 'case', 'operation' => ObjectWriteNode::OP_CREATE, 'fields' => ['title' => '{{ name }}']],
			$overrides
		);
	}//end config()

	/**
	 * @param array<int, array<string, mixed>> $records
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function items(array $records): array {
		return array_map(static fn (array $record): array => FlowItems::item(json: $record), $records);
	}//end items()

	private function resolvable(): void {
		$this->registers->method('find')->willReturn($this->register);
		$this->schemas->method('findBySlugInIds')->willReturn($this->schema);
	}//end resolvable()

	/**
	 * Every save returns an object with the given uuids in turn.
	 *
	 * @param array<int, string> $uuids
	 */
	private function savesAs(array $uuids): void {
		$this->objects->method('saveObject')->willReturnCallback(
			static function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true) use (&$uuids): ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setUuid((string)array_shift($uuids));
				$entity->setObject((array)$object);

				return $entity;
			}
		);
	}//end savesAs()

	public function testAnUnresolvableRegisterIsNamedAndChainsTheCause(): void {
		$cause = new DoesNotExistException('no row');
		$this->registers->method('find')->with('cases', false, false)->willThrowException($cause);
		$this->objects->expects(self::never())->method('saveObject');

		try {
			$this->node->execute($this->items([['name' => 'a']]), $this->config(), ['runAs' => 'alice']);
			self::fail('An unknown register must refuse the step.');
		} catch (UnexpectedValueException $e) {
			self::assertSame('The register "cases" could not be resolved.', $e->getMessage());
			self::assertSame($cause, $e->getPrevious());
		}
	}//end testAnUnresolvableRegisterIsNamedAndChainsTheCause()

	public function testANumericSchemaIsLookedUpByIdAndAMissOneIsNamed(): void {
		$this->registers->method('find')->willReturn($this->register);
		$this->schemas->expects(self::never())->method('findBySlugInIds');
		$this->schemas->expects(self::once())->method('find')->with('7', self::anything(), false, false)->willThrowException(new DoesNotExistException('no row'));

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('The schema "7" could not be resolved.');

		$this->node->execute($this->items([['name' => 'a']]), $this->config(['schema' => 7]), ['runAs' => 'alice']);
	}//end testANumericSchemaIsLookedUpByIdAndAMissOneIsNamed()

	public function testANumericSchemaThatResolvesIsWrittenUnder(): void {
		$this->registers->method('find')->willReturn($this->register);
		$this->schemas->method('find')->with('2', self::anything(), false, false)->willReturn($this->schema);
		$this->objects->expects(self::once())->method('saveObject')
			->with(['title' => 'a'], self::anything(), $this->register, $this->schema)
			->willReturnCallback(static function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setUuid('u-1');
				$entity->setObject(['title' => 'a']);

				return $entity;
			});

		$out = $this->node->execute($this->items([['name' => 'a']]), $this->config(['schema' => '2']), ['runAs' => 'alice']);

		self::assertSame('u-1', $out[0][FlowItems::JSON]['uuid']);
	}//end testANumericSchemaThatResolvesIsWrittenUnder()

	public function testASchemaSlugTheRegisterDoesNotCarryIsRefusedNotResolvedGlobally(): void {
		$this->registers->method('find')->willReturn($this->register);
		$this->schemas->expects(self::once())->method('findBySlugInIds')->with('foreign', [2])->willReturn(null);
		$this->schemas->expects(self::once())->method('countBySlug')->with('foreign')->willReturn(3);
		$this->schemas->expects(self::never())->method('find');
		$this->objects->expects(self::never())->method('saveObject');

		$this->expectException(SchemaNotInRegisterException::class);

		$this->node->execute($this->items([['name' => 'a']]), $this->config(['schema' => 'foreign']), ['runAs' => 'alice']);
	}//end testASchemaSlugTheRegisterDoesNotCarryIsRefusedNotResolvedGlobally()

	public function testTheWrittenObjectIsOfferedAsTheSubjectOnce(): void {
		$this->resolvable();
		$this->savesAs(['u-1', 'u-1', 'u-2']);
		$context = ['runAs' => 'alice', 'runUuid' => 'run-1'];
		$this->recorder->expects(self::once())->method('recordOne')
			->with(
				$context,
				self::callback(static function (FlowSubjectCandidates $candidates): bool {
					return $candidates->role === 'zaak'
						&& $candidates->uuids === ['u-1', 'u-2']
						&& $candidates->register === '1'
						&& $candidates->schema === '2';
				})
			);

		$this->node->execute(
			$this->items([['name' => 'a'], ['name' => 'b'], ['name' => 'c']]),
			$this->config(['subjectRole' => ' zaak ']),
			$context
		);
	}//end testTheWrittenObjectIsOfferedAsTheSubjectOnce()

	public function testTheSubjectIsReadUnderTheOutputKey(): void {
		$this->resolvable();
		$this->savesAs(['u-9']);
		$this->recorder->expects(self::once())->method('recordOne')
			->with(self::anything(), self::callback(static fn (FlowSubjectCandidates $candidates): bool => $candidates->uuids === ['u-9']));

		$out = $this->node->execute(
			$this->items([['name' => 'a', 'uuid' => 'incoming']]),
			$this->config(['subjectRole' => 'zaak', 'output' => 'written']),
			['runAs' => 'alice']
		);

		self::assertSame('incoming', $out[0][FlowItems::JSON]['uuid'], 'the incoming record is kept beside the output key');
	}//end testTheSubjectIsReadUnderTheOutputKey()

	public function testNoSubjectIsRecordedWithoutARole(): void {
		$this->resolvable();
		$this->savesAs(['u-1']);
		$this->recorder->expects(self::never())->method('recordOne');

		$out = $this->node->execute($this->items([['name' => 'a']]), $this->config(['subjectRole' => '  ']), ['runAs' => 'alice']);

		self::assertSame('u-1', $out[0][FlowItems::JSON]['uuid']);
	}//end testNoSubjectIsRecordedWithoutARole()

	public function testADeletedObjectIsNeverRecordedAsTheSubject(): void {
		$this->resolvable();
		$entity = new ObjectEntity();
		$entity->setUuid('u-gone');
		$entity->setObject(['sourceId' => 'r1']);
		$this->objects->method('findAll')->willReturn([$entity]);
		$this->objects->expects(self::once())->method('deleteObject')->willReturn(true);
		$this->recorder->expects(self::never())->method('recordOne');

		$out = $this->node->execute(
			$this->items([['retiredId' => 'r1']]),
			[
				'register' => 'cases',
				'schema' => 'case',
				'operation' => 'delete',
				'confirmDelete' => true,
				'subjectRole' => 'zaak',
				'match' => [['property' => 'sourceId', 'value' => '{{retiredId}}']],
			],
			['runAs' => 'alice']
		);

		self::assertTrue($out[0][FlowItems::JSON]['deleted']);
	}//end testADeletedObjectIsNeverRecordedAsTheSubject()
}//end class
