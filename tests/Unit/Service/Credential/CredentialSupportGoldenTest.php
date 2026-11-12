<?php

/**
 * CredentialSupportGoldenTest — pins the app-token, relink, Doriath and update-request outcomes.
 *
 * Covers the four credential support classes before they are restructured:
 * {@see \OCA\OpenRegister\Service\Credential\CredentialAppTokenService} (every
 * accepted and refused broker token, and the exact issued format),
 * {@see \OCA\OpenRegister\Service\Credential\CredentialRelinkNotifier} (who is told
 * what, and that failures are swallowed),
 * {@see \OCA\OpenRegister\Service\Credential\DoriathApplicationRegistrar} (when a
 * per-app application is registered) and
 * {@see \OCA\OpenRegister\Service\Credential\CredentialUpdateRequest} (which edits a
 * request may make). Clocks are fixed values.
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
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Event\CredentialRelinkRequiredEvent;
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialAppTokenService;
use OCA\OpenRegister\Service\Credential\CredentialRelinkNotifier;
use OCA\OpenRegister\Service\Credential\CredentialUpdateRequest;
use OCA\OpenRegister\Service\Configuration\AppSettingsAccess;
use OCA\OpenRegister\Service\Credential\DoriathApplicationRegistrar;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Security\ICredentialsManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\Credential\BrokerTokenBinding;

/**
 * Golden outcomes of the credential support classes.
 */
class CredentialSupportGoldenTest extends TestCase {
	/** @var array<string, string> The vault, by key. */
	private array $vault = [];

	/** @var integer The fixed clock. */
	private int $now = 1000;

	public function testTheIssuedTokenFormatIsStable(): void {
		$service = $this->tokens();
		$this->assertSame('SECRET', $service->registerApp(appId: 'hermiq'));
		$this->assertSame(['openregister/credential-app-key/hermiq' => 'SECRET'], $this->vault);

		$payload = rtrim(strtr(base64_encode('{"appId":"hermiq","credentialId":"cred-1","iat":1000,"exp":1300}'), '+/', '-_'), '=');
		$signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, 'SECRET', true)), '+/', '-_'), '=');
		$this->assertSame($payload . '.' . $signature, $service->issueToken(appId: 'hermiq', credentialId: 'cred-1'));

		$bound = $service->issueToken(appId: 'hermiq', credentialId: 'cred-1', binding: new BrokerTokenBinding(method: 'get', path: 'v1/x'));
		$claims = json_decode(base64_decode(strtr(explode('.', $bound)[0], '-_', '+/')), true);
		$this->assertSame(hash('sha256', 'GET /v1/x'), $claims['req']);

		$unbound = $service->issueToken(appId: 'hermiq', credentialId: 'cred-1', binding: new BrokerTokenBinding(method: 'GET', path: null));
		$this->assertArrayNotHasKey('req', json_decode(base64_decode(strtr(explode('.', $unbound)[0], '-_', '+/')), true));
	}

	public function testAnUnregisteredAppCannotIssue(): void {
		$this->expectException(CredentialAccessDeniedException::class);
		$this->expectExceptionMessage('App "ghost" has no registered signing secret');
		$this->tokens()->issueToken(appId: 'ghost', credentialId: 'c');
	}

	public function testRegistrationIsReportedOnlyForANonEmptySecret(): void {
		$service = $this->tokens();
		$this->assertFalse($service->isRegistered(appId: 'hermiq'));
		$this->vault['openregister/credential-app-key/hermiq'] = '';
		$this->assertFalse($service->isRegistered(appId: 'hermiq'));
		$service->registerApp(appId: 'hermiq');
		$this->assertTrue($service->isRegistered(appId: 'hermiq'));
	}

	public function testAcceptedTokens(): void {
		$service = $this->tokens();
		$service->registerApp(appId: 'hermiq');

		$this->assertSame(['appId' => 'hermiq', 'credentialId' => 'cred-1'], $service->verify(token: $service->issueToken(appId: 'hermiq', credentialId: 'cred-1')));
		$this->assertSame(
			['appId' => 'hermiq', 'credentialId' => 'cred-1'],
			$service->verify(token: $service->issueToken(appId: 'hermiq', credentialId: 'cred-1', binding: new BrokerTokenBinding(method: 'get', path: '/v1/x')), request: new BrokerTokenBinding(method: 'GET', path: 'v1/x'))
		);
		$this->assertSame(
			['appId' => 'hermiq', 'credentialId' => 'cred-1'],
			$service->verify(token: $service->issueToken(appId: 'hermiq', credentialId: 'cred-1'), request: new BrokerTokenBinding(method: 'POST', path: '/other'))
		);

		$token = $service->issueToken(appId: 'hermiq', credentialId: 'cred-1');
		$this->now = 1299;
		$this->assertSame('cred-1', $service->verify(token: $token)['credentialId']);
	}

	/**
	 * @return array<string, array{0: callable(CredentialAppTokenService, CredentialSupportGoldenTest): array{0: string, 1: string|null, 2: string|null}, 1: string}>
	 */
	public static function refusedTokens(): array {
		$b64 = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
		$sign = static fn (string $payload, string $secret): string => $payload . '.' . $b64(hash_hmac('sha256', $payload, $secret, true));

		return [
			'no dot' => [static fn (): array => ['abc', null, null], 'Malformed broker token'],
			'three parts' => [static fn (): array => ['a.b.c', null, null], 'Malformed broker token'],
			'empty payload' => [static fn (): array => ['.b', null, null], 'Malformed broker token'],
			'empty signature' => [static fn (): array => ['a.', null, null], 'Malformed broker token'],
			'not base64' => [static fn (): array => ['!!!.sig', null, null], 'Malformed broker token payload'],
			'not json' => [static fn (): array => [$b64('nope') . '.sig', null, null], 'Malformed broker token claims'],
			'no app' => [static fn (): array => [$b64('{"credentialId":"c","exp":2000}') . '.sig', null, null], 'Malformed broker token claims'],
			'app not a string' => [static fn (): array => [$b64('{"appId":1,"credentialId":"c","exp":2000}') . '.sig', null, null], 'Malformed broker token claims'],
			'no credential' => [static fn (): array => [$b64('{"appId":"hermiq","exp":2000}') . '.sig', null, null], 'Malformed broker token claims'],
			'exp a string' => [static fn (): array => [$b64('{"appId":"hermiq","credentialId":"c","exp":"2000"}') . '.sig', null, null], 'Malformed broker token claims'],
			'unknown app' => [static fn (): array => [$sign($b64('{"appId":"ghost","credentialId":"c","exp":2000}'), 'SECRET'), null, null], 'Unknown app "ghost" in broker token'],
			'signed by another secret' => [static fn (): array => [$sign($b64('{"appId":"hermiq","credentialId":"c","exp":2000}'), 'OTHER'), null, null], 'Bad broker token signature'],
			'expired at the second' => [static fn (): array => [$sign($b64('{"appId":"hermiq","credentialId":"c","exp":1000}'), 'SECRET'), null, null], 'Expired broker token'],
			'bound, verified unbound' => [static fn (): array => [$sign($b64('{"appId":"hermiq","credentialId":"c","exp":2000,"req":"' . hash('sha256', 'GET /x') . '"}'), 'SECRET'), null, null], 'Broker token request-binding mismatch'],
			'bound, path only' => [static fn (): array => [$sign($b64('{"appId":"hermiq","credentialId":"c","exp":2000,"req":"' . hash('sha256', 'GET /x') . '"}'), 'SECRET'), null, '/x'], 'Broker token request-binding mismatch'],
			'bound, other call' => [static fn (): array => [$sign($b64('{"appId":"hermiq","credentialId":"c","exp":2000,"req":"' . hash('sha256', 'GET /x') . '"}'), 'SECRET'), 'PUT', '/x'], 'Broker token request-binding mismatch'],
		];
	}

	/**
	 * @dataProvider refusedTokens
	 *
	 * @param callable $make Builds the token, method and path.
	 * @param string $message The expected refusal.
	 */
	public function testRefusedTokens(callable $make, string $message): void {
		$service = $this->tokens();
		$service->registerApp(appId: 'hermiq');
		[$token, $method, $path] = $make($service, $this);

		try {
			$service->verify(token: $token, request: new BrokerTokenBinding(method: $method, path: $path));
			$this->fail('token accepted');
		} catch (CredentialAccessDeniedException $refused) {
			$this->assertSame($message, $refused->getMessage());
		}
	}

	public function testARelinkIsAnnouncedAndTheOwnerTold(): void {
		$events = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (object $event) use (&$events): void {
			$events[] = $event;
		});

		$calls = [];
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setObject', 'setSubject', 'setDateTime'] as $setter) {
			$notification->method($setter)->willReturnCallback(function (...$args) use (&$calls, $setter, $notification): INotification {
				if ($setter !== 'setDateTime') {
					$calls[$setter] = $args;
				}

				return $notification;
			});
		}

		$manager = $this->createMock(INotificationManager::class);
		$manager->method('createNotification')->willReturn($notification);
		$manager->expects($this->once())->method('notify')->with($notification);

		(new CredentialRelinkNotifier(eventDispatcher: $dispatcher, notifications: $manager, logger: $this->createMock(LoggerInterface::class)))
			->announce(event: new CredentialRelinkRequiredEvent(credentialId: 'cred-1', provider: 'mastodon', owner: 'alice', reason: 'invalid_grant'));

		$this->assertCount(1, $events);
		$this->assertInstanceOf(CredentialRelinkRequiredEvent::class, $events[0]);
		$this->assertSame(
			[
				'setApp' => ['openregister'],
				'setUser' => ['alice'],
				'setObject' => ['brokered_credential', 'cred-1'],
				'setSubject' => ['credential_relink_needed', ['provider' => 'mastodon', 'connection' => 'mastodon']],
			],
			$calls
		);
	}

	public function testARelinkWithoutAnOwnerIsOnlyAnnounced(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->once())->method('dispatchTyped');
		$manager = $this->createMock(INotificationManager::class);
		$manager->expects($this->never())->method('createNotification');

		(new CredentialRelinkNotifier(eventDispatcher: $dispatcher, notifications: $manager, logger: $this->createMock(LoggerInterface::class)))
			->announce(event: new CredentialRelinkRequiredEvent(credentialId: 'cred-1', provider: 'mastodon', owner: '', reason: 'invalid_grant'));
	}

	public function testRelinkFailuresAreSwallowedAndTheOwnerStillTold(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willThrowException(new \RuntimeException('bus down'));
		$manager = $this->createMock(INotificationManager::class);
		$manager->expects($this->once())->method('createNotification')->willThrowException(new \RuntimeException('notifications down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning');

		(new CredentialRelinkNotifier(eventDispatcher: $dispatcher, notifications: $manager, logger: $logger))
			->announce(event: new CredentialRelinkRequiredEvent(credentialId: 'cred-1', provider: 'mastodon', owner: 'alice', reason: 'invalid_grant'));
	}

	/**
	 * @return array<string, array{0: bool, 1: bool, 2: string, 3: bool|null, 4: string|null, 5: array<int, mixed>|null}>
	 */
	public static function doriathCases(): array {
		$registered = ['hermiq', 'Hermiq app', 'internal', null, null, false];
		return [
			'disabled' => [false, true, '', null, 'Hermiq app', null],
			'no service' => [true, false, '', null, 'Hermiq app', null],
			'first time' => [true, true, '', null, 'Hermiq app', $registered],
			'description falls back' => [true, true, '', null, '', ['hermiq', 'hermiq', 'internal', null, null, false]],
			'null description falls back' => [true, true, '', null, null, ['hermiq', 'hermiq', 'internal', null, null, false]],
			'live row' => [true, true, 'app-uuid', true, 'Hermiq app', null],
			'stale row' => [true, true, 'app-uuid', false, 'Hermiq app', $registered],
		];
	}

	/**
	 * @dataProvider doriathCases
	 *
	 * @param bool $enabled Whether the credential app is enabled.
	 * @param bool $loadable Whether its ApplicationService resolves.
	 * @param string $persisted The persisted application UUID.
	 * @param bool|null $live Whether Doriath still holds the persisted row.
	 * @param string|null $description The manifest description.
	 * @param array<int, mixed>|null $expected The register() arguments, or null for none.
	 */
	public function testDoriathRegistration(bool $enabled, bool $loadable, string $persisted, ?bool $live, ?string $description, ?array $expected): void {
		$service = new class ($live) {
			/** @var array<int, array<int, mixed>> */
			public array $registered = [];

			/** @var array<int, array<int, mixed>> */
			public array $gets = [];

			/**
			 * @param bool|null $live Whether the row is live.
			 */
			public function __construct(private readonly ?bool $live) {
			}

			/**
			 * @param mixed ...$args The arguments.
			 *
			 * @return object
			 */
			public function get(...$args): object {
				$this->gets[] = $args;
				if ($this->live !== true) {
					throw new \RuntimeException('gone');
				}

				return new \stdClass();
			}

			/**
			 * @param mixed ...$args The arguments.
			 *
			 * @return object
			 */
			public function register(...$args): object {
				$this->registered[] = $args;
				return new class {
					/**
					 * @return string
					 */
					public function getId(): string {
						return 'new-uuid';
					}
				};
			}
		};

		$writes = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default): string => ($key === 'doriath_application_id_hermiq' ? $persisted : $default));
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value) use (&$writes): bool {
			$writes[] = [$app, $key, $value];
			return true;
		});
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn($enabled);
		$appManager->method('isInstalled')->willReturn($enabled);

		$registrar = new class ($appConfig, $appManager, $this->createMock(LoggerInterface::class), $loadable ? $service : null) extends DoriathApplicationRegistrar {
			/**
			 * @param IAppConfig $appConfig The config.
			 * @param IAppManager $appManager The app manager.
			 * @param LoggerInterface $logger The logger.
			 * @param object|null $service The fake service.
			 */
			public function __construct(IAppConfig $appConfig, IAppManager $appManager, LoggerInterface $logger, private readonly ?object $service) {
				parent::__construct(settings: new AppSettingsAccess(appConfig: $appConfig, appManager: $appManager, logger: $logger));
			}

			/**
			 * @return object|null
			 */
			protected function resolveApplicationService(): ?object {
				return $this->service;
			}
		};

		$registrar->registerApplication(appId: 'hermiq', description: $description);

		$this->assertSame($expected === null ? [] : [$expected], $service->registered);
		$this->assertSame($expected === null ? [] : [['openregister', 'doriath_application_id_hermiq', 'new-uuid']], $writes);
		$this->assertSame($persisted !== '' && $enabled && $loadable ? [['app-uuid', '', true]] : [], $service->gets);
	}

	public function testADoriathFailureIsSwallowedAndPersistsNothing(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$appConfig->expects($this->never())->method('setValueString');
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(true);
		$appManager->method('isInstalled')->willReturn(true);
		$service = new class {
			/**
			 * @param mixed ...$args The arguments.
			 *
			 * @return object
			 */
			public function register(...$args): object {
				throw new \RuntimeException('doriath down');
			}
		};

		$registrar = new class ($appConfig, $appManager, $this->createMock(LoggerInterface::class), $service) extends DoriathApplicationRegistrar {
			/**
			 * @param IAppConfig $appConfig The config.
			 * @param IAppManager $appManager The app manager.
			 * @param LoggerInterface $logger The logger.
			 * @param object $service The fake service.
			 */
			public function __construct(IAppConfig $appConfig, IAppManager $appManager, LoggerInterface $logger, private readonly object $service) {
				parent::__construct(settings: new AppSettingsAccess(appConfig: $appConfig, appManager: $appManager, logger: $logger));
			}

			/**
			 * @return object|null
			 */
			protected function resolveApplicationService(): ?object {
				return $this->service;
			}
		};

		$registrar->registerApplication(appId: 'hermiq');
		$this->assertSame('doriath_application_id_hermiq', DoriathApplicationRegistrar::appConfigKey(appId: 'hermiq'));
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	public static function updates(): array {
		$stored = ['name' => 'Old', 'allowedApps' => ['a'], 'secretRef' => 'x'];
		return [
			'nothing offered' => [[], $stored, $stored],
			'name trimmed' => [['name' => '  New  '], $stored, ['name' => 'New', 'allowedApps' => ['a'], 'secretRef' => 'x']],
			'blank name ignored' => [['name' => '   '], $stored, $stored],
			'name not a string' => [['name' => 5], $stored, $stored],
			'apps filtered' => [['allowedApps' => ['b', '', 3, 'c']], $stored, ['name' => 'Old', 'allowedApps' => ['b', 'c'], 'secretRef' => 'x']],
			'apps not a list' => [['allowedApps' => 'b'], $stored, ['name' => 'Old', 'allowedApps' => [], 'secretRef' => 'x']],
		];
	}

	/**
	 * @dataProvider updates
	 *
	 * @param array<string, mixed> $params The request params.
	 * @param array<string, mixed> $stored The stored bag.
	 * @param array<string, mixed> $expected The edited bag.
	 */
	public function testUpdateEdits(array $params, array $stored, array $expected): void {
		$this->assertSame($expected, $this->update(params: $params)->applyTo(data: $stored));
	}

	public function testUpdateHostSecretAndBounds(): void {
		$this->assertFalse($this->update(params: [])->wouldRepointHost(data: ['instanceBaseUrl' => 'https://a.example']));
		$this->assertFalse($this->update(params: ['instanceBaseUrl' => ' https://a.example '])->wouldRepointHost(data: ['instanceBaseUrl' => 'https://a.example']));
		$this->assertFalse($this->update(params: ['instanceBaseUrl' => '  '])->wouldRepointHost(data: ['instanceBaseUrl' => 'https://a.example']));
		$this->assertTrue($this->update(params: ['instanceBaseUrl' => 'https://b.example'])->wouldRepointHost(data: ['instanceBaseUrl' => 'https://a.example']));
		$this->assertTrue($this->update(params: ['instanceBaseUrl' => 'https://b.example'])->wouldRepointHost(data: []));

		$this->assertNull($this->update(params: [])->rotatedSecret());
		$this->assertNull($this->update(params: ['secret' => " \n"])->rotatedSecret());
		$this->assertNull($this->update(params: ['secret' => 42])->rotatedSecret());
		$this->assertSame('s3cr3t', $this->update(params: ['secret' => " s3cr3t\n"])->rotatedSecret());

		$update = $this->update(params: []);
		$this->assertFalse($update->exceedsBounds(data: ['name' => str_repeat('é', 255), 'allowedApps' => [str_repeat('a', 64), 5]]));
		$this->assertTrue($update->exceedsBounds(data: ['name' => str_repeat('é', 256)]));
		$this->assertTrue($update->exceedsBounds(data: ['allowedApps' => ['ok', str_repeat('a', 65)]]));
		$this->assertFalse($update->exceedsBounds(data: ['name' => 5, 'allowedApps' => 'x']));
		$this->assertFalse($update->exceedsBounds(data: []));
	}

	/**
	 * An update request over the given params.
	 *
	 * @param array<string, mixed> $params The params.
	 *
	 * @return CredentialUpdateRequest
	 */
	private function update(array $params): CredentialUpdateRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => (array_key_exists($key, $params) === true ? $params[$key] : $default));

		return new CredentialUpdateRequest(request: $request);
	}

	/**
	 * The token service over an in-memory vault and a fixed clock.
	 *
	 * @return CredentialAppTokenService
	 */
	private function tokens(): CredentialAppTokenService {
		$vault = $this->createMock(ICredentialsManager::class);
		$vault->method('store')->willReturnCallback(function (string $userId, string $key, $value): void {
			$this->vault[$key] = $value;
		});
		$vault->method('retrieve')->willReturnCallback(fn (string $userId, string $key) => ($this->vault[$key] ?? null));
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('SECRET');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new CredentialAppTokenService(credentialsManager: $vault, secureRandom: $random, timeFactory: $time);
	}
}
