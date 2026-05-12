<?php

/**
 * Unit tests for XwikiProvider.
 *
 * Covers:
 *  - metadata matches the leaf spec (id / label / icon / group /
 *    requiredApp / storage strategy / OpenConnector source)
 *  - authRequirements() reports the external-config-via-OpenConnector
 *    shape
 *  - isEnabled() mirrors IAppManager::isInstalled('openconnector')
 *  - list() / get() / create() / update() / delete() delegate to
 *    ExternalIntegrationRouter with the right method + path + context,
 *    and list()/get()/create()/update() normalise rows (id / title /
 *    space / page / breadcrumb fallback / url / content)
 *  - health() defers to router->probe()
 *
 * The upstream call path itself (OpenConnector → XWiki REST) is
 * exercised by integration tests; here the router is mocked so we
 * assert the provider's own contract.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-xwiki/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\Providers\XwikiProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for XwikiProvider.
 */
class XwikiProviderTest extends TestCase
{

    /**
     * @var ExternalIntegrationRouter&\PHPUnit\Framework\MockObject\MockObject
     */
    private $router;

    /**
     * @var IAppManager&\PHPUnit\Framework\MockObject\MockObject
     */
    private $appManager;

    private XwikiProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router     = $this->createMock(ExternalIntegrationRouter::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $l10n             = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->provider = new XwikiProvider(
            router: $this->router,
            appManager: $this->appManager,
            l10n: $l10n,
        );
    }//end setUp()

    public function testMetadataMatchesLeafSpec(): void
    {
        $this->assertSame('xwiki', $this->provider->getId());
        $this->assertSame('Articles', $this->provider->getLabel());
        $this->assertSame('FileDocumentMultiple', $this->provider->getIcon());
        $this->assertSame('external', $this->provider->getGroup());
        $this->assertSame('openconnector', $this->provider->getRequiredApp());
        $this->assertSame('external', $this->provider->getStorageStrategy());
        $this->assertSame('xwiki', $this->provider->getOpenConnectorSource());
        $this->assertNull($this->provider->requiresPermission());
    }//end testMetadataMatchesLeafSpec()

    public function testAuthRequirementsAreExternalViaOpenConnector(): void
    {
        $auth = $this->provider->authRequirements();
        $this->assertSame('external', $auth['type']);
        $this->assertSame('openconnector', $auth['configuredVia']);
        $this->assertSame('xwiki', $auth['source']);
        $this->assertContains('basic', $auth['supports']);
        $this->assertContains('oauth2', $auth['supports']);
    }//end testAuthRequirementsAreExternalViaOpenConnector()

    public function testIsEnabledMirrorsOpenConnectorInstall(): void
    {
        $this->appManager->method('isInstalled')->with('openconnector')->willReturn(true);
        $this->assertTrue($this->provider->isEnabled());

        $appManager2 = $this->createMock(IAppManager::class);
        $appManager2->method('isInstalled')->with('openconnector')->willReturn(false);
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $disabled = new XwikiProvider($this->router, $appManager2, $l10n);
        $this->assertFalse($disabled->isEnabled());
    }//end testIsEnabledMirrorsOpenConnectorInstall()

    public function testListDelegatesToRouterWithContextAndNormalisesRows(): void
    {
        $this->router->expects($this->once())
            ->method('call')
            ->with(
                $this->provider,
                'GET',
                '',
                ['query' => ['_search' => 'foo', 'register' => 'r1', 'schema' => 's1', 'object' => 'obj-1']]
            )
            ->willReturn([
                'results' => [
                    [
                        'id'         => 'Departments.Legal.Handbook',
                        'title'      => 'Handbook',
                        'space'      => 'Departments.Legal',
                        'name'       => 'Handbook',
                        'xwikiAbsoluteUrl' => 'https://wiki.example.org/bin/view/Departments/Legal/Handbook',
                        'breadcrumb' => ['Wiki', 'Departments', 'Legal', 'Handbook'],
                    ],
                    [
                        // No breadcrumb supplied — provider builds one from space + title.
                        'reference' => 'Sales.Pitch',
                        'title'     => 'Pitch',
                        'space'     => 'Sales',
                    ],
                ],
            ]);

        $rows = $this->provider->list('r1', 's1', 'obj-1', ['_search' => 'foo']);

        $this->assertCount(2, $rows);
        $this->assertSame('Departments.Legal.Handbook', $rows[0]['id']);
        $this->assertSame('Handbook', $rows[0]['title']);
        $this->assertSame(['Wiki', 'Departments', 'Legal', 'Handbook'], $rows[0]['breadcrumb']);
        $this->assertSame('https://wiki.example.org/bin/view/Departments/Legal/Handbook', $rows[0]['url']);
        // Second row: breadcrumb derived from "Sales" space + "Pitch" title.
        $this->assertSame('Sales.Pitch', $rows[1]['id']);
        $this->assertSame(['Sales', 'Pitch'], $rows[1]['breadcrumb']);
    }//end testListDelegatesToRouterWithContextAndNormalisesRows()

    public function testListAcceptsBareArrayResponse(): void
    {
        $this->router->method('call')->willReturn([
            ['reference' => 'A.B', 'title' => 'B', 'space' => 'A'],
        ]);
        $rows = $this->provider->list('r', 's', 'o');
        $this->assertCount(1, $rows);
        $this->assertSame('A.B', $rows[0]['id']);
    }//end testListAcceptsBareArrayResponse()

    public function testListAcceptsRawXwikiPageSummariesEnvelope(): void
    {
        // Shape returned by XWiki's REST API: GET /rest/wikis/<wiki>/spaces/<Space>/pages.
        $this->router->method('call')->willReturn([
            'links'         => [],
            'pageSummaries' => [
                [
                    'id'       => 'xwiki:Sandbox.IntegrationTest',
                    'fullName' => 'Sandbox.IntegrationTest',
                    'wiki'     => 'xwiki',
                    'space'    => 'Sandbox',
                    'name'     => 'IntegrationTest',
                    'title'    => 'Integration Test Page',
                    'links'    => [
                        ['rel' => 'http://www.xwiki.org/rel/space', 'href' => 'http://wiki/rest/wikis/xwiki/spaces/Sandbox'],
                        ['rel' => 'http://www.xwiki.org/rel/page', 'href' => 'http://wiki/rest/wikis/xwiki/spaces/Sandbox/pages/IntegrationTest'],
                    ],
                ],
            ],
        ]);

        $rows = $this->provider->list('decidesk', 'meeting', 'obj-1');

        $this->assertCount(1, $rows);
        $this->assertSame('xwiki:Sandbox.IntegrationTest', $rows[0]['id']);
        $this->assertSame('Integration Test Page', $rows[0]['title']);
        $this->assertSame('Sandbox', $rows[0]['space']);
        $this->assertSame('IntegrationTest', $rows[0]['page']);
        $this->assertSame(['Sandbox', 'Integration Test Page'], $rows[0]['breadcrumb']);
        // url falls back to the rel="/rel/page" href from the links array.
        $this->assertSame('http://wiki/rest/wikis/xwiki/spaces/Sandbox/pages/IntegrationTest', $rows[0]['url']);
    }//end testListAcceptsRawXwikiPageSummariesEnvelope()

    public function testListReturnsEmptyForEnvelopeWithNoRowsKey(): void
    {
        // An assoc response with no recognised rows key (e.g. XWiki's
        // top-level wiki/space representation) must not be iterated as rows.
        $this->router->method('call')->willReturn(['links' => [['rel' => 'x', 'href' => 'y']], 'syntaxes' => ['xwiki/2.1']]);
        $this->assertSame([], $this->provider->list('r', 's', 'o'));
    }//end testListReturnsEmptyForEnvelopeWithNoRowsKey()

    public function testGetFetchesEncodedReference(): void
    {
        $this->router->expects($this->once())
            ->method('call')
            ->with(
                $this->provider,
                'GET',
                rawurlencode('Space.Page'),
                ['query' => ['register' => 'r', 'schema' => 's', 'object' => 'o']]
            )
            ->willReturn(['reference' => 'Space.Page', 'title' => 'Page', 'content' => '<p>hello</p>']);

        $row = $this->provider->get('r', 's', 'o', 'Space.Page');
        $this->assertSame('Space.Page', $row['id']);
        $this->assertSame('<p>hello</p>', $row['content']);
    }//end testGetFetchesEncodedReference()

    public function testCreateMergesContextIntoBodyAndNormalises(): void
    {
        $this->router->expects($this->once())
            ->method('call')
            ->with(
                $this->provider,
                'POST',
                '',
                ['body' => ['reference' => 'https://wiki/bin/view/X/Y', 'register' => 'r', 'schema' => 's', 'object' => 'o']]
            )
            ->willReturn(['reference' => 'X.Y', 'title' => 'Y', 'space' => 'X']);

        $row = $this->provider->create('r', 's', 'o', ['reference' => 'https://wiki/bin/view/X/Y']);
        $this->assertSame('X.Y', $row['id']);
        $this->assertSame('Y', $row['title']);
    }//end testCreateMergesContextIntoBodyAndNormalises()

    public function testUpdatePutsEncodedReference(): void
    {
        $this->router->expects($this->once())
            ->method('call')
            ->with(
                $this->provider,
                'PUT',
                rawurlencode('Old.Ref'),
                ['body' => ['reference' => 'New.Ref', 'register' => 'r', 'schema' => 's', 'object' => 'o']]
            )
            ->willReturn(['reference' => 'New.Ref', 'title' => 'New']);

        $row = $this->provider->update('r', 's', 'o', 'Old.Ref', ['reference' => 'New.Ref']);
        $this->assertSame('New.Ref', $row['id']);
    }//end testUpdatePutsEncodedReference()

    public function testDeleteCallsRouterWithDeleteAndContext(): void
    {
        $this->router->expects($this->once())
            ->method('call')
            ->with(
                $this->provider,
                'DELETE',
                rawurlencode('Space.Page'),
                ['query' => ['register' => 'r', 'schema' => 's', 'object' => 'o']]
            )
            ->willReturn([]);

        $this->provider->delete('r', 's', 'o', 'Space.Page');
    }//end testDeleteCallsRouterWithDeleteAndContext()

    public function testHealthDefersToRouterProbe(): void
    {
        $this->router->expects($this->once())
            ->method('probe')
            ->with($this->provider)
            ->willReturn(['status' => 'unavailable', 'authStatus' => 'expired', 'message' => 'token refresh failed']);

        $health = $this->provider->health();
        $this->assertSame('unavailable', $health['status']);
        $this->assertSame('expired', $health['authStatus']);
        $this->assertSame('token refresh failed', $health['message']);
    }//end testHealthDefersToRouterProbe()

}//end class
