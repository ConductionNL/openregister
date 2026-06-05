<?php

/**
 * OpenRegister XwikiProviderTest
 *
 * Unit tests for XwikiProvider. The ExternalIntegrationRouter is fully mocked;
 * no real HTTP connections are made.
 *
 * @category Test
 * @package  Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-xwiki/tasks.md#task-6
 */

declare(strict_types=1);

namespace Tests\Unit\Service\Integration\Providers;

use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\ProviderUnavailableException;
use OCA\OpenRegister\Service\Integration\Providers\XwikiProvider;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for XwikiProvider.
 *
 * Coverage: provider metadata, auth requirements, CRUD delegation to router,
 * health check, normalizeRow shaping, and isEnabled() reflecting app state.
 *
 * @spec openspec/changes/integration-xwiki/tasks.md#task-6
 */
class XwikiProviderTest extends TestCase
{
    private XwikiProvider $provider;

    private ExternalIntegrationRouter&MockObject $router;

    private IAppManager&MockObject $appManager;

    private LoggerInterface&MockObject $logger;

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
    }//end setUp()

    // ── Provider metadata ─────────────────────────────────────────────────

    /**
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function testProviderMetadata(): void
    {
        $this->assertSame('xwiki', $this->provider->getId());
        $this->assertSame('Articles', $this->provider->getLabel());
        $this->assertSame('FileDocumentMultiple', $this->provider->getIcon());
        $this->assertSame('external', $this->provider->getGroup());
        $this->assertSame('openconnector', $this->provider->getRequiredApp());
        $this->assertSame('external', $this->provider->getStorageStrategy());
        $this->assertSame('xwiki', $this->provider->getOpenConnectorSource());
    }//end testProviderMetadata()

    /**
     * @spec openspec/changes/integration-xwiki/tasks.md#task-2
     */
    public function testAuthRequirementsStructure(): void
    {
        $auth = $this->provider->authRequirements();

        $this->assertSame('external', $auth['type']);
        $this->assertSame('openconnector', $auth['configuredVia']);
        $this->assertSame('xwiki', $auth['source']);
        $this->assertContains('basic', $auth['supports']);
        $this->assertContains('oauth2', $auth['supports']);
    }//end testAuthRequirementsStructure()

    // ── isEnabled() ───────────────────────────────────────────────────────

    /**
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function testIsEnabledReturnsTrueWhenOpenConnectorInstalled(): void
    {
        $this->appManager
            ->method('isInstalled')
            ->with('openconnector')
            ->willReturn(true);

        $this->assertTrue($this->provider->isEnabled());
    }//end testIsEnabledReturnsTrueWhenOpenConnectorInstalled()

    /**
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function testIsEnabledReturnsFalseWhenOpenConnectorMissing(): void
    {
        $this->appManager
            ->method('isInstalled')
            ->with('openconnector')
            ->willReturn(false);

        $this->assertFalse($this->provider->isEnabled());
    }//end testIsEnabledReturnsFalseWhenOpenConnectorMissing()

    // ── list() ────────────────────────────────────────────────────────────

    /**
     * @spec openspec/changes/integration-xwiki/tasks.md#task-3
     */
    public function testListDelegatestoRouterAndNormalizesRows(): void
    {
        $rawRow = [
            'id'        => 'Dept.Policy.Privacy',
            'title'     => 'Privacy Policy',
            'space'     => 'Dept.Policy',
            'page'      => 'Privacy',
            'url'       => 'https://wiki.example/xwiki/bin/view/Dept/Policy/Privacy',
            'breadcrumb'=> 'Dept / Policy / Privacy Policy',
            'content'   => 'This policy covers…',
        ];

        $this->router
            ->expects($this->once())
            ->method('call')
            ->with(
                source: 'xwiki',
                action: 'list',
                register: 'myreg',
                schema: 'myschema',
                objectId: 'obj-1',
                payload: [],
            )
            ->willReturn(['result' => [$rawRow]]);

        $result = $this->provider->list(
            register: 'myreg',
            schema: 'myschema',
            objectId: 'obj-1',
        );

        $this->assertCount(1, $result);
        $this->assertSame('Privacy Policy', $result[0]['title']);
        $this->assertSame('Dept / Policy / Privacy Policy', $result[0]['breadcrumb']);
    }//end testListDelegatestoRouterAndNormalizesRows()

    // ── create() ──────────────────────────────────────────────────────────

    /**
     * @spec openspec/changes/integration-xwiki/tasks.md#task-3
     */
    public function testCreatePassesReferenceThroughToRouter(): void
    {
        $data = ['reference' => 'https://wiki.example/xwiki/bin/view/Dept/Policy/Privacy'];

        $this->router
            ->expects($this->once())
            ->method('call')
            ->with(
                source: 'xwiki',
                action: 'create',
                register: 'myreg',
                schema: 'myschema',
                objectId: 'obj-1',
                payload: $data,
            )
            ->willReturn(['result' => ['id' => 'new-1', 'title' => 'Privacy Policy', 'space' => 'Dept.Policy']]);

        $result = $this->provider->create(
            register: 'myreg',
            schema: 'myschema',
            objectId: 'obj-1',
            data: $data,
        );

        $this->assertSame('new-1', $result['id']);
    }//end testCreatePassesReferenceThroughToRouter()

    // ── delete() ──────────────────────────────────────────────────────────

    /**
     * @spec openspec/changes/integration-xwiki/tasks.md#task-3
     */
    public function testDeleteDelegatesUnlinkToRouter(): void
    {
        $this->router
            ->expects($this->once())
            ->method('call')
            ->with(
                source: 'xwiki',
                action: 'delete',
                register: 'myreg',
                schema: 'myschema',
                objectId: 'obj-1',
                payload: ['id' => 'link-99'],
            )
            ->willReturn(['result' => []]);

        $this->provider->delete(
            register: 'myreg',
            schema: 'myschema',
            objectId: 'obj-1',
            itemId: 'link-99',
        );

        // Assertion is on the mock expectation (once) above.
        $this->addToAssertionCount(1);
    }//end testDeleteDelegatesUnlinkToRouter()

    // ── health() ──────────────────────────────────────────────────────────

    /**
     * @spec openspec/changes/integration-xwiki/tasks.md#task-3
     */
    public function testHealthDelegatesProbeToRouter(): void
    {
        $this->router
            ->expects($this->once())
            ->method('probe')
            ->with('xwiki')
            ->willReturn(['status' => 'ok']);

        $result = $this->provider->health();

        $this->assertSame('ok', $result['status']);
    }//end testHealthDelegatesProbeToRouter()

    // ── normalizeRow() ────────────────────────────────────────────────────

    /**
     * @spec openspec/changes/integration-xwiki/tasks.md#task-3
     */
    public function testNormalizeRowMapsCanonicalFields(): void
    {
        $raw = [
            'id'          => 'Dept.Policy.Privacy',
            'title'       => 'Privacy Policy',
            'space'       => 'Dept.Policy',
            'page'        => 'Privacy',
            'breadcrumb'  => 'Dept / Policy / Privacy Policy',
            'url'         => 'https://wiki.example/xwiki/bin/view/Dept/Policy/Privacy',
            'content'     => 'Plain text preview.',
        ];

        $normalised = $this->provider->normalizeRow(row: $raw);

        $this->assertArrayHasKey('id', $normalised);
        $this->assertArrayHasKey('reference', $normalised);
        $this->assertArrayHasKey('title', $normalised);
        $this->assertArrayHasKey('space', $normalised);
        $this->assertArrayHasKey('page', $normalised);
        $this->assertArrayHasKey('breadcrumb', $normalised);
        $this->assertArrayHasKey('url', $normalised);
        $this->assertArrayHasKey('content', $normalised);
        $this->assertSame('Privacy Policy', $normalised['title']);
        $this->assertSame('Dept / Policy / Privacy Policy', $normalised['breadcrumb']);
    }//end testNormalizeRowMapsCanonicalFields()

    /**
     * Breadcrumb falls back to "$space / $title" when not provided by the source.
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-3
     */
    public function testNormalizeRowDerivesBreadcrumbWhenMissing(): void
    {
        $raw = [
            'id'    => 'Dept.Privacy',
            'title' => 'Privacy Policy',
            'space' => 'Dept',
        ];

        $normalised = $this->provider->normalizeRow(row: $raw);

        $this->assertSame('Dept / Privacy Policy', $normalised['breadcrumb']);
    }//end testNormalizeRowDerivesBreadcrumbWhenMissing()

    /**
     * renderedContent alias is accepted as content value.
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-3
     */
    public function testNormalizeRowAcceptsRenderedContentAlias(): void
    {
        $raw = [
            'id'              => 'x',
            'renderedContent' => 'Rendered body text.',
        ];

        $normalised = $this->provider->normalizeRow(row: $raw);

        $this->assertSame('Rendered body text.', $normalised['content']);
    }//end testNormalizeRowAcceptsRenderedContentAlias()

    // ── Router exception propagation ──────────────────────────────────────

    /**
     * @spec openspec/changes/integration-xwiki/tasks.md#task-3
     */
    public function testListPropagatesProviderUnavailableException(): void
    {
        $this->router
            ->method('call')
            ->willThrowException(
                new ProviderUnavailableException(
                    message: 'OpenConnector app is not installed.',
                    reason: 'openconnector_missing',
                )
            );

        $this->expectException(ProviderUnavailableException::class);
        $this->expectExceptionCode(503);

        $this->provider->list(register: 'r', schema: 's', objectId: 'o');
    }//end testListPropagatesProviderUnavailableException()
}//end class
