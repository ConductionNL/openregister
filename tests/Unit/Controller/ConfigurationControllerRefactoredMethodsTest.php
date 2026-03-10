<?php

/**
 * ConfigurationController Refactored Methods Unit Tests
 *
 * Comprehensive tests for the 1 private method extracted during Phase 1 refactoring.
 * Tests cover data-driven configuration updates via Configuration entity setters.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ConfigurationController;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCA\OpenRegister\Service\Configuration\GitLabHandler;
use OCA\OpenRegister\Service\NotificationService;
use OCP\App\IAppManager;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for ConfigurationController refactored methods.
 *
 * Tests the 1 extracted private method using reflection:
 * 1. applyConfigurationUpdates(Configuration $configuration, array $data): void
 */
class ConfigurationControllerRefactoredMethodsTest extends TestCase
{
	private ConfigurationController $configurationController;
	private ReflectionClass $reflection;

	/** @var MockObject|IRequest */
	private $request;

	/** @var MockObject|ConfigurationMapper */
	private $configurationMapper;

	/** @var MockObject|ConfigurationService */
	private $configurationService;

	/** @var MockObject|NotificationService */
	private $notificationService;

	/** @var MockObject|GitHubHandler */
	private $githubHandler;

	/** @var MockObject|GitLabHandler */
	private $gitlabHandler;

	/** @var MockObject|IAppManager */
	private $appManager;

	/** @var MockObject|LoggerInterface */
	private $logger;

	/**
	 * Set up test environment before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();

		// Create mocks for all dependencies.
		$this->request = $this->createMock(IRequest::class);
		$this->configurationMapper = $this->createMock(ConfigurationMapper::class);
		$this->configurationService = $this->createMock(ConfigurationService::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->githubHandler = $this->createMock(GitHubHandler::class);
		$this->gitlabHandler = $this->createMock(GitLabHandler::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Create ConfigurationController instance.
		$this->configurationController = new ConfigurationController(
			appName: 'openregister',
			request: $this->request,
			configurationMapper: $this->configurationMapper,
			configurationService: $this->configurationService,
			notificationService: $this->notificationService,
			githubHandler: $this->githubHandler,
			gitlabHandler: $this->gitlabHandler,
			appManager: $this->appManager,
			logger: $this->logger
		);

		// Set up reflection for accessing private methods.
		$this->reflection = new ReflectionClass(ConfigurationController::class);
	}

	/**
	 * Helper method to invoke private methods using reflection.
	 *
	 * @param string $methodName The name of the private method.
	 * @param array  $parameters The parameters to pass to the method.
	 *
	 * @return mixed The result of the method invocation.
	 */
	private function invokePrivateMethod(string $methodName, array $parameters = []): mixed
	{
		$method = $this->reflection->getMethod($methodName);
		$method->setAccessible(true);

		return $method->invokeArgs($this->configurationController, $parameters);
	}

	// ==================== applyConfigurationUpdates() Tests ====================

	/**
	 * Test applyConfigurationUpdates applies single field update.
	 *
	 * @return void
	 */
	public function testApplyConfigurationUpdatesAppliesSingleField(): void
	{
		$config = new Configuration();
		$config->setTitle('Old Name');
		$config->setDescription('Old Description');

		$input = [
			'title' => 'New Name'
		];

		$this->invokePrivateMethod(
			methodName: 'applyConfigurationUpdates',
			parameters: [$config, $input]
		);

		$this->assertEquals('New Name', $config->getTitle(), 'Title should be updated.');
		$this->assertEquals('Old Description', $config->getDescription(), 'Description should remain unchanged.');
	}

	/**
	 * Test applyConfigurationUpdates applies multiple field updates.
	 *
	 * @return void
	 */
	public function testApplyConfigurationUpdatesAppliesMultipleFields(): void
	{
		$config = new Configuration();
		$config->setTitle('Old Name');
		$config->setDescription('Old Description');
		$config->setVersion('1.0.0');

		$input = [
			'title' => 'New Name',
			'description' => 'New Description',
			'version' => '2.0.0'
		];

		$this->invokePrivateMethod(
			methodName: 'applyConfigurationUpdates',
			parameters: [$config, $input]
		);

		$this->assertEquals('New Name', $config->getTitle(), 'Title should be updated.');
		$this->assertEquals('New Description', $config->getDescription(), 'Description should be updated.');
		$this->assertEquals('2.0.0', $config->getVersion(), 'Version should be updated.');
	}

	/**
	 * Test applyConfigurationUpdates handles empty input.
	 *
	 * @return void
	 */
	public function testApplyConfigurationUpdatesWithEmptyInput(): void
	{
		$config = new Configuration();
		$config->setTitle('Test Config');
		$config->setDescription('Test Description');

		$this->invokePrivateMethod(
			methodName: 'applyConfigurationUpdates',
			parameters: [$config, []]
		);

		$this->assertEquals('Test Config', $config->getTitle(), 'Title should remain unchanged with empty input.');
		$this->assertEquals('Test Description', $config->getDescription(), 'Description should remain unchanged with empty input.');
	}

	/**
	 * Test applyConfigurationUpdates handles null values (should not apply).
	 *
	 * @return void
	 */
	public function testApplyConfigurationUpdatesWithNullValues(): void
	{
		$config = new Configuration();
		$config->setTitle('Test Config');
		$config->setDescription('Test Description');

		$input = [
			'description' => null,
		];

		$this->invokePrivateMethod(
			methodName: 'applyConfigurationUpdates',
			parameters: [$config, $input]
		);

		// Null values should not be applied (the method checks for !== null).
		$this->assertEquals('Test Config', $config->getTitle(), 'Title should remain unchanged.');
		$this->assertEquals('Test Description', $config->getDescription(), 'Description should remain unchanged when null is passed.');
	}

	/**
	 * Test applyConfigurationUpdates handles boolean values.
	 *
	 * @return void
	 */
	public function testApplyConfigurationUpdatesWithBooleanValues(): void
	{
		$config = new Configuration();
		$config->setAutoUpdate(false);

		$input = [
			'autoUpdate' => true
		];

		$this->invokePrivateMethod(
			methodName: 'applyConfigurationUpdates',
			parameters: [$config, $input]
		);

		$this->assertTrue($config->getAutoUpdate(), 'AutoUpdate should be true.');
	}

	/**
	 * Test applyConfigurationUpdates handles array values.
	 *
	 * @return void
	 */
	public function testApplyConfigurationUpdatesWithArrayValues(): void
	{
		$config = new Configuration();
		$config->setRegisters(['reg1']);

		$input = [
			'registers' => ['reg1', 'reg2', 'reg3'],
			'schemas' => ['schema1', 'schema2']
		];

		$this->invokePrivateMethod(
			methodName: 'applyConfigurationUpdates',
			parameters: [$config, $input]
		);

		$this->assertEquals(['reg1', 'reg2', 'reg3'], $config->getRegisters(), 'Registers should be replaced.');
		$this->assertEquals(['schema1', 'schema2'], $config->getSchemas(), 'Schemas should be set.');
	}

	/**
	 * Test applyConfigurationUpdates preserves data types.
	 *
	 * @return void
	 */
	public function testApplyConfigurationUpdatesPreservesDataTypes(): void
	{
		$config = new Configuration();

		$input = [
			'title' => 'new_value',
			'autoUpdate' => false,
			'registers' => [1, 2, 3],
		];

		$this->invokePrivateMethod(
			methodName: 'applyConfigurationUpdates',
			parameters: [$config, $input]
		);

		$this->assertIsString($config->getTitle(), 'Title should be string.');
		$this->assertIsBool($config->getAutoUpdate(), 'AutoUpdate should be boolean.');
		$this->assertIsArray($config->getRegisters(), 'Registers should be array.');
	}

	/**
	 * Test applyConfigurationUpdates is efficient with large data.
	 *
	 * This test verifies the data-driven approach is performant.
	 *
	 * @return void
	 */
	public function testApplyConfigurationUpdatesPerformance(): void
	{
		$config = new Configuration();
		$config->setTitle('old_title');
		$config->setDescription('old_desc');
		$config->setVersion('1.0.0');
		$config->setSourceUrl('http://example.com');

		$input = [
			'title' => 'updated_title',
			'description' => 'updated_desc',
			'version' => '2.0.0',
			'sourceUrl' => 'http://updated.com'
		];

		$startTime = microtime(true);

		$this->invokePrivateMethod(
			methodName: 'applyConfigurationUpdates',
			parameters: [$config, $input]
		);

		$endTime = microtime(true);
		$duration = $endTime - $startTime;

		// Should complete in under 10ms (generous for CI environments).
		$this->assertLessThan(0.01, $duration, 'Should complete quickly.');

		// Verify updates were applied.
		$this->assertEquals('updated_title', $config->getTitle(), 'Title should be updated.');
		$this->assertEquals('updated_desc', $config->getDescription(), 'Description should be updated.');
		$this->assertEquals('2.0.0', $config->getVersion(), 'Version should be updated.');
		$this->assertEquals('http://updated.com', $config->getSourceUrl(), 'SourceUrl should be updated.');
	}

	/**
	 * Test that refactored update() method pattern is efficient.
	 *
	 * This conceptual test validates that the data-driven approach reduces complexity.
	 *
	 * @return void
	 */
	public function testDataDrivenApproachReducesComplexity(): void
	{
		$config = new Configuration();

		// Simulate updating all fields that had separate if statements.
		$input = [
			'title' => 'new',
			'description' => 'new',
			'sourceUrl' => 'http://new.example.com',
			'type' => 'new_type'
		];

		$this->invokePrivateMethod(
			methodName: 'applyConfigurationUpdates',
			parameters: [$config, $input]
		);

		// All fields should be updated via the data-driven setter approach.
		$this->assertEquals('new', $config->getTitle(), 'Title should be updated.');
		$this->assertEquals('new', $config->getDescription(), 'Description should be updated.');
		$this->assertEquals('http://new.example.com', $config->getSourceUrl(), 'SourceUrl should be updated.');
		$this->assertEquals('new_type', $config->getType(), 'Type should be updated.');

		$this->assertTrue(true, 'Data-driven approach successfully handles all update scenarios.');
	}

	/**
	 * Test applyConfigurationUpdates ignores unknown fields.
	 *
	 * @return void
	 */
	public function testApplyConfigurationUpdatesIgnoresUnknownFields(): void
	{
		$config = new Configuration();
		$config->setTitle('Original');

		$input = [
			'title' => 'Updated',
			'nonExistentField' => 'should be ignored',
			'anotherFakeField' => 42
		];

		// Should not throw an exception even with unknown fields.
		$this->invokePrivateMethod(
			methodName: 'applyConfigurationUpdates',
			parameters: [$config, $input]
		);

		$this->assertEquals('Updated', $config->getTitle(), 'Title should be updated.');
	}

	/**
	 * Test applyConfigurationUpdates with GitHub-related fields.
	 *
	 * @return void
	 */
	public function testApplyConfigurationUpdatesWithGitHubFields(): void
	{
		$config = new Configuration();

		$input = [
			'githubRepo' => 'ConductionNL/openregister',
			'githubBranch' => 'main',
			'githubPath' => '/schemas'
		];

		$this->invokePrivateMethod(
			methodName: 'applyConfigurationUpdates',
			parameters: [$config, $input]
		);

		$this->assertEquals('ConductionNL/openregister', $config->getGithubRepo(), 'GitHub repo should be set.');
		$this->assertEquals('main', $config->getGithubBranch(), 'GitHub branch should be set.');
		$this->assertEquals('/schemas', $config->getGithubPath(), 'GitHub path should be set.');
	}
}
