<?php

/**
 * Unit tests for ExampleSetPeople's reading of a plan: refusals, missing accounts and declared ids.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\ExampleSet\ExampleSetAccounts;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetVouchedAccounts;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ExampleSetPeoplePlanTest extends TestCase {

	private ExampleSetAccounts&MockObject $accounts;
	private ExampleSetPeople $people;

	protected function setUp(): void {
		$this->accounts = $this->createMock(ExampleSetAccounts::class);
		$this->people   = new ExampleSetPeople($this->accounts, $this->createMock(ExampleSetRecord::class));
	}

	private function plan(array $people = [], array $groups = [], array $fragments = []): array {
		return ['set' => 'demo', 'cast' => ['people' => $people, 'groups' => $groups], 'fragments' => $fragments];
	}

	private function noOneVouched(): ExampleSetVouchedAccounts {
		return new ExampleSetVouchedAccounts(['users' => []], []);
	}

	public function testTheElevenTest(): void {
		$this->assertTrue(ExampleSetPeople::passesElevenTest('111222333'));
		$this->assertTrue(ExampleSetPeople::passesElevenTest('123456782'));
		$this->assertFalse(ExampleSetPeople::passesElevenTest('123456789'));
		$this->assertFalse(ExampleSetPeople::passesElevenTest('12345678'));
		$this->assertFalse(ExampleSetPeople::passesElevenTest('12345678a'));
	}

	public function testACleanPlanHasNoRefusals(): void {
		$this->accounts->method('userExists')->willReturn(false);
		$plan = $this->plan(
			['ann' => ['uid' => 'ann', 'bsn' => '111222333', 'groups' => ['team']]],
			['team' => ['gid' => 'demo-team', 'subAdmins' => ['ann']]]
		);

		$this->assertSame([], $this->people->refusals($plan, $this->noOneVouched()));
	}

	public function testABadBsnAndAnUnknownCastGroupAreRefusedNamingTheCastFile(): void {
		$this->accounts->method('userExists')->willReturn(false);
		$plan = $this->plan(['ann' => ['uid' => 'ann', 'bsn' => '123456789', 'groups' => ['ghosts']]]);

		$this->assertSame(
			[
				'openregister/lib/Settings/example-sets/demo.json: cast "ann": BSN 123456789 fails the eleven test',
				'openregister/lib/Settings/example-sets/demo.json: cast "ann": group "ghosts" is not a cast group',
			],
			$this->people->refusals($plan, $this->noOneVouched())
		);
	}

	public function testAnExistingAccountTheSetDidNotMakeIsRefusedUnlessVouchedFor(): void {
		$this->accounts->method('userExists')->willReturnCallback(static fn (string $uid): bool => $uid !== 'new');
		$plan = $this->plan(['ann' => ['uid' => 'ann'], 'bob' => ['uid' => 'bob'], 'nu' => ['uid' => 'new'], 'none' => []]);
		$vouched = new ExampleSetVouchedAccounts(['users' => ['bob' => 'created']], []);

		$this->assertSame(
			['account "ann" exists and this set did not create it, so it is left alone. Pass --adopt=ann to let the set use it; remove will then leave it in place.'],
			$this->people->refusals($plan, $vouched)
		);
		$this->assertSame([], $this->people->refusals($plan, new ExampleSetVouchedAccounts(['users' => ['bob' => 'x']], ['ann'])));
	}

	public function testTheAdminGroupIsRefusedInAnyCase(): void {
		$this->accounts->method('userExists')->willReturn(false);
		$plan = $this->plan([], ['boss' => ['gid' => 'Admin']]);

		$refusals = $this->people->refusals($plan, $this->noOneVouched());

		$this->assertCount(1, $refusals);
		$this->assertStringStartsWith('group "Admin": an example set never adds anyone to Nextcloud\'s administrators.', $refusals[0]);
	}

	public function testSubAdminsAndFragmentMembersMustBeCastPeopleWithAnAccount(): void {
		$this->accounts->method('userExists')->willReturn(false);
		$plan = $this->plan(
			['ann' => ['uid' => 'ann'], 'clerk' => []],
			['team' => ['subAdmins' => ['clerk']]],
			[['file' => 'frag.json', 'groups' => [['group' => 'app-users', 'members' => ['ann', 'stranger']]]]]
		);

		$this->assertSame(
			[
				'group "team": sub-admin "clerk" is not a cast person with an account',
				'frag.json: group "app-users": "stranger" is not a cast person with an account',
			],
			$this->people->refusals($plan, $this->noOneVouched())
		);
	}

	public function testAServiceAccountMayNotReuseACastMembersId(): void {
		$this->accounts->method('userExists')->willReturn(false);
		$plan = $this->plan(
			['ann' => ['uid' => 'ann']],
			[],
			[['file' => 'f.json', 'groups' => [], 'serviceAccounts' => [['key' => 'bot', 'uid' => 'ann', 'groups' => []]]]]
		);

		$this->assertSame(
			['service account "bot": the account id ann is a cast member\'s'],
			$this->people->refusals($plan, $this->noOneVouched())
		);
	}

	public function testMissingNamesEveryRecordedThingThatIsGone(): void {
		$this->accounts->method('userExists')->willReturnMap([['ann', true], ['bob', false]]);
		$this->accounts->method('groupExists')->willReturnMap([['team', false]]);
		$this->accounts->method('inGroup')->willReturnMap([['team', 'ann', false]]);
		$this->accounts->method('isSubAdmin')->willReturnMap([['team', 'ann', false]]);

		$this->assertSame(
			['account bob', 'group team', 'membership team / ann', 'sub-admin team / ann'],
			$this->people->missing(
				[
					'users' => ['ann' => 'created', 'bob' => 'created'],
					'groups' => ['team'],
					'memberships' => [['team', 'ann']],
					'subAdmins' => [['team', 'ann']],
				]
			)
		);
	}

	public function testARecordWithoutSubAdminsIsReadAsNone(): void {
		$this->accounts->expects($this->never())->method('isSubAdmin');

		$this->assertSame([], $this->people->missing(['users' => [], 'groups' => [], 'memberships' => []]));
	}

	public function testServiceAccountsAreCollectedFromEveryFragment(): void {
		$plan = $this->plan(
			[],
			[],
			[
				['groups' => [], 'serviceAccounts' => [['uid' => 'bot1', 'groups' => []]]],
				['groups' => []],
				['groups' => [], 'serviceAccounts' => [['uid' => 'bot2', 'groups' => []]]],
			]
		);

		$this->assertSame(['bot1', 'bot2'], array_column($this->people->serviceAccounts($plan), 'uid'));
	}

	public function testDeclaredUidsAreTheUniqueNonEmptyCastAndServiceIds(): void {
		$plan = $this->plan(
			['a' => ['uid' => 'ann'], 'b' => [], 'c' => ['uid' => 'ann']],
			[],
			[['groups' => [], 'serviceAccounts' => [['uid' => 'bot', 'groups' => []]]]]
		);

		$this->assertSame(['ann', 'bot'], $this->people->declaredUids($plan));
	}

	public function testTheGroupsOfASetAreTheRecordedOnesPlusDeclaredOnesNotYetPresent(): void {
		$this->accounts->method('groupExists')->willReturnMap([['existing', true], ['fresh', false], ['svc', false]]);
		$plan = $this->plan(
			[],
			['t' => ['gid' => 'existing'], 'f' => ['gid' => 'fresh']],
			[['groups' => [['group' => 'recorded']], 'serviceAccounts' => [['uid' => 'bot', 'groups' => ['svc']]]]]
		);

		$this->assertSame(['recorded', 'fresh', 'svc'], $this->people->groupsOfSet($plan, ['groups' => ['recorded', 7]]));
	}
}
