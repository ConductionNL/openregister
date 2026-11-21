<?php

declare(strict_types=1);

/**
 * SchemasToolProvider Unit Tests
 *
 * Exercises the schema CRUD logic that was relocated from
 * McpToolsService::executeSchemas() into the built-in SchemasToolProvider
 * by the ai-chat-companion-orchestrator change.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\BuiltIn
 * @author   OpenRegister Team
 * @license  EUPL-1.2
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Mcp\BuiltIn;

use OCA\OpenRegister\Service\AuthorizationAuditService;
use OCA\OpenRegister\Service\AuthorizationChange;
use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Mcp\BuiltIn\SchemasToolProvider;
use OCA\OpenRegister\Service\Rbac\DefinitionWriteRights;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SchemasToolProvider.
 */
class SchemasToolProviderTest extends TestCase {

	/** @var SchemaMapper&MockObject */
	private $schemaMapper;

	private SchemasToolProvider $provider;

	/** @var AuthorizationAuditService&MockObject */
	private AuthorizationAuditService $audit;

	protected function setUp(): void {
		parent::setUp();
		$this->audit = $this->createMock(AuthorizationAuditService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->provider = new SchemasToolProvider($this->schemaMapper, $this->rightsFor(uid: 'root'), $this->audit, new \Psr\Log\NullLogger());

	}//end setUp()

	/**
	 * Build a mock Schema whose jsonSerialize() returns $data.
	 *
	 * @param array<string, mixed> $data Serialized payload
	 *
	 * @return Schema&MockObject
	 */
	private function mockSchema(array $data): Schema {
		$schema = $this->createMock(Schema::class);
		$schema->method('jsonSerialize')->willReturn($data);
		return $schema;
	}//end mockSchema()

	/**
	 * A provider acting for a plain user in the given groups.
	 *
	 * @param array<int, string> $groups The groups of the user.
	 *
	 * @return SchemasToolProvider
	 */
	private function providerFor(array $groups = []): SchemasToolProvider {
		return new SchemasToolProvider($this->schemaMapper, $this->rightsFor(uid: 'mallory', groups: $groups), $this->audit, new \Psr\Log\NullLogger());
	}//end providerFor()

	/**
	 * A stored schema whose `manage` rule names the group `beheer`.
	 *
	 * @return Schema
	 */
	private function storedSchema(): Schema {
		$schema = new Schema();
		$schema->setId(5);
		$schema->setAuthorization(['manage' => ['beheer'], 'read' => ['hr']]);
		$this->schemaMapper->method('find')->willReturn($schema);

		return $schema;
	}//end storedSchema()

	/**
	 * Rights for a signed-in user: only `root` is an administrator and only the
	 * acting uid is asked about.
	 *
	 * @param string             $uid    The acting user.
	 * @param array<int, string> $groups The groups of the acting user.
	 *
	 * @return DefinitionWriteRights
	 */
	private function rightsFor(string $uid, array $groups = []): DefinitionWriteRights {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $asked): bool => $asked === 'root' && $uid === 'root');
		$groupManager->method('getUserGroupIds')->willReturnCallback(static fn (IUser $asked): array => $asked === $user ? $groups : []);

		return new DefinitionWriteRights($session, $groupManager);
	}//end rightsFor()

	// ── rights ─────────────────────────────────────────────────────

	public function testASignedInUserCannotCreateASchema(): void {
		$this->schemaMapper->expects($this->never())->method('createFromArray');
		$this->expectException(RuntimeException::class);
		$this->providerFor(groups: ['beheer'])->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'create', 'data' => ['title' => 'S']]);
	}//end testASignedInUserCannotCreateASchema()

	public function testAUserOutsideTheManageRuleCannotUpdateASchema(): void {
		$this->storedSchema();
		$this->schemaMapper->expects($this->never())->method('updateFromArray');
		$this->expectException(RuntimeException::class);
		$this->providerFor(groups: ['andere-groep'])->invokeTool(
			SchemasToolProvider::TOOL_ID,
			['action' => 'update', 'id' => 5, 'data' => ['authorization' => ['read' => ['public']]]]
		);
	}//end testAUserOutsideTheManageRuleCannotUpdateASchema()

	public function testAUserOutsideTheManageRuleCannotDeleteASchema(): void {
		$this->storedSchema();
		$this->schemaMapper->expects($this->never())->method('delete');
		$this->expectException(RuntimeException::class);
		$this->providerFor()->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'delete', 'id' => 5]);
	}//end testAUserOutsideTheManageRuleCannotDeleteASchema()

	public function testAManagerMayDeleteASchema(): void {
		$schema = $this->storedSchema();
		$this->schemaMapper->expects($this->once())->method('delete')->with($schema);
		$this->providerFor(groups: ['beheer'])->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'delete', 'id' => 5]);
	}//end testAManagerMayDeleteASchema()

	public function testAManagerMayUpdateButNotMoveASchema(): void {
		$schema = $this->storedSchema();
		$this->schemaMapper->expects($this->once())
			->method('updateFromArray')
			->with(id: 5, object: ['title' => 'Edited'])
			->willReturn($schema);

		$this->providerFor(groups: ['beheer'])->invokeTool(
			SchemasToolProvider::TOOL_ID,
			['action' => 'update', 'id' => 5, 'data' => ['title' => 'Edited', 'id' => 9, 'organisation' => 'other', 'owner' => 'mallory', 'created' => '2000-01-01']]
		);
	}//end testAManagerMayUpdateButNotMoveASchema()

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function markedBlocks(): array {
		$block = ['manage' => ['beheer'], 'read' => ['hr', ['group' => 'public', 'crossOrganisation' => true]]];

		return ['as an array' => [$block], 'as JSON text' => [json_encode($block)]];
	}//end markedBlocks()

	#[DataProvider('markedBlocks')]
	public function testAManagerCannotAddACrossOrganisationMarker(mixed $block): void {
		$this->storedSchema();
		$this->schemaMapper->expects($this->never())->method('updateFromArray');
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('crossOrganisation');
		$this->providerFor(groups: ['beheer'])->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'update', 'id' => 5, 'data' => ['authorization' => $block]]);
	}//end testAManagerCannotAddACrossOrganisationMarker()

	public function testATextThatIsNoJsonBlockIsPassedOnAsSent(): void {
		$schema = $this->storedSchema();
		$this->schemaMapper->expects($this->once())
			->method('updateFromArray')
			->with(id: 5, object: ['authorization' => 'admin'])
			->willReturn($schema);

		$this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'update', 'id' => 5, 'data' => ['authorization' => 'admin']]);
	}//end testATextThatIsNoJsonBlockIsPassedOnAsSent()

	public function testAManagerMayKeepAMarkerAnAdministratorSet(): void {
		$schema = $this->storedSchema();
		$block = ['manage' => ['beheer'], 'read' => [['group' => 'public', 'crossOrganisation' => true]]];
		$schema->setAuthorization($block);
		$this->schemaMapper->expects($this->once())->method('updateFromArray')->willReturn($schema);

		$this->providerFor(groups: ['beheer'])->invokeTool(
			SchemasToolProvider::TOOL_ID,
			['action' => 'update', 'id' => 5, 'data' => ['authorization' => $block]]
		);
	}//end testAManagerMayKeepAMarkerAnAdministratorSet()

	#[DataProvider('markedBlocks')]
	public function testAnAdministratorMayAddACrossOrganisationMarker(mixed $block): void {
		$schema = $this->storedSchema();
		$stored = ['manage' => ['beheer'], 'read' => ['hr', ['group' => 'public', 'crossOrganisation' => true]]];
		$this->schemaMapper->expects($this->once())
			->method('updateFromArray')
			->with(id: 5, object: ['authorization' => $stored])
			->willReturn($schema);

		$this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'update', 'id' => 5, 'data' => ['authorization' => $block]]);
	}//end testAnAdministratorMayAddACrossOrganisationMarker()

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function writes(): array {
		return ['create' => ['create'], 'update' => ['update']];
	}//end writes()

	#[DataProvider('writes')]
	public function testAnExternalGrantWithoutAnEndIsRefused(string $action): void {
		$this->storedSchema();
		$this->schemaMapper->expects($this->never())->method('createFromArray');
		$this->schemaMapper->expects($this->never())->method('updateFromArray');
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('external grant');
		$this->provider->invokeTool(
			SchemasToolProvider::TOOL_ID,
			['action' => $action, 'id' => 5, 'data' => ['authorization' => ['read' => [['group' => 'ghost', 'external' => true]]]]]
		);
	}//end testAnExternalGrantWithoutAnEndIsRefused()

	// ── descriptor surface ─────────────────────────────────────────

	public function testGetAppIdIsOpenregister(): void {
		$this->assertSame('openregister', $this->provider->getAppId());

	}//end testGetAppIdIsOpenregister()

	public function testGetToolsReturnsOneNamespacedDescriptor(): void {
		$tools = $this->provider->getTools();

		$this->assertCount(1, $tools);
		$this->assertSame('openregister.schemas', $tools[0]['id']);
		$this->assertSame(SchemasToolProvider::TOOL_ID, $tools[0]['id']);
		$this->assertNotEmpty($tools[0]['description']);
		$this->assertSame('object', $tools[0]['inputSchema']['type']);
		$this->assertArrayHasKey('action', $tools[0]['inputSchema']['properties']);
		$this->assertSame(['action'], $tools[0]['inputSchema']['required']);

	}//end testGetToolsReturnsOneNamespacedDescriptor()

	// ── list ───────────────────────────────────────────────────────

	public function testListReturnsSerializedSchemas(): void {
		$this->schemaMapper->expects($this->once())
			->method('findAll')
			->with(limit: null, offset: null)
			->willReturn([$this->mockSchema(['id' => 1]), $this->mockSchema(['id' => 2])]);

		$this->assertSame([['id' => 1], ['id' => 2]], $this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'list']));

	}//end testListReturnsSerializedSchemas()

	public function testListPassesLimitAndOffset(): void {
		$this->schemaMapper->expects($this->once())->method('findAll')->with(limit: 3, offset: 6)->willReturn([]);

		$this->assertSame([], $this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'list', 'limit' => 3, 'offset' => 6]));

	}//end testListPassesLimitAndOffset()

	public function testListReturnsEmptyArrayWhenNoSchemas(): void {
		$this->schemaMapper->method('findAll')->willReturn([]);
		$this->assertSame([], $this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'list']));

	}//end testListReturnsEmptyArrayWhenNoSchemas()

	// ── get ────────────────────────────────────────────────────────

	public function testGetReturnsSerializedSchema(): void {
		$this->schemaMapper->expects($this->once())->method('find')->with(11)->willReturn($this->mockSchema(['id' => 11]));

		$this->assertSame(['id' => 11], $this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'get', 'id' => 11]));

	}//end testGetReturnsSerializedSchema()

	public function testGetRequiresId(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Missing required parameter: id');
		$this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'get']);

	}//end testGetRequiresId()

	// ── create ─────────────────────────────────────────────────────

	public function testCreateReturnsSerializedSchema(): void {
		$this->schemaMapper->expects($this->once())
			->method('createFromArray')
			->with(object: ['title' => 'S'])
			->willReturn($this->mockSchema(['id' => 12, 'title' => 'S']));

		$this->assertSame(['id' => 12, 'title' => 'S'], $this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'create', 'data' => ['title' => 'S']]));

	}//end testCreateReturnsSerializedSchema()

	public function testCreateRequiresData(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Missing required parameter: data');
		$this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'create']);

	}//end testCreateRequiresData()

	// ── update ─────────────────────────────────────────────────────

	public function testUpdateReturnsSerializedSchema(): void {
		$this->schemaMapper->expects($this->once())
			->method('updateFromArray')
			->with(id: 5, object: ['title' => 'Edited'])
			->willReturn($this->mockSchema(['id' => 5, 'title' => 'Edited']));

		$this->assertSame(['id' => 5, 'title' => 'Edited'], $this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'update', 'id' => 5, 'data' => ['title' => 'Edited']]));

	}//end testUpdateReturnsSerializedSchema()

	public function testUpdateRequiresId(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Missing required parameter: id');
		$this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'update', 'data' => []]);

	}//end testUpdateRequiresId()

	public function testUpdateRequiresData(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Missing required parameter: data');
		$this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'update', 'id' => 5]);

	}//end testUpdateRequiresData()

	// ── delete ─────────────────────────────────────────────────────

	public function testDeleteFindsThenDeletesAndReturnsConfirmation(): void {
		$schema = $this->mockSchema(['id' => 8]);
		$this->schemaMapper->expects($this->once())->method('find')->with(8)->willReturn($schema);
		$this->schemaMapper->expects($this->once())->method('delete')->with($schema);

		$this->assertSame(['deleted' => true, 'id' => 8], $this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'delete', 'id' => 8]));

	}//end testDeleteFindsThenDeletesAndReturnsConfirmation()

	public function testDeleteRequiresId(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Missing required parameter: id');
		$this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'delete']);

	}//end testDeleteRequiresId()

	// ── unknown / missing action ───────────────────────────────────

	public function testUnknownActionThrows(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown action: bogus');
		$this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'bogus']);

	}//end testUnknownActionThrows()

	public function testMissingActionThrows(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->provider->invokeTool(SchemasToolProvider::TOOL_ID, []);

	}//end testMissingActionThrows()

	public function testAnMcpUpdateByANonAdministratorMayNotSetOrphanCleanup(): void {
		$this->schemaMapper->method('find')->willReturn($this->storedSchema());
		$this->schemaMapper->expects($this->never())->method('updateFromArray');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('orphanCleanup');
		$this->providerFor(groups: ['beheer'])->invokeTool('schemas', ['action' => 'update', 'id' => 1, 'data' => ['configuration' => ['orphanCleanup' => true]]]);
	}

	public function testAnMcpCreateByANonAdministratorMayNotSetOrphanCleanup(): void {
		$this->schemaMapper->expects($this->never())->method('createFromArray');

		$this->expectException(\RuntimeException::class);
		$this->providerFor(groups: [])->invokeTool('schemas', ['action' => 'create', 'data' => ['configuration' => ['orphanCleanup' => true]]]);
	}
	public function testAnAuthorizationChangeIsAudited(): void {
		$stored = $this->storedSchema();
		$saved = new Schema();
		$saved->setId(5);
		$saved->setTitle('Edited');
		$saved->setAuthorization(['manage' => ['beheer'], 'read' => ['beheer']]);
		$this->schemaMapper->method('updateFromArray')->willReturn($saved);
		$this->audit->expects($this->once())->method('recordSchemaChange')->with(
			$this->callback(static fn (AuthorizationChange $change): bool => $change->entityId === 5
				&& $change->before === $stored->getAuthorization()
				&& $change->after === ['manage' => ['beheer'], 'read' => ['beheer']])
		);

		$this->providerFor(groups: ['beheer'])->invokeTool(
			SchemasToolProvider::TOOL_ID,
			['action' => 'update', 'id' => 5, 'data' => ['authorization' => ['manage' => ['beheer'], 'read' => ['beheer']]]]
		);
	}//end testAnAuthorizationChangeIsAudited()

	public function testAnUpdateWithoutAuthorizationIsNotAudited(): void {
		$stored = $this->storedSchema();
		$this->schemaMapper->method('updateFromArray')->willReturn($stored);
		$this->audit->expects($this->never())->method('recordSchemaChange');

		$this->providerFor(groups: ['beheer'])->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'update', 'id' => 5, 'data' => ['title' => 'Edited']]);
	}//end testAnUpdateWithoutAuthorizationIsNotAudited()

	public function testInternalParametersAreNotWritten(): void {
		$stored = $this->storedSchema();
		$this->schemaMapper->expects($this->once())
			->method('updateFromArray')
			->with(id: 5, object: ['title' => 'Edited'])
			->willReturn($stored);

		$this->providerFor(groups: ['beheer'])->invokeTool(
			SchemasToolProvider::TOOL_ID,
			['action' => 'update', 'id' => 5, 'data' => ['title' => 'Edited', '_rbac' => false, '_route' => 'x']]
		);
	}//end testInternalParametersAreNotWritten()
}//end class
