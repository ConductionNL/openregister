<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Tests\Unit\Controller\Support\FederationControllerFactory;
use OCA\OpenRegister\Controller\FederationController;
use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\FederationShareService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A share the caller may not grant or revoke is answered 403.
 *
 * @covers \OCA\OpenRegister\Controller\FederationController
 * @uses \OCA\OpenRegister\Controller\Support\FederationControllerServices
 * @uses   \OCA\OpenRegister\Db\FederatedShare
 * @uses   \OCA\OpenRegister\Exception\NotAuthorizedException
 */
class FederationControllerShareRightsTest extends TestCase {

	private function controller(FederationShareService $service): FederationController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $default);

		return FederationControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(FederatedShareMapper::class),
			$this->createMock(ObjectService::class),
			$service,
			$this->createMock(IThrottler::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	public function testAStoredFilterCannotMoveTheSearchOffTheSharesTarget(): void {
		$share = new FederatedShare();
		$share->setDirection('outgoing');
		$share->setStatus('accepted');
		$share->setScope('query');
		$share->setRegister('zaken');
		$share->setSchema('zaak');
		$share->setOrganisation('org-a');
		$share->setPermissions('read');
		$share->setQueryFilter(['register' => 'other', 'schema' => 'other', 'status' => 'open']);
		$mapper = $this->createMock(FederatedShareMapper::class);
		$mapper->method('findByToken')->willReturn($share);
		$seen    = null;
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(static function (array $config) use (&$seen): array {
			$seen = $config['filters'];
			return [];
		});
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $default);
		$controller = FederationControllerFactory::create('openregister', $request, $mapper, $objects, $this->createMock(FederationShareService::class), $this->createMock(IThrottler::class), $this->createMock(LoggerInterface::class));

		$this->assertSame(200, $controller->objects(shareToken: 't')->getStatus());
		$this->assertSame(['status' => 'open'], $seen);
	}

	public function testARefusedShareIsAnswered403(): void {
		$service = $this->createMock(FederationShareService::class);
		$service->method('createOutgoingShare')->willThrowException(new NotAuthorizedException(message: 'This share needs the right to manage its schema'));

		$response = $this->controller(service: $service)->createShare();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'This share needs the right to manage its schema'], $response->getData());
	}

	public function testAGrantedShareIsAnswered201(): void {
		$service = $this->createMock(FederationShareService::class);
		$service->method('createOutgoingShare')->willReturn(new FederatedShare());

		$this->assertSame(201, $this->controller(service: $service)->createShare()->getStatus());
	}

	public function testARefusedRevokeIsAnswered403(): void {
		$service = $this->createMock(FederationShareService::class);
		$service->method('setStatus')->willThrowException(new NotAuthorizedException(message: 'no'));

		$this->assertSame(403, $this->controller(service: $service)->revokeShare(id: 7)->getStatus());
	}
}
