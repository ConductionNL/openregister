<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\CaseController;
use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Exception\CaseValidationException;
use OCA\OpenRegister\Service\Case\CasePlanAuthorizationService;
use OCA\OpenRegister\Service\Case\CasePlanService;
use OCA\OpenRegister\Service\Case\ZaaktypeCaseSkeletonMapper;
use OCA\OpenRegister\Tests\Unit\Controller\Support\CaseControllerFactory;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pins exactly what the case routes hand the plan service: every argument of
 * a plan creation, the item data of an ad-hoc attach (null fields dropped,
 * `required` last, a body `authorization` forwarded so the service refuses
 * it) and the arguments of a hand transition, with its refusal.
 */
class CaseControllerForwardingGoldenTest extends TestCase {

	private CasePlanService&MockObject $plans;

	private IRequest&MockObject $request;

	/**
	 * @var list<array<string, mixed>>
	 */
	private array $calls = [];

	protected function setUp(): void {
		$this->plans   = $this->createMock(CasePlanService::class);
		$this->request = $this->createMock(IRequest::class);
	}

	private function controller(array $body = []): CaseController {
		$this->request->method('getParams')->willReturn($body);
		$session = $this->createMock(IUserSession::class);
		$user    = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session->method('getUser')->willReturn($user);

		return CaseControllerFactory::create(
			appName: 'openregister',
			request: $this->request,
			plans: $this->plans,
			zaaktypes: new ZaaktypeCaseSkeletonMapper(),
			authorization: $this->createMock(CasePlanAuthorizationService::class),
			userSession: $session,
			logger: null
		);
	}

	private function item(): CaseItem {
		$item = new CaseItem();
		$item->setUuid('i-1');
		return $item;
	}

	public function testCreateForwardsEveryArgument(): void {
		$this->plans->expects($this->once())->method('createPlan')->willReturnCallback(function (mixed ...$args): array {
			$this->calls[] = $args;
			return ['objectUuid' => 'o'];
		});

		$response = $this->controller()->create('o', 3, 4, ['items' => [1]], 'f-1', 2);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['objectUuid' => 'o'], $response->getData());
		$this->assertSame(
			[['o', 3, 4, ['items' => [1]], 'alice', 'f-1', 2]],
			$this->calls
		);
	}

	public function testCreateWithoutADefinitionSendsAnEmptyOne(): void {
		$this->plans->method('createPlan')->willReturnCallback(function (mixed ...$args): array {
			$this->calls[] = $args;
			return [];
		});

		$this->controller()->create('o');

		$this->assertSame(
			[['o', null, null, [], 'alice', null, null]],
			$this->calls
		);
	}

	public function testAttachSendsEveryGivenFieldInOrder(): void {
		$this->plans->method('attachAdHoc')->willReturnCallback(function (string $objectUuid, array $data, ?string $uid): CaseItem {
			$this->calls[] = [$objectUuid, $data, $uid];
			return $this->item();
		});

		$response = $this->controller()->attach('o', 'k', 'humanTask', 'N', 'D', 'p', [1], [2], ['u'], ['g'], 'r', '2026-01-01', '2026-02-01', false);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('i-1', $response->getData()['uuid']);
		$this->assertSame(
			[[
				'o',
				[
					'key' => 'k', 'type' => 'humanTask', 'name' => 'N', 'description' => 'D', 'parent' => 'p',
					'entryCriteria' => [1], 'exitCriteria' => [2], 'candidateUsers' => ['u'], 'candidateGroups' => ['g'],
					'candidateRole' => 'r', 'dueAt' => '2026-01-01', 'expiresAt' => '2026-02-01', 'required' => false,
				],
				'alice',
			]],
			$this->calls
		);
	}

	public function testAttachDropsNullFieldsAndForwardsABodyAuthorization(): void {
		$this->plans->method('attachAdHoc')->willReturnCallback(function (string $objectUuid, array $data, ?string $uid): CaseItem {
			$this->calls[] = [$objectUuid, $data, $uid];
			throw new CaseValidationException('An ad-hoc item cannot declare its own authorization');
		});

		$response = $this->controller(['authorization' => null, 'name' => 'ignored'])->attach('o', 'k');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame([['o', ['key' => 'k', 'required' => true, 'authorization' => null], 'alice']], $this->calls);
	}

	public function testAttachWithoutABodyAuthorizationSendsNone(): void {
		$this->plans->method('attachAdHoc')->willReturnCallback(function (string $objectUuid, array $data, ?string $uid): CaseItem {
			$this->calls[] = [$objectUuid, $data, $uid];
			return $this->item();
		});

		$this->controller(['key' => 'body'])->attach('o');

		$this->assertSame([['o', ['required' => true], 'alice']], $this->calls);
	}

	public function testTransitionForwardsItsReason(): void {
		$this->plans->method('transition')->willReturnCallback(function (mixed ...$args): CaseItem {
			$this->calls[] = $args;
			return $this->item();
		});

		$response = $this->controller()->transition('i-1', 'completed', 'done');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['i-1', 'completed', 'alice', 'done']], $this->calls);
	}

	public function testTransitionWithoutATargetIsRefused(): void {
		$this->plans->expects($this->never())->method('transition');

		$response = $this->controller()->transition('i-1', '  ', 'done');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'A target state `to` is required.'], $response->getData());
	}
}
