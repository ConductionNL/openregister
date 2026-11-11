<?php

/**
 * CredentialOauth2Controller::start() signs exactly the claims the caller is entitled to,
 * cleans up after a failed start, and a refused callback survives a throttler fault.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\CredentialOauth2Controller;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\OAuth2AuthorizationRequest;
use OCA\OpenRegister\Service\Credential\OAuth2ClientNotConfiguredException;
use OCA\OpenRegister\Service\Credential\OAuth2ConnectionRepository;
use OCA\OpenRegister\Service\Credential\OAuth2ConnectService;
use OCA\OpenRegister\Service\Credential\OAuth2Endpoints;
use OCA\OpenRegister\Service\Credential\OAuth2InstanceClientInterface;
use OCA\OpenRegister\Service\Credential\OAuth2InstanceHostInterface;
use OCA\OpenRegister\Service\Credential\OAuth2RegistrationFailedException;
use OCA\OpenRegister\Service\Credential\OAuth2RelayGuard;
use OCA\OpenRegister\Service\Credential\OAuth2StateService;
use OCA\OpenRegister\Service\Credential\OAuth2StateServiceInterface;
use OCA\OpenRegister\Tests\Unit\Controller\Support\CredentialOauth2ControllerFactory;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Service\Credential\OAuth2ClientRegistration;

/**
 * Behaviour of the start flow's claims and cleanup.
 */
class CredentialOauth2ControllerStartBehaviourTest extends TestCase {

	private const CALLBACK = 'https://home.example/apps/openregister/oauth2/callback';

	/**
	 * Claims passed to issue().
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $issued = [];

	/**
	 * Nonces withdrawn.
	 *
	 * @var string[]
	 */
	private array $withdrawn = [];

	/**
	 * Discarded client credentials: [credentialId, scope].
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $discarded = [];

	/**
	 * Hosts asked of the instance-host validator.
	 *
	 * @var string[]
	 */
	private array $hostsAsked = [];

	/**
	 * Logged messages: [level, message].
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $log = [];

	/**
	 * Build the controller.
	 *
	 * @param array<string, mixed>  $params   Request parameters.
	 * @param array<string, mixed>  $provider The catalogue provider entry.
	 * @param array<string, object> $throws   Collaborator method => exception to throw.
	 * @param string|null           $mints    A client credential ensureInstanceClient() mints, or null.
	 * @param bool                  $cleanupFails Whether withdraw() and discard() throw.
	 * @param bool                  $throttlerFails Whether registerAttempt() throws.
	 *
	 * @return CredentialOauth2Controller
	 */
	private function controller(
		array $params = [],
		array $provider = ['identifier' => 'linkedin', 'oauth2' => ['defaultScopes' => ['openid', 'profile']]],
		array $throws = [],
		?string $mints = null,
		bool $cleanupFails = false,
		bool $throttlerFails = false,
	): CredentialOauth2Controller {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => array_key_exists($key, $params) === true ? $params[$key] : $default
		);
		$request->method('getRemoteAddress')->willReturn('198.51.100.7');

		$states = $this->createMock(OAuth2StateService::class);
		$states->method('issue')->willReturnCallback(
			function (array $claims) use ($throws): array {
				$this->issued[] = $claims;
				if (isset($throws['issue']) === true) {
					throw $throws['issue'];
				}

				return ['state' => 'STATE', 'nonce' => 'nonce-1', 'verifier' => 'v', 'challenge' => 'CH'];
			}
		);
		$states->method('withdraw')->willReturnCallback(
			function (string $nonce) use ($cleanupFails): void {
				if ($cleanupFails === true) {
					throw new RuntimeException('vault down');
				}

				$this->withdrawn[] = $nonce;
			}
		);
		$states->method('parseUnverified')->willReturn(null);

		$connect = $this->createMock(OAuth2ConnectService::class);
		$connect->method('oauth2Provider')->willReturn($provider);
		$connect->method('ensureInstanceClient')->willReturnCallback(
			static function (OAuth2ClientRegistration $registration) use ($mints, $throws): array {
				$claims = $registration->claims;
				if (isset($throws['ensureInstanceClient']) === true) {
					throw $throws['ensureInstanceClient'];
				}

				if ($mints === null) {
					return $claims;
				}

				return array_merge($claims, ['cl' => 'client-id', OAuth2InstanceClientInterface::MINTED_KEY => $mints]);
			}
		);
		$connect->method('authorizationRequest')->willReturnCallback(
			static fn (string $redirectUri, array $issued): OAuth2AuthorizationRequest => new OAuth2AuthorizationRequest($redirectUri, $issued['state'], $issued['challenge'])
		);
		$connect->method('authorizationUrl')->willReturnCallback(
			static function (array $provider, array $claims, OAuth2AuthorizationRequest $request) use ($throws): string {
				if (isset($throws['authorizationUrl']) === true) {
					throw $throws['authorizationUrl'];
				}

				return 'https://provider.example/authorize?state='.$request->state.'&cc='.$request->challenge;
			}
		);

		$connections = $this->createMock(OAuth2ConnectionRepository::class);
		$connections->method('gatedOrganisation')->willReturnCallback(
			static fn (string $uid, string $requestedScope): ?string => match ($requestedScope) {
				'organisation' => 'org-1',
				'explode'      => throw new RuntimeException('organisation store down'),
				default        => null,
			}
		);
		$connections->method('findManageable')->willReturnCallback(
			static fn (string $credentialId, string $uid): ?ObjectEntity => $credentialId === 'mine' ? new ObjectEntity() : null
		);
		$connections->method('discard')->willReturnCallback(
			function (string $credentialId, string $scope) use ($cleanupFails): void {
				if ($cleanupFails === true) {
					throw new RuntimeException('store down');
				}

				$this->discarded[] = [$credentialId, $scope];
			}
		);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route): string => $route === 'openregister.credentialOauth2.callback' ? self::CALLBACK : 'https://home.example/apps/openregister/'
		);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://home.example'.$path);

		$throttler = $this->createMock(IThrottler::class);
		if ($throttlerFails === true) {
			$throttler->method('registerAttempt')->willThrowException(new RuntimeException('throttle store down'));
		}

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->log[] = [$level, $message];
				}
			);
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$host = $this->createMock(OAuth2InstanceHostInterface::class);
		$host->method('origin')->willReturnCallback(
			function (string $candidate): string {
				$this->hostsAsked[] = $candidate;
				if ($candidate === '' || str_starts_with($candidate, 'https://') === false) {
					throw new InvalidArgumentException('unsafe host');
				}

				return rtrim($candidate, '/');
			}
		);

		return CredentialOauth2ControllerFactory::create(
			'openregister',
			$request,
			$connect,
			$states,
			$this->createMock(OAuth2RelayGuard::class),
			$connections,
			new OAuth2Endpoints(urlGenerator: $urlGenerator),
			$session,
			$throttler,
			$logger,
			$host
		);
	}

	public function testDefaultStartSignsTheCallerProviderScopeAndDefaults(): void {
		$response = $this->controller(['provider' => 'linkedin'])->start();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['authorizationUrl' => 'https://provider.example/authorize?state=STATE&cc=CH', 'expiresIn' => OAuth2StateServiceInterface::STATE_TTL_SECONDS],
			$response->getData()
		);
		$this->assertCount(1, $this->issued);
		$claims = $this->issued[0];
		$this->assertSame(['u', 'p', 's', 'o', 'h', 'sc', 'a', 'nm', 'cl', 'cr', 'cid', 'r', 'cb'], array_keys($claims));
		$this->assertSame(
			['alice', 'linkedin', 'personal', null, null, ['openid', 'profile'], [], 'linkedin', '', '', ''],
			array_slice(array_values($claims), 0, 11)
		);
		$this->assertSame(self::CALLBACK, $claims['cb']);
		$this->assertSame([], $this->hostsAsked, 'a provider without baseUrlFrom needs no host');
	}

	public function testRequestedScopesNameAndRefsAreTrimmedAndSigned(): void {
		$this->controller(
			[
				'provider'      => 'linkedin',
				'scope'         => 'organisation',
				'scopes'        => ['email', 7],
				'name'          => '  Work account ',
				'clientId'      => ' cid ',
				'credentialRef' => ' ref ',
				'credentialId'  => ' mine ',
			]
		)->start();

		$claims = $this->issued[0];
		$this->assertSame('organisation', $claims['s']);
		$this->assertSame('org-1', $claims['o']);
		$this->assertSame(['email', '7'], $claims['sc']);
		$this->assertSame('Work account', $claims['nm']);
		$this->assertSame(['cid', 'ref', 'mine'], [$claims['cl'], $claims['cr'], $claims['cid']]);
	}

	public function testEmptyOrNonArrayScopesFallBackToTheProviderDefaults(): void {
		$this->controller(['provider' => 'linkedin', 'scopes' => []])->start();
		$this->controller(['provider' => 'linkedin', 'scopes' => 'email'])->start();
		$this->controller(['provider' => 'x', 'scopes' => null], ['identifier' => 'x'])->start();

		$this->assertSame([['openid', 'profile'], ['openid', 'profile'], []], array_column($this->issued, 'sc'));
	}

	public function testOnlyWellFormedAppIdsAreGranted(): void {
		$this->controller(['provider' => 'linkedin', 'allowedApps' => [' procest ', 'Bad App', '', 'my_app-2', 'x;y']])->start();
		$this->controller(['provider' => 'linkedin', 'allowedApps' => 'procest'])->start();

		$this->assertSame([['procest', 'my_app-2'], []], array_column($this->issued, 'a'));
	}

	public function testAPerAccountProviderPinsTheValidatedHost(): void {
		$this->controller(
			['provider' => 'mastodon', 'instanceBaseUrl' => 'https://social.example/'],
			['identifier' => 'mastodon', 'baseUrlFrom' => 'instanceBaseUrl']
		)->start();

		$this->assertSame(['https://social.example/'], $this->hostsAsked);
		$this->assertSame('https://social.example', $this->issued[0]['h']);
	}

	public function testAPerAccountProviderWithAnUnsafeHostIsA400AndSignsNothing(): void {
		$response = $this->controller(
			['provider' => 'mastodon', 'instanceBaseUrl' => 'http://10.0.0.1'],
			['identifier' => 'mastodon', 'baseUrlFrom' => 'instanceBaseUrl']
		)->start();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Invalid connection request'], $response->getData());
		$this->assertSame([], $this->issued);
	}

	public function testAReauthorisationOfSomeoneElsesCredentialIsRefusedAndLogged(): void {
		$response = $this->controller(['provider' => 'linkedin', 'credentialId' => 'theirs'])->start();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Connection not permitted'], $response->getData());
		$this->assertSame([], $this->issued);
		$this->assertSame('warning', $this->log[0][0]);
		$this->assertStringContainsString('refused a connection start', $this->log[0][1]);
	}

	public function testTheMintedMarkerIsStrippedBeforeSigning(): void {
		$this->controller(['provider' => 'mastodon'], mints: 'minted-cred')->start();

		$this->assertArrayNotHasKey(OAuth2InstanceClientInterface::MINTED_KEY, $this->issued[0]);
		$this->assertSame('client-id', $this->issued[0]['cl']);
		$this->assertSame([], $this->discarded);
	}

	public function testANotConfiguredClientAfterMintingWithdrawsTheStateAndDiscardsTheClient(): void {
		$response = $this->controller(
			['provider' => 'mastodon', 'scope' => 'organisation'],
			throws: ['authorizationUrl' => new OAuth2ClientNotConfiguredException('no client')],
			mints: 'minted-cred'
		)->start();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['message' => 'This provider is not configured on this server'], $response->getData());
		$this->assertSame(['nonce-1'], $this->withdrawn);
		$this->assertSame([['minted-cred', 'organisation']], $this->discarded);
	}

	public function testANotConfiguredClientBeforeAnyStateHasNothingToClean(): void {
		$response = $this->controller(
			['provider' => 'linkedin'],
			throws: ['ensureInstanceClient' => new OAuth2ClientNotConfiguredException('no client')]
		)->start();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame([], $this->withdrawn);
		$this->assertSame([], $this->discarded);
	}

	public function testARefusedRegistrationIsA502AndLogged(): void {
		$response = $this->controller(
			['provider' => 'mastodon'],
			throws: ['ensureInstanceClient' => new OAuth2RegistrationFailedException('server said no')]
		)->start();

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame(
			[['warning', '[CredentialOauth2Controller] the provider server did not register a client: server said no']],
			$this->log
		);
	}

	public function testAGenuineFaultIsA500LoggedByClassAndMessageWithCleanup(): void {
		$response = $this->controller(
			['provider' => 'mastodon'],
			throws: ['authorizationUrl' => new RuntimeException('boom')],
			mints: 'minted-cred'
		)->start();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'Unable to start the connection'], $response->getData());
		$this->assertSame(['nonce-1'], $this->withdrawn);
		$this->assertSame([['minted-cred', 'personal']], $this->discarded);
		$this->assertContains(['error', '[CredentialOauth2Controller] could not start a connection: RuntimeException: boom'], $this->log);
	}

	public function testFailingCleanupIsLoggedAndTheFaultStillAnswers500(): void {
		$response = $this->controller(
			['provider' => 'mastodon'],
			throws: ['authorizationUrl' => new RuntimeException('boom')],
			mints: 'minted-cred',
			cleanupFails: true
		)->start();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$messages = array_column($this->log, 1);
		$this->assertContains('[CredentialOauth2Controller] could not remove the pending state of a failed start: RuntimeException', $messages);
		$this->assertContains('[CredentialOauth2Controller] could not remove the client credential a failed start minted: RuntimeException', $messages);
	}

	public function testAGuardRefusalRaisedWhileSigningIsA500(): void {
		$response = $this->controller(
			['provider' => 'linkedin'],
			throws: ['issue' => new CredentialAccessDeniedException('nope')]
		)->start();

		// A refusal raised after the claims is the server's fault, not the caller's.
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}

	public function testAFaultWhileResolvingTheOrganisationIsA500AndSignsNothing(): void {
		$response = $this->controller(['provider' => 'linkedin', 'scope' => 'explode'])->start();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame([], $this->issued);
		$this->assertSame([['error', '[CredentialOauth2Controller] could not start a connection: RuntimeException: organisation store down']], $this->log);
	}

	public function testARefusedCallbackStaysA400WhenTheThrottlerFails(): void {
		$response = $this->controller(['state' => 'x', 'code' => 'c'], throttlerFails: true)->callback();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Bad Request'], $response->getData());
		$this->assertSame([['warning', '[CredentialOauth2Controller] registerAttempt failed: throttle store down']], $this->log);
	}
}
