<?php

/**
 * AppHost MetricsAccessGuard — who may read an app's Prometheus metrics.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Observability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Observability;

use InvalidArgumentException;
use OCA\OpenRegister\AppHost\Observability\MetricsAccessGuard;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\Ip\IAddress;
use OCP\Security\Ip\IFactory;
use OCP\Security\Ip\IRange;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Admin sessions are served; a scraper is served with the app's token or from
 * an allowed range; a wrong token, a foreign address, a non-admin and anything
 * unreadable are refused.
 *
 * @covers \OCA\OpenRegister\AppHost\Observability\MetricsAccessGuard
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-prometheus-metrics-endpoint
 */
class MetricsAccessGuardTest extends TestCase {

	/** @var array<string, string> */
	private array $config = [];

	private ?string $sessionUid = null;

	private bool $sessionIsAdmin = false;

	private bool $configUnreadable = false;

	public function testAnAdminSessionIsServed(): void {
		$this->sessionUid = 'root';
		$this->sessionIsAdmin = true;

		$this->assertSame(200, $this->guard()->decide('leafapp', $this->request()));
	}

	public function testAnAnonymousCallerIsUnauthorizedWhenNothingIsConfigured(): void {
		$this->assertSame(401, $this->guard()->decide('leafapp', $this->request()));
	}

	public function testANonAdminSessionIsForbidden(): void {
		$this->sessionUid = 'alice';

		$this->assertSame(403, $this->guard()->decide('leafapp', $this->request()));
	}

	public function testTheConfiguredTokenServesAScraperWithoutASession(): void {
		$this->config['leafapp.metrics_api_token'] = 's3cret-token';

		$this->assertSame(200, $this->guard()->decide('leafapp', $this->request(authorization: 'Bearer s3cret-token')));
	}

	public function testTheBearerSchemeIsCaseInsensitive(): void {
		$this->config['leafapp.metrics_api_token'] = 's3cret-token';

		$this->assertSame(200, $this->guard()->decide('leafapp', $this->request(authorization: 'bearer s3cret-token')));
	}

	public function testAWrongTokenIsForbidden(): void {
		$this->config['leafapp.metrics_api_token'] = 's3cret-token';

		$this->assertSame(403, $this->guard()->decide('leafapp', $this->request(authorization: 'Bearer guessed')));
	}

	public function testATokenIsForbiddenWhenNoneIsConfigured(): void {
		$this->assertSame(403, $this->guard()->decide('leafapp', $this->request(authorization: 'Bearer ')));
		$this->assertSame(403, $this->guard()->decide('leafapp', $this->request(authorization: 'Bearer anything')));
	}

	public function testAnotherAppsTokenIsForbidden(): void {
		$this->config['otherapp.metrics_api_token'] = 's3cret-token';

		$this->assertSame(403, $this->guard()->decide('leafapp', $this->request(authorization: 'Bearer s3cret-token')));
	}

	public function testAWrongTokenIsNotRescuedByAnAllowedAddress(): void {
		$this->config['leafapp.metrics_api_token'] = 's3cret-token';
		$this->config['leafapp.metrics_allowed_ips'] = '10.0.0.0/8';

		$this->assertSame(403, $this->guard()->decide('leafapp', $this->request(authorization: 'Bearer guessed', address: '10.0.1.50')));
	}

	public function testAnAddressInAnAllowedRangeIsServed(): void {
		$this->config['leafapp.metrics_allowed_ips'] = '10.0.0.0/8, 172.16.0.0/12';

		$this->assertSame(200, $this->guard()->decide('leafapp', $this->request(address: '10.0.1.50')));
		$this->assertSame(200, $this->guard()->decide('leafapp', $this->request(address: '172.16.4.2')));
	}

	public function testAnAddressOutsideTheAllowedRangesIsForbidden(): void {
		$this->config['leafapp.metrics_allowed_ips'] = '10.0.0.0/8,172.16.0.0/12';

		$this->assertSame(403, $this->guard()->decide('leafapp', $this->request(address: '203.0.113.5')));
	}

	public function testAnUnparsableRangeIsSkippedAndTheOthersStillApply(): void {
		$this->config['leafapp.metrics_allowed_ips'] = 'not-a-range,10.0.0.0/8';

		$this->assertSame(200, $this->guard()->decide('leafapp', $this->request(address: '10.0.1.50')));
	}

	public function testOnlyUnparsableRangesLeaveTheEndpointClosed(): void {
		$this->config['leafapp.metrics_allowed_ips'] = 'not-a-range';

		$this->assertSame(401, $this->guard()->decide('leafapp', $this->request(address: '10.0.1.50')));
	}

	public function testAnUnparsableCallerAddressIsForbidden(): void {
		$this->config['leafapp.metrics_allowed_ips'] = '10.0.0.0/8';

		$this->assertSame(403, $this->guard()->decide('leafapp', $this->request(address: 'garbage')));
	}

	public function testUnreadableConfigKeepsTheEndpointClosed(): void {
		$this->configUnreadable = true;

		$this->assertSame(401, $this->guard()->decide('leafapp', $this->request(address: '10.0.1.50')));
		$this->assertSame(403, $this->guard()->decide('leafapp', $this->request(authorization: 'Bearer s3cret-token')));
	}

	public function testPresentsTokenTellsBearerHeadersApart(): void {
		$guard = $this->guard();

		$this->assertTrue($guard->presentsToken($this->request(authorization: 'Bearer x')));
		$this->assertFalse($guard->presentsToken($this->request(authorization: 'Basic eDp5')));
		$this->assertFalse($guard->presentsToken($this->request()));
	}

	private function guard(): MetricsAccessGuard {
		$userSession = $this->createMock(IUserSession::class);
		$user = null;
		if ($this->sessionUid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($this->sessionUid);
		}
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(fn (string $uid): bool => $uid === $this->sessionUid && $this->sessionIsAdmin);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(function (string $app, string $key, string $default = ''): string {
			if ($this->configUnreadable === true) {
				throw new RuntimeException('config store down');
			}

			return $this->config[$app . '.' . $key] ?? $default;
		});

		return new MetricsAccessGuard($userSession, $groupManager, $appConfig, $this->ipFactory());
	}

	/**
	 * An IP factory over IPv4 CIDR ranges, enough to tell the test addresses apart.
	 */
	private function ipFactory(): IFactory {
		$factory = $this->createMock(IFactory::class);
		$factory->method('addressFromString')->willReturnCallback(function (string $ip): IAddress {
			if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
				throw new InvalidArgumentException('not an address');
			}

			$address = $this->createMock(IAddress::class);
			$address->method('__toString')->willReturn($ip);
			return $address;
		});
		$factory->method('rangeFromString')->willReturnCallback(function (string $cidr): IRange {
			if (preg_match('#^(\d+\.\d+\.\d+\.\d+)/(\d+)$#', $cidr, $m) !== 1) {
				throw new InvalidArgumentException('not a range');
			}

			$mask = (-1 << (32 - (int)$m[2])) & 0xFFFFFFFF;
			$range = $this->createMock(IRange::class);
			$range->method('contains')->willReturnCallback(
				static fn (IAddress $address): bool => ((ip2long((string)$address) & $mask) === (ip2long($m[1]) & $mask))
			);
			return $range;
		});

		return $factory;
	}

	private function request(string $authorization = '', string $address = '198.51.100.7'): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($name === 'Authorization' ? $authorization : ''));
		$request->method('getRemoteAddress')->willReturn($address);

		return $request;
	}
}
