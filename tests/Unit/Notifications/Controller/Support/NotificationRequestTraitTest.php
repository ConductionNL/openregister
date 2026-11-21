<?php

/**
 * NotificationRequestTrait: reading the user, parameters and the requested setting of a notification request.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Controller\Support;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Notifications\Controller\Support\NotificationRequestTrait;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Controller\Support\NotificationRequestTrait
 */
class NotificationRequestTraitTest extends TestCase {

	/**
	 * A controller-like object exposing the trait's private helpers.
	 *
	 * @param IUserSession $userSession The session.
	 *
	 * @return object
	 */
	private function request(IUserSession $userSession): object {
		return new class($userSession) {
			use NotificationRequestTrait;

			public function __construct(private IUserSession $userSession) {
			}

			public function call(string $method, array $args = []): mixed {
				return $this->$method(...$args);
			}
		};
	}

	private function anonymous(): object {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		return $this->request($session);
	}

	public function testTheUserIdComesFromTheSessionOrIsNull(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->assertSame('alice', $this->request($session)->call('resolveUserId'));
		$this->assertNull($this->anonymous()->call('resolveUserId'));
	}

	public function testAuthenticationRequiredIsA401(): void {
		$response = $this->anonymous()->call('authenticationRequired');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}

	public function testStringParamsMustBeNonEmptyStrings(): void {
		$request = $this->anonymous();
		$params = ['a' => 'x', 'b' => '', 'c' => 3];

		$this->assertSame('x', $request->call('stringParam', ['params' => $params, 'key' => 'a']));
		$this->assertNull($request->call('stringParam', ['params' => $params, 'key' => 'b']));
		$this->assertNull($request->call('stringParam', ['params' => $params, 'key' => 'c']));
		$this->assertNull($request->call('stringParam', ['params' => $params, 'key' => 'd']));
	}

	public function testAResetRequestHasNoSetting(): void {
		$request = $this->anonymous();

		$this->assertNull($request->call('requestedSetting', ['params' => ['reset' => true, 'enabled' => false]]));
		$this->assertNull($request->call('requestedSetting', ['params' => ['reset' => 'true']]));
		$this->assertSame(['enabled' => true], $request->call('requestedSetting', ['params' => ['reset' => '1']]));
	}

	public function testTheSettingDefaultsToEnabledAndKeepsOnlyArrayChannels(): void {
		$request = $this->anonymous();

		$this->assertSame(['enabled' => true], $request->call('requestedSetting', ['params' => []]));
		$this->assertSame(
			['enabled' => false, 'channels' => ['mail']],
			$request->call('requestedSetting', ['params' => ['enabled' => 0, 'channels' => ['mail']]])
		);
		$this->assertSame(['enabled' => true], $request->call('requestedSetting', ['params' => ['channels' => 'mail']]));
	}

	public function testTheSettingResponseDefaultsTheScopeAndLetsStoredValuesWin(): void {
		$request = $this->anonymous();

		$this->assertSame(
			['rule' => 'welcome', 'scope' => 'global', 'enabled' => false],
			$request->call('settingResponse', ['target' => ['rule' => 'welcome'], 'stored' => ['enabled' => false]])->getData()
		);
		$this->assertSame(
			['rule' => 'welcome', 'scope' => 'stored'],
			$request->call('settingResponse', ['target' => ['rule' => 'welcome', 'scope' => 'register'], 'stored' => ['scope' => 'stored']])->getData()
		);
	}
}
