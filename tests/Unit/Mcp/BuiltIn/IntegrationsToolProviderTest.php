<?php

declare(strict_types=1);

/**
 * Unit tests for IntegrationsToolProvider — the MCP discovery surface for
 * the pluggable integration registry (ADR-019 / ADR-022 "MCP discovery").
 *
 * Covers:
 *  - getAppId / namespaced tool id
 *  - action "list-integrations" enumerates registered ids + descriptors
 *  - action "list" delegates to the matched provider->list()
 *  - action "get" delegates to provider->get()
 *  - action "link"/"create" delegate to provider->create()
 *  - unknown integration id / disabled provider raise InvalidArgumentException
 *  - missing required params raise InvalidArgumentException
 *  - unknown action raises InvalidArgumentException
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\BuiltIn
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 */

namespace OCA\OpenRegister\Tests\Unit\Mcp\BuiltIn;

use InvalidArgumentException;
use OCA\OpenRegister\Mcp\BuiltIn\IntegrationsToolProvider;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * In-memory provider stub recording delegated calls.
 */
class _McpStubProvider extends AbstractIntegrationProvider {

	public array $listCalled = [];
	public array $getCalled = [];
	public array $createCalled = [];

	public function __construct(
		private string $id = 'files',
		private ?string $requiredApp = 'files',
		private string $storage = 'magic-column',
		private bool $enabled = true,
	) {
	}//end __construct()

	public function getId(): string {
		return $this->id;
	}//end getId()

	public function getLabel(): string {
		return ucfirst($this->id);
	}//end getLabel()

	public function getIcon(): string {
		return 'Paperclip';
	}//end getIcon()

	public function getRequiredApp(): ?string {
		return $this->requiredApp;
	}//end getRequiredApp()

	public function getStorageStrategy(): string {
		return $this->storage;
	}//end getStorageStrategy()

	public function isEnabled(): bool {
		return $this->enabled;
	}//end isEnabled()

	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		$this->listCalled = compact('register', 'schema', 'objectId', 'filters');
		return [['id' => 'a'], ['id' => 'b']];
	}//end list()

	public function get(string $register, string $schema, string $objectId, string $entityId): array {
		$this->getCalled = compact('register', 'schema', 'objectId', 'entityId');
		return ['id' => $entityId, 'name' => 'thing'];
	}//end get()

	public function create(string $register, string $schema, string $objectId, array $payload): array {
		$this->createCalled = compact('register', 'schema', 'objectId', 'payload');
		return ['id' => 'new-id'];
	}//end create()
}//end class

/**
 * Unit tests for IntegrationsToolProvider.
 */
class IntegrationsToolProviderTest extends TestCase {

	/**
	 * Build a provider over a registry seeded with the given providers.
	 *
	 * @param array<int, AbstractIntegrationProvider> $providers Providers.
	 *
	 * @return IntegrationsToolProvider
	 */
	private function build(array $providers): IntegrationsToolProvider {
		$registry = new IntegrationRegistry(new NullLogger());
		$registry->withProviders($providers);
		return new IntegrationsToolProvider($registry);
	}//end build()

	/**
	 * App id + descriptor namespace.
	 *
	 * @return void
	 */
	public function testToolDescriptor(): void {
		$provider = $this->build([]);

		$this->assertSame('openregister', $provider->getAppId());

		$tools = $provider->getTools();
		$this->assertCount(1, $tools);
		$this->assertSame(IntegrationsToolProvider::TOOL_ID, $tools[0]['id']);
		$this->assertStringStartsWith('openregister.', $tools[0]['id']);
	}//end testToolDescriptor()

	/**
	 * "list-integrations" enumerates registered ids + descriptors.
	 *
	 * @return void
	 */
	public function testListIntegrationsDiscovery(): void {
		$provider = $this->build(
			[
				new _McpStubProvider(id: 'files', requiredApp: 'files'),
				new _McpStubProvider(id: 'calendar', requiredApp: 'calendar'),
			]
		);

		$result = $provider->invokeTool(
			toolId: IntegrationsToolProvider::TOOL_ID,
			arguments: ['action' => 'list-integrations']
		);

		$this->assertSame(['files', 'calendar'], $result['registered']);
		$this->assertCount(2, $result['integrations']);
		$this->assertSame('files', $result['integrations'][0]['id']);
		$this->assertSame('files', $result['integrations'][0]['requiredApp']);
		$this->assertSame('magic-column', $result['integrations'][0]['storageStrategy']);
	}//end testListIntegrationsDiscovery()

	/**
	 * "list" delegates to the matched provider's list().
	 *
	 * @return void
	 */
	public function testListDelegates(): void {
		$stub = new _McpStubProvider(id: 'files');
		$provider = $this->build([$stub]);

		$result = $provider->invokeTool(
			toolId: IntegrationsToolProvider::TOOL_ID,
			arguments: [
				'action' => 'list',
				'integrationId' => 'files',
				'register' => 'reg',
				'schema' => 'sch',
				'objectId' => 'obj-1',
				'filters' => ['_limit' => 5],
			]
		);

		$this->assertSame([['id' => 'a'], ['id' => 'b']], $result['items']);
		$this->assertSame('reg', $stub->listCalled['register']);
		$this->assertSame('obj-1', $stub->listCalled['objectId']);
		$this->assertSame(['_limit' => 5], $stub->listCalled['filters']);
	}//end testListDelegates()

	/**
	 * "get" delegates to provider->get().
	 *
	 * @return void
	 */
	public function testGetDelegates(): void {
		$stub = new _McpStubProvider(id: 'files');
		$provider = $this->build([$stub]);

		$result = $provider->invokeTool(
			toolId: IntegrationsToolProvider::TOOL_ID,
			arguments: [
				'action' => 'get',
				'integrationId' => 'files',
				'register' => 'reg',
				'schema' => 'sch',
				'objectId' => 'obj-1',
				'entityId' => 'ent-9',
			]
		);

		$this->assertSame('ent-9', $result['id']);
		$this->assertSame('ent-9', $stub->getCalled['entityId']);
	}//end testGetDelegates()

	/**
	 * "link" and "create" both delegate to provider->create().
	 *
	 * @return void
	 */
	public function testLinkAndCreateDelegate(): void {
		$stub = new _McpStubProvider(id: 'files');
		$provider = $this->build([$stub]);

		foreach (['link', 'create'] as $action) {
			$result = $provider->invokeTool(
				toolId: IntegrationsToolProvider::TOOL_ID,
				arguments: [
					'action' => $action,
					'integrationId' => 'files',
					'register' => 'reg',
					'schema' => 'sch',
					'objectId' => 'obj-1',
					'payload' => ['name' => 'doc'],
				]
			);

			$this->assertSame('new-id', $result['id']);
			$this->assertSame(['name' => 'doc'], $stub->createCalled['payload']);
		}
	}//end testLinkAndCreateDelegate()

	/**
	 * Unknown integration id raises InvalidArgumentException.
	 *
	 * @return void
	 */
	public function testUnknownIntegrationThrows(): void {
		$provider = $this->build([new _McpStubProvider(id: 'files')]);

		$this->expectException(InvalidArgumentException::class);
		$provider->invokeTool(
			toolId: IntegrationsToolProvider::TOOL_ID,
			arguments: [
				'action' => 'list',
				'integrationId' => 'nope',
				'register' => 'reg',
				'schema' => 'sch',
				'objectId' => 'obj-1',
			]
		);
	}//end testUnknownIntegrationThrows()

	/**
	 * A disabled provider is not invokable.
	 *
	 * @return void
	 */
	public function testDisabledProviderThrows(): void {
		$provider = $this->build([new _McpStubProvider(id: 'files', enabled: false)]);

		$this->expectException(InvalidArgumentException::class);
		$provider->invokeTool(
			toolId: IntegrationsToolProvider::TOOL_ID,
			arguments: [
				'action' => 'list',
				'integrationId' => 'files',
				'register' => 'reg',
				'schema' => 'sch',
				'objectId' => 'obj-1',
			]
		);
	}//end testDisabledProviderThrows()

	/**
	 * Missing required parameter raises InvalidArgumentException.
	 *
	 * @return void
	 */
	public function testMissingParamThrows(): void {
		$provider = $this->build([new _McpStubProvider(id: 'files')]);

		$this->expectException(InvalidArgumentException::class);
		$provider->invokeTool(
			toolId: IntegrationsToolProvider::TOOL_ID,
			arguments: [
				'action' => 'list',
				'integrationId' => 'files',
				// register/schema/objectId omitted.
			]
		);
	}//end testMissingParamThrows()

	/**
	 * Unknown action raises InvalidArgumentException.
	 *
	 * @return void
	 */
	public function testUnknownActionThrows(): void {
		$provider = $this->build([new _McpStubProvider(id: 'files')]);

		$this->expectException(InvalidArgumentException::class);
		$provider->invokeTool(
			toolId: IntegrationsToolProvider::TOOL_ID,
			arguments: ['action' => 'bogus']
		);
	}//end testUnknownActionThrows()
}//end class
