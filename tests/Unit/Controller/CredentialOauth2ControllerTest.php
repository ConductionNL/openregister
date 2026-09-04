<?php

/**
 * CredentialOauth2ControllerTest — the two roles of one callback, and the refusals.
 *
 * The properties worth pinning are the ones that would be invisible if they broke.
 * A relay must make NO token request, which is asserted by failing the test if the
 * connect service is touched at all. Every rejection branch must register a
 * brute-force attempt, because ADR-082's whole finding is that the attribute alone
 * does nothing. And a failed exchange must leave the user on a redirect that says
 * so without quoting the provider, because the alternative is an oracle for forging
 * a state.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
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
 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-a-relay-forwards-a-code-and-never-exchanges-it
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\CredentialOauth2Controller;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\OAuth2ConnectionRepository;
use OCA\OpenRegister\Service\Credential\OAuth2ConnectService;
use OCA\OpenRegister\Service\Credential\OAuth2Endpoints;
use OCA\OpenRegister\Service\Credential\OAuth2RelayGuard;
use OCA\OpenRegister\Service\Credential\OAuth2StateService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Controller\CredentialOauth2Controller
 */
class CredentialOauth2ControllerTest extends TestCase {
	/** @var string This instance's own callback URL. */
	private const OWN_CALLBACK = 'https://home.example/apps/openregister/oauth2/callback';

	/** @var integer How many brute-force attempts were registered. */
	private int $attempts = 0;

	/** @var integer How many times the connect service was asked to complete a flow. */
	private int $completions = 0;

	/** @var array<int, array<string, mixed>> Every local disable performed. */
	private array $disables = [];

	protected function setUp(): void {
		$this->attempts = 0;
		$this->completions = 0;
		$this->disables = [];
	}

	public function testARelayForwardsToAnAllowListedTenantAndExchangesNothing(): void {
		$destination = 'https://tenant.example/apps/openregister/oauth2/callback';
		$controller = $this->makeController(
			params: ['state' => 'STATE_VALUE_HERE', 'code' => 'AUTH_CODE_HERE'],
			unverifiedClaims: ['cb' => $destination],
			relayPermits: true
		);

		$response = $controller->callback();

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertStringStartsWith($destination . '?', $response->getRedirectURL());
		$this->assertStringContainsString('code=AUTH_CODE_HERE', $response->getRedirectURL());
		$this->assertSame(0, $this->completions, 'a relay must never exchange a code');
	}

	public function testARelayRefusesAnUnknownTargetAndRegistersTheAttempt(): void {
		$controller = $this->makeController(
			params: ['state' => 'STATE_VALUE_HERE', 'code' => 'AUTH_CODE_HERE'],
			unverifiedClaims: ['cb' => 'https://evil.example/apps/openregister/oauth2/callback'],
			relayPermits: false
		);

		$response = $controller->callback();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(1, $this->attempts);
		$this->assertSame(0, $this->completions);
	}

	public function testAnUnverifiableStateIsRefusedAndRegistersTheAttempt(): void {
		$controller = $this->makeController(
			params: ['state' => 'STATE_VALUE_HERE', 'code' => 'AUTH_CODE_HERE'],
			unverifiedClaims: ['cb' => self::OWN_CALLBACK],
			consumed: null
		);

		$response = $controller->callback();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(1, $this->attempts);
		$this->assertSame(0, $this->completions, 'a state that did not redeem must not reach the exchange');
	}

	public function testACallbackWithoutACodeIsRefused(): void {
		$controller = $this->makeController(
			params: ['state' => 'STATE_VALUE_HERE', 'code' => ''],
			unverifiedClaims: ['cb' => self::OWN_CALLBACK]
		);

		$response = $controller->callback();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(1, $this->attempts);
	}

	public function testAValueThatIsNotAStateIsRefused(): void {
		$controller = $this->makeController(
			params: ['state' => 'rubbish', 'code' => 'AUTH_CODE_HERE'],
			unverifiedClaims: null
		);

		$response = $controller->callback();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(1, $this->attempts);
	}

	public function testASuccessfulCallbackRedirectsToTheReturnUrlDeclaredAtStart(): void {
		$controller = $this->makeController(
			params: ['state' => 'STATE_VALUE_HERE', 'code' => 'AUTH_CODE_HERE'],
			unverifiedClaims: ['cb' => self::OWN_CALLBACK],
			consumed: ['claims' => ['cb' => self::OWN_CALLBACK, 'r' => '/settings/user/additional'], 'verifier' => 'VERIFIER_HERE']
		);

		$response = $controller->callback();

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertStringEndsWith('?connected=ok', $response->getRedirectURL());
		$this->assertSame(1, $this->completions);
	}

	public function testAFailedExchangeRedirectsWithAFailureMarkerAndQuotesNoProvider(): void {
		$controller = $this->makeController(
			params: ['state' => 'STATE_VALUE_HERE', 'code' => 'AUTH_CODE_HERE'],
			unverifiedClaims: ['cb' => self::OWN_CALLBACK],
			consumed: ['claims' => ['cb' => self::OWN_CALLBACK, 'r' => '/settings/user/additional'], 'verifier' => 'VERIFIER_HERE'],
			completeThrows: new RuntimeException('token endpoint returned error invalid_client for CLIENT_ID_HERE')
		);

		$response = $controller->callback();

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertStringEndsWith('?connected=failed', $response->getRedirectURL());
		$this->assertStringNotContainsString('invalid_client', $response->getRedirectURL());
		$this->assertStringNotContainsString('CLIENT_ID_HERE', $response->getRedirectURL());
	}

	public function testAnOffInstanceReturnUrlFallsBackToPersonalSettings(): void {
		$controller = $this->makeController(
			params: ['state' => 'STATE_VALUE_HERE', 'code' => 'AUTH_CODE_HERE'],
			unverifiedClaims: ['cb' => self::OWN_CALLBACK],
			consumed: ['claims' => ['cb' => self::OWN_CALLBACK, 'r' => 'https://evil.example/steal'], 'verifier' => 'VERIFIER_HERE']
		);

		$response = $controller->callback();

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertStringStartsWith('https://home.example/settings/user/additional', $response->getRedirectURL());
	}

	public function testTheClientMetadataIdentifiesItselfByItsOwnUrl(): void {
		$controller = $this->makeController(params: []);

		$response = $controller->clientMetadata();
		$data = $response->getData();

		$this->assertSame('https://home.example/apps/openregister/oauth2/client-metadata.json', $data['client_id']);
		$this->assertSame([self::OWN_CALLBACK], $data['redirect_uris']);
		$this->assertTrue($data['dpop_bound_access_tokens']);
	}

	public function testStartRefusesAnUnauthenticatedCaller(): void {
		$controller = $this->makeController(params: ['provider' => 'mastodon'], authenticated: false);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->start()->getStatus());
	}

	public function testDisconnectRevokesUpstreamThenDisablesLocally(): void {
		$controller = $this->makeController(
			params: [],
			manageable: ['provider' => 'mastodon', 'scope' => 'personal', 'owner' => 'alice']
		);

		$response = $controller->disconnect(id: 'cred-1');

		$this->assertSame(['status' => 'disabled', 'revoked' => true], $response->getData());
		$this->assertSame('', $this->disables[0]['lastError']);
	}

	public function testAnUnreachableProviderStillDisconnectsLocally(): void {
		// The branch that matters. A provider that cannot be reached must never keep a
		// tenant connected: the alternative is a credential nobody can switch off
		// because somebody else's server is down.
		$controller = $this->makeController(
			params: [],
			manageable: ['provider' => 'mastodon', 'scope' => 'personal', 'owner' => 'alice'],
			revokeResult: null
		);

		$response = $controller->disconnect(id: 'cred-1');

		$this->assertSame('disabled', $response->getData()['status']);
		$this->assertFalse($response->getData()['revoked'], 'the answer says the upstream revoke did not happen');
		$this->assertSame('revoke_failed', $this->disables[0]['lastError']);
	}

	public function testDisconnectRefusesAConnectionTheCallerMayNotManage(): void {
		$controller = $this->makeController(params: [], manageable: null);

		$response = $controller->disconnect(id: 'cred-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame([], $this->disables, 'nothing may be disabled for a caller who cannot manage it');
	}

	public function testDisconnectRefusesAnUnauthenticatedCaller(): void {
		$controller = $this->makeController(params: [], authenticated: false);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->disconnect(id: 'cred-1')->getStatus());
	}

	public function testAFailedLocalDisableIsReportedRatherThanClaimedAsSuccess(): void {
		$controller = $this->makeController(
			params: [],
			manageable: ['provider' => 'mastodon', 'scope' => 'personal', 'owner' => 'alice'],
			disableFails: true
		);

		$this->assertSame(
			Http::STATUS_INTERNAL_SERVER_ERROR,
			$controller->disconnect(id: 'cred-1')->getStatus()
		);
	}

	/**
	 * Build the controller with scripted collaborators.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param array<string, mixed>|null $unverifiedClaims What parseUnverified() answers.
	 * @param array<string, mixed>|null $consumed What consume() answers.
	 * @param boolean $relayPermits Whether the relay guard permits the destination.
	 * @param \Throwable|null $completeThrows A failure the connect service raises.
	 * @param boolean $authenticated Whether a user session exists.
	 * @param array<string, mixed>|null $manageable The stored connection a disconnect targets, or null when there is none.
	 * @param string|null $revokeResult What the upstream revoke reports, or null to have it throw.
	 * @param boolean $disableFails Whether the local disable fails.
	 *
	 * @return CredentialOauth2Controller The controller under test.
	 */
	private function makeController(
		array $params,
		?array $unverifiedClaims = null,
		?array $consumed = ['claims' => ['cb' => self::OWN_CALLBACK], 'verifier' => 'VERIFIER_HERE'],
		bool $relayPermits = false,
		?\Throwable $completeThrows = null,
		bool $authenticated = true,
		?array $manageable = null,
		?string $revokeResult = '',
		bool $disableFails = false,
	): CredentialOauth2Controller {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);
		$request->method('getRemoteAddress')->willReturn('198.51.100.7');

		$states = $this->createMock(OAuth2StateService::class);
		$states->method('parseUnverified')->willReturn($unverifiedClaims);
		$states->method('consume')->willReturn($consumed);

		$relayGuard = $this->createMock(OAuth2RelayGuard::class);
		$relayGuard->method('permits')->willReturn($relayPermits);

		$connect = $this->createMock(OAuth2ConnectService::class);
		if ($completeThrows !== null) {
			$connect->method('complete')->willReturnCallback(
				function () use ($completeThrows): string {
					$this->completions++;
					throw $completeThrows;
				}
			);
		} else {
			$connect->method('complete')->willReturnCallback(
				function (): string {
					$this->completions++;
					return 'minted-uuid';
				}
			);
		}

		$throttler = $this->createMock(IThrottler::class);
		$throttler->method('registerAttempt')->willReturnCallback(
			function (): void {
				$this->attempts++;
			}
		);

		// The real OAuth2Endpoints over a scripted URL generator rather than a mock of
		// it: safeReturnUrl() is a security control, and a mock would assert only that
		// the controller called something.
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static function (string $route): string {
				if ($route === 'openregister.credentialOauth2.callback') {
					return self::OWN_CALLBACK;
				}

				if ($route === 'openregister.credentialOauth2.clientMetadata') {
					return 'https://home.example/apps/openregister/oauth2/client-metadata.json';
				}

				return 'https://home.example/settings/user/additional';
			}
		);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://home.example' . $path
		);
		$endpoints = new OAuth2Endpoints(urlGenerator: $urlGenerator);

		$connections = $this->createMock(OAuth2ConnectionRepository::class);
		if ($manageable === null) {
			$connections->method('findManageable')->willReturn(null);
		} else {
			$entity = new ObjectEntity();
			$entity->setUuid('cred-1');
			$entity->setObject($manageable);
			$connections->method('findManageable')->willReturn($entity);
		}

		$connections->method('disable')->willReturnCallback(
			function (string $credentialId, array $data, string $lastError) use ($disableFails): void {
				if ($disableFails === true) {
					throw new RuntimeException('the object store is down');
				}

				$this->disables[] = ['credentialId' => $credentialId, 'lastError' => $lastError];
			}
		);

		$connect->method('oauth2Provider')->willReturn(['identifier' => 'mastodon', 'kind' => 'oauth2-token-set']);
		$connect->method('revokeUpstream')->willReturnCallback(
			function () use ($revokeResult): string {
				if ($revokeResult === null) {
					throw new RuntimeException('the provider is unreachable');
				}

				return $revokeResult;
			}
		);

		$session = $this->createMock(IUserSession::class);
		if ($authenticated === true) {
			$user = $this->createMock(\OCP\IUser::class);
			$user->method('getUID')->willReturn('alice');
			$session->method('getUser')->willReturn($user);
		} else {
			$session->method('getUser')->willReturn(null);
		}

		return new CredentialOauth2Controller(
			'openregister',
			$request,
			$connect,
			$states,
			$relayGuard,
			$connections,
			$endpoints,
			$session,
			$throttler,
			$this->createMock(LoggerInterface::class)
		);
	}
}
