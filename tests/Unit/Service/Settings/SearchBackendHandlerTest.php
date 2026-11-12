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
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SearchBackendHandler
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Comprehensive coverage requires many test methods
 */
class SearchBackendHandlerTest extends TestCase {
	/** @var SearchBackendHandler */
	private SearchBackendHandler $handler;

	/**
	 * Set up test fixtures
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->handler = new SearchBackendHandler();
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
		$result = $this->handler->getSearchBackendConfig();

		$this->assertSame('database', $result['active']);
		$this->assertSame(['database'], $result['available']);
	}
}
