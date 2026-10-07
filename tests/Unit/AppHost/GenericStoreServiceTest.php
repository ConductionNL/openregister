<?php

/**
 * Tests for the AppHost GenericStoreService (ADR-080 store plane).
 *
 * Ported from openbuild's RemoteTemplateStoreServiceTest as part of moving the
 * store client into OpenRegister. Per ADR-080 Consequences the SSRF negative
 * controls (private address rejected, non-http(s) scheme rejected, redirect
 * never followed) are NOT optional in this migration — they are the reason the
 * code moved, so they are asserted here directly rather than assumed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/apphost-store-plane/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Service\StoreDescriptor;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for GenericStoreService.
 */
class GenericStoreServiceTest extends TestCase {
	/**
	 * Mock HTTP client factory.
	 *
	 * @var IClientService&MockObject
	 */
	private IClientService&MockObject $clientService;

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->clientService = $this->createMock(IClientService::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Build the service under test.
	 *
	 * @return GenericStoreService
	 */
	private function service(): GenericStoreService {
		return new GenericStoreService(
			clientService: $this->clientService,
			appConfig: $this->appConfig,
			logger: $this->logger
		);

	}//end service()

	/**
	 * A descriptor standing in for a leaf app's store.
	 *
	 * @param string $appId The leaf app id.
	 * @param string $schema The remote schema slug.
	 *
	 * @return StoreDescriptor
	 */
	private function descriptor(string $appId = 'openbuild', string $schema = 'application-template'): StoreDescriptor {
		return new StoreDescriptor(
			appId: $appId,
			schema: $schema,
			defaultRegister: $appId
		);

	}//end descriptor()

	/**
	 * Wire IAppConfig::getValueString to return the given registry config.
	 *
	 * @param string $url The registry base URL.
	 * @param string $register The register segment.
	 * @param string $token The optional read token.
	 *
	 * @return void
	 */
	private function configure(string $url, string $register = 'openbuild', string $token = ''): void {
		$this->appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = '') use ($url, $register, $token): string {
					return match ($key) {
						'registry_url' => $url,
						'registry_register' => $register,
						'registry_token' => $token,
						default => $default,
					};
				}
			);

	}//end configure()

	/**
	 * Stub the HTTP client to return the given body, capturing request options.
	 *
	 * @param string $body The response body.
	 * @param int $status The HTTP status code.
	 * @param array<string, mixed>|null &$options Receives the captured request options.
	 * @param string|null &$url Receives the captured request URL.
	 *
	 * @return void
	 */
	private function stubResponse(string $body, int $status = 200, ?array &$options = null, ?string &$url = null): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			static function (string $u, array $o) use ($response, &$options, &$url): IResponse {
				$url = $u;
				$options = $o;
				return $response;
			}
		);

		$this->clientService->method('newClient')->willReturn($client);

	}//end stubResponse()

	/**
	 * No registry configured means no network call at all — the ADR-080
	 * Decision 4 fallback that lets a store page render built-in items.
	 *
	 * @return void
	 */
	public function testUnconfiguredStoreMakesNoNetworkCall(): void {
		$this->configure(url: '');
		$this->clientService->expects(self::never())->method('newClient');

		$result = $this->service()->search(descriptor: $this->descriptor());

		self::assertSame(GenericStoreService::OUTCOME_NOT_CONFIGURED, $result['outcome']);
		self::assertSame([], $result['cards']);

	}//end testUnconfiguredStoreMakesNoNetworkCall()

	/**
	 * A configured store returns normalised cards.
	 *
	 * @return void
	 */
	public function testSearchReturnsNormalisedCards(): void {
		$this->configure(url: 'https://93.184.216.34/');
		$this->stubResponse(
			body: json_encode(
				[
					'results' => [
						[
							'slug' => 'crm',
							'title' => 'CRM',
							'description' => 'A CRM app',
							'category' => 'sales',
							'version' => '1.0.0',
							'kind' => 'app-template',
						],
					],
				]
			)
		);

		$result = $this->service()->search(descriptor: $this->descriptor());

		self::assertSame(GenericStoreService::OUTCOME_OK, $result['outcome']);
		self::assertCount(1, $result['cards']);
		self::assertSame('crm', $result['cards'][0]['slug']);
		self::assertSame('app-template', $result['cards'][0]['kind']);

	}//end testSearchReturnsNormalisedCards()

	/**
	 * A card never carries the install payload — only the descriptor's fields
	 * plus `kind`. Guards against leaking a manifest (or worse) into a list.
	 *
	 * @return void
	 */
	public function testCardOmitsFieldsOutsideTheDescriptor(): void {
		$this->configure(url: 'https://93.184.216.34/');
		$this->stubResponse(
			body: json_encode(
				[
					'results' => [
						[
							'slug' => 'crm',
							'title' => 'CRM',
							'manifest' => ['pages' => ['secret']],
							'token' => 'should-never-appear',
						],
					],
				]
			)
		);

		$card = $this->service()->search(descriptor: $this->descriptor())['cards'][0];

		self::assertArrayNotHasKey('manifest', $card);
		self::assertArrayNotHasKey('token', $card);

	}//end testCardOmitsFieldsOutsideTheDescriptor()

	/**
	 * SSRF negative control: a private-address registry is rejected before any
	 * request is issued.
	 *
	 * @return void
	 */
	public function testPrivateAddressRegistryIsRejected(): void {
		$this->configure(url: 'http://192.168.1.10/');
		$this->clientService->expects(self::never())->method('newClient');

		$result = $this->service()->search(descriptor: $this->descriptor());

		self::assertSame(GenericStoreService::OUTCOME_UNREACHABLE, $result['outcome']);

	}//end testPrivateAddressRegistryIsRejected()

	/**
	 * SSRF negative control: a non-http(s) scheme is rejected fail-closed.
	 *
	 * @return void
	 */
	public function testNonHttpSchemeRegistryIsRejected(): void {
		$this->configure(url: 'file:///etc/passwd');
		$this->clientService->expects(self::never())->method('newClient');

		$result = $this->service()->search(descriptor: $this->descriptor());

		self::assertSame(GenericStoreService::OUTCOME_UNREACHABLE, $result['outcome']);

	}//end testNonHttpSchemeRegistryIsRejected()

	/**
	 * SSRF behaviour worth pinning: the guard resolves DNS and fails CLOSED,
	 * so an unresolvable registry host is rejected without a network call.
	 *
	 * This is why the positive-path tests above use a literal public IP rather
	 * than a hostname — a made-up hostname does not resolve in CI, so every
	 * case would return `store_unreachable` and the negative controls would
	 * pass for the wrong reason (proving only that everything is rejected).
	 *
	 * @return void
	 */
	public function testUnresolvableRegistryHostIsRejectedFailClosed(): void {
		$this->configure(url: 'https://registry.invalid/');
		$this->clientService->expects(self::never())->method('newClient');

		$result = $this->service()->search(descriptor: $this->descriptor());

		self::assertSame(GenericStoreService::OUTCOME_UNREACHABLE, $result['outcome']);

	}//end testUnresolvableRegistryHostIsRejectedFailClosed()

	/**
	 * SSRF negative control: redirects are never followed, so a public host
	 * cannot bounce the Bearer token at a private/metadata address.
	 *
	 * @return void
	 */
	public function testRegistryFetchNeverFollowsRedirects(): void {
		$this->configure(url: 'https://93.184.216.34/', token: 'secret-token');
		$captured = null;
		$this->stubResponse(body: json_encode(['results' => []]), options: $captured);

		$this->service()->search(descriptor: $this->descriptor());

		self::assertIsArray($captured);
		self::assertArrayHasKey('allow_redirects', $captured);
		self::assertFalse($captured['allow_redirects'], 'registry fetch must not follow redirects');

	}//end testRegistryFetchNeverFollowsRedirects()

	/**
	 * The token travels as a Bearer header and nowhere else.
	 *
	 * @return void
	 */
	public function testTokenIsSentOnlyAsABearerHeader(): void {
		$this->configure(url: 'https://93.184.216.34/', token: 'secret-token');
		$captured = null;
		$url = null;
		$this->stubResponse(body: json_encode(['results' => []]), options: $captured, url: $url);

		$this->service()->search(descriptor: $this->descriptor());

		self::assertSame('Bearer secret-token', $captured['headers']['Authorization']);
		self::assertStringNotContainsString('secret-token', (string)$url);
		self::assertStringNotContainsString('secret-token', json_encode($captured['query']));

	}//end testTokenIsSentOnlyAsABearerHeader()

	/**
	 * An unreachable registry yields a generic outcome, not an upstream error.
	 *
	 * @return void
	 */
	public function testUnreachableRegistryYieldsGenericOutcome(): void {
		$this->configure(url: 'https://93.184.216.34/');
		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException(new RuntimeException('connect timeout to 10.0.0.5'));
		$this->clientService->method('newClient')->willReturn($client);

		$result = $this->service()->search(descriptor: $this->descriptor());

		self::assertSame(GenericStoreService::OUTCOME_UNREACHABLE, $result['outcome']);
		self::assertSame([], $result['cards']);

	}//end testUnreachableRegistryYieldsGenericOutcome()

	/**
	 * A non-2xx status is unreachable, not a successful empty result.
	 *
	 * @return void
	 */
	public function testNon2xxStatusIsUnreachable(): void {
		$this->configure(url: 'https://93.184.216.34/');
		$this->stubResponse(body: 'nope', status: 503);

		$result = $this->service()->search(descriptor: $this->descriptor());

		self::assertSame(GenericStoreService::OUTCOME_UNREACHABLE, $result['outcome']);

	}//end testNon2xxStatusIsUnreachable()

	/**
	 * An unparseable body is distinguishable from an empty one.
	 *
	 * @return void
	 */
	public function testUnparseableBodyYieldsInvalidOutcome(): void {
		$this->configure(url: 'https://93.184.216.34/');
		$this->stubResponse(body: '<html>not json</html>');

		$result = $this->service()->search(descriptor: $this->descriptor());

		self::assertSame(GenericStoreService::OUTCOME_INVALID, $result['outcome']);

	}//end testUnparseableBodyYieldsInvalidOutcome()

	/**
	 * The descriptor drives the URL, so two apps hit two different schemas —
	 * the whole point of generalising the service.
	 *
	 * @return void
	 */
	public function testDescriptorDrivesTheRemoteUrl(): void {
		$this->configure(url: 'https://93.184.216.34/', register: 'openconnector');
		$url = null;
		$this->stubResponse(body: json_encode(['results' => []]), url: $url);

		$this->service()->search(
			descriptor: $this->descriptor(appId: 'openconnector', schema: 'catalog_item')
		);

		self::assertStringContainsString('/apps/openregister/api/objects/openconnector/catalog_item', (string)$url);

	}//end testDescriptorDrivesTheRemoteUrl()

	/**
	 * resolve() trusts the returned slug, not the filter — a registry that
	 * ignores an unknown query param must not yield an arbitrary first row.
	 *
	 * @return void
	 */
	public function testResolveRejectsAMismatchedSlug(): void {
		$this->configure(url: 'https://93.184.216.34/');
		$this->stubResponse(body: json_encode(['results' => [['slug' => 'something-else', 'title' => 'Other']]]));

		self::assertNull($this->service()->resolve(descriptor: $this->descriptor(), slug: 'crm'));

	}//end testResolveRejectsAMismatchedSlug()

	/**
	 * resolve() returns the FULL payload (install needs the manifest), unlike
	 * search() which returns trimmed cards.
	 *
	 * @return void
	 */
	public function testResolveReturnsTheFullPayload(): void {
		$this->configure(url: 'https://93.184.216.34/');
		$this->stubResponse(
			body: json_encode(['results' => [['slug' => 'crm', 'manifest' => ['pages' => []]]]])
		);

		$resolved = $this->service()->resolve(descriptor: $this->descriptor(), slug: 'crm');

		self::assertIsArray($resolved);
		self::assertArrayHasKey('manifest', $resolved);

	}//end testResolveReturnsTheFullPayload()

	/**
	 * A descriptor that opted in to publishing, standing in for learniq's.
	 *
	 * @param array<int, string> $fields The allowed publish fields.
	 * @param array<int, string> $groups The publish groups.
	 *
	 * @return StoreDescriptor
	 */
	private function publishingDescriptor(
		array $fields = ['title', 'description', 'package'],
		array $groups = ['instructors']
	): StoreDescriptor {
		return new StoreDescriptor(
			appId: 'learniq',
			schema: 'shared-course-package',
			defaultRegister: 'learniq',
			publishFields: $fields,
			publishGroups: $groups
		);

	}//end publishingDescriptor()

	/**
	 * Stub the HTTP client's POST, capturing the URL and request options.
	 *
	 * @param string $body The response body.
	 * @param int $status The HTTP status code.
	 * @param array<string, mixed>|null &$options Receives the captured request options.
	 * @param string|null &$url Receives the captured request URL.
	 *
	 * @return void
	 */
	private function stubPost(string $body, int $status = 201, ?array &$options = null, ?string &$url = null): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		$client = $this->createMock(IClient::class);
		$client->expects(self::never())->method('get');
		$client->method('post')->willReturnCallback(
			static function (string $u, array $o) use ($response, &$options, &$url): IResponse {
				$url = $u;
				$options = $o;
				return $response;
			}
		);

		$this->clientService->method('newClient')->willReturn($client);

	}//end stubPost()

	/**
	 * A descriptor that names fields but no group cannot publish, and no client is built.
	 *
	 * @return void
	 */
	public function testPublishRefusesADescriptorThatNamesNoGroup(): void {
		$this->configure(url: 'https://93.184.216.34/');
		$this->clientService->expects(self::never())->method('newClient');
		$this->logger->expects(self::once())->method('error')
			->with(self::stringContains('learniq'));

		$result = $this->service()->publish(
			descriptor: $this->publishingDescriptor(groups: []),
			payload: ['slug' => 'course-package-betoog-1a2b3c4d', 'title' => 'Betoog']
		);

		self::assertSame(GenericStoreService::OUTCOME_NOT_PUBLISHABLE, $result['outcome']);
		self::assertSame('', $result['slug']);

	}//end testPublishRefusesADescriptorThatNamesNoGroup()

	/**
	 * A read-only descriptor, and one that names a group but no fields, cannot publish.
	 *
	 * The read-only case is every descriptor written before publishing existed.
	 *
	 * @return void
	 */
	public function testPublishRefusesADescriptorThatAllowsNoFields(): void {
		$this->configure(url: 'https://93.184.216.34/');
		$this->clientService->expects(self::never())->method('newClient');
		$payload = ['slug' => 'course-package-betoog-1a2b3c4d', 'title' => 'Betoog'];

		$readOnly = $this->service()->publish(descriptor: $this->descriptor(), payload: $payload);
		$noFields = $this->service()->publish(
			descriptor: $this->publishingDescriptor(fields: []),
			payload: $payload
		);

		self::assertSame(GenericStoreService::OUTCOME_NOT_PUBLISHABLE, $readOnly['outcome']);
		self::assertSame(GenericStoreService::OUTCOME_NOT_PUBLISHABLE, $noFields['outcome']);

	}//end testPublishRefusesADescriptorThatAllowsNoFields()

	/**
	 * No registry configured means no publish request at all.
	 *
	 * @return void
	 */
	public function testPublishToAnUnconfiguredStoreMakesNoRequest(): void {
		$this->configure(url: '   ');
		$this->clientService->expects(self::never())->method('newClient');

		$result = $this->service()->publish(
			descriptor: $this->publishingDescriptor(),
			payload: ['slug' => 'course-package-betoog-1a2b3c4d', 'title' => 'Betoog']
		);

		self::assertSame(GenericStoreService::OUTCOME_NOT_CONFIGURED, $result['outcome']);
		self::assertSame('', $result['slug']);

	}//end testPublishToAnUnconfiguredStoreMakesNoRequest()

	/**
	 * Only the slug and the allowed fields travel.
	 *
	 * @return void
	 */
	public function testPublishSendsOnlyAllowedFields(): void {
		$this->configure(url: 'https://93.184.216.34/', register: 'learniq');
		$options = null;
		$this->stubPost(body: json_encode(['slug' => 'course-package-betoog-1a2b3c4d']), options: $options);

		$this->service()->publish(
			descriptor: $this->publishingDescriptor(fields: ['title']),
			payload: [
				'slug' => 'course-package-betoog-1a2b3c4d',
				'title' => 'Betoog',
				'internalNote' => 'stays home',
			]
		);

		$sent = json_decode((string)$options['body'], true);
		self::assertSame(['slug' => 'course-package-betoog-1a2b3c4d', 'title' => 'Betoog'], $sent);
		self::assertSame('application/json', $options['headers']['Content-Type']);

	}//end testPublishSendsOnlyAllowedFields()

	/**
	 * An identity key never travels, even when the descriptor lists it.
	 *
	 * A body carrying the id of an object that already lives on the registry
	 * would replace that object instead of creating one.
	 *
	 * @return void
	 */
	public function testPublishNeverSendsAnIdentityKey(): void {
		$this->configure(url: 'https://93.184.216.34/', register: 'learniq');
		$options = null;
		$this->stubPost(body: json_encode(['slug' => 'course-package-betoog-1a2b3c4d']), options: $options);

		$this->service()->publish(
			descriptor: $this->publishingDescriptor(fields: ['id', 'uuid', '@self', 'title']),
			payload: [
				'slug' => 'course-package-betoog-1a2b3c4d',
				'id' => '00000000-0000-0000-0000-000000000001',
				'uuid' => '00000000-0000-0000-0000-000000000001',
				'@self' => ['id' => '00000000-0000-0000-0000-000000000001'],
				'title' => 'Betoog',
			]
		);

		$sent = json_decode((string)$options['body'], true);
		self::assertArrayNotHasKey('id', $sent);
		self::assertArrayNotHasKey('uuid', $sent);
		self::assertArrayNotHasKey('@self', $sent);
		self::assertSame('Betoog', $sent['title']);

	}//end testPublishNeverSendsAnIdentityKey()

	/**
	 * A payload without a valid slug is refused before any client is built.
	 *
	 * @return void
	 */
	public function testPublishRefusesAPayloadWithoutAValidSlug(): void {
		$this->configure(url: 'https://93.184.216.34/');
		$this->clientService->expects(self::never())->method('newClient');

		$payloads = [
			['title' => 'no slug'],
			['slug' => 42],
			['slug' => 'Course-Package'],
			['slug' => 'course/../package'],
			['slug' => '-leading-hyphen'],
			['slug' => ''],
		];
		foreach ($payloads as $payload) {
			$result = $this->service()->publish(descriptor: $this->publishingDescriptor(), payload: $payload);
			self::assertSame(
				GenericStoreService::OUTCOME_NOT_PUBLISHABLE,
				$result['outcome'],
				'refused payload: ' . json_encode($payload)
			);
		}

	}//end testPublishRefusesAPayloadWithoutAValidSlug()

	/**
	 * A publish is a POST to the descriptor's register and schema.
	 *
	 * @return void
	 */
	public function testPublishPostsToTheDescriptorSchema(): void {
		$this->configure(url: 'https://93.184.216.34/', register: 'learniq');
		$url = null;
		$options = null;
		$this->stubPost(
			body: json_encode(['slug' => 'course-package-betoog-1a2b3c4d']),
			options: $options,
			url: $url
		);

		$this->service()->publish(
			descriptor: $this->publishingDescriptor(),
			payload: ['slug' => 'course-package-betoog-1a2b3c4d', 'title' => 'Betoog']
		);

		self::assertStringEndsWith(
			'/index.php/apps/openregister/api/objects/learniq/shared-course-package',
			(string)$url
		);
		self::assertSame(10, $options['timeout']);
		self::assertSame(10, $options['connect_timeout']);

	}//end testPublishPostsToTheDescriptorSchema()

	/**
	 * SSRF negative control for the write path.
	 *
	 * @return void
	 */
	public function testPublishToAPrivateAddressIsRejected(): void {
		$this->configure(url: 'http://192.168.1.10/');
		$this->clientService->expects(self::never())->method('newClient');

		$result = $this->service()->publish(
			descriptor: $this->publishingDescriptor(),
			payload: ['slug' => 'course-package-betoog-1a2b3c4d', 'title' => 'Betoog']
		);

		self::assertSame(GenericStoreService::OUTCOME_UNREACHABLE, $result['outcome']);
		self::assertSame('', $result['slug']);

	}//end testPublishToAPrivateAddressIsRejected()

	/**
	 * Redirects are refused and the token travels only as a Bearer header.
	 *
	 * @return void
	 */
	public function testPublishNeverFollowsRedirectsAndSendsTheTokenOnlyAsBearer(): void {
		$this->configure(url: 'https://93.184.216.34/', register: 'learniq', token: 'TOKEN_PLACEHOLDER');
		$url = null;
		$options = null;
		$this->stubPost(
			body: json_encode(['slug' => 'course-package-betoog-1a2b3c4d']),
			options: $options,
			url: $url
		);

		$result = $this->service()->publish(
			descriptor: $this->publishingDescriptor(),
			payload: ['slug' => 'course-package-betoog-1a2b3c4d', 'title' => 'Betoog']
		);

		self::assertFalse($options['allow_redirects']);
		self::assertSame('Bearer TOKEN_PLACEHOLDER', $options['headers']['Authorization']);
		self::assertStringNotContainsString('TOKEN_PLACEHOLDER', (string)$url);
		self::assertStringNotContainsString('TOKEN_PLACEHOLDER', (string)$options['body']);
		self::assertStringNotContainsString('TOKEN_PLACEHOLDER', json_encode($result));

	}//end testPublishNeverFollowsRedirectsAndSendsTheTokenOnlyAsBearer()

	/**
	 * A body over 20 MiB is not sent.
	 *
	 * @return void
	 */
	public function testPublishRefusesAnOversizedBody(): void {
		$this->configure(url: 'https://93.184.216.34/');
		$this->clientService->expects(self::never())->method('newClient');

		$result = $this->service()->publish(
			descriptor: $this->publishingDescriptor(),
			payload: [
				'slug' => 'course-package-betoog-1a2b3c4d',
				'package' => str_repeat('a', (20 * 1024 * 1024) + 1),
			]
		);

		self::assertSame(GenericStoreService::OUTCOME_TOO_LARGE, $result['outcome']);

	}//end testPublishRefusesAnOversizedBody()

	/**
	 * A transport failure is unreachable, and the upstream message stays server-side.
	 *
	 * @return void
	 */
	public function testPublishTransportFailureIsUnreachable(): void {
		$this->configure(url: 'https://93.184.216.34/');
		$client = $this->createMock(IClient::class);
		$client->method('post')->willThrowException(new RuntimeException('connect timeout to 10.0.0.5'));
		$this->clientService->method('newClient')->willReturn($client);

		$result = $this->service()->publish(
			descriptor: $this->publishingDescriptor(),
			payload: ['slug' => 'course-package-betoog-1a2b3c4d', 'title' => 'Betoog']
		);

		self::assertSame(GenericStoreService::OUTCOME_UNREACHABLE, $result['outcome']);
		self::assertStringNotContainsString('10.0.0.5', json_encode($result));

	}//end testPublishTransportFailureIsUnreachable()

	/**
	 * Status codes the registry can answer a publish with, and their outcome.
	 *
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function publishStatusProvider(): array {
		return [
			'redirect is unreachable' => [302, GenericStoreService::OUTCOME_UNREACHABLE],
			'validation is rejected' => [422, GenericStoreService::OUTCOME_REJECTED],
			'duplicate is rejected' => [409, GenericStoreService::OUTCOME_REJECTED],
			'no write rights is rejected' => [403, GenericStoreService::OUTCOME_REJECTED],
			'rate limit is itself' => [429, GenericStoreService::OUTCOME_RATE_LIMITED],
			'server error is unreachable' => [503, GenericStoreService::OUTCOME_UNREACHABLE],
		];

	}//end publishStatusProvider()

	/**
	 * A non-2xx answer maps to the outcome that names its remedy.
	 *
	 * @param int $status The registry's status.
	 * @param string $expected The expected outcome.
	 *
	 * @return void
	 *
	 * @dataProvider publishStatusProvider
	 */
	public function testPublishStatusMapsToTheRightOutcome(int $status, string $expected): void {
		$this->configure(url: 'https://93.184.216.34/');
		$this->stubPost(body: '{"message":"upstream detail"}', status: $status);

		$result = $this->service()->publish(
			descriptor: $this->publishingDescriptor(),
			payload: ['slug' => 'course-package-betoog-1a2b3c4d', 'title' => 'Betoog']
		);

		self::assertSame($expected, $result['outcome']);
		self::assertSame('', $result['slug']);
		self::assertStringNotContainsString('upstream detail', json_encode($result));

	}//end testPublishStatusMapsToTheRightOutcome()

	/**
	 * A 2xx with a body that is not a JSON object is invalid, not published.
	 *
	 * @return void
	 */
	public function testPublishUnparseableBodyIsInvalid(): void {
		$this->configure(url: 'https://93.184.216.34/');
		$this->stubPost(body: '<html>not json</html>');

		$result = $this->service()->publish(
			descriptor: $this->publishingDescriptor(),
			payload: ['slug' => 'course-package-betoog-1a2b3c4d', 'title' => 'Betoog']
		);

		self::assertSame(GenericStoreService::OUTCOME_INVALID, $result['outcome']);
		self::assertSame('', $result['slug']);

	}//end testPublishUnparseableBodyIsInvalid()

	/**
	 * A 201 carrying the sent slug is published, and the slug comes back.
	 *
	 * @return void
	 */
	public function testPublishReturnsTheVerifiedSlug(): void {
		$this->configure(url: 'https://93.184.216.34/');
		$this->stubPost(
			body: json_encode(
				[
					'slug' => 'course-package-betoog-1a2b3c4d',
					'title' => 'Betoog',
					'@self' => ['id' => '00000000-0000-0000-0000-000000000002'],
				]
			)
		);

		$result = $this->service()->publish(
			descriptor: $this->publishingDescriptor(),
			payload: ['slug' => 'course-package-betoog-1a2b3c4d', 'title' => 'Betoog']
		);

		self::assertSame(
			['outcome' => GenericStoreService::OUTCOME_OK, 'slug' => 'course-package-betoog-1a2b3c4d'],
			$result
		);

	}//end testPublishReturnsTheVerifiedSlug()

	/**
	 * A 201 carrying another slug is not reported as published.
	 *
	 * @return void
	 */
	public function testPublishRejectsAMismatchedSlug(): void {
		$this->configure(url: 'https://93.184.216.34/');
		$this->stubPost(body: json_encode(['slug' => 'course-package-betoog-1a2b3c4d-2']));
		$this->logger->expects(self::atLeastOnce())->method('warning')
			->with(self::stringContains('course-package-betoog-1a2b3c4d-2'));

		$result = $this->service()->publish(
			descriptor: $this->publishingDescriptor(),
			payload: ['slug' => 'course-package-betoog-1a2b3c4d', 'title' => 'Betoog']
		);

		self::assertSame(GenericStoreService::OUTCOME_INVALID, $result['outcome']);
		self::assertSame('', $result['slug']);

	}//end testPublishRejectsAMismatchedSlug()
}//end class
