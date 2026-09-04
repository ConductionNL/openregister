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
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\OAuth2ClientResolver;
use OCA\OpenRegister\Service\Credential\OAuth2ConnectService;
use OCA\OpenRegister\Service\Credential\OAuth2RelayGuard;
use OCA\OpenRegister\Service\Credential\OAuth2StateService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
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

	protected function setUp(): void {
		$this->attempts = 0;
		$this->completions = 0;
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

	/**
	 * Build the controller with scripted collaborators.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param array<string, mixed>|null $unverifiedClaims What parseUnverified() answers.
	 * @param array<string, mixed>|null $consumed What consume() answers.
	 * @param boolean $relayPermits Whether the relay guard permits the destination.
	 * @param \Throwable|null $completeThrows A failure the connect service raises.
	 * @param boolean $authenticated Whether a user session exists.
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
			$this->createMock(OAuth2ClientResolver::class),
			$this->createMock(CredentialStore::class),
			$this->createMock(ObjectService::class),
			$this->createMock(OrganisationService::class),
			$session,
			$urlGenerator,
			$throttler,
			$this->createMock(LoggerInterface::class)
		);
	}
}
