<?php

/**
 * XWiki Provider Unit Tests
 *
 * Covers: metadata getters, isEnabled(), authRequirements(), list/get/create/update/delete
 * delegation to ExternalIntegrationRouter, health(), normalizeRow(), normalizeList().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-xwiki/tasks.md#task-6
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\Providers\XwikiProvider;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for XwikiProvider.
 *
 * OpenConnector router is fully mocked — no HTTP calls are made.
 */
class XwikiProviderTest extends TestCase
{

    /**
     * Integration router mock.
     *
     * @var ExternalIntegrationRouter&MockObject
     */
    private ExternalIntegrationRouter&MockObject $router;

    /**
     * App manager mock.
     *
     * @var IAppManager&MockObject
     */
    private IAppManager&MockObject $appManager;

    /**
     * Logger mock.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Subject under test.
     *
     * @var XwikiProvider
     */
    private XwikiProvider $provider;

    /**
     * Set up the test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->router     = $this->createMock(originalClassName: ExternalIntegrationRouter::class);
        $this->appManager = $this->createMock(originalClassName: IAppManager::class);
        $this->logger     = $this->createMock(originalClassName: LoggerInterface::class);

        $this->provider = new XwikiProvider(
            router: $this->router,
            appManager: $this->appManager,
            logger: $this->logger
        );
    }//end setUp()

    // ──────────────────────────────────────────────
    // Test 1: Metadata getters
    // ──────────────────────────────────────────────

    /**
     * Test: provider metadata is correct.
     *
     * @return void
     */
    public function testProviderMetadata(): void
    {
        $this->assertSame(expected: 'xwiki', actual: $this->provider->getId());
        $this->assertSame(expected: 'Articles', actual: $this->provider->getLabel());
        $this->assertSame(expected: 'FileDocumentMultiple', actual: $this->provider->getIcon());
        $this->assertSame(expected: 'external', actual: $this->provider->getGroup());
        $this->assertSame(expected: 'openconnector', actual: $this->provider->getRequiredApp());
        $this->assertSame(expected: 'external', actual: $this->provider->getStorageStrategy());
        $this->assertSame(expected: 'xwiki', actual: $this->provider->getOpenConnectorSource());
    }//end testProviderMetadata()

    // ──────────────────────────────────────────────
    // Test 2: isEnabled mirrors IAppManager
    // ──────────────────────────────────────────────

    /**
     * Test: isEnabled returns true when openconnector is installed.
     *
     * @return void
     */
    public function testIsEnabledWhenOpenConnectorInstalled(): void
    {
        $this->appManager
            ->method('isInstalled')
            ->with('openconnector')
            ->willReturn(true);

        $this->assertTrue(condition: $this->provider->isEnabled());
    }//end testIsEnabledWhenOpenConnectorInstalled()

    /**
     * Test: isEnabled returns false when openconnector is absent.
     *
     * @return void
     */
    public function testIsEnabledWhenOpenConnectorAbsent(): void
    {
        $this->appManager
            ->method('isInstalled')
            ->with('openconnector')
            ->willReturn(false);

        $this->assertFalse(condition: $this->provider->isEnabled());
    }//end testIsEnabledWhenOpenConnectorAbsent()

    // ──────────────────────────────────────────────
    // Test 3: authRequirements shape
    // ──────────────────────────────────────────────

    /**
     * Test: authRequirements returns correct shape.
     *
     * @return void
     */
    public function testAuthRequirementsShape(): void
    {
        $auth = $this->provider->authRequirements();

        $this->assertSame(expected: 'external', actual: $auth['type']);
        $this->assertSame(expected: 'openconnector', actual: $auth['configuredVia']);
        $this->assertSame(expected: 'xwiki', actual: $auth['source']);
        $this->assertContains(needle: 'basic', haystack: $auth['supports']);
        $this->assertContains(needle: 'oauth2', haystack: $auth['supports']);
    }//end testAuthRequirementsShape()

    // ──────────────────────────────────────────────
    // Test 4: list() delegates to router
    // ──────────────────────────────────────────────

    /**
     * Test: list() calls router and returns normalised rows.
     *
     * @return void
     */
    public function testListDelegatesToRouter(): void
    {
        $raw = [
            'results' => [
                [
                    'id'        => '1',
                    'title'     => 'Privacy Policy',
                    'space'     => 'Dept',
                    'reference' => 'Dept.Privacy',
                    'url'       => 'https://wiki.example.org/bin/view/Dept/Privacy',
                    'content'   => 'This is the policy text.',
                ],
            ],
            'total'   => 1,
        ];

        $this->router
            ->expects($this->once())
            ->method('call')
            ->with(
                source: 'xwiki',
                operation: 'list',
                context: $this->arrayHasKey(key: 'register')
            )
            ->willReturn($raw);

        $result = $this->provider->list(
            register: 'reg1',
            schema: 'schema1',
            objectId: 'obj-uuid'
        );

        $this->assertSame(expected: 1, actual: $result['total']);
        $this->assertCount(expectedCount: 1, haystack: $result['results']);
        $this->assertSame(expected: 'Privacy Policy', actual: $result['results'][0]['title']);
        $this->assertSame(expected: 'Dept', actual: $result['results'][0]['space']);
    }//end testListDelegatesToRouter()

    // ──────────────────────────────────────────────
    // Test 5: get() returns null on empty response
    // ──────────────────────────────────────────────

    /**
     * Test: get() returns null when router returns an empty array.
     *
     * @return void
     */
    public function testGetReturnsNullOnEmptyResponse(): void
    {
        $this->router
            ->method('call')
            ->willReturn([]);

        $result = $this->provider->get(
            register: 'reg',
            schema: 'sch',
            objectId: 'obj',
            linkedId: 'page-1'
        );

        $this->assertNull(actual: $result);
    }//end testGetReturnsNullOnEmptyResponse()

    /**
     * Test: get() returns normalised row on success.
     *
     * @return void
     */
    public function testGetReturnsNormalisedRow(): void
    {
        $this->router
            ->method('call')
            ->willReturn(
                [
                    'id'        => 'page-42',
                    'title'     => 'Legal Notice',
                    'space'     => 'Legal',
                    'reference' => 'Legal.Notice',
                    'url'       => 'https://wiki.example.org/bin/view/Legal/Notice',
                    'content'   => 'Plain text body.',
                ]
            );

        $result = $this->provider->get(
            register: 'reg',
            schema: 'sch',
            objectId: 'obj',
            linkedId: 'page-42'
        );

        $this->assertNotNull(actual: $result);
        $this->assertSame(expected: 'page-42', actual: $result['id']);
        $this->assertSame(expected: 'Legal Notice', actual: $result['title']);
    }//end testGetReturnsNormalisedRow()

    // ──────────────────────────────────────────────
    // Test 6: create() delegates and returns row
    // ──────────────────────────────────────────────

    /**
     * Test: create() passes data to router and returns normalised row.
     *
     * @return void
     */
    public function testCreateDelegatesToRouter(): void
    {
        $linkData = ['reference' => 'https://wiki.example.org/xwiki/bin/view/Dept/Privacy'];

        $this->router
            ->expects($this->once())
            ->method('call')
            ->with(
                source: 'xwiki',
                operation: 'create',
                context: $this->callback(
                    callback: function (array $ctx) use ($linkData): bool {
                        return $ctx['data'] === $linkData;
                    }
                )
            )
            ->willReturn(
                [
                    'id'        => 'new-link-1',
                    'title'     => 'Privacy Policy',
                    'space'     => 'Dept',
                    'reference' => 'Dept.Privacy',
                    'url'       => 'https://wiki.example.org/bin/view/Dept/Privacy',
                    'content'   => '',
                ]
            );

        $result = $this->provider->create(
            register: 'reg',
            schema: 'sch',
            objectId: 'obj',
            data: $linkData
        );

        $this->assertSame(expected: 'new-link-1', actual: $result['id']);
        $this->assertSame(expected: 'Privacy Policy', actual: $result['title']);
    }//end testCreateDelegatesToRouter()

    // ──────────────────────────────────────────────
    // Test 7: delete() calls router without return
    // ──────────────────────────────────────────────

    /**
     * Test: delete() calls router and returns void.
     *
     * @return void
     */
    public function testDeleteDelegatesToRouter(): void
    {
        $this->router
            ->expects($this->once())
            ->method('call')
            ->with(
                source: 'xwiki',
                operation: 'delete',
                context: $this->callback(
                    callback: function (array $ctx): bool {
                        return $ctx['id'] === 'link-99';
                    }
                )
            )
            ->willReturn([]);

        $this->provider->delete(
            register: 'reg',
            schema: 'sch',
            objectId: 'obj',
            linkedId: 'link-99'
        );

        // No exception = pass.
        $this->addToAssertionCount(count: 1);
    }//end testDeleteDelegatesToRouter()

    // ──────────────────────────────────────────────
    // Test 8: health() delegates to router probe
    // ──────────────────────────────────────────────

    /**
     * Test: health() returns the probe result from the router.
     *
     * @return void
     */
    public function testHealthDelegatesToRouterProbe(): void
    {
        $this->router
            ->expects($this->once())
            ->method('probe')
            ->with('xwiki')
            ->willReturn(['status' => 'ok', 'message' => 'Source reachable']);

        $result = $this->provider->health();

        $this->assertSame(expected: 'ok', actual: $result['status']);
    }//end testHealthDelegatesToRouterProbe()

    // ──────────────────────────────────────────────
    // Test 9: normalizeRow shapes canonical fields
    // ──────────────────────────────────────────────

    /**
     * Test: normalizeRow maps all expected fields.
     *
     * @return void
     */
    public function testNormalizeRowMapsAllFields(): void
    {
        $raw = [
            'id'        => 'xwiki-page-5',
            'reference' => 'Dept.Policy.Privacy',
            'title'     => 'Privacy Policy',
            'space'     => 'Dept.Policy',
            'page'      => 'Privacy',
            'url'       => 'https://wiki.example.org/bin/view/Dept/Policy/Privacy',
            'content'   => 'This is the plain text of the privacy policy.',
            'hierarchy' => [
                'items' => [
                    ['label' => 'Wiki'],
                    ['label' => 'Dept'],
                    ['label' => 'Policy'],
                    ['label' => 'Privacy Policy'],
                ],
            ],
        ];

        $row = $this->provider->normalizeRow(row: $raw);

        $this->assertSame(expected: 'xwiki-page-5', actual: $row['id']);
        $this->assertSame(expected: 'Dept.Policy.Privacy', actual: $row['reference']);
        $this->assertSame(expected: 'Privacy Policy', actual: $row['title']);
        $this->assertSame(expected: 'Dept.Policy', actual: $row['space']);
        $this->assertSame(expected: 'Privacy', actual: $row['page']);
        $this->assertSame(expected: 'https://wiki.example.org/bin/view/Dept/Policy/Privacy', actual: $row['url']);
        $this->assertSame(expected: 'This is the plain text of the privacy policy.', actual: $row['content']);

        // Breadcrumb drops the last item (page title) — expects "Wiki / Dept / Policy".
        $this->assertSame(expected: 'Wiki / Dept / Policy', actual: $row['breadcrumb']);
    }//end testNormalizeRowMapsAllFields()

    // ──────────────────────────────────────────────
    // Test 10: normalizeRow breadcrumb fallback
    // ──────────────────────────────────────────────

    /**
     * Test: normalizeRow derives breadcrumb from space when hierarchy is absent.
     *
     * @return void
     */
    public function testNormalizeRowBreadcrumbFallbackToSpace(): void
    {
        $raw = [
            'id'    => 'page-7',
            'title' => 'GDPR Guide',
            'space' => 'Legal.GDPR',
        ];

        $row = $this->provider->normalizeRow(row: $raw);

        $this->assertSame(expected: 'Legal.GDPR', actual: $row['breadcrumb']);
    }//end testNormalizeRowBreadcrumbFallbackToSpace()
}//end class
