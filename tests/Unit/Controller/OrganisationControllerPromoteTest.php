<?php

/**
 * Unit tests for OrganisationController::promote().
 *
 * `POST /api/organisations/{uuid}/promote` is admin only, refuses a missing
 * target, and answers with the landed draft set or the refusal's own status.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\OrganisationController;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Controller\Support\OrganisationAuditing;
use OCA\OpenRegister\Controller\Support\OrganisationControllerServices;
use OCA\OpenRegister\Controller\Support\OrganisationDomain;
use OCA\OpenRegister\Controller\Support\OrganisationMappers;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\TenantUsageMapper;
use OCA\OpenRegister\Kernel\Contracts\PromotionRefusedException;
use OCA\OpenRegister\Service\Organisation\PromotionServiceInterface;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\OrganisationUsageService;
use OCA\OpenRegister\Service\TenantLifecycleService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OrganisationControllerPromoteTest extends TestCase {

	private PromotionServiceInterface&MockObject $promotion;

	protected function setUp(): void {
		parent::setUp();
		$this->promotion = $this->createMock(PromotionServiceInterface::class);
	}

	private function controller(bool $admin, bool $withPromotion = true): OrganisationController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('caller');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('caller')->willReturn($admin);

		$promotion = null;
		if ($withPromotion === true) {
			$promotion = $this->promotion;
		}

		return new OrganisationController(
			$this->createMock(IRequest::class),
			new OrganisationControllerServices(
				session: new ControllerSession(userSession: $userSession, groupManager: $groups, logger: new NullLogger()),
				mappers: new OrganisationMappers(
					organisationMapper: $this->createMock(OrganisationMapper::class),
					tenantUsageMapper: $this->createMock(TenantUsageMapper::class),
				),
				domain: new OrganisationDomain(
					organisationService: $this->createMock(OrganisationService::class),
					tenantLifecycle: $this->createMock(TenantLifecycleService::class),
					usageService: $this->createMock(OrganisationUsageService::class),
					promotion: $promotion,
				),
				auditing: new OrganisationAuditing(lifecycleAuditor: null),
			)
		);
	}

	public function testANonAdministratorIsRefusedBeforeAnythingIsPromoted(): void {
		$this->promotion->expects($this->never())->method('promote');

		$response = $this->controller(admin: false)->promote('org-a', 'org-b');

		$this->assertSame(403, $response->getStatus());
	}

	public function testAMissingTargetIsA400(): void {
		$this->promotion->expects($this->never())->method('promote');

		$this->assertSame(400, $this->controller(admin: true)->promote('org-a', null)->getStatus());
		$this->assertSame(400, $this->controller(admin: true)->promote('org-a', '  ')->getStatus());
	}

	public function testWithoutThePromotionServiceTheRouteSaysSo(): void {
		$this->assertSame(503, $this->controller(admin: true, withPromotion: false)->promote('org-a', 'org-b')->getStatus());
	}

	public function testALandedPromotionIs201WithTheSet(): void {
		$result = ['set' => ['uuid' => 'set-1'], 'source' => 'org-a', 'target' => 'org-b', 'components' => ['schema' => 2], 'remapped' => []];
		$this->promotion->expects($this->once())->method('promote')->with('org-a', 'org-b')->willReturn($result);

		$response = $this->controller(admin: true)->promote('org-a', ' org-b ');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame($result, $response->getData());
	}

	public function testARefusalAnswersWithItsOwnStatusAndBody(): void {
		$this->promotion->method('promote')->willThrowException(
			new PromotionRefusedException(
				reason: 'invalid',
				message: 'Promotion must follow OTAP order: development -> test -> acceptance -> production',
				refusals: [],
				statusCode: 400
			)
		);

		$response = $this->controller(admin: true)->promote('org-a', 'org-b');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('Promotion must follow OTAP order: development -> test -> acceptance -> production', $response->getData()['message']);
		$this->assertSame('invalid', $response->getData()['error']);
	}
}
