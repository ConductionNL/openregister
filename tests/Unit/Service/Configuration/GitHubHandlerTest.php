<?php

declare(strict_types=1);

/**
 * GitHubHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use Exception;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for GitHubHandler
 *
 * Tests GitHub API interactions including search, file fetching,
 * branch listing, token management, and publishing.
 */
class GitHubHandlerTest extends TestCase
{
    /** @var GitHubHandler */
    private GitHubHandler $handler;

    /** @var IClient&MockObject */
    private IClient $client;

    /** @var IAppConfig&MockObject */
    private IAppConfig $appConfig;

    /** @var IConfig&MockObject */
    private IConfig $config;

    /** @var ICache&MockObject */
    private ICache $cache;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->createMock(IClient::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->config = $this->createMock(IConfig::class);
        $this->cache = $this->createMock(ICache::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($this->client);

        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')
            ->with('openregister_github_configs')
            ->willReturn($this->cache);

        $this->handler = new GitHubHandler(
            $clientService,
            $this->appConfig,
            $this->config,
            $cacheFactory,
            $this->logger
        );
    }

    /**
     * Helper to create a mock IResponse with given body and status code.
     */
    private function createMockResponse(string $body, int $statusCode = 200): IResponse&MockObject
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn($body);
        $response->method('getStatusCode')->willReturn($statusCode);
        return $response;
    }

    // =========================================================================
    // searchConfigurations tests
    // =========================================================================

    #[Test]
    public function testSearchConfigurationsReturnsResultsOnSuccess(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('test-token');

        $apiResponse = json_encode([
            'total_count' => 1,
            'items' => [
                [
                    'name' => 'config.json',
                    'path' => 'lib/config.json',
                    'sha' => 'abc123',
                    'url' => 'https://api.github.com/repos/owner/repo/contents/lib/config.json',
                    'html_url' => 'https://github.com/owner/repo/blob/main/lib/config.json',
                    'repository' => [
                        'full_name' => 'owner/repo',
                        'name' => 'repo',
                        'description' => 'A test repo',
                        'stargazers_count' => 10,
                        'owner' => [
                            'login' => 'owner',
                            'type' => 'User',
                            'avatar_url' => 'https://example.com/avatar.png',
                            'html_url' => 'https://github.com/owner',
                        ],
                        'default_branch' => 'main',
                    ],
                ],
            ],
        ]);

        // Enrichment response (second HTTP call via raw.githubusercontent.com)
        $enrichResponse = json_encode([
            'info' => ['title' => 'Config', 'description' => 'Desc', 'version' => '1.0'],
            'x-openregister' => ['app' => 'test', 'type' => 'config'],
        ]);

        // Cache miss for enrichment
        $this->cache->method('get')->willReturn(null);

        $this->client->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls(
                $this->createMockResponse($apiResponse),
                $this->createMockResponse($enrichResponse)
            );

        $result = $this->handler->searchConfigurations();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('total_count', $result);
        $this->assertArrayHasKey('results', $result);
        $this->assertCount(1, $result['results']);
        $this->assertEquals('owner', $result['results'][0]['owner']);
        $this->assertEquals('repo', $result['results'][0]['repo']);
    }

    #[Test]
    public function testSearchConfigurationsWithSearchTerm(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('test-token');

        $apiResponse = json_encode([
            'total_count' => 0,
            'items' => [],
        ]);

        $this->client->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                $this->stringContains('/search/code'),
                $this->callback(function ($options) {
                    return str_contains($options['query']['q'], 'mySearch');
                })
            )
            ->willReturn($this->createMockResponse($apiResponse));

        $result = $this->handler->searchConfigurations('mySearch');

        $this->assertIsArray($result);
        $this->assertEquals(0, $result['total_count']);
    }

    #[Test]
    public function testSearchConfigurationsThrowsOnApiError(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('test-token');

        $this->client->method('request')
            ->willThrowException(new Exception('API rate limit exceeded'));

        $this->expectException(Exception::class);

        $this->handler->searchConfigurations();
    }

    // =========================================================================
    // enrichConfigurationDetails tests
    // =========================================================================

    #[Test]
    public function testEnrichConfigurationDetailsReturnsMetadata(): void
    {
        $configJson = json_encode([
            'info' => [
                'title' => 'My Config',
                'description' => 'A great config',
                'version' => '1.0.0',
            ],
            'x-openregister' => [
                'app' => 'myapp',
                'type' => 'configuration',
                'openregister' => true,
            ],
        ]);

        $this->client->expects($this->once())
            ->method('request')
            ->with('GET', $this->stringContains('raw.githubusercontent.com/owner/repo/main/config.json'))
            ->willReturn($this->createMockResponse($configJson));

        $result = $this->handler->enrichConfigurationDetails('owner', 'repo', 'config.json');

        $this->assertIsArray($result);
        $this->assertEquals('My Config', $result['title']);
        $this->assertEquals('A great config', $result['description']);
        $this->assertEquals('1.0.0', $result['version']);
        $this->assertEquals('myapp', $result['app']);
        $this->assertEquals('configuration', $result['type']);
        $this->assertTrue($result['openregister']);
    }

    #[Test]
    public function testEnrichConfigurationDetailsReturnsNullOnInvalidJson(): void
    {
        $this->client->method('request')
            ->willReturn($this->createMockResponse('not valid json {{{'));

        $result = $this->handler->enrichConfigurationDetails('owner', 'repo', 'config.json');

        $this->assertNull($result);
    }

    #[Test]
    public function testEnrichConfigurationDetailsReturnsNullOnException(): void
    {
        $this->client->method('request')
            ->willThrowException(new Exception('Network error'));

        $result = $this->handler->enrichConfigurationDetails('owner', 'repo', 'config.json');

        $this->assertNull($result);
    }

    #[Test]
    public function testEnrichConfigurationDetailsWithCustomBranch(): void
    {
        $configJson = json_encode([
            'info' => ['title' => 'Dev Config'],
            'x-openregister' => [],
        ]);

        $this->client->expects($this->once())
            ->method('request')
            ->with('GET', $this->stringContains('raw.githubusercontent.com/owner/repo/develop/config.json'))
            ->willReturn($this->createMockResponse($configJson));

        $result = $this->handler->enrichConfigurationDetails('owner', 'repo', 'config.json', 'develop');

        $this->assertIsArray($result);
        $this->assertEquals('Dev Config', $result['title']);
    }

    // =========================================================================
    // getBranches tests
    // =========================================================================

    #[Test]
    public function testGetBranchesReturnsBranchList(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('test-token');

        $apiResponse = json_encode([
            [
                'name' => 'main',
                'commit' => ['sha' => 'abc123'],
                'protected' => true,
            ],
            [
                'name' => 'develop',
                'commit' => ['sha' => 'def456'],
                'protected' => false,
            ],
        ]);

        $this->client->expects($this->once())
            ->method('request')
            ->with('GET', $this->stringContains('/repos/owner/repo/branches'))
            ->willReturn($this->createMockResponse($apiResponse));

        $result = $this->handler->getBranches('owner', 'repo');

        $this->assertCount(2, $result);
        $this->assertEquals('main', $result[0]['name']);
        $this->assertEquals('abc123', $result[0]['commit']);
        $this->assertTrue($result[0]['protected']);
        $this->assertEquals('develop', $result[1]['name']);
        $this->assertFalse($result[1]['protected']);
    }

    #[Test]
    public function testGetBranchesThrowsOnApiFailure(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('test-token');

        $this->client->method('request')
            ->willThrowException(new \GuzzleHttp\Exception\ConnectException(
                'Connection refused',
                new \GuzzleHttp\Psr7\Request('GET', 'test')
            ));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to fetch branches');

        $this->handler->getBranches('owner', 'repo');
    }

    // =========================================================================
    // getFileContent tests
    // =========================================================================

    #[Test]
    public function testGetFileContentReturnsDecodedJson(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('test-token');

        $fileContent = json_encode(['key' => 'value', 'nested' => ['a' => 1]]);
        $apiResponse = json_encode([
            'content' => base64_encode($fileContent),
            'encoding' => 'base64',
        ]);

        $this->client->expects($this->once())
            ->method('request')
            ->willReturn($this->createMockResponse($apiResponse));

        $result = $this->handler->getFileContent('owner', 'repo', 'path/to/file.json');

        $this->assertEquals(['key' => 'value', 'nested' => ['a' => 1]], $result);
    }

    #[Test]
    public function testGetFileContentThrowsOnInvalidJson(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('test-token');

        $apiResponse = json_encode([
            'content' => base64_encode('not json'),
        ]);

        $this->client->method('request')
            ->willReturn($this->createMockResponse($apiResponse));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid JSON in file');

        $this->handler->getFileContent('owner', 'repo', 'file.json');
    }

    #[Test]
    public function testGetFileContentThrowsOnNoContent(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('test-token');

        $apiResponse = json_encode([]);

        $this->client->method('request')
            ->willReturn($this->createMockResponse($apiResponse));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No content found in file');

        $this->handler->getFileContent('owner', 'repo', 'file.json');
    }

    // =========================================================================
    // getRepositories tests
    // =========================================================================

    #[Test]
    public function testGetRepositoriesReturnsEmptyWhenNoToken(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $result = $this->handler->getRepositories();

        $this->assertSame([], $result);
    }

    #[Test]
    public function testGetRepositoriesReturnsMappedData(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('valid-token');

        $apiResponse = json_encode([
            [
                'id' => 123,
                'name' => 'my-repo',
                'full_name' => 'owner/my-repo',
                'owner' => ['login' => 'owner', 'type' => 'User'],
                'private' => false,
                'description' => 'Test repo',
                'default_branch' => 'main',
                'html_url' => 'https://github.com/owner/my-repo',
                'url' => 'https://api.github.com/repos/owner/my-repo',
            ],
        ]);

        $this->client->expects($this->once())
            ->method('request')
            ->willReturn($this->createMockResponse($apiResponse));

        $result = $this->handler->getRepositories();

        $this->assertCount(1, $result);
        $this->assertEquals(123, $result[0]['id']);
        $this->assertEquals('my-repo', $result[0]['name']);
        $this->assertEquals('owner', $result[0]['owner']);
        $this->assertFalse($result[0]['private']);
    }

    // =========================================================================
    // getRepositoryInfo tests
    // =========================================================================

    #[Test]
    public function testGetRepositoryInfoReturnsFormattedData(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('test-token');

        $apiResponse = json_encode([
            'id' => 456,
            'name' => 'the-repo',
            'full_name' => 'org/the-repo',
            'owner' => ['login' => 'org'],
            'private' => true,
            'description' => 'Private repo',
            'default_branch' => 'develop',
            'html_url' => 'https://github.com/org/the-repo',
        ]);

        $this->client->expects($this->once())
            ->method('request')
            ->willReturn($this->createMockResponse($apiResponse));

        $result = $this->handler->getRepositoryInfo('org', 'the-repo');

        $this->assertEquals(456, $result['id']);
        $this->assertEquals('the-repo', $result['name']);
        $this->assertEquals('org/the-repo', $result['full_name']);
        $this->assertTrue($result['private']);
        $this->assertEquals('develop', $result['default_branch']);
    }

    #[Test]
    public function testGetRepositoryInfoThrowsOnFailure(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('test-token');

        $this->client->method('request')
            ->willThrowException(new \GuzzleHttp\Exception\ConnectException(
                'Connection refused',
                new \GuzzleHttp\Psr7\Request('GET', 'test')
            ));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to fetch repository info');

        $this->handler->getRepositoryInfo('owner', 'repo');
    }

    // =========================================================================
    // getUserToken / setUserToken tests
    // =========================================================================

    #[Test]
    public function testGetUserTokenReturnsTokenWhenSet(): void
    {
        $this->config->method('getUserValue')
            ->with('user1', 'openregister', 'github_token', '')
            ->willReturn('my-github-token');

        $result = $this->handler->getUserToken('user1');

        $this->assertEquals('my-github-token', $result);
    }

    #[Test]
    public function testGetUserTokenReturnsNullWhenEmpty(): void
    {
        $this->config->method('getUserValue')
            ->with('user1', 'openregister', 'github_token', '')
            ->willReturn('');

        $result = $this->handler->getUserToken('user1');

        $this->assertNull($result);
    }

    #[Test]
    public function testSetUserTokenStoresToken(): void
    {
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with('user1', 'openregister', 'github_token', 'new-token');

        $this->handler->setUserToken('new-token', 'user1');
    }

    #[Test]
    public function testSetUserTokenDeletesWhenNull(): void
    {
        $this->config->expects($this->once())
            ->method('deleteUserValue')
            ->with('user1', 'openregister', 'github_token');

        $this->handler->setUserToken(null, 'user1');
    }

    // =========================================================================
    // validateToken tests
    // =========================================================================

    #[Test]
    public function testValidateTokenReturnsTrueOnSuccess(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('valid-token');

        $this->client->expects($this->once())
            ->method('request')
            ->with('GET', $this->stringContains('/user'))
            ->willReturn($this->createMockResponse('{}', 200));

        $result = $this->handler->validateToken();

        $this->assertTrue($result);
    }

    #[Test]
    public function testValidateTokenReturnsFalseOnEmptyToken(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $result = $this->handler->validateToken();

        $this->assertFalse($result);
    }

    #[Test]
    public function testValidateTokenReturnsFalseOnException(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('bad-token');

        $this->client->method('request')
            ->willThrowException(new Exception('Unauthorized'));

        $result = $this->handler->validateToken();

        $this->assertFalse($result);
    }

    #[Test]
    public function testValidateTokenWithUserIdUsesUserToken(): void
    {
        $this->config->method('getUserValue')
            ->with('user1', 'openregister', 'github_token', '')
            ->willReturn('user-specific-token');

        $this->client->expects($this->once())
            ->method('request')
            ->willReturn($this->createMockResponse('{}', 200));

        $result = $this->handler->validateToken('user1');

        $this->assertTrue($result);
    }

    // =========================================================================
    // getFileSha tests
    // =========================================================================

    #[Test]
    public function testGetFileShaReturnsSha(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('test-token');

        $apiResponse = json_encode(['sha' => 'abc123def456']);

        $this->client->expects($this->once())
            ->method('request')
            ->willReturn($this->createMockResponse($apiResponse));

        $result = $this->handler->getFileSha('owner', 'repo', 'path/to/file.json');

        $this->assertEquals('abc123def456', $result);
    }

    #[Test]
    public function testGetFileShaReturnsNullOn404(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('test-token');

        $mockResponse = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(404);

        $request = new \GuzzleHttp\Psr7\Request('GET', 'test');
        $exception = new \GuzzleHttp\Exception\ClientException(
            'Not Found',
            $request,
            $mockResponse
        );

        $this->client->method('request')
            ->willThrowException($exception);

        $result = $this->handler->getFileSha('owner', 'repo', 'nonexistent.json');

        $this->assertNull($result);
    }

    // =========================================================================
    // publishConfiguration tests
    // =========================================================================

    #[Test]
    public function testPublishConfigurationSuccess(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('test-token');

        $apiResponse = json_encode([
            'content' => ['sha' => 'file-sha-123'],
            'commit' => [
                'sha' => 'commit-sha-456',
                'html_url' => 'https://github.com/owner/repo/commit/456',
            ],
        ]);

        $this->client->expects($this->once())
            ->method('request')
            ->with('PUT', $this->stringContains('/repos/owner/repo/contents/config.json'))
            ->willReturn($this->createMockResponse($apiResponse));

        $result = $this->handler->publishConfiguration(
            'owner',
            'repo',
            'config.json',
            'main',
            '{"key":"value"}',
            'Update configuration'
        );

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    #[Test]
    public function testPublishConfigurationThrowsOnFailure(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('test-token');

        $this->client->method('request')
            ->willThrowException(new \GuzzleHttp\Exception\ConnectException(
                'Connection refused',
                new \GuzzleHttp\Psr7\Request('PUT', 'test')
            ));

        $this->expectException(Exception::class);

        $this->handler->publishConfiguration(
            'owner',
            'repo',
            'config.json',
            'main',
            '{"key":"value"}',
            'Update'
        );
    }

    // =========================================================================
    // Authentication header tests (via public method calls)
    // =========================================================================

    #[Test]
    public function testAuthTokenIncludedInSearchRequests(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('my-secret-token');

        $apiResponse = json_encode(['total_count' => 0, 'items' => []]);

        $this->client->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                $this->anything(),
                $this->callback(function ($options) {
                    return isset($options['headers']['Authorization'])
                        && $options['headers']['Authorization'] === 'Bearer my-secret-token';
                })
            )
            ->willReturn($this->createMockResponse($apiResponse));

        $this->handler->searchConfigurations();
    }

    #[Test]
    public function testNoAuthTokenWhenNotConfigured(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $apiResponse = json_encode(['total_count' => 0, 'items' => []]);

        $this->client->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                $this->anything(),
                $this->callback(function ($options) {
                    return !isset($options['headers']['Authorization']);
                })
            )
            ->willReturn($this->createMockResponse($apiResponse));

        $this->handler->searchConfigurations();
    }
}
