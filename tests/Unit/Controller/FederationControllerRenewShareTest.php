<?php

/**
 * Tests for FederationController::renewShare().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\FederationShareService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FederationControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Renewing a share answers with the renewed share, or why it was not renewed.
 */
class FederationControllerRenewShareTest extends TestCase {

	/**
	 * The status the endpoint answers when the service answers this way.
	 *
	 * @return array<string, array{0: Throwable|null, 1: int}>
	 */
	public static function outcomes(): array {
		return [
			'renewed' => [null, 200],
			'not the creator' => [new NotAuthorizedException('Only the creator of a share may renew it.'), 403],
			'another organisation' => [new DoesNotExistException('not found'), 404],
		];
	}//end outcomes()

	/**
	 * The endpoint answers the service's outcome.
	 *
	 * @param Throwable|null $failure What the service throws, or null when it renews.
	 * @param int            $status  The status the endpoint answers.
	 *
	 * @return void
	 *
	 * @dataProvider outcomes
	 */
	public function testTheRenewalAnswersTheServicesOutcome(?Throwable $failure, int $status): void {
		$service = $this->createMock(FederationShareService::class);
		$renew   = $service->expects($this->once())->method('renewShare')->with(7);
		if ($failure === null) {
			$renew->willReturn(new FederatedShare());
		} else {
			$renew->willThrowException($failure);
		}

		$controller = FederationControllerFactory::create(
			'openregister',
			$this->createMock(IRequest::class),
			$this->createMock(FederatedShareMapper::class),
			$this->createMock(ObjectService::class),
			$service,
			$this->createMock(IThrottler::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame($status, $controller->renewShare(id: 7)->getStatus());
	}//end testTheRenewalAnswersTheServicesOutcome()
}//end class
