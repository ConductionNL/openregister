<?php

/**
 * Golden test: who may run an endpoint test, and what is refused.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Endpoint;
use OCA\OpenRegister\Db\EndpointLogMapper;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Service\EndpointService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the allowed and refused outcomes of EndpointService::testEndpoint.
 */
final class EndpointServiceAccessGoldenTest extends TestCase {

	/**
	 * Every caller shape and the status code it gets.
	 *
	 * @return array<string, array{0: ?list<string>, 1: list<string>, 2: int}>
	 */
	public static function callers(): array {
		return [
			'anonymous, open endpoint runs'          => [null, [], 200],
			'anonymous, group endpoint is refused'   => [null, ['editors'], 403],
			'admin runs a group endpoint'            => [['admin'], ['editors'], 200],
			'member of the group runs it'            => [['staff', 'editors'], ['editors'], 200],
			'member of another group is refused'     => [['staff'], ['editors'], 403],
			'user without groups, open endpoint runs' => [[], [], 200],
			'user without groups is refused'         => [[], ['editors'], 403],
		];
	}//end callers()

	/**
	 * The status code of an endpoint test for one caller.
	 *
	 * @param list<string>|null $userGroups The caller's groups; null is no user.
	 * @param list<string>      $groups     The endpoint's groups.
	 * @param int               $expected   The expected status code.
	 *
	 * @return void
	 */
	#[DataProvider('callers')]
	public function testStatusCodeForCaller(?array $userGroups, array $groups, int $expected): void {
		$result = $this->service(userGroups: $userGroups)->testEndpoint($this->endpoint(groups: $groups));

		$this->assertSame($expected, $result['statusCode']);
		$this->assertSame($expected === 200, $result['success']);
	}//end testStatusCodeForCaller()

	/**
	 * A refused caller is refused before anything is logged.
	 *
	 * @return void
	 */
	public function testRefusedCallerIsNotLogged(): void {
		$mapper = $this->createMock(EndpointLogMapper::class);
		$mapper->expects($this->never())->method('insert');

		$result = $this->service(userGroups: ['staff'], mapper: $mapper)->testEndpoint($this->endpoint(groups: ['editors']));

		$this->assertSame(403, $result['statusCode']);
	}//end testRefusedCallerIsNotLogged()

	/**
	 * An allowed caller's call is logged once.
	 *
	 * @return void
	 */
	public function testAllowedCallerIsLogged(): void {
		$mapper = $this->createMock(EndpointLogMapper::class);
		$mapper->expects($this->once())->method('insert');

		$result = $this->service(userGroups: ['editors'], mapper: $mapper)->testEndpoint($this->endpoint(groups: ['editors']));

		$this->assertSame(200, $result['statusCode']);
	}//end testAllowedCallerIsLogged()

	/**
	 * Build the service for one caller.
	 *
	 * @param list<string>|null      $userGroups The caller's groups; null is no user.
	 * @param EndpointLogMapper|null $mapper     The log mapper, or a fresh mock.
	 *
	 * @return EndpointService
	 */
	private function service(?array $userGroups, ?EndpointLogMapper $mapper=null): EndpointService {
		$session = $this->createMock(IUserSession::class);
		$groups  = $this->createMock(IGroupManager::class);
		if ($userGroups === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$session->method('getUser')->willReturn($user);
			$groups->method('getUserGroupIds')->willReturn($userGroups);
		}

		return new EndpointService(
			$mapper ?? $this->createMock(EndpointLogMapper::class),
			$this->createMock(LoggerInterface::class),
			$session,
			$groups,
			$this->createMock(AgentMapper::class)
		);
	}//end service()

	/**
	 * A placeholder-target endpoint with the given groups.
	 *
	 * @param list<string> $groups The endpoint's groups.
	 *
	 * @return Endpoint
	 */
	private function endpoint(array $groups): Endpoint {
		$endpoint = new Endpoint();
		$endpoint->setId(7);
		$endpoint->setEndpoint('/test');
		$endpoint->setMethod('GET');
		$endpoint->setTargetType('view');
		$endpoint->setGroups($groups);

		return $endpoint;
	}//end endpoint()
}//end class
