<?php

/**
 * Unit test for ProcessingLogAccess.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Controller\Support\ProcessingLogAccess;
use OCA\OpenRegister\Controller\Support\ProcessingLogPolicy;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Who may read the processing log, and over which organisation.
 *
 * @covers \OCA\OpenRegister\Controller\Support\ProcessingLogAccess
 * @uses   \OCA\OpenRegister\Controller\Support\ControllerSession
 * @uses   \OCA\OpenRegister\Controller\Support\GdprRegisterAccess
 * @uses   \OCA\OpenRegister\Controller\Support\GdprRegisterReplies
 * @uses   \OCA\OpenRegister\Controller\Support\ProcessingLogPolicy
 */
class ProcessingLogAccessTest extends TestCase {
	private function organisation(string $uuid): object {
		return new class ($uuid) {
			public function __construct(private string $uuid) {
			}

			public function getUuid(): string {
				return $this->uuid;
			}
		};
	}

	private function access(
		bool $loggedIn,
		array $groups,
		array $organisations = [],
		string $fgGroup = 'privacy-officer',
		bool $expectOrganisations = false
	): ProcessingLogAccess {
		$user = null;
		if ($loggedIn === true) {
			$user = $this->createMock(IUser::class);
		}
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($fgGroup);
		$organisationService = $this->createMock(OrganisationServiceInterface::class);
		$calls = $this->never();
		if ($expectOrganisations === true) {
			$calls = $this->once();
		}
		$organisationService->expects($calls)->method('getUserOrganisations')->willReturn($organisations);

		return new ProcessingLogAccess(
			new ControllerSession($userSession, $groupManager, $this->createMock(LoggerInterface::class)),
			new ProcessingLogPolicy($appConfig),
			$organisationService
		);
	}

	public function testAnAnonymousCallerIsUnauthorized(): void {
		$result = $this->access(false, [])->resolve();

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(401, $result->getStatus());
	}

	public function testACallerWithoutAdminOrOfficerGroupIsForbidden(): void {
		$result = $this->access(true, ['staff'])->resolve();

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(403, $result->getStatus());
		$this->assertSame(['error' => 'Privacy-officer or admin privileges required'], $result->getData());
	}

	public function testAnAdminReadsEveryOrganisation(): void {
		$this->assertSame([true, null], $this->access(true, ['admin'])->resolve());
	}

	public function testAnOfficerIsScopedToTheirFirstOrganisationWithAUuid(): void {
		$organisations = [$this->organisation(''), $this->organisation('org-2'), $this->organisation('org-3')];
		$access        = $this->access(true, ['privacy-officer'], $organisations, 'privacy-officer', true);

		$this->assertSame([true, 'org-2'], $access->resolve());
	}

	public function testTheOfficerGroupComesFromTheConfig(): void {
		$this->assertSame(403, $this->access(true, ['privacy-officer'], [], 'dpo')->resolve()->getStatus());
		$this->assertSame([true, 'o'], $this->access(true, ['dpo'], [$this->organisation('o')], 'dpo', true)->resolve());
	}

	public function testAnOfficerWithoutOrganisationGetsAnEmptyList(): void {
		$result = $this->access(true, ['privacy-officer'], [$this->organisation('')], 'privacy-officer', true)->resolve();

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(200, $result->getStatus());
		$this->assertSame(['count' => 0, 'results' => []], $result->getData());
	}
}
