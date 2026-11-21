<?php

/**
 * Behaviour test for HardeningStatementController: showing, accepting and managing the statement.
 *
 * Asserts that showing and accepting need a signed-in account and act on the
 * session's account, that invalid input answers 400, and that publishing and
 * withdrawing need a fresh sign-in.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\HardeningStatementController;
use OCA\OpenRegister\Controller\Support\HardeningStatementControllerServices;
use OCA\OpenRegister\Service\Hardening\ElevationRequiredException;
use OCA\OpenRegister\Service\Hardening\ElevationServiceInterface;
use OCA\OpenRegister\Service\Hardening\StatementPublication;
use OCA\OpenRegister\Service\Hardening\StatementServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HardeningStatementControllerBehaviourTest extends TestCase {

	/** @var StatementServiceInterface&MockObject The statement store. */
	private StatementServiceInterface $statements;

	/** @var ElevationServiceInterface&MockObject The elevation gate. */
	private ElevationServiceInterface $elevation;

	protected function setUp(): void {
		$this->statements = $this->createMock(StatementServiceInterface::class);
		$this->elevation = $this->createMock(ElevationServiceInterface::class);
	}

	private function controller(?string $uid, array $params = []): HardeningStatementController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key): mixed => $params[$key] ?? null);
		$session = $this->createMock(IUserSession::class);
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		return new HardeningStatementController(
			$request,
			new HardeningStatementControllerServices($this->statements, $this->elevation, $session)
		);
	}

	public function testAGuestSeesNoStatement(): void {
		$this->statements->expects($this->never())->method('published');
		$response = $this->controller(null)->statement();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('A statement is shown to an account.', $response->getData()['error']);
	}

	public function testAnAccountWithAnEmptyIdSeesNoStatement(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller('')->statement()->getStatus());
	}

	public function testTheStatementShowsTheAccountsAcceptance(): void {
		$this->statements->method('published')->willReturn(['version' => 'v2']);
		$this->statements->expects($this->once())->method('acceptanceOf')->with('alice')->willReturn(['version' => 'v1']);
		$this->statements->expects($this->once())->method('needsAcceptance')->with('alice')->willReturn(true);

		$this->assertSame(
			['statement' => ['version' => 'v2'], 'acceptance' => ['version' => 'v1'], 'needsAcceptance' => true],
			$this->controller('alice')->statement()->getData()
		);
	}

	public function testAGuestCannotAccept(): void {
		$this->statements->expects($this->never())->method('accept');
		$response = $this->controller(null, ['version' => 'v2'])->acceptStatement();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('An acceptance is recorded against an account.', $response->getData()['error']);
	}

	public function testAcceptingRecordsTheSessionAccountAndVersion(): void {
		$this->statements->expects($this->once())->method('accept')->with('alice', 'v2')->willReturn(['accepted' => 'v2']);
		$this->assertSame(['accepted' => 'v2'], $this->controller('alice', ['version' => 'v2'])->acceptStatement()->getData());
	}

	public function testAcceptingAnUnknownVersionAnswersBadRequest(): void {
		$this->statements->method('accept')->with('alice', '')->willThrowException(new InvalidArgumentException('No such version'));
		$response = $this->controller('alice')->acceptStatement();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'No such version'], $response->getData());
	}

	public function testPublishingNeedsAFreshSignIn(): void {
		$this->elevation->method('requireElevated')->willThrowException(new ElevationRequiredException(900));
		$this->statements->expects($this->never())->method('publish');
		$response = $this->controller('admin', ['version' => 'v3'])->publishStatement();
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(900, $response->getData()['periodSeconds']);
	}

	public function testPublishingPassesTheFieldsAndThePublisher(): void {
		$this->elevation->expects($this->once())->method('requireElevated');
		$this->statements->expects($this->once())->method('publish')->with(new StatementPublication(version: 'v3', body: 'Body', title: 'Title', publishedBy: 'admin'))->willReturn(['version' => 'v3']);
		$response = $this->controller('admin', ['version' => 'v3', 'body' => 'Body', 'title' => 'Title'])->publishStatement();
		$this->assertSame(['version' => 'v3'], $response->getData());
	}

	public function testPublishingWithoutASessionUsesAnEmptyPublisherAndEmptyFields(): void {
		$this->statements->expects($this->once())->method('publish')->with(new StatementPublication(version: '', body: '', title: '', publishedBy: ''))
			->willThrowException(new InvalidArgumentException('Version required'));
		$response = $this->controller(null)->publishStatement();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Version required'], $response->getData());
	}

	public function testWithdrawingNeedsAFreshSignIn(): void {
		$this->elevation->method('requireElevated')->willThrowException(new ElevationRequiredException(60));
		$this->statements->expects($this->never())->method('withdraw');
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller('admin')->withdrawStatement()->getStatus());
	}

	public function testWithdrawingClearsTheStatement(): void {
		$this->statements->expects($this->once())->method('withdraw');
		$this->assertSame(['statement' => null], $this->controller('admin')->withdrawStatement()->getData());
	}
}
