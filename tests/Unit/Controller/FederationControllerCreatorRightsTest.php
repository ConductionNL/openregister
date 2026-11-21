<?php

/**
 * A share token is served only while its creator still holds what it hands out.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\FederationController;
use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapper;
use OCA\OpenRegister\Service\FederationShareService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FederationControllerFactory;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Controller\FederationController
 * @uses   \OCA\OpenRegister\Controller\Support\FederationControllerServices
 * @uses   \OCA\OpenRegister\Db\FederatedShare
 */
class FederationControllerCreatorRightsTest extends TestCase {

	private FederatedShareMapper&MockObject $shareMapper;

	private ObjectService&MockObject $objectService;

	private FederationShareService&MockObject $shareService;

	private FederationController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->shareMapper   = $this->createMock(FederatedShareMapper::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->shareService  = $this->createMock(FederationShareService::class);

		$share = new FederatedShare();
		$share->setDirection('outgoing');
		$share->setStatus('accepted');
		$share->setScope('schema');
		$share->setRegister('zaken');
		$share->setSchema('zaak');
		$share->setPermissions('read-write');
		$share->setOrganisation('org-a');
		$share->setOwner('bob');
		$this->shareMapper->method('findByToken')->willReturn($share);

		$this->controller = FederationControllerFactory::create(
			'openregister',
			$this->createMock(IRequest::class),
			$this->shareMapper,
			$this->objectService,
			$this->shareService,
			$this->createMock(IThrottler::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	public function testATokenWhoseCreatorLostTheRightsServesNothing(): void {
		$this->shareService->method('servingRefusal')->willReturn('This share needs the right to manage its schema');
		$this->objectService->expects($this->never())->method('findAll');
		$this->objectService->expects($this->never())->method('find');
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertSame(403, $this->controller->objects(shareToken: 'tok')->getStatus());
		$this->assertSame(403, $this->controller->meta(shareToken: 'tok')->getStatus());
		$this->assertSame(403, $this->controller->createObject(shareToken: 'tok')->getStatus());
	}

	public function testATokenWhoseCreatorHoldsTheRightsIsServed(): void {
		$this->shareService->expects($this->atLeastOnce())->method('servingRefusal')->willReturn(null);
		$this->objectService->expects($this->once())->method('findAll')->willReturn([]);

		$this->assertSame(200, $this->controller->objects(shareToken: 'tok')->getStatus());
	}
}
