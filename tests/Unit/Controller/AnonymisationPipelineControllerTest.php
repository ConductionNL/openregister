<?php

/**
 * The anonymisation pipeline report is answered to an administrator and refused to anyone else.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-content-altering-step-reports-its-state-req-adi-006
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\AnonymisationPipelineController;
use OCA\OpenRegister\Service\Anonymisation\ContentPipelineReport;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * An administrator, a non-administrator and an anonymous caller reading the pipeline.
 */
#[CoversClass(AnonymisationPipelineController::class)]
final class AnonymisationPipelineControllerTest extends TestCase {
	/**
	 * The controller with the given signed-in user (null: nobody) and admin answer.
	 *
	 * @param string|null           $uid     The signed-in user's id, or null.
	 * @param bool                  $isAdmin What the group manager answers.
	 * @param ContentPipelineReport $report  The report.
	 *
	 * @return AnonymisationPipelineController
	 */
	private function controller(?string $uid, bool $isAdmin, ContentPipelineReport $report): AnonymisationPipelineController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(
			static fn (string $id): bool => $id === $uid && $isAdmin
		);

		return new AnonymisationPipelineController(
			$this->createMock(IRequest::class),
			$session,
			$groups,
			$report
		);
	}

	/**
	 * An administrator gets every step and its state under `steps`.
	 *
	 * @return void
	 */
	public function testAnAdministratorGetsTheSteps(): void {
		$steps = [
			['step' => 'ocr', 'state' => 'active'],
			['step' => 'redaction', 'state' => 'unavailable'],
		];
		$report = $this->createMock(ContentPipelineReport::class);
		$report->expects($this->once())->method('report')->willReturn($steps);

		$response = $this->controller('admin', true, $report)->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['steps' => $steps], $response->getData());
	}

	/**
	 * A signed-in non-administrator is refused with 403 and the report is never built.
	 *
	 * @return void
	 */
	public function testANonAdministratorIsRefused(): void {
		$report = $this->createMock(ContentPipelineReport::class);
		$report->expects($this->never())->method('report');

		$response = $this->controller('jan', false, $report)->index();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(
			['error' => 'Administrator privileges are required to read the anonymisation pipeline'],
			$response->getData()
		);
	}

	/**
	 * Without a session user the call is refused before the admin check or the report runs.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$report = $this->createMock(ContentPipelineReport::class);
		$report->expects($this->never())->method('report');

		$response = $this->controller(null, true, $report)->index();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}
}
