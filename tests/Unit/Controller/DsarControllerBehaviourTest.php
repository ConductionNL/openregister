<?php

/**
 * Behaviour test for DsarController: who may drive DSAR operations and what each returns.
 *
 * Every endpoint refuses a guest and a non-admin, validates its input, passes the
 * normalised type and mode to the DSAR service and shapes the response.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\DsarController;
use OCA\OpenRegister\Controller\Support\DsarControllerServices;
use OCA\OpenRegister\Service\AvgComplianceServiceInterface;
use OCA\OpenRegister\Service\DsarServiceInterface;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DsarControllerBehaviourTest extends TestCase {

	/** @var DsarServiceInterface&MockObject The DSAR service. */
	private DsarServiceInterface $dsar;

	/** @var AvgComplianceServiceInterface&MockObject The compliance checks. */
	private AvgComplianceServiceInterface $compliance;

	protected function setUp(): void {
		$this->dsar = $this->createMock(DsarServiceInterface::class);
		$this->compliance = $this->createMock(AvgComplianceServiceInterface::class);
	}

	private function controller(array $params, ?array $groups = ['admin']): DsarController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				if (array_key_exists($key, $params) === true) {
					return $params[$key];
				}

				return $default;
			}
		);
		$session = $this->createMock(IUserSession::class);
		$groupManager = $this->createMock(IGroupManager::class);
		if ($groups !== null) {
			$user = $this->createMock(IUser::class);
			$session->method('getUser')->willReturn($user);
			$groupManager->method('getUserGroupIds')->with($user)->willReturn($groups);
		}

		return new DsarController($request, new DsarControllerServices($this->dsar, $this->compliance, $session, $groupManager));
	}

	public static function endpoints(): array {
		return [['access'], ['portability'], ['erasure'], ['rectification'], ['compliance']];
	}

	#[DataProvider('endpoints')]
	public function testAGuestIsRefused(string $endpoint): void {
		$this->dsar->expects($this->never())->method($this->anything());
		$this->compliance->expects($this->never())->method('runAllChecks');
		$response = $this->controller(['subject' => 'alice'], null)->$endpoint();
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('Admin privileges required to drive DSAR operations', $response->getData()['error']);
	}

	#[DataProvider('endpoints')]
	public function testANonAdminIsRefused(string $endpoint): void {
		$this->dsar->expects($this->never())->method($this->anything());
		$response = $this->controller(['subject' => 'alice', 'objectId' => 3, 'changes' => ['a' => 1]], ['users', 'Admin'])->$endpoint();
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public static function subjectEndpoints(): array {
		return [['access'], ['portability'], ['erasure']];
	}

	#[DataProvider('subjectEndpoints')]
	public function testASubjectIsRequired(string $endpoint): void {
		$this->dsar->expects($this->never())->method($this->anything());
		$response = $this->controller(['subject' => ''])->$endpoint();
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('`subject` query parameter is required', $response->getData()['error']);
	}

	public function testAccessDefaultsToExactModeWithoutAType(): void {
		$rows = [['object' => ['id' => 1]], ['object' => ['id' => 2]]];
		$this->dsar->expects($this->once())->method('findObjectsForSubject')->with('alice', null, 'exact')->willReturn($rows);

		$response = $this->controller(['subject' => 'alice', 'type' => ''])->access();
		$this->assertSame(['subject' => 'alice', 'type' => '', 'count' => 2, 'results' => $rows], $response->getData());
	}

	public function testAccessPassesTheTypeAndMode(): void {
		$this->dsar->expects($this->once())->method('findObjectsForSubject')->with('alice', 'email', 'fuzzy')->willReturn([]);
		$data = $this->controller(['subject' => 'alice', 'type' => 'email', 'mode' => 'fuzzy'])->access()->getData();
		$this->assertSame(0, $data['count']);
		$this->assertSame('email', $data['type']);
	}

	public function testPortabilityExportsOnlyTheObjects(): void {
		$rows = [['object' => ['id' => 1], 'schema' => 's'], ['object' => ['id' => 2], 'schema' => 's']];
		$this->dsar->expects($this->once())->method('findObjectsForSubject')->with('alice', 'bsn', 'exact')->willReturn($rows);

		$data = $this->controller(['subject' => 'alice', 'type' => 'bsn'])->portability()->getData();
		$this->assertSame([['id' => 1], ['id' => 2]], $data['objects']);
		$this->assertSame(2, $data['count']);
		$this->assertSame('alice', $data['subject']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $data['generated']);
	}

	public function testPortabilityWithoutATypeSearchesAllTypes(): void {
		$this->dsar->expects($this->once())->method('findObjectsForSubject')->with('alice', null, 'exact')->willReturn([]);
		$this->assertSame([], $this->controller(['subject' => 'alice'])->portability()->getData()['objects']);
	}

	public function testErasureIsADryRunOnlyWhenAsked(): void {
		$this->dsar->expects($this->exactly(2))->method('eraseObjectsForSubject')
			->willReturnCallback(static fn (string $s, ?string $t, bool $dry): array => ['type' => $t, 'dryRun' => $dry]);

		$dry = $this->controller(['subject' => 'alice', 'type' => 'email', 'dryRun' => 'true'])->erasure();
		$this->assertSame(['type' => 'email', 'dryRun' => true], $dry->getData());
		$this->assertSame(['type' => null, 'dryRun' => false], $this->controller(['subject' => 'alice'])->erasure()->getData());
	}

	public function testRectificationNeedsAnObjectId(): void {
		$this->dsar->expects($this->never())->method('rectifyObjectForSubject');
		$response = $this->controller(['changes' => ['a' => 1]])->rectification();
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('`objectId` is required', $response->getData()['error']);
	}

	public function testRectificationNeedsNonEmptyChanges(): void {
		$this->dsar->expects($this->never())->method('rectifyObjectForSubject');
		foreach ([[], 'name=x'] as $changes) {
			$response = $this->controller(['objectId' => '4', 'changes' => $changes])->rectification();
			$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
			$this->assertSame('`changes` must be a non-empty object', $response->getData()['error']);
		}
	}

	public function testRectificationReportsAMissingObject(): void {
		$this->dsar->method('rectifyObjectForSubject')->with(4, ['a' => 1])->willReturn(null);
		$response = $this->controller(['objectId' => '4', 'changes' => ['a' => 1]])->rectification();
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(4, $response->getData()['objectId']);
	}

	public function testRectificationReturnsTheUpdatedObject(): void {
		$this->dsar->expects($this->once())->method('rectifyObjectForSubject')->with(4, ['a' => 1])->willReturn(['id' => 4, 'a' => 1]);
		$response = $this->controller(['objectId' => '4', 'changes' => ['a' => 1]])->rectification();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 4, 'a' => 1], $response->getData());
	}

	public function testComplianceReturnsTheChecks(): void {
		$this->compliance->expects($this->once())->method('runAllChecks')->willReturn(['checks' => [['id' => 'c1']]]);
		$this->assertSame(['checks' => [['id' => 'c1']]], $this->controller([])->compliance()->getData());
	}
}
