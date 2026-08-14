<?php

/**
 * Unit tests for BrpPersonProvider.
 *
 * Covers:
 *  - metadata matches the leaf spec (id / label / icon / group /
 *    requiredApp / storage strategy / OpenConnector source)
 *  - authRequirements() reports the external-config-via-OpenConnector
 *    OAuth2 + mTLS shape
 *  - isEnabled() mirrors IAppManager::isInstalled('openconnector')
 *  - lookupByBsn() / list() delegate to ExternalIntegrationRouter with a
 *    POST to `personen`, a `RaadpleegMetBurgerservicenummer` body carrying
 *    the BSN, and unwrap the HaalCentraal `personen` / `_embedded.personen`
 *    envelope to a flat person-object list
 *  - the 4-state degraded contract: a ProviderUnavailableException from the
 *    router becomes a `{ unavailable, cause }` descriptor (never a fatal) on
 *    the public lookup method (AD-23)
 *  - health() defers to router->probe()
 *
 * The upstream call path itself (OpenConnector → HaalCentraal REST, OAuth2 +
 * mTLS) is exercised by integration tests; here the router is mocked so we
 * assert the provider's own contract. No real BSN is used.
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
 * @spec openspec/changes/integration-brp-haalcentraal/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- test method names + arrange/act/assert structure make intent obvious; matches KvkProviderTest convention.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention; mirroring KvkProviderTest in this repo.

use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\Providers\BrpPersonProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for BrpPersonProvider.
 */
class BrpPersonProviderTest extends TestCase {

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
	 * @var BrpPersonProvider
	 */
	private BrpPersonProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		$this->router = $this->createMock(ExternalIntegrationRouter::class);
		$this->appManager = $this->createMock(IAppManager::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);

		$this->provider = new BrpPersonProvider(
			router: $this->router,
			appManager: $this->appManager,
			l10n: $l10n,
			logger: $logger,
		);
	}//end setUp()

	public function testMetadataMatchesLeafSpec(): void {
		$this->assertSame('brp-haalcentraal', $this->provider->getId());
		$this->assertSame('BRP Person Register (HaalCentraal)', $this->provider->getLabel());
		$this->assertSame('AccountSearch', $this->provider->getIcon());
		$this->assertSame('external', $this->provider->getGroup());
		$this->assertSame('openconnector', $this->provider->getRequiredApp());
		$this->assertSame('external', $this->provider->getStorageStrategy());
		$this->assertSame('brp-haalcentraal', $this->provider->getOpenConnectorSource());
		$this->assertSame('brp-haalcentraal', BrpPersonProvider::SOURCE_ID);
		$this->assertNull($this->provider->requiresPermission());
	}//end testMetadataMatchesLeafSpec()

	public function testAuthRequirementsAreExternalOAuthAndMtlsViaOpenConnector(): void {
		$auth = $this->provider->authRequirements();
		$this->assertSame('external', $auth['type']);
		$this->assertSame('openconnector', $auth['configuredVia']);
		$this->assertSame('brp-haalcentraal', $auth['source']);
		$this->assertContains('oauth2_client_credentials', $auth['supports']);
		$this->assertContains('mtls', $auth['supports']);
	}//end testAuthRequirementsAreExternalOAuthAndMtlsViaOpenConnector()

	public function testIsEnabledMirrorsOpenConnectorInstall(): void {
		$this->appManager->method('isInstalled')->with('openconnector')->willReturn(true);
		$this->assertTrue($this->provider->isEnabled());
	}//end testIsEnabledMirrorsOpenConnectorInstall()

	public function testLookupByBsnPostsRaadpleegBodyAndUnwrapsPersonen(): void {
		$this->router->expects($this->once())
			->method('callWithMeta')
			->with(
				$this->provider,
				'POST',
				'personen',
				$this->callback(
					static function (array $opts): bool {
						$body = ($opts['body'] ?? []);
						return ($body['type'] ?? null) === 'RaadpleegMetBurgerservicenummer'
							&& ($body['burgerservicenummer'][0] ?? null) === '999993653'
							&& is_array($body['fields'] ?? null) === true
							&& in_array('burgerservicenummer', $body['fields'], true) === true
							&& ($opts['headers']['Accept'] ?? null) === 'application/hal+json';
					}
				)
			)
			->willReturn(
				[
					'body' => ['personen' => [['burgerservicenummer' => '999993653', 'naam' => ['geslachtsnaam' => 'Tester']]]],
					'meta' => ['correlationId' => null, 'durationMs' => 0, 'status' => 200],
				]
			);

		$result = $this->provider->lookupByBsn('999993653');
		$this->assertArrayNotHasKey('unavailable', $result);
		$this->assertSame(1, $result['total']);
		$this->assertSame('999993653', $result['results'][0]['burgerservicenummer']);
	}//end testLookupByBsnPostsRaadpleegBodyAndUnwrapsPersonen()

	public function testLookupUnwrapsEmbeddedPersonenEnvelope(): void {
		$this->router->method('callWithMeta')->willReturn(
			[
				'body' => ['_embedded' => ['personen' => [['burgerservicenummer' => '999993653']]]],
				'meta' => ['correlationId' => null, 'durationMs' => 0, 'status' => 200],
			]
		);

		$result = $this->provider->lookupByBsn('999993653');
		$this->assertSame(1, $result['total']);
		$this->assertSame('999993653', $result['results'][0]['burgerservicenummer']);
	}//end testLookupUnwrapsEmbeddedPersonenEnvelope()

	public function testLookupSurfacesAuditMetadataFromUpstreamResponse(): void {
		// The router surfaces the upstream X-Correlation-ID + round-trip
		// duration + HTTP status; the leaf shapes them into `meta` so the
		// consuming app can persist the Wet-BRP-required audit fields.
		$this->router->method('callWithMeta')->willReturn(
			[
				'body' => ['personen' => [['burgerservicenummer' => '999993653']]],
				'meta' => [
					'correlationId' => 'abcd-1234-correlation',
					'durationMs' => 137,
					'status' => 200,
					'headers' => ['X-Correlation-ID' => 'abcd-1234-correlation'],
				],
			]
		);

		$result = $this->provider->lookupByBsn('999993653');
		$this->assertArrayHasKey('meta', $result);
		$this->assertSame('abcd-1234-correlation', $result['meta']['correlationId']);
		$this->assertSame(137, $result['meta']['durationMs']);
		$this->assertSame(200, $result['meta']['status']);
		// The BSN must NEVER appear in the audit metadata.
		$this->assertStringNotContainsString('999993653', json_encode($result['meta']));
	}//end testLookupSurfacesAuditMetadataFromUpstreamResponse()

	public function testLookupMetaDefaultsWhenRouterOmitsMeta(): void {
		$this->router->method('callWithMeta')->willReturn(
			['body' => ['personen' => [['burgerservicenummer' => '999993653']]]]
		);

		$result = $this->provider->lookupByBsn('999993653');
		$this->assertNull($result['meta']['correlationId']);
		$this->assertSame(0, $result['meta']['durationMs']);
		$this->assertSame(0, $result['meta']['status']);
	}//end testLookupMetaDefaultsWhenRouterOmitsMeta()

	public function testLookupByEmptyBsnShortCircuits(): void {
		$this->router->expects($this->never())->method('callWithMeta');
		$result = $this->provider->lookupByBsn('   ');
		$this->assertSame(
			['results' => [], 'total' => 0, 'meta' => ['correlationId' => null, 'durationMs' => 0, 'status' => 0]],
			$result
		);
	}//end testLookupByEmptyBsnShortCircuits()

	public function testListDelegatesUsingSearchFilterAsBsn(): void {
		$this->router->expects($this->once())
			->method('call')
			->with(
				$this->provider,
				'POST',
				'personen',
				$this->callback(
					static fn (array $opts): bool => ($opts['body']['burgerservicenummer'][0] ?? null) === '999993653'
				)
			)
			->willReturn(['personen' => [['burgerservicenummer' => '999993653']]]);

		$rows = $this->provider->list('', '', '', ['_search' => '999993653']);
		$this->assertCount(1, $rows);
		$this->assertSame('999993653', $rows[0]['burgerservicenummer']);
	}//end testListDelegatesUsingSearchFilterAsBsn()

	public function testListWithoutBsnShortCircuits(): void {
		$this->router->expects($this->never())->method('call');
		$this->assertSame([], $this->provider->list('', '', '', []));
	}//end testListWithoutBsnShortCircuits()

	public function testLookupDegradesOnSourceMissing(): void {
		$this->router->method('callWithMeta')->willThrowException(
			new ProviderUnavailableException(
				'no source',
				ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING
			)
		);

		$result = $this->provider->lookupByBsn('999993653');
		$this->assertTrue($result['unavailable']);
		$this->assertSame('openconnector-source-missing', $result['cause']);
		$this->assertSame([], $result['results']);
		$this->assertSame(0, $result['total']);
	}//end testLookupDegradesOnSourceMissing()

	public function testLookupDegradesOnUpstreamDown(): void {
		$this->router->method('callWithMeta')->willThrowException(
			new ProviderUnavailableException(
				'down',
				ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN
			)
		);

		$result = $this->provider->lookupByBsn('999993653');
		$this->assertTrue($result['unavailable']);
		$this->assertSame('upstream-service-down', $result['cause']);
	}//end testLookupDegradesOnUpstreamDown()

	public function testLookupDegradesOnUnexpectedThrowable(): void {
		$this->router->method('callWithMeta')->willThrowException(new \RuntimeException('boom'));

		$result = $this->provider->lookupByBsn('999993653');
		$this->assertTrue($result['unavailable']);
		$this->assertSame('upstream-service-down', $result['cause']);
	}//end testLookupDegradesOnUnexpectedThrowable()

	public function testHealthDefersToRouterProbe(): void {
		$probe = ['status' => 'ok', 'authStatus' => 'configured', 'message' => null];
		$this->router->expects($this->once())->method('probe')->with($this->provider)->willReturn($probe);
		$this->assertSame($probe, $this->provider->health());
	}//end testHealthDefersToRouterProbe()
}//end class
