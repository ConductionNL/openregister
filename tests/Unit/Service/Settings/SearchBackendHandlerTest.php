<?php

declare(strict_types=1);

/**
 * SearchBackendHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Settings
 * @author   OpenRegister Team
 * @license  EUPL-1.2
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use OCA\OpenRegister\Service\Settings\SearchBackendHandler;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SearchBackendHandler
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Comprehensive coverage requires many test methods
 */
class SearchBackendHandlerTest extends TestCase {
	/** @var SearchBackendHandler */
	private SearchBackendHandler $handler;

	/** @var IAppConfig&MockObject */
	private IAppConfig $appConfig;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/**
	 * Set up test fixtures
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->handler = new SearchBackendHandler($this->appConfig, $this->logger, 'openregister');
	}

	/**
	 * Test getSearchBackendConfig always returns database as active backend.
	 *
	 * @return void
	 */
	public function testGetSearchBackendConfigReturnsDefaultWhenEmpty(): void {
		$result = $this->handler->getSearchBackendConfig();

		$this->assertSame('database', $result['active']);
		$this->assertSame(['database'], $result['available']);
		$this->assertCount(1, $result['available']);
	}

	/**
	 * Test getSearchBackendConfig always returns database regardless of stored config.
	 *
	 * @return void
	 */
	public function testGetSearchBackendConfigReturnsDecodedConfig(): void {
		// getSearchBackendConfig ignores appConfig — always returns database.
		$result = $this->handler->getSearchBackendConfig();

		$this->assertSame('database', $result['active']);
		$this->assertSame(['database'], $result['available']);
	}

	/**
	 * Test updateSearchBackendConfig with invalid backend throws InvalidArgumentException.
	 *
	 * @return void
	 */
	public function testUpdateSearchBackendConfigWithInvalidBackendThrowsException(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid backend');

		$this->handler->updateSearchBackendConfig('mongodb');
	}

	/**
	 * Test updateSearchBackendConfig with 'database' is valid and returns correct config.
	 *
	 * @return void
	 */
	public function testUpdateSearchBackendConfigWithDatabase(): void {
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('openregister', 'search_backend', $this->isType('string'));

		$result = $this->handler->updateSearchBackendConfig('database');

		$this->assertSame('database', $result['active']);
		$this->assertSame(['database'], $result['available']);
		$this->assertArrayHasKey('updated', $result);
		$this->assertIsInt($result['updated']);
	}

	/**
	 * Test updateSearchBackendConfig sets updated timestamp.
	 *
	 * @return void
	 */
	public function testUpdateSearchBackendConfigSetsTimestamp(): void {
		$this->appConfig->method('setValueString');

		$before = time();
		$result = $this->handler->updateSearchBackendConfig('database');
		$after = time();

		$this->assertGreaterThanOrEqual($before, $result['updated']);
		$this->assertLessThanOrEqual($after, $result['updated']);
	}

	/**
	 * Test updateSearchBackendConfig logs with correct context.
	 *
	 * @return void
	 */
	public function testUpdateSearchBackendConfigLogsCorrectContext(): void {
		$this->appConfig->method('setValueString');

		$this->logger->expects($this->once())
			->method('info')
			->with(
				$this->anything(),
				$this->callback(function ($context) {
					return isset($context['app'])
						&& $context['app'] === 'openregister';
				})
			);

		$this->handler->updateSearchBackendConfig('database');
	}

	/**
	 * Test updateSearchBackendConfig available backends list contains only database.
	 *
	 * @return void
	 */
	public function testUpdateSearchBackendConfigAvailableBackendsComplete(): void {
		$this->appConfig->method('setValueString');

		$result = $this->handler->updateSearchBackendConfig('database');

		$this->assertCount(1, $result['available']);
		$this->assertSame(['database'], $result['available']);
	}
}
