<?php

/**
 * Unit test for OperationsActingRequest.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\OperationsActingRequest;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The acting user and the typed parameters of an operations request.
 *
 * @covers \OCA\OpenRegister\Controller\Support\OperationsActingRequest
 */
class OperationsActingRequestTest extends TestCase {
	private function acting(array $params, ?string $uid = null): OperationsActingRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key): mixed => $params[$key] ?? null);
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}
		$session->method('getUser')->willReturn($user);

		return new OperationsActingRequest($request, $session);
	}

	public function testTheActorIsTheSessionUidOrEmpty(): void {
		$this->assertSame('ann', $this->acting([], 'ann')->actor());
		$this->assertSame('', $this->acting([])->actor());
	}

	public function testTheSessionIsExposed(): void {
		$session = $this->createMock(IUserSession::class);

		$this->assertSame($session, (new OperationsActingRequest($this->createMock(IRequest::class), $session))->userSession);
	}

	public function testStringParamIsOnlyANonEmptyString(): void {
		$acting = $this->acting(['a' => 'job-1', 'b' => '', 'c' => 5, 'd' => ['x']]);

		$this->assertSame('job-1', $acting->stringParam('a'));
		$this->assertNull($acting->stringParam('b'));
		$this->assertNull($acting->stringParam('c'));
		$this->assertNull($acting->stringParam('d'));
		$this->assertNull($acting->stringParam('missing'));
	}

	public function testNullableIntParamReadsNumbers(): void {
		$acting = $this->acting(['a' => '12', 'b' => 3, 'c' => '2.9', 'd' => 'ten', 'e' => '']);

		$this->assertSame(12, $acting->nullableIntParam('a'));
		$this->assertSame(3, $acting->nullableIntParam('b'));
		$this->assertSame(2, $acting->nullableIntParam('c'));
		$this->assertNull($acting->nullableIntParam('d'));
		$this->assertNull($acting->nullableIntParam('e'));
		$this->assertNull($acting->nullableIntParam('missing'));
	}

	public function testIntParamFallsBackOnlyWhenNotNumeric(): void {
		$acting = $this->acting(['limit' => '0', 'bad' => 'x']);

		$this->assertSame(0, $acting->intParam('limit', 50));
		$this->assertSame(50, $acting->intParam('bad', 50));
		$this->assertSame(25, $acting->intParam('missing', 25));
	}
}
