<?php

/**
 * The auditor's question: who may open this, and who could open it in March.
 *
 * Provenance reads both ways (design D-10). The scopes endpoint answers one
 * caller about themselves; this answers everybody about one object, and its
 * history answers the question a point-in-time read usually cannot: not only
 * who held a right then, but which rule took it away afterwards.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\Rbac\ObjectPermissionsResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tasks 7.2 and 7.3: the access set of one object, and its history.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\ObjectPermissionsResolver
 */
class ObjectPermissionsResolverTest extends TestCase {

	/**
	 * The resolver under test.
	 *
	 * @var ObjectPermissionsResolver
	 */
	private ObjectPermissionsResolver $resolver;

	/**
	 * Build the resolver over the shared readers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new ObjectPermissionsResolver();
	}//end setUp()

	/**
	 * One audit-trail entry recording an authorization change.
	 *
	 * @param string     $at   The moment, as anything strtotime reads.
	 * @param string     $by   The user who made the change.
	 * @param array|null $from The block before.
	 * @param array|null $to   The block after.
	 *
	 * @return AuditTrail The entry.
	 */
	private function entry(string $at, string $by, ?array $from, ?array $to): AuditTrail {
		$trail = new AuditTrail();
		$trail->setAction('update');
		$trail->setUser($by);
		$trail->setUserName(ucfirst($by));
		$trail->setCreated(new DateTime($at));
		$trail->setChanged(['authorization' => ['old' => $from, 'new' => $to]]);

		return $trail;
	}//end entry()

	/**
	 * 🔴 Three grant sources, three principals, each with the rule behind it.
	 *
	 * The scenario as the spec writes it: an object reachable by one role grant,
	 * one per-object grant and one grant written higher up the cascade.
	 *
	 * @return void
	 */
	public function testEveryPrincipalIsReportedWithTheRuleBehindIt(): void {
		$set = $this->resolver->holders(
			blocks: [
				'object' => ['read' => ['stagiairs']],
				'schema' => ['roles' => ['behandelaar' => ['behandelaars']]],
				'register' => ['read' => ['directie'], 'manage' => ['beheerders']],
			],
			roleDefinitions: [['name' => 'behandelaar', 'actions' => ['read', 'update']]]
		);

		$byPrincipal = [];
		foreach ($set['holders'] as $holder) {
			$byPrincipal[$holder['principal']] = $holder;
		}

		$this->assertSame(
			['stagiairs', 'behandelaars', 'directie', 'beheerders'],
			array_keys($byPrincipal),
			'the principals are reported most specific level first'
		);

		// The per-object grant names the object as the level it is written at.
		$this->assertSame(['read'], $byPrincipal['stagiairs']['verbs']);
		$this->assertSame('object', $byPrincipal['stagiairs']['rules'][0]['level']);

		// The role grant names the ROLE, because the role is what an
		// administrator edits. Reporting only the group would be true and
		// useless: nobody granted that group the verb, the role did.
		$this->assertSame(['read', 'update'], $byPrincipal['behandelaars']['verbs']);
		$this->assertSame('behandelaar', $byPrincipal['behandelaars']['rules'][0]['role']);
		$this->assertSame('schema', $byPrincipal['behandelaars']['rules'][0]['level']);

		// And the grant written on the register is reported at the register.
		$this->assertSame('register', $byPrincipal['directie']['rules'][0]['level']);
	}//end testEveryPrincipalIsReportedWithTheRuleBehindIt()

	/**
	 * A deny is reported beside the grants, not subtracted from them.
	 *
	 * Below `enforcing` a deny has not started biting, so a report that had
	 * already subtracted it would be wrong in the one direction that matters:
	 * it would show an auditor a person as locked out while they are still
	 * working.
	 *
	 * @return void
	 */
	public function testADenyIsReportedBesideTheGrantsRatherThanSubtracted(): void {
		$set = $this->resolver->holders(
			blocks: [
				'object' => ['deny' => ['read' => ['waarnemers']]],
				'schema' => ['read' => ['waarnemers', 'behandelaars']],
			]
		);

		$principals = array_column($set['holders'], 'principal');
		$this->assertContains('waarnemers', $principals, 'the denied principal vanished from the grants');

		$this->assertCount(1, $set['denied']);
		$this->assertSame('waarnemers', $set['denied'][0]['principal']);
		$this->assertSame('read', $set['denied'][0]['action']);
		$this->assertSame('object', $set['denied'][0]['level']);
	}//end testADenyIsReportedBesideTheGrantsRatherThanSubtracted()

	/**
	 * A control key is not a verb, and a verb nobody declared says so.
	 *
	 * @return void
	 */
	public function testControlKeysAreNotReportedAsVerbs(): void {
		$set = $this->resolver->holders(
			blocks: [
				'schema' => [
					'read' => ['behandelaars'],
					'approve' => ['managers'],
					'roles' => [],
					'scope' => 'organisation',
					'public' => true,
				],
			]
		);

		$verbs = [];
		foreach ($set['holders'] as $holder) {
			$verbs = array_merge($verbs, $holder['verbs']);
		}

		$this->assertSame(['read', 'approve'], $verbs);

		$undeclared = [];
		foreach ($set['holders'] as $holder) {
			foreach ($holder['rules'] as $rule) {
				if ($rule['declared'] === false) {
					$undeclared[] = $rule['action'];
				}
			}
		}

		$this->assertSame(['approve'], $undeclared, 'a verb no app declares must be reported as undeclared');
	}//end testControlKeysAreNotReportedAsVerbs()

	/**
	 * An object nobody wrote a rule for reports nobody, rather than everybody.
	 *
	 * The floor for every case above.
	 *
	 * @return void
	 */
	public function testAnObjectWithNoRulesReportsNoHolders(): void {
		$set = $this->resolver->holders(blocks: ['object' => null, 'schema' => [], 'register' => null]);

		$this->assertSame([], $set['holders']);
		$this->assertSame([], $set['denied']);
	}//end testAnObjectWithNoRulesReportsNoHolders()

	/**
	 * 🔴 The history answers who held a right at a past moment, and what removed it.
	 *
	 * @return void
	 */
	public function testTheHistoryAnswersAboutAPastMoment(): void {
		// Newest first, which is the order the trail mapper answers in.
		$entries = [
			$this->entry(
				at: '2026-06-01T10:00:00+02:00',
				by: 'ruben',
				from: ['read' => ['behandelaars', 'stagiairs']],
				to: ['read' => ['behandelaars']]
			),
			$this->entry(
				at: '2026-03-01T09:00:00+01:00',
				by: 'ana',
				from: ['read' => ['behandelaars']],
				to: ['read' => ['behandelaars', 'stagiairs']]
			),
		];

		$history = $this->resolver->history(entries: $entries, at: '2026-04-15T12:00:00+02:00');

		$this->assertCount(2, $history['changes']);
		$this->assertNotNull($history['asOf'], 'no set was reported for the moment asked about');

		$principals = array_column($history['asOf']['holders'], 'principal');
		$this->assertContains(
			'stagiairs',
			$principals,
			'the grant held in April is missing from the April answer'
		);
		$this->assertSame('ana', $history['asOf']['setBy']);

		// And the second half of the auditor's question: what changed it after.
		$this->assertNotNull($history['asOf']['changedAfterwardsBy']);
		$this->assertSame('ruben', $history['asOf']['changedAfterwardsBy']['by']);
		$this->assertSame(
			['behandelaars'],
			$history['asOf']['changedAfterwardsBy']['to']['read'],
			'the later change is reported with the set it left behind, by value'
		);
	}//end testTheHistoryAnswersAboutAPastMoment()

	/**
	 * A change that touched only the data is not a change to the access set.
	 *
	 * Reporting one would tell an auditor that access moved on a day nobody
	 * touched it, which is the kind of finding that costs a week.
	 *
	 * @return void
	 */
	public function testAChangeThatDidNotTouchTheRulesIsNotReported(): void {
		$dataOnly = new AuditTrail();
		$dataOnly->setAction('update');
		$dataOnly->setUser('ana');
		$dataOnly->setCreated(new DateTime('2026-05-01T09:00:00+02:00'));
		$dataOnly->setChanged(['object' => ['old' => ['title' => 'oud'], 'new' => ['title' => 'nieuw']]]);

		$history = $this->resolver->history(entries: [$dataOnly], at: '2026-06-01T00:00:00+02:00');

		$this->assertSame([], $history['changes']);
		$this->assertNull($history['asOf']);
	}//end testAChangeThatDidNotTouchTheRulesIsNotReported()

	/**
	 * A stored block that arrived as JSON text is read, not dropped.
	 *
	 * @return void
	 */
	public function testAnAuthorizationStoredAsJsonTextIsStillRead(): void {
		$entry = new AuditTrail();
		$entry->setAction('update');
		$entry->setUser('ana');
		$entry->setCreated(new DateTime('2026-02-01T09:00:00+01:00'));
		$entry->setChanged(
			[
				'authorization' => [
					'old' => null,
					'new' => json_encode(['read' => ['behandelaars']]),
				],
			]
		);

		$history = $this->resolver->history(entries: [$entry], at: '2026-03-01T09:00:00+01:00');

		$this->assertCount(1, $history['changes']);
		$this->assertSame(
			['behandelaars'],
			array_column($history['asOf']['holders'], 'principal')
		);
	}//end testAnAuthorizationStoredAsJsonTextIsStillRead()
}//end class
