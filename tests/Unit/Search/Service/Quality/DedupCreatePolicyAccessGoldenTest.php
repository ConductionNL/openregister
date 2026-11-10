<?php

/**
 * Golden outcomes of the create-time duplicate block and its override.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Quality;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\DuplicateBlockedException;
use OCA\OpenRegister\Search\Service\Quality\DedupCreatePolicy;
use OCA\OpenRegister\Search\Service\Quality\DedupOverrideAudit;
use OCA\OpenRegister\Search\Service\Quality\DuplicateDetectionServiceInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Search\Service\Quality\CreateCandidate;

/**
 * Which creates the policy allows and which it refuses, and when it audits.
 */
class DedupCreatePolicyAccessGoldenTest extends TestCase {
	/**
	 * Cases: annotation, matches, override flag, signed-in uid and groups, expected outcome.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: int, 2: bool, 3: ?string, 4: array<int, string>, 5: string}>
	 */
	public static function cases(): array {
		$block = ['onCreate' => 'block', 'overrideGroups' => ['dedup-admins']];
		return [
			'warn never refuses'                    => [['onCreate' => 'warn'], 1, false, 'alice', [], 'allowed:0'],
			'no annotation never refuses'           => [[], 1, false, 'alice', [], 'allowed:0'],
			'block without a match allows'          => [$block, 0, false, 'alice', [], 'allowed:0'],
			'block with a match refuses'            => [$block, 1, false, 'alice', ['dedup-admins'], 'refused'],
			'override outside the group refuses'    => [$block, 1, true, 'alice', ['other'], 'refused'],
			'override signed out refuses'           => [$block, 1, true, null, [], 'refused'],
			'override inside the group allows'      => [$block, 2, true, 'bob', ['dedup-admins'], 'allowed:2'],
			'block naming no override group refuses' => [['onCreate' => 'block'], 1, true, 'bob', ['dedup-admins'], 'refused'],
			'blank override groups are skipped'     => [['onCreate' => 'block', 'overrideGroups' => ['', 7]], 1, true, 'bob', [''], 'refused'],
		];
	}//end cases()

	/**
	 * The guard allows or refuses exactly as pinned.
	 *
	 * @param array<string, mixed> $annotation The dedup annotation.
	 * @param int                  $matches    How many matches the scorer returns.
	 * @param bool                 $override   Whether the caller asked to save anyway.
	 * @param string|null          $uid        The signed-in uid, or null when signed out.
	 * @param array<int, string>   $groups     The groups the uid is in.
	 * @param string               $expected   'refused', or 'allowed:<matches returned>'.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testGuardOutcome(array $annotation, int $matches, bool $override, ?string $uid, array $groups, string $expected): void {
		$policy = $this->policy($annotation, $matches, $uid, $groups, $this->createMock(AuditTrailMapperInterface::class));

		try {
			$actual = 'allowed:' . count($policy->guardCreate(new CreateCandidate(register: 'reg', schema: 'person', data: ['name' => 'x'], overrideRequested: $override)));
		} catch (DuplicateBlockedException $e) {
			$actual = 'refused';
		}

		$this->assertSame($expected, $actual);
	}//end testGuardOutcome()

	/**
	 * An override is audited once; no override writes nothing.
	 *
	 * @return void
	 */
	public function testOverrideAuditOutcome(): void {
		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->expects($this->once())->method('recordObjectEvent');
		$policy = $this->policy([], 0, 'bob', [], $audit);

		$policy->recordOverride(new ObjectEntity(), []);
		$policy->recordOverride(new ObjectEntity(), [['uuid' => 'm-1']]);
	}//end testOverrideAuditOutcome()

	/**
	 * The policy over doubles.
	 *
	 * @param array<string, mixed>      $annotation The dedup annotation.
	 * @param int                       $matches    How many matches the scorer returns.
	 * @param string|null               $uid        The signed-in uid.
	 * @param array<int, string>        $groups     The groups the uid is in.
	 * @param AuditTrailMapperInterface $audit      The audit writer.
	 *
	 * @return DedupCreatePolicy
	 */
	private function policy(array $annotation, int $matches, ?string $uid, array $groups, AuditTrailMapperInterface $audit): DedupCreatePolicy {
		$duplicates = $this->createMock(DuplicateDetectionServiceInterface::class);
		$duplicates->method('dedupAnnotation')->willReturn($annotation);
		$duplicates->method('checkCandidate')->willReturn(array_fill(0, $matches, ['uuid' => 'm', 'score' => 0.9]));

		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $who, string $group): bool => ($who === $uid && in_array($group, $groups, true))
		);

		return new DedupCreatePolicy($duplicates, $session, $groupManager, new DedupOverrideAudit($audit, $this->createMock(LoggerInterface::class)));
	}//end policy()
}//end class
