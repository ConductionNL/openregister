<?php

namespace Unit\Service\Integration\Providers;

use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\Providers\XwikiProvider;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for XwikiProvider.
 *
 * Covers: metadata getters, authRequirements, isEnabled, list/get/create/
 * update/delete delegation to ExternalIntegrationRouter, health probe, and
 * normalizeRow (including breadcrumb derivation and content sanitisation).
 *
 * @spec openspec/changes/integration-xwiki/tasks.md#task-1.6
 */
class XwikiProviderTest extends TestCase
{

    private ExternalIntegrationRouter&MockObject $router;
    private IAppManager&MockObject $appManager;
    private LoggerInterface&MockObject $logger;
    private XwikiProvider $provider;

    protected function setUp(): void
    {
        $this->router     = $this->createMock(ExternalIntegrationRouter::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->logger     = $this->createMock(LoggerInterface::class);

        $this->provider = new XwikiProvider(
            router: $this->router,
            appManager: $this->appManager,
            logger: $this->logger,
        );
    }

    // --- Metadata ---

    /**
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.1
     */
    public function testMetadata(): void
    {
        $this->assertSame('xwiki', $this->provider->getId());
        $this->assertSame('Articles', $this->provider->getLabel());
        $this->assertSame('FileDocumentMultiple', $this->provider->getIcon());
        $this->assertSame('external', $this->provider->getGroup());
        $this->assertSame('openconnector', $this->provider->getRequiredApp());
        $this->assertSame('external', $this->provider->getStorageStrategy());
        $this->assertSame('xwiki', $this->provider->getOpenConnectorSource());
        $this->assertNull($this->provider->requiresPermission());
    }

    // --- Auth requirements ---

    /**
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.2
     */
    public function testAuthRequirements(): void
    {
        $auth = $this->provider->authRequirements();

        $this->assertSame('external', $auth['type']);
        $this->assertSame('openconnector', $auth['configuredVia']);
        $this->assertSame('xwiki', $auth['source']);
        $this->assertContains('basic', $auth['supports']);
        $this->assertContains('oauth2', $auth['supports']);
    }

    // --- isEnabled ---

    /**
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.1
     */
    public function testIsEnabledWhenOpenConnectorInstalled(): void
    {
        $this->appManager->method('isInstalled')
            ->with('openconnector')
            ->willReturn(true);

        $this->assertTrue($this->provider->isEnabled());
    }

    /**
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.1
     */
    public function testIsDisabledWhenOpenConnectorMissing(): void
    {
        $this->appManager->method('isInstalled')
            ->with('openconnector')
            ->willReturn(false);

        $this->assertFalse($this->provider->isEnabled());
    }

    // --- CRUD delegation ---

    /**
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function testListDelegatesToRouter(): void
    {
        $this->router->expects($this->once())
            ->method('call')
            ->with(
                method: 'list',
                source: 'xwiki',
                register: 'reg',
                schema: 'sch',
                objectId: 'obj-1',
                id: null,
                data: ['_limit' => 10],
            )
            ->willReturn([
                ['id' => 'p1', 'title' => 'Privacy Policy', 'space' => 'Legal'],
            ]);

        $result = $this->provider->list(
            register: 'reg',
            schema: 'sch',
            objectId: 'obj-1',
            params: ['_limit' => 10],
        );

        $this->assertCount(1, $result);
        $this->assertSame('Privacy Policy', $result[0]['title']);
        $this->assertSame('Legal / Privacy Policy', $result[0]['breadcrumb']);
    }

    /**
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function testGetDelegatesToRouter(): void
    {
        $this->router->expects($this->once())
            ->method('call')
            ->with(
                method: 'get',
                source: 'xwiki',
                register: 'reg',
                schema: 'sch',
                objectId: 'obj-1',
                id: 'page-42',
            )
            ->willReturn(['id' => 'page-42', 'title' => 'My Page']);

        $result = $this->provider->get(
            register: 'reg',
            schema: 'sch',
            objectId: 'obj-1',
            id: 'page-42',
        );

        $this->assertNotNull($result);
        $this->assertSame('My Page', $result['title']);
    }

    /**
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function testGetReturnsNullForEmptyRouterResponse(): void
    {
        $this->router->method('call')->willReturn([]);

        $result = $this->provider->get(
            register: 'reg',
            schema: 'sch',
            objectId: 'obj-1',
            id: 'missing',
        );

        $this->assertNull($result);
    }

    /**
     * AD-2: create passes reference through for server-side URL resolution.
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function testCreatePassesReferenceToRouter(): void
    {
        $ref = 'https://wiki.example.gov/xwiki/bin/view/Dept/Policy/Privacy';

        $this->router->expects($this->once())
            ->method('call')
            ->with(
                method: 'create',
                source: 'xwiki',
                register: 'reg',
                schema: 'sch',
                objectId: 'obj-1',
                id: null,
                data: ['reference' => $ref],
            )
            ->willReturn(['id' => 'new-1', 'reference' => $ref, 'title' => 'Privacy']);

        $result = $this->provider->create(
            register: 'reg',
            schema: 'sch',
            objectId: 'obj-1',
            data: ['reference' => $ref],
        );

        $this->assertSame('new-1', $result['id']);
        $this->assertSame('Privacy', $result['title']);
    }

    /**
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function testUpdateDelegatesToRouter(): void
    {
        $this->router->expects($this->once())
            ->method('call')
            ->with(
                method: 'update',
                source: 'xwiki',
                register: 'reg',
                schema: 'sch',
                objectId: 'obj-1',
                id: 'p1',
                data: ['note' => 'updated'],
            )
            ->willReturn(['id' => 'p1', 'title' => 'Updated']);

        $result = $this->provider->update(
            register: 'reg',
            schema: 'sch',
            objectId: 'obj-1',
            id: 'p1',
            data: ['note' => 'updated'],
        );

        $this->assertSame('Updated', $result['title']);
    }

    /**
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function testDeleteDelegatesToRouter(): void
    {
        $this->router->expects($this->once())
            ->method('call')
            ->with(
                method: 'delete',
                source: 'xwiki',
                register: 'reg',
                schema: 'sch',
                objectId: 'obj-1',
                id: 'p1',
            )
            ->willReturn([]);

        $this->provider->delete(
            register: 'reg',
            schema: 'sch',
            objectId: 'obj-1',
            id: 'p1',
        );

        // No exception — delete succeeded.
        $this->assertTrue(true);
    }

    // --- Health ---

    /**
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function testHealthProbeDelegatesToRouter(): void
    {
        $this->router->expects($this->once())
            ->method('probe')
            ->with('xwiki')
            ->willReturn(['status' => 'ok']);

        $health = $this->provider->health();

        $this->assertSame('ok', $health['status']);
    }

    // --- normalizeRow ---

    /**
     * AD-3: Breadcrumb from hierarchy.items[].label preferred over fallback.
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function testNormalizeRowUsesHierarchyBreadcrumb(): void
    {
        $row = [
            'id'        => 'p42',
            'title'     => 'Privacy',
            'space'     => 'Legal',
            'hierarchy' => [
                'items' => [
                    ['label' => 'Wiki'],
                    ['label' => 'Legal'],
                    ['label' => 'Privacy'],
                ],
            ],
            'url'       => 'https://wiki/Legal/Privacy',
        ];

        $result = $this->provider->normalizeRow(row: $row);

        $this->assertSame('Wiki / Legal / Privacy', $result['breadcrumb']);
        $this->assertSame('p42', $result['id']);
        $this->assertSame('Privacy', $result['title']);
    }

    /**
     * AD-3: Breadcrumb falls back to "space / title" when hierarchy absent.
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function testNormalizeRowFallbackBreadcrumb(): void
    {
        $row = [
            'title' => 'GDPR Policy',
            'space' => 'Legal',
        ];

        $result = $this->provider->normalizeRow(row: $row);

        $this->assertSame('Legal / GDPR Policy', $result['breadcrumb']);
    }

    /**
     * AD-1: Content HTML and script tags are stripped to plain text ≤ 500 chars.
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function testNormalizeRowStripsHtmlAndMacros(): void
    {
        $row = [
            'id'      => 'p1',
            'title'   => 'Page',
            'content' => '<p>Hello <script>alert("xss")</script> World. {{velocity}}some macro{{/velocity}}</p>',
        ];

        $result = $this->provider->normalizeRow(row: $row);

        $this->assertStringNotContainsString('<script>', $result['content']);
        $this->assertStringNotContainsString('alert(', $result['content']);
        $this->assertStringContainsString('Hello', $result['content']);
        $this->assertStringContainsString('World', $result['content']);
        $this->assertLessThanOrEqual(500, mb_strlen(string: $result['content']));
    }

    /**
     * AD-1: Content truncated to 500 chars with ellipsis.
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function testNormalizeRowTruncatesLongContent(): void
    {
        $row = [
            'id'      => 'p1',
            'title'   => 'Long',
            'content' => str_repeat(string: 'x', times: 600),
        ];

        $result = $this->provider->normalizeRow(row: $row);

        $this->assertSame(500, mb_strlen(string: $result['content']));
        $this->assertStringEndsWith('...', $result['content']);
    }

}//end class
