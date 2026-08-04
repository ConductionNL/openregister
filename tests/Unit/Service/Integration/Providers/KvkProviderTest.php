<?php

/**
 * Unit tests for KvkProvider.
 *
 * Covers:
 *  - metadata matches the leaf spec (id / label / icon / group /
 *    requiredApp / storage strategy / OpenConnector source)
 *  - authRequirements() reports the external-config-via-OpenConnector
 *    apikey shape
 *  - isEnabled() mirrors IAppManager::isInstalled('openconnector')
 *  - list() / lookupByKvkNumber() / searchCompanies() delegate to
 *    ExternalIntegrationRouter with the right method + path + query and
 *    unwrap the KvK `resultaten` envelope to a flat row list
 *  - the 4-state degraded contract: a ProviderUnavailableException from
 *    the router becomes a `{ unavailable, cause }` descriptor (never a
 *    fatal) on the public lookup/search methods (AD-23)
 *  - health() defers to router->probe()
 *
 * The upstream call path itself (OpenConnector → KvK REST) is exercised
 * by integration tests; here the router is mocked so we assert the
 * provider's own contract.
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
use OCA\OpenRegister\Service\Integration\Providers\KvkProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for KvkProvider.
 */
class KvkProviderTest extends TestCase
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
     * @var KvkProvider
     */
    private KvkProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router     = $this->createMock(ExternalIntegrationRouter::class);
        $this->appManager = $this->createMock(IAppManager::class);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $logger = $this->createMock(LoggerInterface::class);

        $this->provider = new KvkProvider(
            router: $this->router,
            appManager: $this->appManager,
            l10n: $l10n,
            logger: $logger,
        );
    }//end setUp()

    public function testMetadataMatchesLeafSpec(): void
    {
        $this->assertSame('kvk', $this->provider->getId());
        $this->assertSame('KvK Company Register', $this->provider->getLabel());
        $this->assertSame('OfficeBuilding', $this->provider->getIcon());
        $this->assertSame('external', $this->provider->getGroup());
        $this->assertSame('openconnector', $this->provider->getRequiredApp());
        $this->assertSame('external', $this->provider->getStorageStrategy());
        $this->assertSame('kvk', $this->provider->getOpenConnectorSource());
        $this->assertSame('kvk', KvkProvider::SOURCE_ID);
        $this->assertNull($this->provider->requiresPermission());
    }//end testMetadataMatchesLeafSpec()

    public function testAuthRequirementsAreExternalApiKeyViaOpenConnector(): void
    {
        $auth = $this->provider->authRequirements();
        $this->assertSame('external', $auth['type']);
        $this->assertSame('openconnector', $auth['configuredVia']);
        $this->assertSame('kvk', $auth['source']);
        $this->assertContains('apikey', $auth['supports']);
    }//end testAuthRequirementsAreExternalApiKeyViaOpenConnector()

    public function testIsEnabledMirrorsOpenConnectorInstall(): void
    {
        $this->appManager->method('isInstalled')->with('openconnector')->willReturn(true);
        $this->assertTrue($this->provider->isEnabled());
    }//end testIsEnabledMirrorsOpenConnectorInstall()

    public function testListDelegatesToRouterAndUnwrapsResultaten(): void
    {
        $this->router->expects($this->once())
            ->method('call')
            ->with(
                $this->provider,
                'GET',
                'zoeken',
                $this->callback(
                    static function (array $opts): bool {
                        return ($opts['query']['naam'] ?? null) === 'Acme'
                            && ($opts['query']['aantal'] ?? null) === '10';
                    }
                )
            )
            ->willReturn(['resultaten' => [['kvkNummer' => '12345678', 'naam' => 'Acme BV']]]);

        $rows = $this->provider->list('', '', '', ['_search' => 'Acme', '_limit' => 10]);
        $this->assertCount(1, $rows);
        $this->assertSame('12345678', $rows[0]['kvkNummer']);
    }//end testListDelegatesToRouterAndUnwrapsResultaten()

    public function testLookupByKvkNumberReturnsResultsEnvelope(): void
    {
        $this->router->expects($this->once())
            ->method('call')
            ->with(
                $this->provider,
                'GET',
                'zoeken',
                $this->callback(
                    static fn (array $opts): bool => ($opts['query']['kvkNummer'] ?? null) === '69599084'
                )
            )
            ->willReturn(['resultaten' => [['kvkNummer' => '69599084', 'naam' => 'Conduction BV']]]);

        $result = $this->provider->lookupByKvkNumber('69599084');
        $this->assertArrayNotHasKey('unavailable', $result);
        $this->assertSame(1, $result['total']);
        $this->assertSame('Conduction BV', $result['results'][0]['naam']);
    }//end testLookupByKvkNumberReturnsResultsEnvelope()

    public function testLookupByEmptyNumberShortCircuits(): void
    {
        $this->router->expects($this->never())->method('call');
        $result = $this->provider->lookupByKvkNumber('   ');
        $this->assertSame(['results' => [], 'total' => 0], $result);
    }//end testLookupByEmptyNumberShortCircuits()

    public function testLookupDegradesOnSourceMissing(): void
    {
        $this->router->method('call')->willThrowException(
            new ProviderUnavailableException(
                'no source',
                ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING
            )
        );

        $result = $this->provider->lookupByKvkNumber('12345678');
        $this->assertTrue($result['unavailable']);
        $this->assertSame('openconnector-source-missing', $result['cause']);
        $this->assertSame([], $result['results']);
    }//end testLookupDegradesOnSourceMissing()

    public function testSearchDegradesOnUpstreamDown(): void
    {
        $this->router->method('call')->willThrowException(
            new ProviderUnavailableException(
                'down',
                ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN
            )
        );

        $result = $this->provider->searchCompanies('Acme', [], 25, 1);
        $this->assertTrue($result['unavailable']);
        $this->assertSame('upstream-service-down', $result['cause']);
        $this->assertSame(25, $result['limit']);
        $this->assertSame(1, $result['page']);
    }//end testSearchDegradesOnUpstreamDown()

    public function testSearchClampsLimitAndPassesCriteria(): void
    {
        $this->router->expects($this->once())
            ->method('call')
            ->with(
                $this->provider,
                'GET',
                'zoeken',
                $this->callback(
                    static function (array $opts): bool {
                        return ($opts['query']['aantal'] ?? null) === '100'
                            && ($opts['query']['plaats'] ?? null) === 'Utrecht'
                            && ($opts['query']['naam'] ?? null) === 'Bakery';
                    }
                )
            )
            ->willReturn(['resultaten' => []]);

        $result = $this->provider->searchCompanies('Bakery', ['plaats' => 'Utrecht'], 500, 1);
        $this->assertSame(100, $result['limit']);
    }//end testSearchClampsLimitAndPassesCriteria()

    public function testHealthDefersToRouterProbe(): void
    {
        $probe = ['status' => 'ok', 'authStatus' => 'configured', 'message' => null];
        $this->router->expects($this->once())->method('probe')->with($this->provider)->willReturn($probe);
        $this->assertSame($probe, $this->provider->health());
    }//end testHealthDefersToRouterProbe()
}//end class
