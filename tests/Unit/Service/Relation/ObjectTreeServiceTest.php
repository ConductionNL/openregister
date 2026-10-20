<?php

declare(strict_types=1);

/**
 * The tree read along a schema's declared parent property.
 *
 * The schema is a real organisation fragment carrying `x-openregister-party`
 * with a `parentProperty`, checked by the real validator before it is used.
 * The magic table is a child-to-parent map behind the descender's one query,
 * and read access is a set of uuids behind the mapper's one filter, so what is
 * under test is the walk: levels, the cycle, the bounds and the RBAC pruning.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
 */

namespace Unit\Service\Relation;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectRelationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Party\PartyAnnotationValidator;
use OCA\OpenRegister\Service\Rbac\HierarchyAnnotationValidator;
use OCA\OpenRegister\Service\Rbac\HierarchyDescender;
use OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander;
use OCA\OpenRegister\Service\Relation\ObjectTreeService;
use OCA\OpenRegister\Service\Relation\RelationGraphService;
use OCA\OpenRegister\Service\Relation\RelationTypeResolver;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Relation\ObjectTreeService
 * @uses   \OCA\OpenRegister\Service\Party\PartyDefinition
 * @uses   \OCA\OpenRegister\Service\Party\PartyAnnotationValidator
 * @uses   \OCA\OpenRegister\Service\Rbac\HierarchyAnnotationValidator
 * @uses   \OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander
 * @uses   \OCA\OpenRegister\Service\Rbac\HierarchyDescender
 * @uses   \OCA\OpenRegister\Service\Relation\RelationGraphService
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Register
 */
class ObjectTreeServiceTest extends TestCase {

	/**
	 * The `limit` every `childrenOf` call of the last built service was given.
	 *
	 * @var array<int, int|null>
	 */
	private array $askedLimits = [];

	/**
	 * Whether the access check of the last built service fails instead of answering.
	 *
	 * @var boolean
	 */
	private bool $accessFails = false;

	/**
	 * The redaction the service is given, or null for one that changes nothing.
	 *
	 * @var RenderObject|null
	 */
	private ?RenderObject $renderer = null;

	/**
	 * An organisation schema fragment, as a register file declares it.
	 *
	 * @param array<string, mixed> $extra Extra schema-level keys.
	 *
	 * @return array<string, mixed> The fragment.
	 */
	private static function fragment(array $extra = []): array {
		return array_merge(
			[
				'slug' => 'organisation',
				'properties' => [
					'kvkNumber' => ['type' => 'string', 'pattern' => '^[0-9]{8}$'],
					'tradeName' => ['type' => 'string'],
					'parentOrganisation' => [
						'type' => 'string',
						'format' => 'uuid',
						'$ref' => '#/components/schemas/organisation',
					],
				],
				'x-openregister-party' => [
					'kind' => 'organisation',
					'nameProperty' => 'tradeName',
					'parentProperty' => 'parentOrganisation',
					'maxDepth' => 4,
				],
			],
			$extra
		);
	}//end fragment()

	/**
	 * The schema entity for a fragment, after the save-time validators passed it.
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $fragment): Schema {
		$this->assertSame([], (new PartyAnnotationValidator())->validate(schema: $fragment));
		$this->assertSame([], (new HierarchyAnnotationValidator())->validate(schema: $fragment));

		$schema = new Schema();
		$schema->setId(2);
		$schema->setSlug((string)$fragment['slug']);
		$schema->setProperties($fragment['properties']);
		$configuration = [];
		foreach ($fragment as $key => $value) {
			if (str_starts_with((string)$key, 'x-openregister-') === true) {
				$configuration[$key] = $value;
			}
		}

		$schema->setConfiguration($configuration);

		return $schema;
	}//end schema()

	/**
	 * Build the service over a table of organisations.
	 *
	 * @param array<string, string|null> $parentOf Uuid => parent uuid.
	 * @param array<int, string>|null    $readable The uuids the caller may read; null = all.
	 * @param array<string, mixed>|null  $fragment The schema fragment.
	 * @param int                        $cap      The instance node cap.
	 *
	 * @return ObjectTreeService The service.
	 */
	private function service(array $parentOf, ?array $readable = null, ?array $fragment = null, int $cap = 250): ObjectTreeService {
		$logger = $this->createMock(LoggerInterface::class);

		$descender = $this->getMockBuilder(HierarchyDescender::class)
			->disableOriginalConstructor()
			->onlyMethods(['childrenOf'])
			->getMock();
		$descender->method('childrenOf')->willReturnCallback(
			function (string $table, string $parentColumn, array $parentUuids, ?int $limit = null) use ($parentOf): array {
				$this->assertSame('openregister_table_1_2', $table);
				$this->assertSame('parent_organisation', $parentColumn);
				$this->askedLimits[] = $limit;
				$children = [];
				foreach ($parentOf as $child => $parent) {
					if ($parent !== null && $child !== $parent && in_array($parent, $parentUuids, true) === true) {
						$children[$child] = $parent;
					}
				}

				return ($limit === null ? $children : array_slice($children, 0, $limit, true));
			}
		);

		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('readableUuidsOrNull')->willReturnCallback(
			fn (int $registerId, int $schemaId, array $uuids): ?array => ($this->accessFails === true) ? null : array_values(
				array_filter($uuids, static fn (string $uuid): bool => $readable === null || in_array($uuid, $readable, true))
			)
		);
		$objectMapper->method('findObjectsByUuidsInRegisterSchema')->willReturnCallback(
			function (Register $register, Schema $schema, array $uuids) use ($parentOf): array {
				$found = [];
				foreach ($uuids as $uuid) {
					if (array_key_exists($uuid, $parentOf) === true) {
						$found[$uuid] = $this->organisation(uuid: $uuid, parent: $parentOf[$uuid]);
					}
				}

				return $found;
			}
		);

		$register = new Register();
		$register->setId(1);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($this->schema(fragment: ($fragment ?? self::fragment())));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default): int => ($key === RelationGraphService::MAX_NODES_KEY ? $cap : $default)
		);
		$graphs = new RelationGraphService(
			objectEntityMapper: $objectMapper,
			relationMapper: $this->createMock(ObjectRelationMapper::class),
			schemaMapper: $schemaMapper,
			relationTypes: $this->createMock(RelationTypeResolver::class),
			appConfig: $appConfig,
			logger: $logger
		);

		return new ObjectTreeService(
			descender: $descender,
			hierarchies: new HierarchyGrantExpander(descender: $descender, logger: $logger),
			objectMapper: $objectMapper,
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			graphs: $graphs,
			renderer: ($this->renderer ?? $this->createMock(RenderObject::class)),
			logger: $logger
		);
	}//end service()

	/**
	 * One organisation object.
	 *
	 * @param string               $uuid   The uuid.
	 * @param string|null          $parent The parent uuid.
	 * @param array<string, mixed> $extra  Further properties.
	 *
	 * @return ObjectEntity The object.
	 */
	private function organisation(string $uuid, ?string $parent, array $extra = []): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setName('Org '.$uuid);
		$entity->setRegister('1');
		$entity->setSchema('2');
		$entity->setObject(
			array_merge(
				['tradeName' => 'Org '.$uuid, 'kvkNumber' => '1000000'.strlen($uuid), 'parentOrganisation' => $parent],
				$extra
			)
		);

		return $entity;
	}//end organisation()

	/**
	 * Levels per uuid in an answer.
	 *
	 * @param array<string, mixed> $tree The answer.
	 *
	 * @return array<string, int> Uuid => level.
	 */
	private static function levels(array $tree): array {
		$levels = [];
		foreach ($tree['nodes'] as $node) {
			$levels[$node['uuid']] = $node['level'];
		}

		return $levels;
	}//end levels()

	/**
	 * A holding with two subsidiaries and one below one of them.
	 *
	 * @return array<string, string|null> Uuid => parent.
	 */
	private static function group(): array {
		return ['holding' => null, 'sub-a' => 'holding', 'sub-b' => 'holding', 'sub-a1' => 'sub-a'];
	}//end group()

	/**
	 * The descendants come back by level with their names and chosen fields.
	 *
	 * @return void
	 */
	public function testDescendantsCarryLevelNameAndFields(): void {
		$tree = $this->service(parentOf: self::group())->tree(
			root: $this->organisation(uuid: 'holding', parent: null),
			fields: ['kvkNumber']
		);

		$this->assertSame(['holding' => 0, 'sub-a' => 1, 'sub-b' => 1, 'sub-a1' => 2], self::levels(tree: $tree));
		$this->assertSame('parentOrganisation', $tree['parentProperty']);
		$this->assertSame(4, $tree['maxDepth']);
		$this->assertFalse($tree['truncated']);
		$this->assertSame('Org sub-a1', $tree['nodes'][3]['name']);
		$this->assertSame('sub-a', $tree['nodes'][3]['parent']);
		$this->assertSame(['kvkNumber' => '10000006'], $tree['nodes'][3]['properties']);
	}//end testDescendantsCarryLevelNameAndFields()

	/**
	 * A cycle in the data ends the walk instead of repeating it.
	 *
	 * @return void
	 */
	public function testACycleIsWalkedOnce(): void {
		$cycle = ['a' => 'c', 'b' => 'a', 'c' => 'b'];
		$service = $this->service(parentOf: $cycle);

		$down = $service->tree(root: $this->organisation(uuid: 'a', parent: 'c'));
		$this->assertSame(['a' => 0, 'b' => 1, 'c' => 2], self::levels(tree: $down));
		$this->assertFalse($down['truncated']);

		$up = $service->tree(root: $this->organisation(uuid: 'a', parent: 'c'), direction: ObjectTreeService::DIRECTION_ANCESTORS);
		$this->assertSame(['b' => -2, 'c' => -1, 'a' => 0], self::levels(tree: $up));
	}//end testACycleIsWalkedOnce()

	/**
	 * A depth below the tree's height stops there and says so.
	 *
	 * @return void
	 */
	public function testTheDepthBoundIsReportedAsTruncation(): void {
		$tree = $this->service(parentOf: self::group())->tree(
			root: $this->organisation(uuid: 'holding', parent: null),
			depth: 1
		);

		$this->assertSame(['holding' => 0, 'sub-a' => 1, 'sub-b' => 1], self::levels(tree: $tree));
		$this->assertTrue($tree['truncated']);
		$this->assertSame(ObjectTreeService::TRUNCATED_DEPTH, $tree['truncatedBy']);
	}//end testTheDepthBoundIsReportedAsTruncation()

	/**
	 * A depth above the schema's bound is held to the bound.
	 *
	 * @return void
	 */
	public function testTheDeclaredDepthCapsTheRequest(): void {
		$chain = ['l0' => null, 'l1' => 'l0', 'l2' => 'l1', 'l3' => 'l2', 'l4' => 'l3', 'l5' => 'l4'];
		$tree = $this->service(parentOf: $chain)->tree(root: $this->organisation(uuid: 'l0', parent: null), depth: 50);

		$this->assertSame(4, $tree['depth']);
		$this->assertArrayNotHasKey('l5', self::levels(tree: $tree));
		$this->assertSame(ObjectTreeService::TRUNCATED_DEPTH, $tree['truncatedBy']);
	}//end testTheDeclaredDepthCapsTheRequest()

	/**
	 * The node cap stops the walk and says so.
	 *
	 * @return void
	 */
	public function testTheNodeCapIsReportedAsTruncation(): void {
		$tree = $this->service(parentOf: self::group(), cap: 2)->tree(root: $this->organisation(uuid: 'holding', parent: null));

		$this->assertSame(['holding' => 0], self::levels(tree: $tree));
		$this->assertSame(ObjectTreeService::TRUNCATED_CAP, $tree['truncatedBy']);
	}//end testTheNodeCapIsReportedAsTruncation()

	/**
	 * A subsidiary the caller may not read is not returned, nor anything below it.
	 *
	 * @return void
	 */
	public function testAnUnreadableDescendantIsLeftOutWithItsSubtree(): void {
		$tree = $this->service(parentOf: self::group(), readable: ['holding', 'sub-b', 'sub-a1'])->tree(
			root: $this->organisation(uuid: 'holding', parent: null)
		);

		$this->assertSame(['holding' => 0, 'sub-b' => 1], self::levels(tree: $tree));
		$this->assertFalse($tree['truncated']);
	}//end testAnUnreadableDescendantIsLeftOutWithItsSubtree()

	/**
	 * Without RBAC, for an in-process caller that scopes its own reads, every node is returned.
	 *
	 * @return void
	 */
	public function testWithoutRbacEveryNodeIsReturned(): void {
		$tree = $this->service(parentOf: self::group(), readable: ['holding'])->tree(
			root: $this->organisation(uuid: 'holding', parent: null),
			_rbac: false
		);

		$this->assertCount(4, $tree['nodes']);
	}//end testWithoutRbacEveryNodeIsReturned()

	/**
	 * The ancestors come back above the root, and an unreadable one ends the walk up.
	 *
	 * @return void
	 */
	public function testAncestorsStopAtAnUnreadableOne(): void {
		$root = $this->organisation(uuid: 'sub-a1', parent: 'sub-a');

		$all = $this->service(parentOf: self::group())->tree(root: $root, direction: ObjectTreeService::DIRECTION_BOTH);
		$this->assertSame(['holding' => -2, 'sub-a' => -1, 'sub-a1' => 0], self::levels(tree: $all));

		$hidden = $this->service(parentOf: self::group(), readable: ['sub-a1', 'holding'])->tree(
			root: $root,
			direction: ObjectTreeService::DIRECTION_ANCESTORS
		);
		$this->assertSame(['sub-a1' => 0], self::levels(tree: $hidden));
	}//end testAncestorsStopAtAnUnreadableOne()

	/**
	 * A schema declaring no parent answers with the root alone.
	 *
	 * @return void
	 */
	public function testASchemaWithoutAParentAnswersWithTheRootAlone(): void {
		$fragment = self::fragment();
		unset($fragment['x-openregister-party']);

		$tree = $this->service(parentOf: self::group(), fragment: $fragment)->tree(root: $this->organisation(uuid: 'holding', parent: null));

		$this->assertSame(['holding' => 0], self::levels(tree: $tree));
		$this->assertNull($tree['parentProperty']);
		$this->assertFalse($tree['truncated']);
	}//end testASchemaWithoutAParentAnswersWithTheRootAlone()

	/**
	 * The hierarchy declaration is read the same way and wins over the party one.
	 *
	 * @return void
	 */
	public function testTheHierarchyDeclarationIsReadToo(): void {
		$fragment = self::fragment(['x-openregister-hierarchy' => ['parent' => 'parentOrganisation', 'maxDepth' => 1]]);
		$service = $this->service(parentOf: self::group(), fragment: $fragment);

		$declaration = $service->declarationFor(schema: $this->schema(fragment: $fragment));
		$this->assertSame(HierarchyGrantExpander::ANNOTATION, $declaration['source']);

		$tree = $service->tree(root: $this->organisation(uuid: 'holding', parent: null));
		$this->assertSame(1, $tree['maxDepth']);
		$this->assertSame(['holding' => 0, 'sub-a' => 1, 'sub-b' => 1], self::levels(tree: $tree));
	}//end testTheHierarchyDeclarationIsReadToo()

	/**
	 * The `fields` of a node never carry a write-only, a property-restricted or an encrypted value.
	 *
	 * The service hands every entity it reads to the same redaction every other
	 * read of an object goes through, and reads the properties from what comes
	 * back; the objects the mapper loaded are left as they were.
	 *
	 * @return void
	 */
	public function testFieldsNeverReturnWhatTheCallerMayNotRead(): void {
		$redactions = 0;
		$renderer = $this->createMock(RenderObject::class);
		$renderer->method('redactWriteOnlyFromRows')->willReturnCallback(
			function (array &$rows, bool $_rbac = true) use (&$redactions): void {
				foreach ($rows as $row) {
					$redactions++;
					$data = $row->getObject();
					unset($data['secret'], $data['restricted']);
					$row->setObject($data);
				}
			}
		);
		$this->renderer = $renderer;

		$root = $this->organisation(uuid: 'sub-a', parent: 'holding', extra: ['secret' => 's3cret', 'restricted' => 'no', 'open' => 'yes']);
		$tree = $this->service(parentOf: self::group())->tree(
			root: $root,
			direction: ObjectTreeService::DIRECTION_BOTH,
			fields: ['secret', 'restricted', 'open']
		);

		$this->assertGreaterThanOrEqual(3, $redactions);
		$this->assertCount(3, $tree['nodes']);
		foreach ($tree['nodes'] as $node) {
			$this->assertNull($node['properties']['secret'], 'node '.$node['uuid']);
			$this->assertNull($node['properties']['restricted'], 'node '.$node['uuid']);
		}

		$this->assertSame('yes', $tree['nodes'][1]['properties']['open']);
		$this->assertSame('s3cret', $root->getObject()['secret'], 'The entity the caller passed in is not changed.');
	}//end testFieldsNeverReturnWhatTheCallerMayNotRead()

	/**
	 * A request that names no field redacts nothing: there is nothing to carry.
	 *
	 * @return void
	 */
	public function testWithoutFieldsNothingIsRedacted(): void {
		$renderer = $this->createMock(RenderObject::class);
		$renderer->expects($this->never())->method('redactWriteOnlyFromRows');
		$this->renderer = $renderer;

		$tree = $this->service(parentOf: self::group())->tree(root: $this->organisation(uuid: 'holding', parent: null));

		$this->assertCount(4, $tree['nodes']);
	}//end testWithoutFieldsNothingIsRedacted()

	/**
	 * A failing access check is an incomplete answer, not a complete one without those nodes.
	 *
	 * @return void
	 */
	public function testAFailingAccessCheckReadsAsUnreadableNotAsComplete(): void {
		$this->accessFails = true;
		$service = $this->service(parentOf: self::group());

		$down = $service->tree(root: $this->organisation(uuid: 'holding', parent: null));
		$this->assertSame(['holding' => 0], self::levels(tree: $down));
		$this->assertTrue($down['truncated']);
		$this->assertSame(ObjectTreeService::TRUNCATED_UNREADABLE, $down['truncatedBy']);

		$up = $service->tree(root: $this->organisation(uuid: 'sub-a', parent: 'holding'), direction: ObjectTreeService::DIRECTION_ANCESTORS);
		$this->assertSame(['sub-a' => 0], self::levels(tree: $up));
		$this->assertTrue($up['truncated']);
		$this->assertSame(ObjectTreeService::TRUNCATED_UNREADABLE, $up['truncatedBy']);
	}//end testAFailingAccessCheckReadsAsUnreadableNotAsComplete()

	/**
	 * The node cap bounds the read of a level, not only the answer.
	 *
	 * @return void
	 */
	public function testTheCapBoundsTheRowsAskedFor(): void {
		$wide = ['holding' => null];
		for ($i = 1; $i <= 50; $i++) {
			$wide['child-'.$i] = 'holding';
		}

		$tree = $this->service(parentOf: $wide, cap: 10)->tree(root: $this->organisation(uuid: 'holding', parent: null));

		$this->assertSame(ObjectTreeService::TRUNCATED_CAP, $tree['truncatedBy']);
		$this->assertSame(['holding' => 0], self::levels(tree: $tree));
		$this->assertNotEmpty($this->askedLimits);
		foreach ($this->askedLimits as $limit) {
			$this->assertNotNull($limit);
			$this->assertLessThanOrEqual(11, $limit);
		}
	}//end testTheCapBoundsTheRowsAskedFor()

	/**
	 * A cap used up exactly at the depth bound, with children below, is a cap truncation.
	 *
	 * @return void
	 */
	public function testAnExhaustedCapWithUnreadChildrenBelowIsReportedAsCap(): void {
		$tree = $this->service(parentOf: self::group(), cap: 3)->tree(root: $this->organisation(uuid: 'holding', parent: null), depth: 1);

		$this->assertSame(['holding' => 0, 'sub-a' => 1, 'sub-b' => 1], self::levels(tree: $tree));
		$this->assertTrue($tree['truncated']);
		$this->assertSame(ObjectTreeService::TRUNCATED_CAP, $tree['truncatedBy']);
	}//end testAnExhaustedCapWithUnreadChildrenBelowIsReportedAsCap()

	/**
	 * A level exactly as wide as the cap allows is answered whole.
	 *
	 * @return void
	 */
	public function testALevelThatFitsTheCapIsNotTruncated(): void {
		$tree = $this->service(parentOf: self::group(), cap: 4)->tree(root: $this->organisation(uuid: 'holding', parent: null));

		$this->assertSame(['holding' => 0, 'sub-a' => 1, 'sub-b' => 1, 'sub-a1' => 2], self::levels(tree: $tree));
		$this->assertFalse($tree['truncated']);
	}//end testALevelThatFitsTheCapIsNotTruncated()

	/**
	 * The topmost ancestor does not name a parent the caller may not read.
	 *
	 * @return void
	 */
	public function testTheTopmostAncestorDoesNotNameAHiddenParent(): void {
		$tree = $this->service(parentOf: self::group(), readable: ['sub-a1', 'sub-a'])->tree(
			root: $this->organisation(uuid: 'sub-a1', parent: 'sub-a'),
			direction: ObjectTreeService::DIRECTION_ANCESTORS
		);

		$this->assertSame(['sub-a' => -1, 'sub-a1' => 0], self::levels(tree: $tree));
		$this->assertNull($tree['nodes'][0]['parent']);
		$this->assertFalse($tree['truncated']);
	}//end testTheTopmostAncestorDoesNotNameAHiddenParent()

	/**
	 * At the depth bound the topmost node names the readable parent just above it.
	 *
	 * @return void
	 */
	public function testTheAncestorAtTheDepthBoundNamesItsReadableParent(): void {
		$tree = $this->service(parentOf: self::group())->tree(
			root: $this->organisation(uuid: 'sub-a1', parent: 'sub-a'),
			direction: ObjectTreeService::DIRECTION_ANCESTORS,
			depth: 1
		);

		$this->assertSame(['sub-a' => -1, 'sub-a1' => 0], self::levels(tree: $tree));
		$this->assertSame('holding', $tree['nodes'][0]['parent']);
		$this->assertSame(ObjectTreeService::TRUNCATED_DEPTH, $tree['truncatedBy']);
	}//end testTheAncestorAtTheDepthBoundNamesItsReadableParent()
}//end class
