<?php

/**
 * Unit tests for MessageDispatchProvider.
 *
 * Covers:
 *  - metadata matches the send-leaf spec (id / label / icon / group /
 *    requiredApp / storage strategy / default OpenConnector source)
 *  - authRequirements() reports the external-config-via-OpenConnector
 *    apikey shape with the five allowed messaging sources
 *  - isEnabled() mirrors IAppManager::isInstalled('openconnector')
 *  - dispatch() delegates to ExternalIntegrationRouter with POST + the
 *    caller-composed body + path, sets the per-call source so the router
 *    resolves it via getOpenConnectorSource(), and resets it afterwards
 *  - dispatch() rejects a source slug outside the fixed allow-list before
 *    the router is touched (no SSRF / source-confusion surface)
 *  - the 4-state degraded contract: a ProviderUnavailableException from
 *    the router becomes a `{ unavailable, cause }` descriptor (never a
 *    fatal) (AD-23)
 *  - list() is empty (send-only leaf) and health() defers to router->probe()
 *
 * The upstream call path itself (OpenConnector → provider REST) is
 * exercised by integration tests; here the router is mocked so we assert
 * the provider's own contract.
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
 * @spec openspec/changes/messaging-dispatch-leaf/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- test method names + arrange/act/assert structure make intent obvious; matches KvkProviderTest convention.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention; mirroring KvkProviderTest in this repo.

use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\Providers\MessageDispatchProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for MessageDispatchProvider.
 */
class MessageDispatchProviderTest extends TestCase {

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
	 * @var MessageDispatchProvider
	 */
	private MessageDispatchProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		$this->router = $this->createMock(ExternalIntegrationRouter::class);
		$this->appManager = $this->createMock(IAppManager::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);

		$this->provider = new MessageDispatchProvider(
			router: $this->router,
			appManager: $this->appManager,
			l10n: $l10n,
			logger: $logger,
		);
	}//end setUp()

	public function testMetadataMatchesLeafSpec(): void {
		$this->assertSame('message-dispatch', $this->provider->getId());
		$this->assertSame('Outbound messaging (SMS / WhatsApp)', $this->provider->getLabel());
		$this->assertSame('MessageText', $this->provider->getIcon());
		$this->assertSame('external', $this->provider->getGroup());
		$this->assertSame('openconnector', $this->provider->getRequiredApp());
		$this->assertSame('external', $this->provider->getStorageStrategy());
		// Default advertised source (the real target is per-call).
		$this->assertSame('whatsapp-cloud-api', $this->provider->getOpenConnectorSource());
		$this->assertSame('whatsapp-cloud-api', MessageDispatchProvider::SOURCE_ID);
	}//end testMetadataMatchesLeafSpec()

	public function testAllowedSourcesAreTheFiveSeededSlugs(): void {
		$this->assertSame(
			['cmcom-sms', 'messagebird-sms', 'twilio-sms', 'whatsapp-cloud-api', 'whatsapp-bsp'],
			MessageDispatchProvider::ALLOWED_SOURCES
		);
	}//end testAllowedSourcesAreTheFiveSeededSlugs()

	public function testAuthRequirementsAreExternalApiKeyViaOpenConnector(): void {
		$auth = $this->provider->authRequirements();
		$this->assertSame('external', $auth['type']);
		$this->assertSame('openconnector', $auth['configuredVia']);
		$this->assertContains('apikey', $auth['supports']);
		$this->assertContains('twilio-sms', $auth['sources']);
		$this->assertContains('whatsapp-cloud-api', $auth['sources']);
	}//end testAuthRequirementsAreExternalApiKeyViaOpenConnector()

	public function testIsEnabledMirrorsOpenConnectorInstall(): void {
		$this->appManager->method('isInstalled')->with('openconnector')->willReturn(true);
		$this->assertTrue($this->provider->isEnabled());
	}//end testIsEnabledMirrorsOpenConnectorInstall()

	public function testListIsEmptyForSendOnlyLeaf(): void {
		$this->router->expects($this->never())->method('call');
		$this->assertSame([], $this->provider->list('', '', '', ['_search' => 'x']));
	}//end testListIsEmptyForSendOnlyLeaf()

	public function testDispatchDelegatesPostToRouterWithBodyAndPath(): void {
		$body = ['From' => '+31600000000', 'To' => '+31611111111', 'Body' => 'Hi'];

		$this->router->expects($this->once())
			->method('call')
			->with(
				$this->provider,
				'POST',
				'2010-04-01/Accounts/ACxxxx/Messages.json',
				$this->callback(
					static function (array $opts) use ($body): bool {
						return $opts['body'] === $body
							&& ($opts['headers']['Accept'] ?? null) === 'application/json'
							&& ($opts['headers']['Content-Type'] ?? null) === 'application/x-www-form-urlencoded';
					}
				)
			)
			->willReturn(['sid' => 'SMxxxx', 'status' => 'queued']);

		$result = $this->provider->dispatch(
			source: 'twilio-sms',
			body: $body,
			path: '2010-04-01/Accounts/ACxxxx/Messages.json',
			headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
		);

		$this->assertSame('sent', $result['status']);
		$this->assertSame('twilio-sms', $result['source']);
		$this->assertSame('SMxxxx', $result['response']['sid']);
	}//end testDispatchDelegatesPostToRouterWithBodyAndPath()

	public function testDispatchSetsAndResetsTheTargetSource(): void {
		$this->router->method('call')->willReturn(['ok' => true]);

		$this->provider->dispatch(source: 'cmcom-sms', body: ['x' => 1], path: 'message');

		// After dispatch the per-call source is reset to the advertised
		// default, so a later contract caller (health/probe) never inherits
		// a stale source.
		$this->assertSame('whatsapp-cloud-api', $this->provider->getOpenConnectorSource());
	}//end testDispatchSetsAndResetsTheTargetSource()

	public function testDispatchRejectsUnknownSourceBeforeRouter(): void {
		$this->router->expects($this->never())->method('call');

		$result = $this->provider->dispatch(source: 'evil-source', body: ['x' => 1], path: 'send');

		$this->assertTrue($result['unavailable']);
		$this->assertSame('openconnector-source-missing', $result['cause']);
	}//end testDispatchRejectsUnknownSourceBeforeRouter()

	public function testDispatchDegradesOnSourceMissing(): void {
		$this->router->method('call')->willThrowException(
			new ProviderUnavailableException(
				'no source',
				ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING
			)
		);

		$result = $this->provider->dispatch(source: 'whatsapp-cloud-api', body: ['x' => 1], path: 'PNID/messages');
		$this->assertTrue($result['unavailable']);
		$this->assertSame('openconnector-source-missing', $result['cause']);
	}//end testDispatchDegradesOnSourceMissing()

	public function testDispatchDegradesOnUpstreamDown(): void {
		$this->router->method('call')->willThrowException(
			new ProviderUnavailableException(
				'down',
				ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN
			)
		);

		$result = $this->provider->dispatch(source: 'messagebird-sms', body: ['x' => 1], path: 'messages');
		$this->assertTrue($result['unavailable']);
		$this->assertSame('upstream-service-down', $result['cause']);
		// Source still reset even on the degraded path.
		$this->assertSame('whatsapp-cloud-api', $this->provider->getOpenConnectorSource());
	}//end testDispatchDegradesOnUpstreamDown()

	public function testHealthDefersToRouterProbe(): void {
		$probe = ['status' => 'ok', 'authStatus' => 'configured', 'message' => null];
		$this->router->expects($this->once())->method('probe')->with($this->provider)->willReturn($probe);
		$this->assertSame($probe, $this->provider->health());
	}//end testHealthDefersToRouterProbe()
}//end class
