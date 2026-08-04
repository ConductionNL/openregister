<?php

/**
 * Unit tests for OpenCorporatesProvider.
 *
 * Covers:
 *  - metadata matches the leaf spec (id / label / icon / group /
 *    requiredApp / storage strategy / OpenConnector source)
 *  - authRequirements() reports the external-config-via-OpenConnector
 *    apikey shape
 *  - isEnabled() mirrors IAppManager::isInstalled('openconnector')
 *  - list() / searchCompanies() delegate to ExternalIntegrationRouter
 *    with the right method + path + query and unwrap the OpenCorporates
 *    `results.companies[].company` envelope to a flat row list
 *  - the 4-state degraded contract: a ProviderUnavailableException from
 *    the router becomes a `{ unavailable, cause }` descriptor (never a
 *    fatal) on searchCompanies() (AD-23)
 *  - health() defers to router->probe()
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
 * @spec openspec/changes/integration-kvk-opencorporates/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- test method names + arrange/act/assert structure make intent obvious; matches XwikiProviderTest convention.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention; mirroring XwikiProviderTest in this repo.

use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\Providers\OpenCorporatesProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for OpenCorporatesProvider.
 */
class OpenCorporatesProviderTest extends TestCase
{

    /**
     * Mocked external-call router.
     *
     * @var ExternalIntegrationRouter&\PHPUnit\Framework\MockObject\MockObject
     */
    private $router;

    /**
     * Mocked NC app manager.
     *
     * @var IAppManager&\PHPUnit\Framework\MockObject\MockObject
     */
    private $appManager;

    /**
     * System under test.
     *
     * @var OpenCorporatesProvider
     */
    private OpenCorporatesProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router     = $this->createMock(ExternalIntegrationRouter::class);
        $this->appManager = $this->createMock(IAppManager::class);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $logger = $this->createMock(LoggerInterface::class);

        $this->provider = new OpenCorporatesProvider(
            router: $this->router,
            appManager: $this->appManager,
            l10n: $l10n,
            logger: $logger,
        );
    }//end setUp()

    public function testMetadataMatchesLeafSpec(): void
    {
        $this->assertSame('opencorporates', $this->provider->getId());
        $this->assertSame('OpenCorporates', $this->provider->getLabel());
        $this->assertSame('Domain', $this->provider->getIcon());
        $this->assertSame('external', $this->provider->getGroup());
        $this->assertSame('openconnector', $this->provider->getRequiredApp());
        $this->assertSame('external', $this->provider->getStorageStrategy());
        $this->assertSame('opencorporates', $this->provider->getOpenConnectorSource());
        $this->assertSame('opencorporates', OpenCorporatesProvider::SOURCE_ID);
        $this->assertNull($this->provider->requiresPermission());
    }//end testMetadataMatchesLeafSpec()

    public function testAuthRequirementsAreExternalApiKeyViaOpenConnector(): void
    {
        $auth = $this->provider->authRequirements();
        $this->assertSame('external', $auth['type']);
        $this->assertSame('openconnector', $auth['configuredVia']);
        $this->assertSame('opencorporates', $auth['source']);
        $this->assertContains('apikey', $auth['supports']);
    }//end testAuthRequirementsAreExternalApiKeyViaOpenConnector()

    public function testIsEnabledMirrorsOpenConnectorInstall(): void
    {
        $this->appManager->method('isInstalled')->with('openconnector')->willReturn(true);
        $this->assertTrue($this->provider->isEnabled());
    }//end testIsEnabledMirrorsOpenConnectorInstall()

    public function testListUnwrapsCompaniesEnvelope(): void
    {
        $this->router->expects($this->once())
            ->method('call')
            ->with(
                $this->provider,
                'GET',
                'companies/search',
                $this->callback(
                    static fn (array $opts): bool => ($opts['query']['q'] ?? null) === 'Acme'
                )
            )
            ->willReturn(
                [
                    'results' => [
                        'companies' => [
                            ['company' => ['name' => 'Acme BV', 'company_number' => '69599084']],
                        ],
                    ],
                ]
            );

        $rows = $this->provider->list('', '', '', ['_search' => 'Acme']);
        $this->assertCount(1, $rows);
        $this->assertSame('Acme BV', $rows[0]['name']);
        $this->assertSame('69599084', $rows[0]['company_number']);
    }//end testListUnwrapsCompaniesEnvelope()

    public function testSearchPassesJurisdictionAndClampsLimit(): void
    {
        $this->router->expects($this->once())
            ->method('call')
            ->with(
                $this->provider,
                'GET',
                'companies/search',
                $this->callback(
                    static function (array $opts): bool {
                        return ($opts['query']['jurisdiction_code'] ?? null) === 'nl'
                            && ($opts['query']['per_page'] ?? null) === '100'
                            && ($opts['query']['q'] ?? null) === 'Bakery';
                    }
                )
            )
            ->willReturn(['results' => ['companies' => []]]);

        $result = $this->provider->searchCompanies('Bakery', 'nl', 999, 1);
        $this->assertSame(100, $result['limit']);
        $this->assertSame(0, $result['total']);
    }//end testSearchPassesJurisdictionAndClampsLimit()

    public function testSearchDegradesOnSourceMissing(): void
    {
        $this->router->method('call')->willThrowException(
            new ProviderUnavailableException(
                'no source',
                ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING
            )
        );

        $result = $this->provider->searchCompanies('Acme', null, 30, 1);
        $this->assertTrue($result['unavailable']);
        $this->assertSame('openconnector-source-missing', $result['cause']);
        $this->assertSame([], $result['results']);
        $this->assertSame(30, $result['limit']);
    }//end testSearchDegradesOnSourceMissing()

    public function testHealthDefersToRouterProbe(): void
    {
        $probe = ['status' => 'ok', 'authStatus' => 'configured', 'message' => null];
        $this->router->expects($this->once())->method('probe')->with($this->provider)->willReturn($probe);
        $this->assertSame($probe, $this->provider->health());
    }//end testHealthDefersToRouterProbe()
}//end class
