<?php

declare(strict_types=1);

/**
 * RegistersToolProvider Unit Tests
 *
 * Exercises the register CRUD logic that was relocated from
 * McpToolsService::executeRegisters() into the built-in
 * RegistersToolProvider by the ai-chat-companion-orchestrator change.
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
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Mcp\BuiltIn\RegistersToolProvider;
use OCA\OpenRegister\Service\Rbac\DefinitionWriteRights;
use OCA\OpenRegister\Service\RegisterService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for RegistersToolProvider.
 */
class RegistersToolProviderTest extends TestCase {

	/** @var RegisterService&MockObject */
	private $registerService;

	private RegistersToolProvider $provider;

	/** @var AuthorizationAuditService&MockObject */
	private AuthorizationAuditService $audit;

	protected function setUp(): void {
		parent::setUp();
		$this->audit = $this->createMock(AuthorizationAuditService::class);
		$this->registerService = $this->createMock(RegisterService::class);
		$this->provider = new RegistersToolProvider($this->registerService, $this->rightsFor(uid: 'root'), $this->audit, new \Psr\Log\NullLogger());

	}//end setUp()

	/**
	 * Build a mock Register whose jsonSerialize() returns $data.
	 *
	 * @param array<string, mixed> $data Serialized payload
	 *
	 * @return Register&MockObject
	 */
	private function mockRegister(array $data): Register {
		$register = $this->createMock(Register::class);
		$register->method('jsonSerialize')->willReturn($data);
		return $register;
	}//end mockRegister()

	/**
	 * A provider acting for a plain user in the given groups.
	 *
	 * @param array<int, string> $groups The groups of the user.
	 *
	 * @return RegistersToolProvider
	 */
	private function providerFor(array $groups = []): RegistersToolProvider {
		return new RegistersToolProvider($this->registerService, $this->rightsFor(uid: 'mallory', groups: $groups), $this->audit, new \Psr\Log\NullLogger());
	}//end providerFor()

	/**
	 * A stored register whose `manage` rule names the group `beheer`.
	 *
	 * @return Register
	 */
	private function storedRegister(): Register {
		$register = new Register();
		$register->setId(5);
		$register->setAuthorization(['manage' => ['beheer']]);
		$this->registerService->method('find')->willReturn($register);

		return $register;
	}//end storedRegister()

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

	public function testASignedInUserCannotCreateARegister(): void {
		$this->registerService->expects($this->never())->method('createFromArray');
		$this->expectException(RuntimeException::class);
		$this->providerFor(groups: ['beheer'])->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'create', 'data' => ['title' => 'R']]);
	}//end testASignedInUserCannotCreateARegister()

	public function testAUserOutsideTheManageRuleCannotUpdateARegister(): void {
		$this->storedRegister();
		$this->registerService->expects($this->never())->method('updateFromArray');
		$this->expectException(RuntimeException::class);
		$this->providerFor()->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'update', 'id' => 5, 'data' => ['title' => 'R']]);
	}//end testAUserOutsideTheManageRuleCannotUpdateARegister()

	public function testAUserOutsideTheManageRuleCannotDeleteARegister(): void {
		$this->storedRegister();
		$this->registerService->expects($this->never())->method('delete');
		$this->expectException(RuntimeException::class);
		$this->providerFor()->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'delete', 'id' => 5]);
	}//end testAUserOutsideTheManageRuleCannotDeleteARegister()

	public function testAManagerMayUpdateButNotMoveARegister(): void {
		$register = $this->storedRegister();
		$this->registerService->expects($this->once())
			->method('updateFromArray')
			->with(id: 5, data: ['title' => 'Edited'])
			->willReturn($register);

		$this->providerFor(groups: ['beheer'])->invokeTool(
			RegistersToolProvider::TOOL_ID,
			['action' => 'update', 'id' => 5, 'data' => ['title' => 'Edited', 'id' => 9, 'organisation' => 'other', 'owner' => 'mallory', 'created' => '2000-01-01']]
		);
	}//end testAManagerMayUpdateButNotMoveARegister()

	public function testAManagerMayDeleteARegister(): void {
		$register = $this->storedRegister();
		$this->registerService->expects($this->once())->method('delete')->with(register: $register);
		$this->providerFor(groups: ['beheer'])->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'delete', 'id' => 5]);
	}//end testAManagerMayDeleteARegister()

	// ── descriptor surface ─────────────────────────────────────────

	public function testGetAppIdIsOpenregister(): void {
		$this->assertSame('openregister', $this->provider->getAppId());

	}//end testGetAppIdIsOpenregister()

	public function testGetToolsReturnsOneNamespacedDescriptor(): void {
		$tools = $this->provider->getTools();

		$this->assertCount(1, $tools);
		$this->assertSame('openregister.registers', $tools[0]['id']);
		$this->assertSame(RegistersToolProvider::TOOL_ID, $tools[0]['id']);
		$this->assertNotEmpty($tools[0]['description']);
		$this->assertSame('object', $tools[0]['inputSchema']['type']);
		$this->assertArrayHasKey('action', $tools[0]['inputSchema']['properties']);
		$this->assertSame(['action'], $tools[0]['inputSchema']['required']);

	}//end testGetToolsReturnsOneNamespacedDescriptor()

	// ── list ───────────────────────────────────────────────────────

	public function testListReturnsSerializedRegisters(): void {
		$this->registerService->expects($this->once())
			->method('findAll')
			->with(limit: null, offset: null)
			->willReturn([$this->mockRegister(['id' => 1]), $this->mockRegister(['id' => 2])]);

		$result = $this->provider->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'list']);

		$this->assertSame([['id' => 1], ['id' => 2]], $result);

	}//end testListReturnsSerializedRegisters()

	public function testListPassesLimitAndOffset(): void {
		$this->registerService->expects($this->once())
			->method('findAll')
			->with(limit: 5, offset: 10)
			->willReturn([]);

		$this->assertSame([], $this->provider->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'list', 'limit' => 5, 'offset' => 10]));

	}//end testListPassesLimitAndOffset()

	public function testListReturnsEmptyArrayWhenNoRegisters(): void {
		$this->registerService->method('findAll')->willReturn([]);
		$this->assertSame([], $this->provider->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'list']));

	}//end testListReturnsEmptyArrayWhenNoRegisters()

	// ── get ────────────────────────────────────────────────────────

	public function testGetReturnsSerializedRegister(): void {
		$this->registerService->expects($this->once())->method('find')->with(id: 7)->willReturn($this->mockRegister(['id' => 7, 'title' => 'R']));

		$this->assertSame(['id' => 7, 'title' => 'R'], $this->provider->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'get', 'id' => 7]));

	}//end testGetReturnsSerializedRegister()

	public function testGetRequiresId(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Missing required parameter: id');
		$this->provider->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'get']);

	}//end testGetRequiresId()

	// ── create ─────────────────────────────────────────────────────

	public function testCreateReturnsSerializedRegister(): void {
		$this->registerService->expects($this->once())
			->method('createFromArray')
			->with(data: ['title' => 'New'])
			->willReturn($this->mockRegister(['id' => 9, 'title' => 'New']));

		$this->assertSame(['id' => 9, 'title' => 'New'], $this->provider->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'create', 'data' => ['title' => 'New']]));

	}//end testCreateReturnsSerializedRegister()

	public function testCreateRequiresData(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Missing required parameter: data');
		$this->provider->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'create']);

	}//end testCreateRequiresData()

	// ── update ─────────────────────────────────────────────────────

	public function testUpdateReturnsSerializedRegister(): void {
		$this->registerService->expects($this->once())
			->method('updateFromArray')
			->with(id: 3, data: ['title' => 'Edited'])
			->willReturn($this->mockRegister(['id' => 3, 'title' => 'Edited']));

		$this->assertSame(['id' => 3, 'title' => 'Edited'], $this->provider->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'update', 'id' => 3, 'data' => ['title' => 'Edited']]));

	}//end testUpdateReturnsSerializedRegister()

	public function testUpdateRequiresId(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Missing required parameter: id');
		$this->provider->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'update', 'data' => []]);

	}//end testUpdateRequiresId()

	public function testUpdateRequiresData(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Missing required parameter: data');
		$this->provider->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'update', 'id' => 3]);

	}//end testUpdateRequiresData()

	// ── delete ─────────────────────────────────────────────────────

	public function testDeleteFindsThenDeletesAndReturnsConfirmation(): void {
		$register = $this->mockRegister(['id' => 4]);
		$this->registerService->expects($this->once())->method('find')->with(id: 4)->willReturn($register);
		$this->registerService->expects($this->once())->method('delete')->with(register: $register);

		$this->assertSame(['deleted' => true, 'id' => 4], $this->provider->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'delete', 'id' => 4]));

	}//end testDeleteFindsThenDeletesAndReturnsConfirmation()

	public function testDeleteRequiresId(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Missing required parameter: id');
		$this->provider->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'delete']);

	}//end testDeleteRequiresId()

	// ── unknown / missing action ───────────────────────────────────

	public function testUnknownActionThrows(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown action: frobnicate');
		$this->provider->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'frobnicate']);

	}//end testUnknownActionThrows()

	public function testMissingActionThrows(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->provider->invokeTool(RegistersToolProvider::TOOL_ID, []);

	}//end testMissingActionThrows()
	public function testAnAuthorizationChangeIsAudited(): void {
		$stored = $this->storedRegister();
		$saved = new Register();
		$saved->setId(5);
		$saved->setTitle('Edited');
		$saved->setAuthorization(['manage' => ['beheer'], 'read' => ['beheer']]);
		$this->registerService->method('updateFromArray')->willReturn($saved);
		$this->audit->expects($this->once())->method('recordRegisterChange')->with(
			$this->callback(static fn (AuthorizationChange $change): bool => $change->entityId === 5
				&& $change->before === $stored->getAuthorization()
				&& $change->after === ['manage' => ['beheer'], 'read' => ['beheer']])
		);

		$this->providerFor(groups: ['beheer'])->invokeTool(
			RegistersToolProvider::TOOL_ID,
			['action' => 'update', 'id' => 5, 'data' => ['authorization' => ['manage' => ['beheer'], 'read' => ['beheer']]]]
		);
	}//end testAnAuthorizationChangeIsAudited()

	public function testAnUpdateWithoutAuthorizationIsNotAudited(): void {
		$stored = $this->storedRegister();
		$this->registerService->method('updateFromArray')->willReturn($stored);
		$this->audit->expects($this->never())->method('recordRegisterChange');

		$this->providerFor(groups: ['beheer'])->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'update', 'id' => 5, 'data' => ['title' => 'Edited']]);
	}//end testAnUpdateWithoutAuthorizationIsNotAudited()

	public function testInternalParametersAreNotWritten(): void {
		$stored = $this->storedRegister();
		$this->registerService->expects($this->once())
			->method('updateFromArray')
			->with(id: 5, data: ['title' => 'Edited'])
			->willReturn($stored);

		$this->providerFor(groups: ['beheer'])->invokeTool(
			RegistersToolProvider::TOOL_ID,
			['action' => 'update', 'id' => 5, 'data' => ['title' => 'Edited', '_rbac' => false, '_route' => 'x']]
		);
	}//end testInternalParametersAreNotWritten()
}//end class
