<?php

/**
 * WRITING AN ITEM WHOLE.
 *
 * `fields` enumerates the properties to write, which requires knowing them up
 * front. A synchronization with no `sourceTargetMapping` has no such list, and
 * that single gap is what refuses migration for most synchronizations: measured
 * across 119 cleanly-judged synchronizations on the dev instance, 98 of the 99
 * refusals were "sourceTargetMapping is not set".
 *
 * `payloadFrom` names a path whose resolved value IS the payload.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNode;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

/**
 * Tests for object-write's whole-object payload path.
 */
class ObjectWritePayloadFromTest extends TestCase {

	/**
	 * @var ObjectService|MockObject
	 */
	private $objects;

	/**
	 * @var IUserManager|MockObject
	 */
	private $userManager;

	/**
	 * @var IAppConfig|MockObject
	 */
	private $appConfig;

	/**
	 * @var ObjectWriteNode
	 */
	private ObjectWriteNode $node;

	/**
	 * @var Register
	 */
	private Register $register;

	/**
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * @var array<string, mixed>
	 */
	private array $registerContext;

	/**
	 * Set up the node with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objects = $this->createMock(ObjectService::class);
		// `runAs()` scopes the acting user around a read. The double must RUN the
		// callable, or every lookup silently returns null.
		$this->objects->method('runAs')->willReturnCallback(
			static fn (IUser $user, callable $operation) => $operation()
		);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userManager->method('get')->willReturn($user);
		$this->userManager->method('search')->willReturn([$user]);

		$this->register = new Register();
		$this->register->setId(1);
		$this->register->setSlug('example-hydra-cache');

		$this->schema = new Schema();
		$this->schema->setId(2);
		$this->schema->setSlug('example-cache-entry');

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($this->register);
		$schemas = $this->createMock(SchemaMapper::class);
		// Slug resolution goes through findBySlugInIds(); without it the register
		// reports "carries no schemas at all" and nothing under test is reached.
		$schemas->method('findBySlugInIds')->willReturn($this->schema);
		$schemas->method('find')->willReturn($this->schema);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $parameters = []): string {
				if ($parameters === []) {
					return $text;
				}

				return vsprintf($text, $parameters);
			}
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturnCallback(
			static fn (string $app, string $file): string => '/' . $app . '/img/' . $file
		);

		$this->node = new ObjectWriteNode(
			$this->objects,
			$registers,
			$schemas,
			$this->userManager,
			$this->appConfig,
			$l10n,
			$urls
		);

		$this->registerContext = ['triggeredBy' => 'admin'];

	}//end setUp()

	/**
	 * Wrap records as flow items.
	 *
	 * @param array<int, array<string, mixed>> $records The item records.
	 *
	 * @return array<int, array<string, mixed>> The items.
	 */
	private function items(array $records): array {
		return array_map(static fn (array $r): array => FlowItems::item(json: $r), $records);
	}//end items()

	/**
	 * Build a config for a whole-object write.
	 *
	 * @param array $overrides Config overrides.
	 *
	 * @return array The config.
	 */
	private function config(array $overrides = []): array {
		return array_merge(
			[
				'register' => 'example-hydra-cache',
				'schema' => 'example-cache-entry',
				'operation' => ObjectWriteNode::OP_CREATE,
				'payloadFrom' => 'source',
			],
			$overrides
		);

	}//end config()

	/**
	 * Build a saved entity.
	 *
	 * @param string $uuid The uuid.
	 * @param array  $data The object data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $uuid, array $data = []): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * THE POINT OF THE FEATURE. The object at the path is written whole, with every
	 * property it carries — no `fields` list, nothing enumerated up front.
	 *
	 * @return void
	 */
	public function testWritesTheObjectAtThePathWhole(): void {
		$seen = null;
		$this->objects->method('saveObject')->willReturnCallback(
			function (mixed $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true, bool $silent = false, bool $_validation = true, ?array $uploadedFiles = null, ?IUser $currentUser = null) use (&$seen): ObjectEntity {
				$seen = $object;

				return $this->entity('uuid-1', (array)$object);
			}
		);

		$this->node->execute(
			$this->items([['source' => ['name' => 'a', 'title' => 'A', 'nested' => ['x' => 1]]]]),
			$this->config(),
			$this->registerContext
		);

		$this->assertSame(
			['name' => 'a', 'title' => 'A', 'nested' => ['x' => 1]],
			$seen,
			'every property of the object at the path is written, including nested structure'
		);
	}//end testWritesTheObjectAtThePathWhole()

	/**
	 * A path that resolves to nothing THROWS rather than writing an empty object.
	 * `onMissing: omit` is a per-field rule — it drops one property and writes the
	 * rest. There is no "rest" for a whole payload, so omitting would create a
	 * blank record.
	 *
	 * @return void
	 */
	public function testAnUnresolvablePathThrowsRatherThanWritingBlank(): void {
		$this->objects->expects($this->never())->method('saveObject');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/payloadFrom/');

		$this->node->execute(
			$this->items([['somethingElse' => ['name' => 'a']]]),
			$this->config(),
			$this->registerContext
		);
	}//end testAnUnresolvablePathThrowsRatherThanWritingBlank()

	/**
	 * ...and the same when the path resolves to a scalar rather than an object.
	 * A string is not a record.
	 *
	 * @return void
	 */
	public function testAScalarAtThePathThrows(): void {
		$this->objects->expects($this->never())->method('saveObject');

		$this->expectException(RuntimeException::class);

		$this->node->execute(
			$this->items([['source' => 'not-an-object']]),
			$this->config(),
			$this->registerContext
		);
	}//end testAScalarAtThePathThrows()

	/**
	 * `fields` and `payloadFrom` are alternatives. Accepting both would leave the
	 * author unable to tell which produced the record.
	 *
	 * @return void
	 */
	public function testFieldsAndPayloadFromTogetherAreRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/alternatives/');

		$this->node->execute(
			$this->items([['source' => ['name' => 'a']]]),
			$this->config(['fields' => ['title' => '{{source.name}}']]),
			$this->registerContext
		);
	}//end testFieldsAndPayloadFromTogetherAreRefused()

	/**
	 * Neither one configured is still refused — that guard existed for `fields`
	 * alone and must not have been widened into accepting nothing.
	 *
	 * @return void
	 */
	public function testNeitherFieldsNorPayloadFromIsRefused(): void {
		$config = $this->config();
		unset($config['payloadFrom']);

		$this->expectException(UnexpectedValueException::class);

		$this->node->execute(
			$this->items([['source' => ['name' => 'a']]]),
			$config,
			$this->registerContext
		);
	}//end testNeitherFieldsNorPayloadFromIsRefused()

	/**
	 * A delete names WHICH object goes, never what to write into it.
	 *
	 * @return void
	 */
	public function testPayloadFromIsMeaninglessForADelete(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/payloadFrom/');

		$this->node->execute(
			$this->items([['source' => ['name' => 'a']]]),
			$this->config(
				[
					'operation' => ObjectWriteNode::OP_DELETE,
					'match' => [['property' => 'name', 'value' => '{{source.name}}']],
					'confirmDelete' => true,
				]
			),
			$this->registerContext
		);
	}//end testPayloadFromIsMeaninglessForADelete()

	/**
	 * The key is accepted by the preflight vocabulary. A node that reads a key it
	 * does not declare is a step whose config is silently ignored — the failure
	 * mode the preflight exists to catch.
	 *
	 * @return void
	 */
	public function testPayloadFromIsADeclaredConfigKey(): void {
		$this->assertContains('payloadFrom', $this->node->configKeys());
	}//end testPayloadFromIsADeclaredConfigKey()

	/**
	 * ...and is offered in the editor, since unlike `fields` it is a flat path.
	 *
	 * @return void
	 */
	public function testPayloadFromIsOfferedInTheConfigForm(): void {
		$keys = array_column($this->node->configForm(), 'key');

		$this->assertContains('payloadFrom', $keys);
	}//end testPayloadFromIsOfferedInTheConfigForm()
}//end class
