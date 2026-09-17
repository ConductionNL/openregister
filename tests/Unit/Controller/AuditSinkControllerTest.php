<?php

/**
 * Unit tests for the sink status the operations console reads.
 *
 * Covers the admin gate on both endpoints, the unconfigured instance reported
 * as its own state rather than as healthy, and the acknowledgement that is the
 * only thing which clears the gap.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\AuditSinkController;
use OCA\OpenRegister\Service\Audit\AuditSink;
use OCA\OpenRegister\Service\Audit\AuditSinkStatus;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class AuditSinkControllerTest extends TestCase {
	private function controller(
		bool $admin,
		bool $configured,
		array $status = [],
		?AuditSinkStatus $statusService = null,
	): AuditSinkController {
		$sink = $this->createMock(AuditSink::class);
		$sink->method('isConfigured')->willReturn($configured);
		$path = null;
		if ($configured === true) {
			$path = '/var/log/openregister/audit.jsonl';
		}

		$sink->method('path')->willReturn($path);
		$sink->method('format')->willReturn(AuditSink::FORMAT_JSONL);

		if ($statusService === null) {
			$statusService = $this->createMock(AuditSinkStatus::class);
			$statusService->method('read')->willReturn(
				array_merge(
					[
						'healthy' => true,
						'lastSuccessAt' => null,
						'lastFailureAt' => null,
						'lastError' => null,
						'unshipped' => 0,
						'acknowledgedAt' => null,
					],
					$status
				)
			);
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$memberships = ['users'];
		if ($admin === true) {
			$memberships = ['admin'];
		}

		$groups->method('getUserGroupIds')->willReturn($memberships);

		return new AuditSinkController(
			'openregister',
			$this->createMock(IRequest::class),
			$sink,
			$statusService,
			$session,
			$groups
		);
	}//end controller()

	public function testAnOrdinaryUserIsNotToldWhereTheTrailIsWritten(): void {
		$response = $this->controller(admin: false, configured: true)->show();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertArrayNotHasKey('path', (array)$response->getData());
	}//end testAnOrdinaryUserIsNotToldWhereTheTrailIsWritten()

	public function testAnAdministratorSeesAHealthySink(): void {
		$body = $this->controller(
			admin: true,
			configured: true,
			status: ['lastSuccessAt' => '2026-09-16T08:00:00+02:00']
		)->show()->getData();

		self::assertTrue($body['configured']);
		self::assertTrue($body['healthy']);
		self::assertSame('/var/log/openregister/audit.jsonl', $body['path']);
		self::assertSame(AuditSink::FORMAT_JSONL, $body['format']);
		self::assertSame(0, $body['unshipped']);
	}//end testAnAdministratorSeesAHealthySink()

	public function testAnInstanceWithNoSinkIsReportedAsUnconfiguredNotAsHealthy(): void {
		// A sink nobody set up and a sink that works look identical from a
		// boolean, and that ambiguity is the failure this change is about.
		$body = $this->controller(admin: true, configured: false)->show()->getData();

		self::assertFalse($body['configured']);
		self::assertNull($body['healthy']);
		self::assertNull($body['path']);
	}//end testAnInstanceWithNoSinkIsReportedAsUnconfiguredNotAsHealthy()

	public function testABrokenSinkReportsTheSizeOfTheGapAndWhatWentWrong(): void {
		$body = $this->controller(
			admin: true,
			configured: true,
			status: [
				'healthy' => false,
				'lastFailureAt' => '2026-09-16T03:00:00+02:00',
				'lastError' => 'write to /var/log/openregister/audit.jsonl failed: No space left on device',
				'unshipped' => 4182,
			]
		)->show()->getData();

		self::assertFalse($body['healthy']);
		self::assertSame(4182, $body['unshipped']);
		self::assertStringContainsString('No space left', $body['lastError']);
	}//end testABrokenSinkReportsTheSizeOfTheGapAndWhatWentWrong()

	public function testOnlyAnAdministratorMayAcknowledgeTheGap(): void {
		$statusService = $this->createMock(AuditSinkStatus::class);
		$statusService->expects(self::never())->method('acknowledge');
		$statusService->method('read')->willReturn(
			['healthy' => false, 'lastSuccessAt' => null, 'lastFailureAt' => null,
				'lastError' => null, 'unshipped' => 9, 'acknowledgedAt' => null]
		);

		$response = $this->controller(
			admin: false,
			configured: true,
			statusService: $statusService
		)->acknowledge();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testOnlyAnAdministratorMayAcknowledgeTheGap()

	public function testAcknowledgingClearsTheCount(): void {
		$statusService = $this->createMock(AuditSinkStatus::class);
		$statusService->expects(self::once())->method('acknowledge')->willReturn(
			['healthy' => true, 'unshipped' => 0, 'acknowledgedAt' => '2026-09-16T09:00:00+02:00']
		);
		$statusService->method('read')->willReturn(
			['healthy' => false, 'lastSuccessAt' => null, 'lastFailureAt' => null,
				'lastError' => null, 'unshipped' => 9, 'acknowledgedAt' => null]
		);

		$body = $this->controller(
			admin: true,
			configured: true,
			statusService: $statusService
		)->acknowledge()->getData();

		self::assertTrue($body['healthy']);
		self::assertSame(0, $body['unshipped']);
	}//end testAcknowledgingClearsTheCount()
}//end class
