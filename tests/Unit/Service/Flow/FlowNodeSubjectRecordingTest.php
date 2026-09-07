<?php

/**
 * The two nodes that can declare what a run is working on.
 *
 * 🔴 DECLARED, NEVER DERIVED. A node records into the subject set only when its
 * configuration names a role. A set that filled itself from what nodes wrote
 * would be the audit-derived object list again, which already exists and
 * answers a different question — and every entry in it would then be
 * addressable by `attachTo`, inventing declarations no author made.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-run-subjects-and-answers/specs/flow-run-subjects/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunSubjectRecorder;
use OCA\OpenRegister\Service\Flow\Nodes\LockObjectNode;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNode;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Wiring tests for the two recording nodes.
 *
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\LockObjectNode
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses \OCA\OpenRegister\Service\Flow\FlowNodeResumeState
 * @uses \OCA\OpenRegister\Service\Flow\FlowResumeState
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 */
final class FlowNodeSubjectRecordingTest extends TestCase {

	/**
	 * Every recordOne() call the node made, as named arguments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $recorded = [];

	/**
	 * Object operations, running every runAs callable for real.
	 *
	 * @var ObjectService
	 */
	private ObjectService $objects;

	/**
	 * Wire the doubles the two nodes share.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objects = $this->createMock(ObjectService::class);
		// runAs MUST actually invoke the callable, or every write silently
		// returns null and the node appears to work while doing nothing.
		$this->objects->method('runAs')->willReturnCallback(
			static fn (IUser $user, callable $operation) => $operation()
		);
	}//end setUp()

	/**
	 * A recorder that captures what it was asked to record.
	 *
	 * @return FlowRunSubjectRecorder The spy.
	 */
	private function spy(): FlowRunSubjectRecorder {
		$spy = $this->createMock(FlowRunSubjectRecorder::class);
		$spy->method('recordOne')->willReturnCallback(
			function (
				array $context,
				string $role,
				array $uuids,
				string $register = '',
				string $schema = ''
			): void {
				$this->recorded[] = [
					'role' => $role,
					'uuids' => $uuids,
					'register' => $register,
					'schema' => $schema,
				];
			}
		);

		return $spy;
	}//end spy()

	/**
	 * A user manager that knows one enabled account.
	 *
	 * @return IUserManager The manager.
	 */
	private function users(): IUserManager {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if ($uid !== 'alice') {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				$user->method('isEnabled')->willReturn(true);

				return $user;
			}
		);

		return $users;
	}//end users()

	/**
	 * Translations that render the string they are given.
	 *
	 * @return IL10N The translator.
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $p = []): string => ($p === [] ? $text : vsprintf($text, $p))
		);

		return $l10n;
	}//end l10n()

	/**
	 * The write node over a register 3 / schema 7 pair.
	 *
	 * @param FlowRunSubjectRecorder|null $recorder The recorder, or none.
	 *
	 * @return ObjectWriteNode The node.
	 */
	private function writeNode(?FlowRunSubjectRecorder $recorder): ObjectWriteNode {
		$register = new Register();
		$register->setId(3);
		$register->setSlug('cases');

		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('case');

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($register);

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);
		$schemas->method('findBySlugInIds')->willReturn($schema);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => $default
		);

		return new ObjectWriteNode(
			$this->objects,
			$registers,
			$schemas,
			$this->users(),
			$appConfig,
			$this->l10n(),
			$this->createMock(IURLGenerator::class),
			$recorder
		);
	}//end writeNode()

	/**
	 * A saved object with a uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function saved(string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject(['title' => 'a']);

		return $entity;
	}//end saved()

	/**
	 * A create configuration, optionally declaring a role.
	 *
	 * @param string $role The role, or '' for none.
	 *
	 * @return array<string, mixed> The configuration.
	 */
	private function createConfig(string $role): array {
		$config = [
			'register' => 'cases',
			'schema' => 'case',
			'operation' => 'create',
			'fields' => ['title' => '{{ title }}'],
		];

		if ($role !== '') {
			$config['subjectRole'] = $role;
		}

		return $config;
	}//end createConfig()

	/**
	 * A run context naming an owner.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function context(): array {
		return ['runUuid' => 'run-1', 'triggeredBy' => 'alice', 'runAs' => 'alice'];
	}//end context()

	/**
	 * 🔴 A WRITE THAT NAMES NO ROLE RECORDS NOTHING.
	 *
	 * The write still happens, and the audit-derived object list still shows it.
	 * Only the DECLARED set stays empty, which is the whole distinction.
	 *
	 * @return void
	 */
	public function testAWriteWithNoRoleRecordsNothing(): void {
		$this->objects->expects($this->once())->method('saveObject')
			->willReturn($this->saved('obj-1'));

		$this->writeNode($this->spy())->execute(
			items: [FlowItems::item(json: ['title' => 'a'])],
			config: $this->createConfig(''),
			context: $this->context()
		);

		$this->assertSame([], $this->recorded, 'recording is opt-in, and this step did not opt in');
	}//end testAWriteWithNoRoleRecordsNothing()

	/**
	 * A declared role records the written object, with where it lives.
	 *
	 * 🔑 THE REGISTER AND SCHEMA ARE THE NUMERIC IDS the node already resolved,
	 * not the configured slugs. They are what fills the task's `registerId` and
	 * `schemaId` when a later step attaches to this role.
	 *
	 * @return void
	 */
	public function testADeclaredRoleRecordsTheWrittenObject(): void {
		$this->objects->method('saveObject')->willReturn($this->saved('obj-1'));

		$this->writeNode($this->spy())->execute(
			items: [FlowItems::item(json: ['title' => 'a'])],
			config: $this->createConfig('case'),
			context: $this->context()
		);

		$this->assertCount(1, $this->recorded);
		$this->assertSame('case', $this->recorded[0]['role']);
		$this->assertSame(['obj-1'], $this->recorded[0]['uuids']);
		$this->assertSame('3', $this->recorded[0]['register']);
		$this->assertSame('7', $this->recorded[0]['schema']);
	}//end testADeclaredRoleRecordsTheWrittenObject()

	/**
	 * 🔴 A STEP THAT WROTE TWO OBJECTS HANDS BOTH OVER, and the recorder is
	 * what decides a role names one object.
	 *
	 * The node must not pick one itself. Picking the last would be arbitrary,
	 * and the count is the same decision for the lock node, so it lives in one
	 * place.
	 *
	 * @return void
	 */
	public function testAMultiObjectWriteHandsOverEveryUuid(): void {
		$uuids = ['obj-1', 'obj-2'];
		$this->objects->method('saveObject')->willReturnCallback(
			function () use (&$uuids): ObjectEntity {
				return $this->saved((string)array_shift($uuids));
			}
		);

		$this->writeNode($this->spy())->execute(
			items: [FlowItems::item(json: ['title' => 'a']), FlowItems::item(json: ['title' => 'b'])],
			config: $this->createConfig('case'),
			context: $this->context()
		);

		$this->assertSame(['obj-1', 'obj-2'], $this->recorded[0]['uuids']);
	}//end testAMultiObjectWriteHandsOverEveryUuid()

	/**
	 * The uuid is read through the `output` key when the step nests the object.
	 *
	 * Without this the role would silently record nothing for every flow that
	 * preserves its incoming record — which is the shape a chained flow uses.
	 *
	 * @return void
	 */
	public function testTheUuidIsFoundUnderTheOutputKey(): void {
		$this->objects->method('saveObject')->willReturn($this->saved('obj-1'));

		$config = $this->createConfig('case');
		$config['output'] = 'written';

		$this->writeNode($this->spy())->execute(
			items: [FlowItems::item(json: ['title' => 'a'])],
			config: $config,
			context: $this->context()
		);

		$this->assertSame(['obj-1'], $this->recorded[0]['uuids']);
	}//end testTheUuidIsFoundUnderTheOutputKey()

	/**
	 * `subjectRole` is a key the node declares, on both nodes.
	 *
	 * A form field editing a key the node does not read is a field that does
	 * nothing, and the editor has no way to tell.
	 *
	 * @return void
	 */
	public function testBothNodesDeclareTheKey(): void {
		$this->assertContains('subjectRole', $this->writeNode(null)->configKeys());
		$this->assertContains('subjectRole', $this->lockNode(null)->configKeys());
	}//end testBothNodesDeclareTheKey()

	/**
	 * The lock node over the shared doubles.
	 *
	 * @param FlowRunSubjectRecorder|null $recorder The recorder, or none.
	 *
	 * @return LockObjectNode The node.
	 */
	private function lockNode(?FlowRunSubjectRecorder $recorder): LockObjectNode {
		return new LockObjectNode(
			$this->objects,
			$this->users(),
			$this->l10n(),
			$this->createMock(IURLGenerator::class),
			$recorder
		);
	}//end lockNode()

	/**
	 * A lock run context carrying this node's resume slot.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function lockContext(): array {
		return [
			'runUuid' => 'run-1',
			'runAs' => 'alice',
			FlowNodeResumeState::CONTEXT_KEY => (new FlowResumeState())->forNode('lock-1'),
		];
	}//end lockContext()

	/**
	 * A held lock under a declared role records the object.
	 *
	 * 🔑 NO REGISTER OR SCHEMA. This node locks by uuid and never resolves
	 * them, so it records neither rather than guessing. A task attaching to this
	 * role is anchored by uuid alone, which is what the inbox matches on.
	 *
	 * @return void
	 */
	public function testALockedObjectIsRecordedUnderItsRole(): void {
		$this->objects->expects($this->once())->method('lockObject')->willReturn([]);

		$this->lockNode($this->spy())->execute(
			items: [FlowItems::item(json: ['uuid' => 'obj-1'])],
			config: ['subjectRole' => 'case'],
			context: $this->lockContext()
		);

		$this->assertSame('case', $this->recorded[0]['role']);
		$this->assertSame(['obj-1'], $this->recorded[0]['uuids']);
		$this->assertSame('', $this->recorded[0]['register']);
		$this->assertSame('', $this->recorded[0]['schema']);
	}//end testALockedObjectIsRecordedUnderItsRole()

	/**
	 * A lock that names no role invents none.
	 *
	 * 🔑 THE NODE PASSES THE AUTHOR'S WORD THROUGH, AND NOTHING ELSE. Whether
	 * an empty role records anything is the recorder's decision, made in one
	 * place and tested there; what this asserts is that the node does not
	 * substitute a role of its own when the author named none.
	 *
	 * @return void
	 */
	public function testALockWithNoRoleInventsNone(): void {
		$this->objects->method('lockObject')->willReturn([]);

		$this->lockNode($this->spy())->execute(
			items: [FlowItems::item(json: ['uuid' => 'obj-1'])],
			config: [],
			context: $this->lockContext()
		);

		$this->assertSame('', $this->recorded[0]['role'], 'no role named, so none passed on');
	}//end testALockWithNoRoleInventsNone()

	/**
	 * 🔴 A CONTENDED LOCK RECORDS NOTHING, because the run does not hold it.
	 *
	 * The node parks and retries; reaching the recording line at all means every
	 * target is held. Recording before the acquire would let a run claim a
	 * subject it never locked.
	 *
	 * @return void
	 */
	public function testAFailedLockRecordsNothing(): void {
		$this->objects->method('lockObject')->willThrowException(new \RuntimeException('held by run-9'));

		try {
			$this->lockNode($this->spy())->execute(
				items: [FlowItems::item(json: ['uuid' => 'obj-1'])],
				config: ['subjectRole' => 'case'],
				context: $this->lockContext()
			);
		} catch (\Throwable $e) {
			// Parking or giving up; either way the lock was not taken.
			$this->addToAssertionCount(1);
		}

		$this->assertSame([], $this->recorded, 'a run must not claim a subject it failed to lock');
	}//end testAFailedLockRecordsNothing()
}//end class
