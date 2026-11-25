<?php

/**
 * FederationController lifecycle Test
 *
 * Creating a share passes its declaration of what crosses and reports a share
 * the other instance could not take; a received share is accepted or declined.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-an-object-is-shared-with-a-principal-at-another-instance-with-a-two-sided-lifecycle-req-pcf-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\FederationController;
use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapper;
use OCA\OpenRegister\Exception\FederatedShareNotDeliveredException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\FederationShareService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FederationControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the share lifecycle endpoints of FederationController.
 */
final class FederationControllerLifecycleTest extends TestCase {

	/**
	 * The request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The share service.
	 *
	 * @var FederationShareService&MockObject
	 */
	private FederationShareService&MockObject $shareService;

	/**
	 * Set up the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->shareService = $this->createMock(FederationShareService::class);
	}//end setUp()

	/**
	 * Build the controller.
	 *
	 * @return FederationController
	 */
	private function controller(): FederationController {
		return FederationControllerFactory::create(
			'openregister',
			$this->request,
			$this->createMock(FederatedShareMapper::class),
			$this->createMock(ObjectService::class),
			$this->shareService,
			$this->createMock(IThrottler::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * The declaration of what crosses reaches the service with the other parameters.
	 *
	 * @return void
	 */
	public function testCreatingAShareCarriesWhatCrosses(): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => [
				'scope' => 'object',
				'objectUri' => 'obj-1',
				'sharedWith' => 'bob@there.example',
				'crosses' => ['data', 'files'],
			][$key] ?? $default
		);
		$this->shareService->expects(self::once())->method('createOutgoingShare')
			->with(self::callback(static fn (array $p): bool => $p['crosses'] === ['data', 'files'] && $p['sharedWith'] === 'bob@there.example' && $p['scope'] === 'object'))
			->willReturn(new FederatedShare());

		self::assertSame(201, $this->controller()->createShare()->getStatus());
	}//end testCreatingAShareCarriesWhatCrosses()

	/**
	 * A share the other instance could not take is answered 502 with the reason.
	 *
	 * @return void
	 */
	public function testAnUndeliveredShareIsABadGateway(): void {
		$this->shareService->method('createOutgoingShare')
			->willThrowException(new FederatedShareNotDeliveredException('The share could not be delivered to the other instance'));

		$response = $this->controller()->createShare();

		self::assertSame(502, $response->getStatus());
		self::assertSame(['error' => 'The share could not be delivered to the other instance'], $response->getData());
	}//end testAnUndeliveredShareIsABadGateway()

	/**
	 * Accept and decline answer the share through the service.
	 *
	 * @return void
	 */
	public function testAReceivedShareIsAcceptedOrDeclined(): void {
		$answers = [];
		$this->shareService->method('answerIncomingShare')->willReturnCallback(
			static function (int $id, bool $accept) use (&$answers): FederatedShare {
				$answers[] = [$id, $accept];
				return new FederatedShare();
			}
		);

		self::assertSame(200, $this->controller()->acceptShare(id: 4)->getStatus());
		self::assertSame(200, $this->controller()->declineShare(id: 5)->getStatus());
		self::assertSame([[4, true], [5, false]], $answers);
	}//end testAReceivedShareIsAcceptedOrDeclined()

	/**
	 * Another organisation's share is a 404, a refused change a 403, a share
	 * that cannot be answered a 409, anything else a 500.
	 *
	 * @return void
	 */
	public function testAnsweringMapsEachRefusal(): void {
		$this->shareService->method('answerIncomingShare')->willReturnOnConsecutiveCalls(
			self::throwException(new DoesNotExistException('gone')),
			self::throwException(new NotAuthorizedException(message: 'no')),
			self::throwException(new InvalidArgumentException('not pending')),
			self::throwException(new \RuntimeException('db'))
		);
		$controller = $this->controller();

		self::assertSame(404, $controller->acceptShare(id: 1)->getStatus());
		$forbidden = $controller->declineShare(id: 1);
		self::assertSame(403, $forbidden->getStatus());
		self::assertSame(['error' => 'no'], $forbidden->getData());
		self::assertSame(409, $controller->acceptShare(id: 1)->getStatus());
		$failed = $controller->acceptShare(id: 1);
		self::assertSame(500, $failed->getStatus());
		self::assertSame(['error' => 'Could not answer share'], $failed->getData());
	}//end testAnsweringMapsEachRefusal()
}//end class
