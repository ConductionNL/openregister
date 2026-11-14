<?php

/**
 * Minting an `edit` link asks LinkJourneyGuard first (or-form-and-journey-registry, task 6.1).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-form-link-may-open-an-existing-record-for-change-req-fjr-008
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\AccessLinkController;
use OCA\OpenRegister\Controller\Support\AccessLinkControllerServices;
use OCA\OpenRegister\Controller\Support\AccessLinkDomain;
use OCA\OpenRegister\Controller\Support\AccessLinkPlatform;
use OCA\OpenRegister\Kernel\Contracts\AccessLinkReaderInterface;
use OCA\OpenRegister\Service\Sharing\AccessLinkActsInterface;
use OCA\OpenRegister\Service\Sharing\AccessLinkMintGuardInterface;
use OCA\OpenRegister\Service\Sharing\AccessLinkServiceInterface;
use OCA\OpenRegister\Service\Sharing\LinkJourneyGuard;
use OCA\OpenRegister\Service\Sharing\LinkMintRequest;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Controller\AccessLinkController
 * @uses   \OCA\OpenRegister\Controller\Support\AccessLinkControllerServices
 * @uses   \OCA\OpenRegister\Controller\Support\AccessLinkDomain
 * @uses   \OCA\OpenRegister\Controller\Support\AccessLinkPlatform
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectShareLinkThrottle
 * @uses   \OCA\OpenRegister\Controller\Support\TrimmedRequestParam
 * @uses   \OCA\OpenRegister\Service\Sharing\AccessLinkTerms
 * @uses   \OCA\OpenRegister\Service\Sharing\LinkMintRequest
 */
class AccessLinkEditMintControllerTest extends TestCase {

	private const OBJECT = '5f0c0a1e-0000-4000-8000-000000000001';

	private const JOURNEY = '9b2e4c1a-0000-4000-8000-000000000002';

	private AccessLinkServiceInterface&MockObject $links;

	private AccessLinkMintGuardInterface&MockObject $mintGuard;

	protected function setUp(): void {
		parent::setUp();
		$this->links = $this->createMock(AccessLinkServiceInterface::class);
		$this->mintGuard = $this->createMock(AccessLinkMintGuardInterface::class);
		$this->mintGuard->method('mayMint')->willReturn(true);
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function controller(array $params, ?LinkJourneyGuard $journeys): AccessLinkController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => ($params[$name] ?? $default)
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new AccessLinkController(
			request: $request,
			services: new AccessLinkControllerServices(
				domain: new AccessLinkDomain(
					links: $this->links,
					reader: $this->createMock(AccessLinkReaderInterface::class),
					mintGuard: $this->mintGuard,
					acts: $this->createMock(AccessLinkActsInterface::class),
					journeys: $journeys
				),
				platform: new AccessLinkPlatform(userSession: $session, throttler: $this->createMock(IThrottler::class), logger: new NullLogger())
			)
		);
	}

	private function editParams(): array {
		return ['subjectType' => 'object', 'subjectId' => self::OBJECT, 'capabilities' => ['read', 'Edit'], 'expiresAt' => '+1 day', 'journey' => self::JOURNEY];
	}

	public function testARefusedJourneyAnswers400WithTheReasonAndMintsNothing(): void {
		$journeys = $this->createMock(LinkJourneyGuard::class);
		$journeys->expects($this->once())->method('mintRefusal')->with(self::OBJECT, self::JOURNEY)
			->willReturn('You may not change this record, so you cannot hand out a link that changes it.');
		$this->links->expects($this->never())->method('mint');

		$response = $this->controller(params: $this->editParams(), journeys: $journeys)->mint();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'You may not change this record, so you cannot hand out a link that changes it.'], $response->getData());
	}

	public function testWithoutTheGuardEveryEditLinkIsRefused(): void {
		$this->links->expects($this->never())->method('mint');

		$response = $this->controller(params: $this->editParams(), journeys: null)->mint();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Links that change a record are not available.'], $response->getData());
	}

	public function testAFittingJourneyIsMintedWithTheLink(): void {
		$journeys = $this->createMock(LinkJourneyGuard::class);
		$journeys->method('mintRefusal')->willReturn(null);
		$this->links->expects($this->once())->method('mint')->with(
			$this->callback(
				static fn (LinkMintRequest $request): bool => $request->terms->journey === self::JOURNEY
					&& $request->terms->capabilities === ['read', 'Edit']
					&& $request->subjectId === self::OBJECT
					&& $request->minter === 'alice'
			)
		)->willReturn(['uuid' => 'l1', 'journey' => self::JOURNEY]);

		$response = $this->controller(params: $this->editParams(), journeys: $journeys)->mint();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['uuid' => 'l1', 'journey' => self::JOURNEY], $response->getData());
	}

	public function testALinkWithoutEditNeverAsksTheGuard(): void {
		$journeys = $this->createMock(LinkJourneyGuard::class);
		$journeys->expects($this->never())->method('mintRefusal');
		$this->links->expects($this->once())->method('mint')->willReturn(['uuid' => 'l2']);

		$params = ['subjectType' => 'object', 'subjectId' => self::OBJECT, 'capabilities' => 'read,comment', 'expiresAt' => '+1 day'];
		$this->assertSame(Http::STATUS_CREATED, $this->controller(params: $params, journeys: $journeys)->mint()->getStatus());
	}
}//end class
