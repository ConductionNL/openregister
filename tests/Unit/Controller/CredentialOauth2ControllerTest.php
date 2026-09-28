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
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\OAuth2ClientNotConfiguredException;
use OCA\OpenRegister\Service\Credential\OAuth2ConnectionRepository;
use OCA\OpenRegister\Service\Credential\OAuth2ConnectService;
use OCA\OpenRegister\Service\Credential\OAuth2RegistrationFailedException;
use OCA\OpenRegister\Service\Credential\OAuth2Endpoints;
use OCA\OpenRegister\Service\Credential\OAuth2InstanceClient;
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
 * @uses \OCA\OpenRegister\Service\Credential\OAuth2Endpoints
 * @uses \OCA\OpenRegister\Db\ObjectEntity
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

	/** @var array<int, array{0: string, 1: string}> Every minted client credential a failed start removed, with its scope. */
	private array $discards = [];

	/** @var array<int, array<string, mixed>> The claims every issued state was signed over. */
	private array $issuedClaims = [];

	/** @var array<int, string> The nonce of every pending state a failed start withdrew. */
	private array $withdrawals = [];

	/** @var array<int, string> Every warning the controller logged. */
	private array $warnings = [];

	protected function setUp(): void {
		$this->attempts = 0;
		$this->completions = 0;
		$this->disables = [];
		$this->discards = [];
		$this->issuedClaims = [];
		$this->withdrawals = [];
		$this->warnings = [];
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

	public function testStartReturnsTheAuthorizationUrl(): void {
		$response = $this->makeController(params: ['provider' => 'linkedin'])->start();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('https://provider.example/authorize?state=STATE', $response->getData()['authorizationUrl']);
	}

	public function testStartAnswers409WhenTheProviderHasNoClientConfigured(): void {
		$response = $this->makeController(
			params: ['provider' => 'linkedin'],
			startThrows: ['authorizationUrl' => new OAuth2ClientNotConfiguredException('no OAuth2 client id is configured for provider linkedin')],
		)->start();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['n'], $this->withdrawals, 'a 409 leaves no pending state behind');
	}

	public function testStartAnswers502WhenTheProviderServerWillNotRegisterAClient(): void {
		$response = $this->makeController(
			params: ['provider' => 'mastodon'],
			startThrows: ['ensureInstanceClient' => new OAuth2RegistrationFailedException('application registration failed')],
		)->start();

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame([], $this->issuedClaims, 'a 502 comes before any state is stored');
	}

	public function testStartAnswers403WhenAGuardRefusesTheCaller(): void {
		$response = $this->makeController(
			params: ['provider' => 'linkedin', 'scope' => 'organisation'],
			startThrows: ['gatedOrganisation' => new CredentialAccessDeniedException('only an organisation administrator may connect a shared account')],
		)->start();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertCount(1, $this->warnings, 'a refusal reaches a default install\'s log');
		$this->assertStringContainsString('only an organisation administrator', $this->warnings[0]);
	}

	public function testStartAnswers403ForACredentialTheCallerMayNotReauthorise(): void {
		$response = $this->makeController(params: ['provider' => 'linkedin', 'credentialId' => 'someone-elses'])->start();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testStartAnswers500OnlyForAGenuineFault(): void {
		$afterClaims = $this->makeController(
			params: ['provider' => 'linkedin'],
			startThrows: ['issue' => new RuntimeException('the vault insert failed')],
		)->start();
		$beforeClaims = $this->makeController(
			params: ['provider' => 'linkedin'],
			startThrows: ['gatedOrganisation' => new RuntimeException('the organisation store is down')],
		)->start();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $afterClaims->getStatus());
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $beforeClaims->getStatus());
	}

	public function testStartAnswers400ForAProviderThatIsNotAnOAuth2Connection(): void {
		$response = $this->makeController(
			params: ['provider' => 'github'],
			startThrows: ['oauth2Provider' => new \InvalidArgumentException('provider "github" is not an OAuth2 connection')],
		)->start();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testAGuardRefusalAfterTheClaimsIsTheServersFaultNotTheCallers(): void {
		// An admin-configured client the broker cannot resolve (it is unavailable, or
		// the client credential is not shared) is this server's setup, not the caller.
		$response = $this->makeController(
			params: ['provider' => 'linkedin'],
			startThrows: ['authorizationUrl' => new CredentialAccessDeniedException('credential broker is unavailable to resolve the OAuth2 client secret')],
		)->start();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}

	public function testTheMintedMarkerNeverReachesTheSignedState(): void {
		$response = $this->makeController(params: ['provider' => 'mastodon'], mintsClient: 'minted-client')->start();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertArrayNotHasKey(OAuth2InstanceClient::MINTED_KEY, $this->issuedClaims[0]);
		$this->assertSame('minted-client', $this->issuedClaims[0]['cr']);
		$this->assertSame([], $this->discards, 'a start that succeeds keeps the client it minted');
		$this->assertSame([], $this->withdrawals, 'a start that succeeds keeps its pending state');
	}

	public function testAStartThatFailsAfterMintingAClientRemovesIt(): void {
		$vaultDown = $this->makeController(
			params: ['provider' => 'mastodon'],
			startThrows: ['issue' => new RuntimeException('the vault insert failed')],
			mintsClient: 'minted-client',
		)->start();
		$notConfigured = $this->makeController(
			params: ['provider' => 'mastodon'],
			startThrows: ['authorizationUrl' => new OAuth2ClientNotConfiguredException('no OAuth2 client id is configured for provider mastodon')],
			mintsClient: 'second-client',
		)->start();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $vaultDown->getStatus());
		$this->assertSame(Http::STATUS_CONFLICT, $notConfigured->getStatus());
		$this->assertSame([['minted-client', 'personal'], ['second-client', 'personal']], $this->discards);
	}

	public function testAnOrganisationStartRemovesTheClientFromTheOrganisationScope(): void {
		// The secret was minted under the organisation's vault owner; removing it from
		// the user's vault instead would leave it with nothing pointing at it.
		$response = $this->makeController(
			params: ['provider' => 'mastodon', 'scope' => 'organisation'],
			startThrows: ['authorizationUrl' => new RuntimeException('the catalogue entry is broken')],
			mintsClient: 'org-client',
		)->start();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame([['org-client', 'organisation']], $this->discards);
	}

	public function testAFailedCleanupKeepsTheRefusalAndIsLogged(): void {
		$response = $this->makeController(
			params: ['provider' => 'mastodon'],
			startThrows: ['authorizationUrl' => new OAuth2ClientNotConfiguredException('no OAuth2 client id is configured for provider mastodon')],
			mintsClient: 'minted-client',
			discardFails: true,
		)->start();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString('could not remove the client credential', $this->warnings[0]);
	}

	public function testAStartThatFailsAfterStoringItsStateWithdrawsIt(): void {
		$response = $this->makeController(
			params: ['provider' => 'linkedin'],
			startThrows: ['authorizationUrl' => new RuntimeException('the catalogue entry is broken')],
		)->start();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['n'], $this->withdrawals);
	}

	public function testAStartWhoseStateWasNeverStoredHasNothingToWithdraw(): void {
		$this->makeController(
			params: ['provider' => 'linkedin'],
			startThrows: ['issue' => new RuntimeException('the vault insert failed')],
		)->start();

		$this->assertSame([], $this->withdrawals);
	}

	public function testAFailedWithdrawalKeepsTheRefusalAndIsLogged(): void {
		$response = $this->makeController(
			params: ['provider' => 'linkedin'],
			startThrows: ['authorizationUrl' => new OAuth2ClientNotConfiguredException('no OAuth2 client id is configured for provider linkedin')],
			withdrawFails: true,
		)->start();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString('could not remove the pending state', $this->warnings[0]);
	}

	public function testAFailedStartLeavesAClientItDidNotMintAlone(): void {
		$this->makeController(
			params: ['provider' => 'mastodon'],
			startThrows: ['issue' => new RuntimeException('the vault insert failed')],
		)->start();

		$this->assertSame([], $this->discards);
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
	 * @param array<string, \Throwable> $startThrows A failure per start collaborator method, by method name.
	 * @param string|null $mintsClient The client credential a per-instance start mints, or null when it mints none.
	 * @param boolean $discardFails Whether removing a minted client fails.
	 * @param boolean $withdrawFails Whether withdrawing a pending state fails.
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
		array $startThrows = [],
		?string $mintsClient = null,
		bool $discardFails = false,
		bool $withdrawFails = false,
	): CredentialOauth2Controller {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);
		$request->method('getRemoteAddress')->willReturn('198.51.100.7');

		$states = $this->createMock(OAuth2StateService::class);
		$states->method('parseUnverified')->willReturn($unverifiedClaims);
		$states->method('consume')->willReturn($consumed);
		$states->method('issue')->willReturnCallback(
			function (array $claims) use ($startThrows): array {
				$this->issuedClaims[] = $claims;
				if (isset($startThrows['issue']) === true) {
					throw $startThrows['issue'];
				}

				return ['state' => 'STATE', 'nonce' => 'n', 'verifier' => 'v', 'challenge' => 'CHALLENGE'];
			}
		);
		$states->method('withdraw')->willReturnCallback(
			function (string $nonce) use ($withdrawFails): void {
				if ($withdrawFails === true) {
					throw new RuntimeException('the vault is down');
				}

				$this->withdrawals[] = $nonce;
			}
		);

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

		if (isset($startThrows['oauth2Provider']) === true) {
			$connect->method('oauth2Provider')->willThrowException($startThrows['oauth2Provider']);
		} else {
			$connect->method('oauth2Provider')->willReturn(['identifier' => 'mastodon', 'kind' => 'oauth2-token-set']);
		}

		if (isset($startThrows['ensureInstanceClient']) === true) {
			$connect->method('ensureInstanceClient')->willThrowException($startThrows['ensureInstanceClient']);
		} else {
			$connect->method('ensureInstanceClient')->willReturnCallback(
				static function (array $provider, array $claims) use ($mintsClient): array {
					if ($mintsClient === null) {
						return $claims;
					}

					return array_merge($claims, ['cr' => $mintsClient, OAuth2InstanceClient::MINTED_KEY => $mintsClient]);
				}
			);
		}

		$connections->method('discard')->willReturnCallback(
			function (string $credentialId, string $scope) use ($discardFails): void {
				if ($discardFails === true) {
					throw new RuntimeException('the object store is down');
				}

				$this->discards[] = [$credentialId, $scope];
			}
		);

		if (isset($startThrows['authorizationUrl']) === true) {
			$connect->method('authorizationUrl')->willThrowException($startThrows['authorizationUrl']);
		} else {
			$connect->method('authorizationUrl')->willReturn('https://provider.example/authorize?state=STATE');
		}

		if (isset($startThrows['gatedOrganisation']) === true) {
			$connections->method('gatedOrganisation')->willThrowException($startThrows['gatedOrganisation']);
		}
		$connect->method('revokeUpstream')->willReturnCallback(
			function () use ($revokeResult): string {
				if ($revokeResult === null) {
					throw new RuntimeException('the provider is unreachable');
				}

				return $revokeResult;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
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
			$logger
		);
	}
}
