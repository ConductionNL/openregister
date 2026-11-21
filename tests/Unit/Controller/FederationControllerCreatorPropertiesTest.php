<?php

/**
 * An object-scope share serves the object through the creator's property rules.
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

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FederationController;
use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Federation\CreatorPropertyFilter;
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
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 * @uses \OCA\OpenRegister\Service\CauseFrame
 * @uses \OCA\OpenRegister\Service\WriteCause
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 */
class FederationControllerCreatorPropertiesTest extends TestCase {

	private FederatedShareMapper&MockObject $shareMapper;

	private ObjectService&MockObject $objectService;

	private CreatorPropertyFilter&MockObject $filter;

	private FederationController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->shareMapper   = $this->createMock(FederatedShareMapper::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->filter        = $this->createMock(CreatorPropertyFilter::class);

		$shareService = $this->createMock(FederationShareService::class);
		$shareService->method('servingRefusal')->willReturn(null);

		$this->controller = FederationControllerFactory::create(
			'openregister',
			$this->createMock(IRequest::class),
			$this->shareMapper,
			$this->objectService,
			$shareService,
			$this->createMock(IThrottler::class),
			$this->createMock(LoggerInterface::class),
			$this->filter
		);
	}//end setUp()

	private function share(string $scope): FederatedShare {
		$share = new FederatedShare();
		$share->setDirection('outgoing');
		$share->setStatus('accepted');
		$share->setScope($scope);
		$share->setRegister('zaken');
		$share->setSchema('zaak');
		$share->setObjectUri($scope === 'object' ? 'https://remote.example/api/objects/granted-1' : null);
		$share->setOrganisation('org-a');
		$share->setPermissions('read');
		$share->setOwner('bob');
		return $share;
	}

	private function entity(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('granted-1');
		$entity->setOrganisation('org-a');
		$entity->setObject(['title' => 'Open', 'bsn' => '123456782', 'confidentiality' => 'public']);
		return $entity;
	}

	public function testAnObjectScopeShareServesWhatTheCreatorMayRead(): void {
		$share = $this->share('object');
		$this->shareMapper->method('findByToken')->willReturn($share);
		$this->objectService->method('find')->willReturn($this->entity());
		$this->filter->expects($this->atLeastOnce())->method('readable')
			->with($share, $this->isInstanceOf(ObjectEntity::class), $this->arrayHasKey('bsn'))
			->willReturnCallback(function (FederatedShare $share, ObjectEntity $object, array $rendered): array {
				unset($rendered['bsn']);
				return $rendered;
			});

		$response = $this->controller->object(shareToken: 'tok', id: 'granted-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('Open', $response->getData()['title']);
		$this->assertArrayNotHasKey('bsn', $response->getData());
	}

	public function testASchemaScopeShareKeepsItsRendering(): void {
		$this->shareMapper->method('findByToken')->willReturn($this->share('schema'));
		$this->objectService->method('findAll')->willReturn([$this->entity()]);
		$this->filter->expects($this->never())->method('readable');

		$response = $this->controller->objects(shareToken: 'tok');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('123456782', $response->getData()['results'][0]['bsn']);
	}
}
