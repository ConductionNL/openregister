<?php

/**
 * Golden tests for DuplicateController::dismiss and the dismiss-group gate.
 *
 * Pins every allowed and refused outcome of dismiss() (and the gate shared with
 * undismiss()) on the existing code, so the gate can be split into named steps
 * without changing who may say two objects are different.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Search\Controller\DuplicateController;
use OCA\OpenRegister\Search\Controller\Support\DuplicateControllerServices;
use OCA\OpenRegister\Search\Service\Quality\DismissedPairStore;
use OCA\OpenRegister\Search\Service\Quality\DuplicateDetectionService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the dismiss outcomes: bad request, forbidden, not found and stored.
 */
final class DuplicateControllerDismissGoldenTest extends TestCase {

	private const FORBIDDEN = 'You are not in a group this schema allows to dismiss duplicate pairs.';

	/**
	 * Request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = ['objectA' => 'uuid-a', 'objectB' => 'uuid-b', 'reason' => 'different people'];

	/**
	 * The schema's dedup annotation.
	 *
	 * @var array<string, mixed>
	 */
	private array $annotation = ['dismissGroups' => ['reviewers']];

	/**
	 * The groups the signed-in user is in; null for an anonymous caller.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $userGroups = ['reviewers'];

	/**
	 * The group ids isInGroup() was asked about, in order.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	private DismissedPairStore&MockObject $dismissals;

	private ObjectService&MockObject $objects;

	protected function setUp(): void {
		$this->dismissals = $this->createMock(DismissedPairStore::class);
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('find')->willReturnCallback(fn () => $this->entity());
	}//end setUp()

	private function entity(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject(['naam' => 'x']);
		return $entity;
	}//end entity()

	private function controller(): DuplicateController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($this->params[$key] ?? $default));

		$duplicates = $this->createMock(DuplicateDetectionService::class);
		$duplicates->method('dedupAnnotation')->willReturnCallback(fn () => $this->annotation);
		$duplicates->method('effectiveRules')->willReturn([]);
		$duplicates->method('pairFingerprint')->willReturn('fp-1');

		$user = null;
		if ($this->userGroups !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('reviewer-1');
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			function (string $uid, string $group): bool {
				$this->asked[] = $group;
				return in_array($group, ($this->userGroups ?? []), true);
			}
		);

		return new DuplicateController(
			$request,
			new DuplicateControllerServices(
				$duplicates,
				$this->dismissals,
				$this->objects,
				new ControllerSession($session, $groups, $this->createMock(LoggerInterface::class))
			)
		);
	}//end controller()

	public function testAMemberOfADismissGroupStoresTheDismissal(): void {
		$this->dismissals->expects($this->once())->method('dismiss')
			->with(
				$this->callback(fn ($pair) => $pair->first === 'uuid-a' && $pair->second === 'uuid-b' && $pair->registerSlug === 'reg' && $pair->schemaSlug === 'sch'),
				$this->callback(fn ($d) => $d->fingerprint === 'fp-1' && $d->reason === 'different people' && $d->dismissedBy === 'reviewer-1')
			)->willReturn(['id' => 7]);

		$response = $this->controller()->dismiss('reg', 'sch');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 7], $response->getData());
	}//end testAMemberOfADismissGroupStoresTheDismissal()

	/**
	 * @param array<string, mixed> $params The request parameters.
	 */
	#[DataProvider('badPairs')]
	public function testAPairThatIsNotTwoDifferentObjectsIsABadRequestBeforeTheGate(array $params): void {
		$this->params = $params;
		$this->annotation = [];
		$this->dismissals->expects($this->never())->method('dismiss');

		$response = $this->controller()->dismiss('reg', 'sch');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'A dismissal needs two different objects.'], $response->getData());
	}//end testAPairThatIsNotTwoDifferentObjectsIsABadRequestBeforeTheGate()

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function badPairs(): array {
		return [
			'no first object' => [['objectB' => 'uuid-b']],
			'no second object' => [['objectA' => 'uuid-a']],
			'the same object twice' => [['objectA' => 'uuid-a', 'objectB' => 'uuid-a']],
		];
	}//end badPairs()

	/**
	 * @param array<string, mixed>    $annotation The schema's dedup annotation.
	 * @param array<int, string>|null $userGroups The caller's groups, null when anonymous.
	 */
	#[DataProvider('refusedCallers')]
	public function testACallerOutsideTheDismissGroupsIsForbidden(array $annotation, ?array $userGroups): void {
		$this->annotation = $annotation;
		$this->userGroups = $userGroups;
		$this->dismissals->expects($this->never())->method('dismiss');
		$this->objects->expects($this->never())->method('find');

		$response = $this->controller()->dismiss('reg', 'sch');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => self::FORBIDDEN], $response->getData());
	}//end testACallerOutsideTheDismissGroupsIsForbidden()

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<int, string>|null}>
	 */
	public static function refusedCallers(): array {
		return [
			'no annotation' => [[], ['reviewers']],
			'dismissGroups not a list' => [['dismissGroups' => 'reviewers'], ['reviewers']],
			'dismissGroups empty' => [['dismissGroups' => []], ['reviewers']],
			'anonymous caller' => [['dismissGroups' => ['reviewers']], null],
			'not in any named group' => [['dismissGroups' => ['reviewers', 'admins']], ['staff']],
			'only invalid group names' => [['dismissGroups' => ['', 5, null]], ['']],
		];
	}//end refusedCallers()

	public function testInvalidGroupNamesAreSkippedAndTheFirstMatchingGroupAllows(): void {
		$this->annotation = ['dismissGroups' => ['', 3, 'staff', 'reviewers', 'admins']];
		$this->userGroups = ['reviewers', 'admins'];
		$this->dismissals->method('dismiss')->willReturn(['id' => 1]);

		$response = $this->controller()->dismiss('reg', 'sch');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['staff', 'reviewers'], $this->asked);
	}//end testInvalidGroupNamesAreSkippedAndTheFirstMatchingGroupAllows()

	public function testAnUnreadableObjectIsNotFound(): void {
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('find')->willReturn(null);
		$this->dismissals->expects($this->never())->method('dismiss');

		$response = $this->controller()->dismiss('reg', 'sch');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'One of the objects could not be read.'], $response->getData());
	}//end testAnUnreadableObjectIsNotFound()

	public function testAFailingObjectLookupIsNotFound(): void {
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('find')->willThrowException(new RuntimeException('boom'));

		$response = $this->controller()->dismiss('reg', 'sch');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAFailingObjectLookupIsNotFound()

	public function testUndismissIsGatedTheSameWay(): void {
		$this->userGroups = ['staff'];
		$this->dismissals->expects($this->never())->method('undismiss');

		$response = $this->controller()->undismiss('reg', 'sch');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => self::FORBIDDEN], $response->getData());
	}//end testUndismissIsGatedTheSameWay()

	public function testUndismissByAMemberReversesTheDismissal(): void {
		$this->dismissals->expects($this->once())->method('undismiss')
			->with($this->anything(), 'reviewer-1')->willReturn(['id' => 7, 'reversed' => true]);

		$response = $this->controller()->undismiss('reg', 'sch');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testUndismissByAMemberReversesTheDismissal()
}//end class
