<?php

declare(strict_types=1);

/**
 * SearchBackendHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Settings
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use OCA\OpenRegister\Service\Settings\SearchBackendHandler;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for SearchBackendHandler
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Comprehensive coverage requires many test methods
 */
class SearchBackendHandlerTest extends TestCase
{
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
    protected function setUp(): void
    {
        parent::setUp();

        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handler = new SearchBackendHandler($this->appConfig, $this->logger, 'openregister');
    }

    /**
     * Test getSearchBackendConfig returns default when empty.
     *
     * @return void
     */
    public function testGetSearchBackendConfigReturnsDefaultWhenEmpty(): void
    {
        $this->appConfig->method('getValueString')
            ->with('openregister', 'search_backend', '')
            ->willReturn('');

        $result = $this->handler->getSearchBackendConfig();

        $this->assertSame('solr', $result['active']);
        $this->assertContains('solr', $result['available']);
        $this->assertContains('elasticsearch', $result['available']);
        $this->assertCount(2, $result['available']);
    }

    /**
     * Test getSearchBackendConfig returns decoded config.
     *
     * @return void
     */
    public function testGetSearchBackendConfigReturnsDecodedConfig(): void
    {
        $config = [
            'active'    => 'elasticsearch',
            'available' => ['solr', 'elasticsearch'],
            'updated'   => 1700000000,
        ];

        $this->appConfig->method('getValueString')
            ->willReturn(json_encode($config));

        $result = $this->handler->getSearchBackendConfig();

        $this->assertSame('elasticsearch', $result['active']);
        $this->assertSame(1700000000, $result['updated']);
    }

    /**
     * Test getSearchBackendConfig throws RuntimeException on error.
     *
     * @return void
     */
    public function testGetSearchBackendConfigThrowsRuntimeExceptionOnError(): void
    {
        $this->appConfig->method('getValueString')
            ->willThrowException(new \Exception('DB error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to retrieve search backend configuration: DB error');

        $this->handler->getSearchBackendConfig();
    }

    /**
     * Test updateSearchBackendConfig with solr.
     *
     * @return void
     */
    public function testUpdateSearchBackendConfigWithSolr(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'search_backend', $this->isType('string'));

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('Search backend changed to: solr'),
                $this->arrayHasKey('backend')
            );

        $result = $this->handler->updateSearchBackendConfig('solr');

        $this->assertSame('solr', $result['active']);
        $this->assertContains('solr', $result['available']);
        $this->assertContains('elasticsearch', $result['available']);
        $this->assertArrayHasKey('updated', $result);
        $this->assertIsInt($result['updated']);
    }

    /**
     * Test updateSearchBackendConfig with elasticsearch.
     *
     * @return void
     */
    public function testUpdateSearchBackendConfigWithElasticsearch(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString');

        $this->logger->expects($this->once())
            ->method('info');

        $result = $this->handler->updateSearchBackendConfig('elasticsearch');

        $this->assertSame('elasticsearch', $result['active']);
    }

    /**
     * Test updateSearchBackendConfig with invalid backend throws RuntimeException.
     *
     * @return void
     */
    public function testUpdateSearchBackendConfigWithInvalidBackendThrowsException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid backend');

        $this->handler->updateSearchBackendConfig('mongodb');
    }

    /**
     * Test updateSearchBackendConfig sets updated timestamp.
     *
     * @return void
     */
    public function testUpdateSearchBackendConfigSetsTimestamp(): void
    {
        $this->appConfig->method('setValueString');

        $before = time();
        $result = $this->handler->updateSearchBackendConfig('solr');
        $after = time();

        $this->assertGreaterThanOrEqual($before, $result['updated']);
        $this->assertLessThanOrEqual($after, $result['updated']);
    }

    /**
     * Test updateSearchBackendConfig throws RuntimeException on write error.
     *
     * @return void
     */
    public function testUpdateSearchBackendConfigThrowsRuntimeExceptionOnWriteError(): void
    {
        $this->appConfig->method('setValueString')
            ->willThrowException(new \Exception('Write failed'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to update search backend configuration');

        $this->handler->updateSearchBackendConfig('solr');
    }

    /**
     * Test updateSearchBackendConfig logs with correct context.
     *
     * @return void
     */
    public function testUpdateSearchBackendConfigLogsCorrectContext(): void
    {
        $this->appConfig->method('setValueString');

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->anything(),
                $this->callback(function ($context) {
                    return isset($context['app'])
                        && $context['app'] === 'openregister'
                        && isset($context['backend'])
                        && $context['backend'] === 'elasticsearch';
                })
            );

        $this->handler->updateSearchBackendConfig('elasticsearch');
    }

    /**
     * Test updateSearchBackendConfig available backends list is complete.
     *
     * @return void
     */
    public function testUpdateSearchBackendConfigAvailableBackendsComplete(): void
    {
        $this->appConfig->method('setValueString');

        $result = $this->handler->updateSearchBackendConfig('solr');

        $this->assertCount(2, $result['available']);
        $this->assertSame(['solr', 'elasticsearch'], $result['available']);
    }
}
