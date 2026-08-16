<?php

/**
 * Regression: `object-write` must be able to match on object metadata.
 *
 * `findMatch()` put every match pair into the schema-property bag, so `uuid` —
 * which is metadata living in the magic table's `_uuid` column and never inside
 * the JSON document — was looked up as a property that does not exist and hit
 * `MagicSearchHandler::applyObjectFilters()`'s `WHERE 1 = 0` branch. Zero rows,
 * no error. An update or delete matched nothing while the run reported
 * `completed`, and an upsert silently inserted a duplicate.
 *
 * `ObjectReadNode` puts `uuid` on every item it emits expressly so a follow-up
 * write or delete can name the row, so the most natural way to chain a read into
 * a write was the one way that could not work.
 *
 * These tests assert the SHAPE of the filter array handed to `findAll()`. The
 * table fake in ObjectWriteNodeTest matches filter keys only against a row's
 * `data`, which models the bug — a test written against that fake would pass for
 * the wrong reason.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
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

/**
 * Locks metadata routing in ObjectWriteNode::findMatch().
 */
class ObjectWriteNodeMetadataMatchTest extends TestCase {

	/** @var MockObject&ObjectService */
	private $objects;

	private ObjectWriteNode $node;

	private Register $register;

	private Schema $schema;

	/** @var array<int, string> */
	private array $runContext = ['triggeredBy' => 'alice'];

	/**
	 * The filter bag the node last handed to findAll().
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $seenFilters = null;

	protected function setUp(): void {
		parent::setUp();

		$this->register = new Register();
		$this->register->setId(1);
		$this->register->setSlug('example-hydra-cache');

		$this->schema = new Schema();
		$this->schema->setId(2);
		$this->schema->setSlug('example-cache-entry');

		$this->objects = $this->createMock(ObjectService::class);
		// `runAs()` scopes the acting user around the lookup. The double must
		// RUN the callable: an unstubbed mock method returns null, so
		// `findMatch()` sees no rows and every delete here reported "A delete
		// matched no object" — nine errors that had nothing to do with the
		// behaviour under test. `ObjectWriteNodeTest` and `ObjectReadNodeTest`
		// were both updated when the read moved inside `runAs()`
		// (openregister#2272); this file was not, so it has been failing on
		// `development` ever since.
		$this->objects->method('runAs')->willReturnCallback(
			static fn (IUser $user, callable $operation) => $operation()
		);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($this->register);

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('findBySlugInIds')->willReturn($this->schema);
		$schemas->method('find')->willReturn($this->schema);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if ($uid !== 'alice') {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn('alice');

				return $user;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $parameters = []): string {
				return vsprintf($text, $parameters);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(1000);

		$this->node = new ObjectWriteNode(
			$this->objects,
			$registers,
			$schemas,
			$userManager,
			$appConfig,
			$l10n,
			$this->createMock(IURLGenerator::class)
		);

		$this->seenFilters = null;
	}//end setUp()

	/**
	 * Give the schema a declared property set.
	 *
	 * @param array<string,mixed> $properties Declared properties.
	 *
	 * @return void
	 */
	private function withProperties(array $properties): void {
		$this->schema->setProperties($properties);
	}//end withProperties()

	/**
	 * Capture the filters handed to findAll() and return the given matches.
	 *
	 * @param ObjectEntity[] $matches Entities the lookup should resolve to.
	 *
	 * @return void
	 */
	private function captureFindAll(array $matches = []): void {
		$this->objects->method('findAll')->willReturnCallback(
			function (array $config = []) use ($matches): array {
				$this->seenFilters = (array)($config['filters'] ?? []);

				return $matches;
			}
		);
	}//end captureFindAll()

	/**
	 * Build an entity.
	 *
	 * @param string $uuid Entity uuid.
	 * @param array<string,mixed> $data Entity document.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data = []): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * Build a delete configuration matching on the given pairs.
	 *
	 * @param array<int,array{property:string,value:mixed}> $pairs Match pairs.
	 *
	 * @return array<string,mixed>
	 */
	private function deleteConfig(array $pairs): array {
		return [
			'register' => 'example-hydra-cache',
			'schema' => 'example-cache-entry',
			'operation' => ObjectWriteNode::OP_DELETE,
			'confirmDelete' => true,
			'match' => $pairs,
		];
	}//end deleteConfig()

	/**
	 * Run one item through the node.
	 *
	 * @param array<string,mixed> $config Step configuration.
	 * @param array<string,mixed> $record Item json.
	 *
	 * @return void
	 */
	private function runNode(array $config, array $record): void {
		$this->node->execute([FlowItems::item(json: $record)], $config, $this->runContext);
	}//end runNode()

	/**
	 * A match on `uuid` addresses metadata, not a schema property.
	 *
	 * This is the defect: `uuid` used to land in the property bag, where it
	 * could only produce `WHERE 1 = 0`.
	 *
	 * @return void
	 */
	public function testAMatchOnUuidIsRoutedToTheMetadataBag(): void {
		$this->withProperties(['title' => ['type' => 'string']]);
		$this->captureFindAll([$this->entity('u-1')]);
		$this->objects->method('deleteObject')->willReturn(true);

		$this->runNode(
			$this->deleteConfig([['property' => 'uuid', 'value' => '{{uuid}}']]),
			['uuid' => 'u-1']
		);

		$this->assertIsArray($this->seenFilters);
		$this->assertSame(
			['uuid' => 'u-1'],
			$this->seenFilters['@self'] ?? null,
			'uuid must be addressed as metadata'
		);
		$this->assertArrayNotHasKey(
			'uuid',
			$this->seenFilters,
			'uuid must NOT remain a top-level property filter — that is the 1 = 0 path'
		);
	}//end testAMatchOnUuidIsRoutedToTheMetadataBag()

	/**
	 * The uuid a read node emits is usable verbatim by a following write.
	 *
	 * @return void
	 */
	public function testTheUuidEmittedByAReadIsUsableByTheFollowingWrite(): void {
		$this->withProperties(['title' => ['type' => 'string']]);
		$this->captureFindAll([$this->entity('read-emitted-uuid')]);

		$deleted = null;
		$this->objects->method('deleteObject')->willReturnCallback(
			function (...$args) use (&$deleted): bool {
				$deleted = ($args[0] ?? null);

				return true;
			}
		);

		// Exactly the item shape ObjectReadNode emits: the record's own fields
		// plus the uuid it adds for this purpose.
		$this->runNode(
			$this->deleteConfig([['property' => 'uuid', 'value' => '{{uuid}}']]),
			['title' => 'a cached thing', 'uuid' => 'read-emitted-uuid']
		);

		$this->assertSame('read-emitted-uuid', $deleted);

		// The lookup that produced it must have addressed metadata. Without this
		// the assertion above passes on the pre-fix node too, because the double
		// returns its row regardless of what was asked.
		$this->assertSame(
			['uuid' => 'read-emitted-uuid'],
			$this->seenFilters['@self'] ?? null
		);
	}//end testTheUuidEmittedByAReadIsUsableByTheFollowingWrite()

	/**
	 * Register and schema stay top-level scoping keys, not metadata filters.
	 *
	 * @return void
	 */
	public function testRegisterAndSchemaRemainTopLevelScopingKeys(): void {
		$this->withProperties(['title' => ['type' => 'string']]);
		$this->captureFindAll([$this->entity('u-1')]);
		$this->objects->method('deleteObject')->willReturn(true);

		$this->runNode(
			$this->deleteConfig([['property' => 'uuid', 'value' => 'u-1']]),
			[]
		);

		$this->assertSame(1, $this->seenFilters['register'] ?? null);
		$this->assertSame(2, $this->seenFilters['schema'] ?? null);
	}//end testRegisterAndSchemaRemainTopLevelScopingKeys()

	/**
	 * A declared schema property still addresses the property bag.
	 *
	 * @return void
	 */
	public function testADeclaredPropertyStillAddressesTheProperty(): void {
		$this->withProperties(['sourceId' => ['type' => 'string']]);
		$this->captureFindAll([$this->entity('u-1')]);
		$this->objects->method('deleteObject')->willReturn(true);

		$this->runNode(
			$this->deleteConfig([['property' => 'sourceId', 'value' => 's-1']]),
			[]
		);

		$this->assertSame('s-1', $this->seenFilters['sourceId'] ?? null);
		$this->assertArrayNotHasKey('@self', $this->seenFilters);
	}//end testADeclaredPropertyStillAddressesTheProperty()

	/**
	 * The schema wins when a property shadows a metadata field name.
	 *
	 * A schema that genuinely declares `name` or `owner` must keep behaving
	 * exactly as before.
	 *
	 * @return void
	 */
	public function testADeclaredPropertyShadowsTheMetadataFieldOfTheSameName(): void {
		$this->withProperties(['name' => ['type' => 'string']]);
		$this->captureFindAll([$this->entity('u-1')]);
		$this->objects->method('deleteObject')->willReturn(true);

		$this->runNode(
			$this->deleteConfig([['property' => 'name', 'value' => 'thing']]),
			[]
		);

		$this->assertSame('thing', $this->seenFilters['name'] ?? null);
		$this->assertArrayNotHasKey('@self', $this->seenFilters);
	}//end testADeclaredPropertyShadowsTheMetadataFieldOfTheSameName()

	/**
	 * An explicit `@self.` prefix always addresses metadata.
	 *
	 * This is the escape hatch for a schema that shadows a metadata name.
	 *
	 * @return void
	 */
	public function testAnExplicitSelfPrefixAlwaysAddressesMetadata(): void {
		$this->withProperties(['name' => ['type' => 'string']]);
		$this->captureFindAll([$this->entity('u-1')]);
		$this->objects->method('deleteObject')->willReturn(true);

		$this->runNode(
			$this->deleteConfig([['property' => '@self.name', 'value' => 'thing']]),
			[]
		);

		$this->assertSame(['name' => 'thing'], $this->seenFilters['@self'] ?? null);
		$this->assertArrayNotHasKey('name', $this->seenFilters);
	}//end testAnExplicitSelfPrefixAlwaysAddressesMetadata()

	/**
	 * Metadata and property pairs combine in one match.
	 *
	 * @return void
	 */
	public function testMetadataAndPropertyPairsCombine(): void {
		$this->withProperties(['tenant' => ['type' => 'string']]);
		$this->captureFindAll([$this->entity('u-1')]);
		$this->objects->method('deleteObject')->willReturn(true);

		$this->runNode(
			$this->deleteConfig(
				[
					['property' => 'uuid', 'value' => 'u-1'],
					['property' => 'tenant', 'value' => 'nl'],
				]
			),
			[]
		);

		$this->assertSame(['uuid' => 'u-1'], $this->seenFilters['@self'] ?? null);
		$this->assertSame('nl', $this->seenFilters['tenant'] ?? null);
	}//end testMetadataAndPropertyPairsCombine()

	/**
	 * A name that is neither a property nor metadata is refused, loudly.
	 *
	 * It could only ever have produced `1 = 0`. Refusing beats matching nothing
	 * while reporting success.
	 *
	 * @return void
	 */
	public function testAMatchOnSomethingThatCannotExistIsRefused(): void {
		$this->withProperties(['title' => ['type' => 'string']]);
		$this->captureFindAll([]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('nonsense');

		$this->runNode(
			$this->deleteConfig([['property' => 'nonsense', 'value' => 'x']]),
			[]
		);
	}//end testAMatchOnSomethingThatCannotExistIsRefused()

	/**
	 * An unknown metadata field behind an explicit prefix is refused too.
	 *
	 * @return void
	 */
	public function testAnUnknownExplicitMetadataFieldIsRefused(): void {
		$this->withProperties(['title' => ['type' => 'string']]);
		$this->captureFindAll([]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('nonsense');

		$this->runNode(
			$this->deleteConfig([['property' => '@self.nonsense', 'value' => 'x']]),
			[]
		);
	}//end testAnUnknownExplicitMetadataFieldIsRefused()

	/**
	 * With no declared properties the node keeps its historical behaviour.
	 *
	 * An empty property list cannot distinguish "declares nothing" from "the
	 * declaration was not available", so the refusal must not fire on it —
	 * otherwise a working flow breaks on missing information.
	 *
	 * @return void
	 */
	public function testWithNoDeclaredPropertiesAnUnknownNameIsStillPassedThrough(): void {
		$this->captureFindAll([$this->entity('u-1')]);
		$this->objects->method('deleteObject')->willReturn(true);

		$this->runNode(
			$this->deleteConfig([['property' => 'sourceId', 'value' => 's-1']]),
			[]
		);

		$this->assertSame('s-1', $this->seenFilters['sourceId'] ?? null);
	}//end testWithNoDeclaredPropertiesAnUnknownNameIsStillPassedThrough()

	/**
	 * Even with no declared properties, uuid is routed to metadata.
	 *
	 * @return void
	 */
	public function testUuidIsRoutedToMetadataEvenWithoutDeclaredProperties(): void {
		$this->captureFindAll([$this->entity('u-1')]);
		$this->objects->method('deleteObject')->willReturn(true);

		$this->runNode(
			$this->deleteConfig([['property' => 'uuid', 'value' => 'u-1']]),
			[]
		);

		$this->assertSame(['uuid' => 'u-1'], $this->seenFilters['@self'] ?? null);
	}//end testUuidIsRoutedToMetadataEvenWithoutDeclaredProperties()
}//end class
