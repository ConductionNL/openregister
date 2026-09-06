<?php

/**
 * OAuth2ClientResolverTest — where a client secret comes from, and what happens when it does not.
 *
 * There are exactly two legitimate sources for the client of a connection: the one a
 * tenant brought with its own application, and the instance default an administrator
 * configured. Both are brokered credentials, because the third option people reach
 * for — a secret in the app's config table — is precisely the shape ADR-064 exists to
 * close.
 *
 * The interesting assertions here are the refusals. A resolver that answered "no
 * secret" when it merely could not FIND one would send an unauthenticated token
 * request and let the provider's refusal be read as the tenant's fault, which is the
 * failure mode that wastes an afternoon.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\OAuth2ClientResolver;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Credential\OAuth2ClientResolver
 */
class OAuth2ClientResolverTest extends TestCase {
	/** @var array<int, array<string, mixed>> Every resolveInjectable() the resolver made. */
	private array $resolutions = [];

	protected function setUp(): void {
		$this->resolutions = [];
	}

	public function testATenantsOwnApplicationWins(): void {
		$resolver = $this->makeResolver(
			config: ['oauth2_client_id_x' => 'INSTANCE_CLIENT_ID', 'oauth2_client_x' => 'instance-ref'],
			secret: 'YOUR_CLIENT_SECRET_HERE'
		);

		$client = $resolver->resolve(
			credential: ['clientId' => 'TENANT_CLIENT_ID', 'clientCredentialRef' => 'tenant-ref'],
			provider: 'x',
			actingUserId: 'alice'
		);

		$this->assertSame('TENANT_CLIENT_ID', $client['clientId']);
		$this->assertSame('YOUR_CLIENT_SECRET_HERE', $client['clientSecret']);
		$this->assertSame('tenant-ref', $this->resolutions[0]['credentialId'], 'the tenant\'s own secret must be the one resolved');
	}

	public function testTheInstanceDefaultIsUsedWhenTheTenantBroughtNothing(): void {
		$resolver = $this->makeResolver(
			config: ['oauth2_client_id_x' => 'INSTANCE_CLIENT_ID', 'oauth2_client_x' => 'instance-ref'],
			secret: 'YOUR_CLIENT_SECRET_HERE'
		);

		$client = $resolver->resolve(credential: [], provider: 'x', actingUserId: 'alice');

		$this->assertSame('INSTANCE_CLIENT_ID', $client['clientId']);
		$this->assertSame('instance-ref', $this->resolutions[0]['credentialId']);
	}

	public function testAProviderWithNoClientConfiguredAnywhereIsRefused(): void {
		$resolver = $this->makeResolver(config: [], secret: null);

		$this->expectException(CredentialAccessDeniedException::class);
		$this->expectExceptionMessage('no OAuth2 client id is configured');

		$resolver->resolve(credential: [], provider: 'x', actingUserId: 'alice');
	}

	public function testAPublicClientResolvesToAClientIdAndNoSecret(): void {
		// Bluesky and any other public client: an id, no secret, and that is correct
		// rather than a missing configuration.
		$resolver = $this->makeResolver(config: ['oauth2_client_id_bluesky' => 'https://home.example/client-metadata.json'], secret: null);

		$client = $resolver->resolve(credential: [], provider: 'bluesky', actingUserId: 'alice');

		$this->assertSame('https://home.example/client-metadata.json', $client['clientId']);
		$this->assertNull($client['clientSecret']);
		$this->assertSame([], $this->resolutions, 'no credentialRef means nothing to resolve');
	}

	public function testAnUnresolvableBrokerFailsClosedRatherThanReturningNoSecret(): void {
		// The important one. "I could not reach the broker" and "this client needs no
		// secret" are the same return value if this throws nothing, and the second
		// reading sends an unauthenticated token request.
		$resolver = $this->makeResolver(
			config: ['oauth2_client_id_x' => 'INSTANCE_CLIENT_ID', 'oauth2_client_x' => 'instance-ref'],
			secret: null,
			brokerAvailable: false
		);

		$this->expectException(CredentialAccessDeniedException::class);
		$this->expectExceptionMessage('broker is unavailable');

		$resolver->resolve(credential: [], provider: 'x', actingUserId: 'alice');
	}

	public function testTheAssertedUserIsCarriedIntoTheBrokerGuardChain(): void {
		// A callback has no session, so the owner guard has nothing else to evaluate
		// against. Dropping this would make every connect fail once a session was gone.
		$resolver = $this->makeResolver(
			config: ['oauth2_client_id_x' => 'INSTANCE_CLIENT_ID', 'oauth2_client_x' => 'instance-ref'],
			secret: 'YOUR_CLIENT_SECRET_HERE'
		);

		$resolver->resolve(credential: [], provider: 'x', actingUserId: 'alice');

		$this->assertSame('alice', $this->resolutions[0]['actingUserId']);
		$this->assertSame('openregister', $this->resolutions[0]['appId']);
	}

	/**
	 * Build the resolver over a scripted app config and broker.
	 *
	 * @param array<string, string> $config The app-config values.
	 * @param string|null $secret The secret the broker returns.
	 * @param boolean $brokerAvailable Whether the container can resolve the broker at all.
	 *
	 * @return OAuth2ClientResolver The resolver under test.
	 */
	private function makeResolver(array $config, ?string $secret, bool $brokerAvailable = true): OAuth2ClientResolver {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('resolveInjectable')->willReturnCallback(
			function (string $credentialId, string $appId, ?string $actingUserId = null) use ($secret): ?string {
				$this->resolutions[] = [
					'credentialId' => $credentialId,
					'appId' => $appId,
					'actingUserId' => $actingUserId,
				];

				return $secret;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		if ($brokerAvailable === true) {
			$container->method('get')->willReturn($broker);
		} else {
			$container->method('get')->willThrowException(new RuntimeException('container is not booted'));
		}

		return new OAuth2ClientResolver(appConfig: $appConfig, container: $container);
	}
}
