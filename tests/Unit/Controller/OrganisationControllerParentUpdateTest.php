<?php

/**
 * Characterisation tests for OrganisationController::handleParentUpdate().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\OrganisationController;
use OCA\OpenRegister\Controller\Support\OrganisationEdit;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\TenantUsageMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\OrganisationUsageService;
use OCA\OpenRegister\Service\TenantLifecycleService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\OrganisationControllerFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins the parent step of an organisation update: no `parent` key leaves the
 * parent alone, an empty or null parent clears it, a valid parent is set, and a
 * parent the mapper refuses (a cycle, another tenant) is logged, left unset and
 * answered with 400.
 */
class OrganisationControllerParentUpdateTest extends TestCase {

	private OrganisationMapper&MockObject $mapper;

	private LoggerInterface&MockObject $logger;

	private OrganisationController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(OrganisationMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->controller = OrganisationControllerFactory::create(
			'openregister',
			$this->createMock(IRequest::class),
			$this->createMock(OrganisationService::class),
			$this->mapper,
			$this->logger,
			$this->createMock(TenantLifecycleService::class),
			$this->createMock(TenantUsageMapper::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(OrganisationUsageService::class)
		);
	}

	private function handle(Organisation $organisation, array $data): mixed {
		return (new ReflectionMethod(OrganisationController::class, 'handleParentUpdate'))
			->invoke($this->controller, $organisation, new OrganisationEdit(uuid: 'org-1', data: $data));
	}

	private function organisation(): Organisation {
		$organisation = new Organisation();
		$organisation->setParent('old-parent');

		return $organisation;
	}

	public function testWithoutAParentKeyNothingChanges(): void {
		$organisation = $this->organisation();
		$this->mapper->expects($this->never())->method('validateParentAssignment');

		$this->assertNull($this->handle($organisation, ['name' => 'x', 'parent ' => 'y']));
		$this->assertSame('old-parent', $organisation->getParent());
	}

	public function testAnEmptyOrNullParentClearsIt(): void {
		foreach (['', null] as $parent) {
			$this->setUp();
			$organisation = $this->organisation();
			$this->mapper->expects($this->once())->method('validateParentAssignment')->with('org-1', null);

			$this->assertNull($this->handle($organisation, ['parent' => $parent]));
			$this->assertNull($organisation->getParent());
		}
	}

	public function testAValidParentIsSet(): void {
		$organisation = $this->organisation();
		$this->mapper->expects($this->once())->method('validateParentAssignment')->with('org-1', 'new-parent');
		$this->logger->expects($this->never())->method('warning');

		$this->assertNull($this->handle($organisation, ['parent' => 'new-parent']));
		$this->assertSame('new-parent', $organisation->getParent());
	}

	public function testARefusedParentIsLoggedLeftUnsetAndAnswered400(): void {
		$organisation = $this->organisation();
		$this->mapper->method('validateParentAssignment')->willThrowException(new \RuntimeException('circular'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[OrganisationController] Parent assignment validation failed',
			$this->callback(
				fn (array $context): bool => array_keys($context) === ['file', 'line', 'organisationUuid', 'newParent', 'error']
					&& $context['organisationUuid'] === 'org-1'
					&& $context['newParent'] === 'child'
					&& $context['error'] === 'circular'
			)
		);

		$response = $this->handle($organisation, ['parent' => 'child']);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'circular'], $response->getData());
		$this->assertSame('old-parent', $organisation->getParent());
	}
}//end class
