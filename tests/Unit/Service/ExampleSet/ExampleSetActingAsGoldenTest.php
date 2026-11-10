<?php

/**
 * Golden test: when an example set may act as an account.
 *
 * Pins the allowed and refused outcomes of ExampleSetActingAs::refusal()
 * around the background-run frame, so moving the frame keeps them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
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

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetActingAs;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Allowed and refused outcomes of acting as a cast account.
 *
 * Mutation check: dropping the `finally` that closes the frame makes
 * testAFrameThatThrowsStillCloses fail; dropping the frame check in
 * refusal() makes testAWebProcessInsideTheFrameIsAllowed pass and
 * testAWebProcessOutsideTheFrameIsRefused fail.
 */
final class ExampleSetActingAsGoldenTest extends TestCase {

	/**
	 * A scope for a process with nobody or somebody signed in.
	 *
	 * @param bool        $cli    Whether the process is the command line.
	 * @param string|null $signed The uid signed in, or null.
	 *
	 * @return ExampleSetActingAs
	 */
	private function scope(bool $cli, ?string $signed): ExampleSetActingAs {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($signed !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($signed);
		}

		$session->method('getUser')->willReturn($user);

		return new ExampleSetActingAs(userSession: $session, userManager: $this->createMock(IUserManager::class), cli: $cli);
	}//end scope()

	public function testTheCommandLineIsAllowed(): void {
		$this->assertSame('', $this->scope(true, null)->refusal());
	}//end testTheCommandLineIsAllowed()

	public function testAWebProcessOutsideTheFrameIsRefused(): void {
		$this->assertSame(
			'an example set acts as an account only from occ or the background job',
			$this->scope(false, null)->refusal()
		);
	}//end testAWebProcessOutsideTheFrameIsRefused()

	public function testAWebProcessInsideTheFrameIsAllowed(): void {
		$scope = $this->scope(false, null);
		$this->assertSame('', ExampleSetActingAs::inBackgroundJob(run: fn (): string => $scope->refusal()));
		$this->assertNotSame('', $scope->refusal());
	}//end testAWebProcessInsideTheFrameIsAllowed()

	public function testSomebodySignedInIsRefusedEvenInTheFrame(): void {
		$scope = $this->scope(false, 'alice');
		$this->assertSame(
			'an example set does not act as an account while "alice" is signed in',
			ExampleSetActingAs::inBackgroundJob(run: fn (): string => $scope->refusal())
		);
		$this->assertSame('an example set does not act as an account while "alice" is signed in', $this->scope(true, 'alice')->refusal());
	}//end testSomebodySignedInIsRefusedEvenInTheFrame()

	public function testAFrameThatThrowsStillCloses(): void {
		try {
			ExampleSetActingAs::inBackgroundJob(run: static function (): never {
				throw new RuntimeException('boom');
			});
			$this->fail('the run should throw');
		} catch (RuntimeException $e) {
			$this->assertSame('boom', $e->getMessage());
		}

		$this->assertNotSame('', $this->scope(false, null)->refusal());
	}//end testAFrameThatThrowsStillCloses()

	public function testTheFrameReturnsWhatTheRunReturns(): void {
		$this->assertSame(['ok' => true], ExampleSetActingAs::inBackgroundJob(run: fn (): array => ['ok' => true]));
	}//end testTheFrameReturnsWhatTheRunReturns()
}//end class
