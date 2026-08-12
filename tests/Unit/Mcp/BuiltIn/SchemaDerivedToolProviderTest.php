<?php

declare(strict_types=1);

/**
 * SchemaDerivedToolProvider Unit Tests
 *
 * Exercises the ADR-063 chain-2/3 derivation: enabled-schema tool emission,
 * disabled/absent schema silence, `tools` subset narrowing, self-suppression
 * on a hand-written id collision, search filters/pagination/projection/
 * truncation, RBAC-through-ObjectService write dispatch, and one immutable
 * audit record per invocation (success, and failure with no bypass).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\BuiltIn
 * @author   OpenRegister Team
 * @license  EUPL-1.2
 * @link     https://github.com/OpenRegister/OpenRegister
 *
 * @spec openspec/changes/or-mcp-derived-tool-provider/specs/ai-mcp/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\Mcp\BuiltIn;

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Mcp\BuiltIn\SchemaDerivedToolProvider;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for SchemaDerivedToolProvider.
 */
class SchemaDerivedToolProviderTest extends TestCase {

	/** @var ObjectService&MockObject */
	private $objectService;

	/** @var AuditTrailMapper&MockObject */
	private $auditTrailMapper;

	/** @var LoggerInterface&MockObject */
	private $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Build a REAL Schema entity (not a mock) with configuration/slug/id/
	 * properties/required populated via its real setters.
	 *
	 * `getId()`/`getSlug()`/`getApplication()` are magic (Entity::__call())
	 * rather than concretely-declared methods, so PHPUnit's `createMock()`
	 * cannot stub them (`MethodCannotBeConfiguredException`). A real,
	 * setter-populated instance sidesteps that entirely.
	 *
	 * @param int $id Schema id.
	 * @param string $slug Schema slug.
	 * @param array<string, mixed>|null $mcpBlock `x-openregister-mcp` block, or null for none.
	 * @param array<string, mixed> $properties Schema properties.
	 * @param array<int, string> $required Required property names.
	 *
	 * @return Schema
	 */
	private function mockSchema(
		int $id,
		string $slug,
		?array $mcpBlock,
		array $properties = ['status' => ['type' => 'string'], 'assignee' => ['type' => 'string'], 'name' => ['type' => 'string']],
		array $required = ['name'],
	): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setProperties($properties);
		$schema->setRequired($required);

		$configuration = [];
		if ($mcpBlock !== null) {
			$configuration['x-openregister-mcp'] = $mcpBlock;
		}

		$schema->setConfiguration($configuration);

		return $schema;
	}//end mockSchema()

	/**
	 * Build a mock ObjectEntity whose jsonSerialize() returns $data.
	 *
	 * @param array<string, mixed> $data Serialized payload.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function mockObject(array $data): ObjectEntity {
		$object = $this->createMock(ObjectEntity::class);
		$object->method('jsonSerialize')->willReturn($data);
		return $object;
	}//end mockObject()

	/**
	 * Build the provider under test.
	 *
	 * @param array<int, array{schema: Schema, register: Register|null}> $schemaEntries Schema entries.
	 * @param list<string> $suppressedIds Self-suppressed ids.
	 *
	 * @return SchemaDerivedToolProvider
	 */
	private function provider(array $schemaEntries, array $suppressedIds = []): SchemaDerivedToolProvider {
		return new SchemaDerivedToolProvider(
			appId: 'pipelinq',
			schemaEntries: $schemaEntries,
			suppressedIds: $suppressedIds,
			objectService: $this->objectService,
			auditTrailMapper: $this->auditTrailMapper,
			logger: $this->logger
		);

	}//end provider()

	// ── getAppId ─────────────────────────────────────────────────────

	public function testGetAppIdReturnsOwningApp(): void {
		$provider = $this->provider([]);
		$this->assertSame('pipelinq', $provider->getAppId());

	}//end testGetAppIdReturnsOwningApp()

	// ── getTools: derivation ────────────────────────────────────────

	public function testEnabledSchemaEmitsOneToolPerVerb(): void {
		$schema = $this->mockSchema(id: 1, slug: 'lead', mcpBlock: ['enabled' => true]);
		$provider = $this->provider([['schema' => $schema, 'register' => null]]);

		$tools = $provider->getTools();
		$ids = array_column($tools, 'id');

		$this->assertCount(5, $tools);
		$this->assertSame(
			['pipelinq.lead.search', 'pipelinq.lead.get', 'pipelinq.lead.create', 'pipelinq.lead.update', 'pipelinq.lead.delete'],
			$ids
		);

		foreach ($tools as $tool) {
			$this->assertStringStartsWith('pipelinq.', $tool['id']);
			$this->assertNotEmpty($tool['description']);
			$this->assertSame('object', $tool['inputSchema']['type']);
		}

	}//end testEnabledSchemaEmitsOneToolPerVerb()

	public function testDisabledSchemaEmitsNothing(): void {
		$schema = $this->mockSchema(id: 1, slug: 'lead', mcpBlock: ['enabled' => false]);
		$provider = $this->provider([['schema' => $schema, 'register' => null]]);

		$this->assertSame([], $provider->getTools());

	}//end testDisabledSchemaEmitsNothing()

	public function testAbsentAnnotationEmitsNothing(): void {
		$schema = $this->mockSchema(id: 1, slug: 'lead', mcpBlock: null);
		$provider = $this->provider([['schema' => $schema, 'register' => null]]);

		$this->assertSame([], $provider->getTools());

	}//end testAbsentAnnotationEmitsNothing()

	public function testToolsSubsetNarrowsEmittedVerbs(): void {
		$schema = $this->mockSchema(
			id: 1,
			slug: 'lead',
			mcpBlock: [
				'enabled' => true,
				'tools' => [
					'search' => [],
					'get' => [],
				],
			]
		);
		$provider = $this->provider([['schema' => $schema, 'register' => null]]);

		$ids = array_column($provider->getTools(), 'id');

		$this->assertSame(['pipelinq.lead.search', 'pipelinq.lead.get'], $ids);

	}//end testToolsSubsetNarrowsEmittedVerbs()

	public function testSelfSuppressionOmitsCollidingIdOnly(): void {
		$schema = $this->mockSchema(id: 1, slug: 'lead', mcpBlock: ['enabled' => true]);
		$provider = $this->provider(
			[['schema' => $schema, 'register' => null]],
			suppressedIds: ['pipelinq.lead.search']
		);

		$ids = array_column($provider->getTools(), 'id');

		$this->assertNotContains('pipelinq.lead.search', $ids);
		$this->assertSame(
			['pipelinq.lead.get', 'pipelinq.lead.create', 'pipelinq.lead.update', 'pipelinq.lead.delete'],
			$ids
		);

	}//end testSelfSuppressionOmitsCollidingIdOnly()

	public function testDeclaredDescriptionOverridesDefault(): void {
		$schema = $this->mockSchema(
			id: 1,
			slug: 'lead',
			mcpBlock: [
				'enabled' => true,
				'tools' => ['search' => ['description' => 'Find leads by status.']],
			]
		);
		$provider = $this->provider([['schema' => $schema, 'register' => null]]);

		$tools = $provider->getTools();
		$this->assertSame('Find leads by status.', $tools[0]['description']);

	}//end testDeclaredDescriptionOverridesDefault()

	public function testSearchInputSchemaOnlyExposesDeclaredFilters(): void {
		$schema = $this->mockSchema(
			id: 1,
			slug: 'lead',
			mcpBlock: [
				'enabled' => true,
				'tools' => ['search' => ['filters' => ['status']]],
			]
		);
		$provider = $this->provider([['schema' => $schema, 'register' => null]]);

		$tools = $provider->getTools();
		$filterProps = $tools[0]['inputSchema']['properties']['filters']['properties'];

		$this->assertArrayHasKey('status', $filterProps);
		$this->assertArrayNotHasKey('assignee', $filterProps);

	}//end testSearchInputSchemaOnlyExposesDeclaredFilters()

	public function testMcpHintsArePassedThrough(): void {
		$schema = $this->mockSchema(
			id: 1,
			slug: 'lead',
			mcpBlock: [
				'enabled' => true,
				'tools' => ['delete' => ['destructiveHint' => true, 'idempotentHint' => true]],
			]
		);
		$provider = $this->provider([['schema' => $schema, 'register' => null]]);

		$tools = $provider->getTools();
		$this->assertTrue($tools[0]['destructiveHint']);
		$this->assertTrue($tools[0]['idempotentHint']);

	}//end testMcpHintsArePassedThrough()

	// ── invokeTool: unknown ids ─────────────────────────────────────

	public function testInvokeUnknownToolIdThrows(): void {
		$provider = $this->provider([]);

		$this->auditTrailMapper->expects($this->never())->method('createToolInvocationEntry');

		$this->expectException(InvalidArgumentException::class);
		$provider->invokeTool('pipelinq.lead.search', []);

	}//end testInvokeUnknownToolIdThrows()

	// ── invokeTool: search ───────────────────────────────────────────

	private function enabledLeadEntry(array $searchConfig = []): array {
		$schema = $this->mockSchema(
			id: 1,
			slug: 'lead',
			mcpBlock: [
				'enabled' => true,
				'tools' => ['search' => $searchConfig],
			]
		);

		return ['schema' => $schema, 'register' => null];
	}//end enabledLeadEntry()

	public function testSearchRejectsUndeclaredFilter(): void {
		$entry = $this->enabledLeadEntry(['filters' => ['status']]);
		$provider = $this->provider([$entry]);

		$this->auditTrailMapper->expects($this->once())->method('createToolInvocationEntry');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Undeclared search filter');

		$provider->invokeTool('pipelinq.lead.search', ['filters' => ['unknownField' => 'x']]);

	}//end testSearchRejectsUndeclaredFilter()

	public function testSearchAppliesOnlyDeclaredFilter(): void {
		$entry = $this->enabledLeadEntry(['filters' => ['status']]);
		$provider = $this->provider([$entry]);

		$this->objectService->expects($this->once())
			->method('findAll')
			->with($this->callback(function ($config) {
				return $config['filters'] === ['status' => 'open'];
			}))
			->willReturn([]);
		$this->objectService->method('count')->willReturn(0);

		$provider->invokeTool('pipelinq.lead.search', ['filters' => ['status' => 'open']]);

	}//end testSearchAppliesOnlyDeclaredFilter()

	public function testSearchDefaultsToBoundedPageSize(): void {
		$entry = $this->enabledLeadEntry();
		$provider = $this->provider([$entry]);

		$this->objectService->expects($this->once())
			->method('findAll')
			->with($this->callback(fn ($config) => $config['limit'] === 20 && $config['offset'] === 0))
			->willReturn([]);
		$this->objectService->method('count')->willReturn(0);

		$provider->invokeTool('pipelinq.lead.search', []);

	}//end testSearchDefaultsToBoundedPageSize()

	public function testSearchClampsPageSizeToMax(): void {
		$entry = $this->enabledLeadEntry();
		$provider = $this->provider([$entry]);

		$this->objectService->expects($this->once())
			->method('findAll')
			->with($this->callback(fn ($config) => $config['limit'] === 100))
			->willReturn([]);
		$this->objectService->method('count')->willReturn(0);

		$provider->invokeTool('pipelinq.lead.search', ['pageSize' => 5000]);

	}//end testSearchClampsPageSizeToMax()

	public function testSearchAppliesFieldProjection(): void {
		$entry = $this->enabledLeadEntry();
		$provider = $this->provider([$entry]);

		$this->objectService->expects($this->once())
			->method('findAll')
			->with($this->callback(fn ($config) => ($config['fields'] ?? null) === ['name']))
			->willReturn([]);
		$this->objectService->method('count')->willReturn(0);

		$provider->invokeTool('pipelinq.lead.search', ['fields' => ['name']]);

	}//end testSearchAppliesFieldProjection()

	public function testSearchTruncatesLongStringsAndReportsHasMore(): void {
		$entry = $this->enabledLeadEntry();
		$provider = $this->provider([$entry]);

		$longValue = str_repeat('x', 600);
		$this->objectService->method('findAll')->willReturn([$this->mockObject(['id' => 'u1', 'name' => $longValue])]);
		$this->objectService->method('count')->willReturn(50);

		$result = $provider->invokeTool('pipelinq.lead.search', []);

		$this->assertCount(1, $result['results']);
		$this->assertTrue($result['hasMore']);
		$this->assertSame(50, $result['total']);
		$this->assertStringEndsWith('…', $result['results'][0]['name']);
		// Truncated to 500 bytes plus the (multi-byte) ellipsis marker —
		// strictly shorter than the original 600-byte value either way.
		$this->assertLessThan(600, strlen($result['results'][0]['name']));

	}//end testSearchTruncatesLongStringsAndReportsHasMore()

	public function testSearchWritesOneAuditRecordWithDigestNotRawParams(): void {
		$entry = $this->enabledLeadEntry(['filters' => ['status']]);
		$provider = $this->provider([$entry]);

		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->method('count')->willReturn(0);

		$capturedDigest = null;
		$this->auditTrailMapper->expects($this->once())
			->method('createToolInvocationEntry')
			->willReturnCallback(function (
				string $toolId,
				string $paramsDigest,
				array $resultSummary,
				?int $register,
				?int $schema,
				?int $object,
				?string $objectUuid,
			) use (&$capturedDigest) {
				$capturedDigest = $paramsDigest;
				$this->assertSame('pipelinq.lead.search', $toolId);
				$this->assertFalse($resultSummary['isError']);
				return $this->createMock(AuditTrail::class);
			});

		$provider->invokeTool('pipelinq.lead.search', ['filters' => ['status' => 'open']]);

		$this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $capturedDigest);

	}//end testSearchWritesOneAuditRecordWithDigestNotRawParams()

	// ── invokeTool: get ───────────────────────────────────────────────

	public function testGetReturnsSerializedObject(): void {
		$entry = $this->enabledLeadEntry();
		$provider = $this->provider([$entry]);

		$this->objectService->expects($this->once())
			->method('find')
			->with('uuid-1', [], false, null, $entry['schema'], true, true)
			->willReturn($this->mockObject(['id' => 'uuid-1']));

		$result = $provider->invokeTool('pipelinq.lead.get', ['id' => 'uuid-1']);
		$this->assertSame(['id' => 'uuid-1'], $result);

	}//end testGetReturnsSerializedObject()

	public function testGetRequiresId(): void {
		$entry = $this->enabledLeadEntry();
		$provider = $this->provider([$entry]);

		$this->expectException(InvalidArgumentException::class);
		$provider->invokeTool('pipelinq.lead.get', []);

	}//end testGetRequiresId()

	// ── invokeTool: create/update ────────────────────────────────────

	public function testCreateRoutesThroughObjectService(): void {
		$entry = $this->enabledLeadEntry();
		$provider = $this->provider([$entry]);

		$captured = null;
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(function (mixed $object) use (&$captured) {
				$captured = $object;
				return $this->mockObject(['id' => 'new-uuid', 'name' => 'X']);
			});

		$result = $provider->invokeTool('pipelinq.lead.create', ['name' => 'X']);

		$this->assertSame(['name' => 'X'], $captured);
		$this->assertSame(['id' => 'new-uuid', 'name' => 'X'], $result);

	}//end testCreateRoutesThroughObjectService()

	public function testUpdateStripsIdFromPayloadAndPassesUuid(): void {
		$entry = $this->enabledLeadEntry();
		$provider = $this->provider([$entry]);

		$capturedArgs = null;
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(function (mixed ...$args) use (&$capturedArgs) {
				$capturedArgs = $args;
				return $this->mockObject(['id' => 'uuid-2', 'name' => 'Edited']);
			});

		$provider->invokeTool('pipelinq.lead.update', ['id' => 'uuid-2', 'name' => 'Edited']);

		$this->assertSame(['name' => 'Edited'], $capturedArgs[0]);
		$this->assertContains('uuid-2', $capturedArgs);

	}//end testUpdateStripsIdFromPayloadAndPassesUuid()

	// ── invokeTool: delete + RBAC ────────────────────────────────────

	public function testDeleteCallsDeleteObjectAndReturnsConfirmation(): void {
		$entry = $this->enabledLeadEntry();
		$provider = $this->provider([$entry]);

		// Positional, matching ObjectService::deleteObject()'s real parameter
		// order exactly — PHPUnit's with() matches by argument POSITION, not
		// by the name used at the call site, so named args here would
		// silently compare against the wrong position for a signature whose
		// constrained params are not its first N (see testGetReturnsSerializedObject).
		$this->objectService->expects($this->once())->method('deleteObject')->with('uuid-3', null, $entry['schema'], true, true, false);

		$result = $provider->invokeTool('pipelinq.lead.delete', ['id' => 'uuid-3']);
		$this->assertSame(['deleted' => true, 'id' => 'uuid-3'], $result);

	}//end testDeleteCallsDeleteObjectAndReturnsConfirmation()

	public function testUnauthorizedDeleteFailsWithNoBypassAndIsStillAudited(): void {
		$entry = $this->enabledLeadEntry();
		$provider = $this->provider([$entry]);

		$this->objectService->method('deleteObject')->willThrowException(new RuntimeException('Not authorized'));

		$this->auditTrailMapper->expects($this->once())
			->method('createToolInvocationEntry')
			->with(
				'pipelinq.lead.delete',
				$this->anything(),
				$this->callback(function ($summary) {
					return $summary['isError'] === true && $summary['errorClass'] === RuntimeException::class;
				}),
				null,
				1,
				null,
				null
			)
			->willReturn($this->createMock(AuditTrail::class));

		$this->expectException(RuntimeException::class);
		$provider->invokeTool('pipelinq.lead.delete', ['id' => 'uuid-3']);

	}//end testUnauthorizedDeleteFailsWithNoBypassAndIsStillAudited()

	public function testAuditFailureDoesNotMaskSuccessfulResult(): void {
		$entry = $this->enabledLeadEntry();
		$provider = $this->provider([$entry]);

		$this->objectService->method('deleteObject');
		$this->auditTrailMapper->method('createToolInvocationEntry')->willThrowException(new RuntimeException('db down'));

		$result = $provider->invokeTool('pipelinq.lead.delete', ['id' => 'uuid-3']);
		$this->assertSame(['deleted' => true, 'id' => 'uuid-3'], $result);

	}//end testAuditFailureDoesNotMaskSuccessfulResult()
}//end class
