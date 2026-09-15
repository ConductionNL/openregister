<?php

/**
 * Create-time duplicate policy.
 *
 * Covers `onCreate` (advisory by default, blocking by declaration), the
 * group-gated override, and the audit entry an exercised override leaves
 * behind.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Quality
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
 */

declare(strict_types=1);

namespace Unit\Service\Quality;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\DuplicateBlockedException;
use OCA\OpenRegister\Service\Quality\DedupCreatePolicy;
use OCA\OpenRegister\Service\Quality\DuplicateDetectionService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DedupCreatePolicyTest extends TestCase {

	/**
	 * The scorer, mocked so the policy is tested and not the similarity maths.
	 *
	 * @var DuplicateDetectionService&MockObject
	 */
	private $duplicates;

	/**
	 * Session naming the caller.
	 *
	 * @var IUserSession&MockObject
	 */
	private $userSession;

	/**
	 * Group membership.
	 *
	 * @var IGroupManager&MockObject
	 */
	private $groupManager;

	/**
	 * Audit writer.
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private $auditTrailMapper;

	/**
	 * Policy under test.
	 *
	 * @var DedupCreatePolicy
	 */
	private DedupCreatePolicy $policy;

	/**
	 * Wire the policy over mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->duplicates = $this->createMock(DuplicateDetectionService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);

		$this->policy = new DedupCreatePolicy(
			$this->duplicates,
			$this->userSession,
			$this->groupManager,
			$this->auditTrailMapper,
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * Sign a user in as `$uid`, a member of `$groups`.
	 *
	 * @param string $uid The acting uid.
	 * @param array<int, string> $groups Groups the user belongs to.
	 *
	 * @return void
	 */
	private function signIn(string $uid, array $groups = []): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $who, string $group): bool => ($who === $uid && in_array($group, $groups, true))
		);
	}//end signIn()

	/**
	 * One match, shaped as the scorer returns it.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function oneMatch(): array {
		return [
			[
				'uuid' => 'stored-1',
				'score' => 0.97,
				'matchedOn' => ['requester', 'subject'],
				'matchedRules' => [['field' => 'requester', 'method' => 'exact', 'similarity' => 1.0]],
			],
		];
	}//end oneMatch()

	/**
	 * A schema that declares nothing is advisory: the policy does not even
	 * score, and certainly does not refuse.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	public function testWarnIsTheDefaultAndDoesNotScore(): void {
		$this->duplicates->method('dedupAnnotation')->willReturn(['matchRules' => []]);
		$this->duplicates->expects($this->never())->method('checkCandidate');

		$this->assertSame([], $this->policy->guardCreate(1, 1, ['requester' => 'bsn:1'], false));
	}//end testWarnIsTheDefaultAndDoesNotScore()

	/**
	 * A schema that declares `warn` explicitly behaves the same way.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	public function testDeclaredWarnDoesNotRefuse(): void {
		$this->duplicates->method('dedupAnnotation')->willReturn(['onCreate' => 'warn']);
		$this->duplicates->expects($this->never())->method('checkCandidate');

		$this->assertSame([], $this->policy->guardCreate(1, 1, ['requester' => 'bsn:1'], false));
	}//end testDeclaredWarnDoesNotRefuse()

	/**
	 * A blocked create is refused with 409 and the match travels with it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	public function testBlockRefusesAndNamesTheMatch(): void {
		$this->signIn('handler', ['case-handlers']);
		$this->duplicates->method('dedupAnnotation')->willReturn(
			['onCreate' => 'block', 'overrideGroups' => ['case-supervisors']]
		);
		$this->duplicates->method('checkCandidate')->willReturn($this->oneMatch());

		try {
			$this->policy->guardCreate(1, 1, ['requester' => 'bsn:1'], false);
			$this->fail('a blocking match should have been refused');
		} catch (DuplicateBlockedException $e) {
			$this->assertSame(409, $e->getCode());
			$this->assertSame(['stored-1'], array_column($e->getMatches(), 'uuid'));
		}
	}//end testBlockRefusesAndNamesTheMatch()

	/**
	 * Blocking with no match does not refuse.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	public function testBlockWithoutAMatchAllowsTheCreate(): void {
		$this->duplicates->method('dedupAnnotation')->willReturn(['onCreate' => 'block']);
		$this->duplicates->method('checkCandidate')->willReturn([]);

		$this->assertSame([], $this->policy->guardCreate(1, 1, ['requester' => 'bsn:1'], false));
	}//end testBlockWithoutAMatchAllowsTheCreate()

	/**
	 * Asking to override without being in an override group is still refused:
	 * the flag is a request, not a permission.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	public function testOverrideOutsideTheGroupIsStillRefused(): void {
		$this->signIn('handler', ['case-handlers']);
		$this->duplicates->method('dedupAnnotation')->willReturn(
			['onCreate' => 'block', 'overrideGroups' => ['case-supervisors']]
		);
		$this->duplicates->method('checkCandidate')->willReturn($this->oneMatch());

		$this->expectException(DuplicateBlockedException::class);
		$this->policy->guardCreate(1, 1, ['requester' => 'bsn:1'], true);
	}//end testOverrideOutsideTheGroupIsStillRefused()

	/**
	 * A caller in an override group who asks to override gets through, and the
	 * matches come back so the save can put them on the audit trail.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	public function testOverrideInsideTheGroupPassesAndReturnsTheMatches(): void {
		$this->signIn('supervisor', ['case-supervisors']);
		$this->duplicates->method('dedupAnnotation')->willReturn(
			['onCreate' => 'block', 'overrideGroups' => ['case-supervisors']]
		);
		$this->duplicates->method('checkCandidate')->willReturn($this->oneMatch());

		$overridden = $this->policy->guardCreate(1, 1, ['requester' => 'bsn:1'], true);

		$this->assertSame(['stored-1'], array_column($overridden, 'uuid'));
	}//end testOverrideInsideTheGroupPassesAndReturnsTheMatches()

	/**
	 * Being in an override group is not enough on its own: the caller has to
	 * ask. Otherwise a supervisor could never be warned at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	public function testMembershipWithoutTheFlagIsStillRefused(): void {
		$this->signIn('supervisor', ['case-supervisors']);
		$this->duplicates->method('dedupAnnotation')->willReturn(
			['onCreate' => 'block', 'overrideGroups' => ['case-supervisors']]
		);
		$this->duplicates->method('checkCandidate')->willReturn($this->oneMatch());

		$this->expectException(DuplicateBlockedException::class);
		$this->policy->guardCreate(1, 1, ['requester' => 'bsn:1'], false);
	}//end testMembershipWithoutTheFlagIsStillRefused()

	/**
	 * A schema that blocks and names no override group has said nobody
	 * overrides, and that includes an administrator. There is no implicit
	 * bypass, by design.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	public function testBlockWithoutOverrideGroupsAdmitsNobody(): void {
		$this->signIn('admin', ['admin']);
		$this->duplicates->method('dedupAnnotation')->willReturn(['onCreate' => 'block']);
		$this->duplicates->method('checkCandidate')->willReturn($this->oneMatch());

		$this->expectException(DuplicateBlockedException::class);
		$this->policy->guardCreate(1, 1, ['requester' => 'bsn:1'], true);
	}//end testBlockWithoutOverrideGroupsAdmitsNobody()

	/**
	 * THE CONTROL FOR THE PLUMBING, not for the policy.
	 *
	 * `ObjectsController` strips every `_`-prefixed key from a create body
	 * before the save path sees it, which is the convention for control
	 * parameters that must not be persisted. So the override cannot travel in
	 * the body on the HTTP path: it is read from the raw request and threaded
	 * through `ObjectService::saveObject()` and `SaveObject::saveObject()` as
	 * `$_dedupOverride`, exactly as `_failIfExists` is.
	 *
	 * This test pins the whole chain by reflection, because the failure it
	 * guards against is silent: an override that never arrives looks identical
	 * to an override that was refused, and the user is told "this duplicates
	 * an existing record" with no way to tell which happened.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	public function testTheOverrideHasAParameterOnEverySaveHopNotOnlyTheBody(): void {
		foreach (
			[
				\OCA\OpenRegister\Service\ObjectService::class,
				\OCA\OpenRegister\Service\Object\SaveObject::class,
			] as $class
		) {
			$method = new \ReflectionMethod($class, 'saveObject');
			$names = array_map(
				static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
				$method->getParameters()
			);

			$this->assertContains(
				'_dedupOverride',
				$names,
				$class . '::saveObject() must carry the override as a parameter: the controller strips it from the body.'
			);
		}
	}//end testTheOverrideHasAParameterOnEverySaveHopNotOnlyTheBody()

	/**
	 * An exercised override lands on the created object's audit trail as
	 * `dedup.overridden`, naming what it was created over.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	public function testOverrideIsOnTheAuditTrail(): void {
		$object = new ObjectEntity();
		$object->setUuid('new-1');

		$captured = [];
		$this->auditTrailMapper->expects($this->once())
			->method('createAuditTrailEntry')
			->willReturnCallback(
				function (ObjectEntity $subject, string $action, array $context) use (&$captured): AuditTrail {
					$captured = ['uuid' => $subject->getUuid(), 'action' => $action, 'context' => $context];
					return new AuditTrail();
				}
			);

		$this->policy->recordOverride($object, $this->oneMatch());

		$this->assertSame('new-1', $captured['uuid']);
		$this->assertSame('dedup.overridden', $captured['action']);
		$this->assertSame(['stored-1'], $captured['context']['matchedObjects']);
	}//end testOverrideIsOnTheAuditTrail()

	/**
	 * Nothing to record means nothing is written, so an ordinary create does
	 * not gain an empty audit row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	public function testNoOverrideWritesNoAuditEntry(): void {
		$this->auditTrailMapper->expects($this->never())->method('createAuditTrailEntry');

		$this->policy->recordOverride(new ObjectEntity(), []);
	}//end testNoOverrideWritesNoAuditEntry()
}//end class
