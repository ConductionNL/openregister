<?php

/**
 * The guard at the import seam: the first import, the unattended upgrade and
 * the reset that refuses without an actor.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\ShippedBaseline\DescriptorParts;
use OCA\OpenRegister\Service\ShippedBaseline\DivergenceComparator;
use OCA\OpenRegister\Service\ShippedBaseline\GuardedDescriptorMerge;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineStore;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedConfigurationGuard;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies REQ-LCA-001, the unattended half of REQ-LCA-003 and REQ-LCA-004.
 */
class ShippedConfigurationGuardTest extends TestCase {

	/**
	 * The recorded audit rows.
	 *
	 * @var array<int, string>
	 */
	private array $actions = [];

	/**
	 * The baselines the store was asked to record, by subject.
	 *
	 * @var array<int, array{subject: string, definition: array<string, mixed>}>
	 */
	private array $recorded = [];

	/**
	 * A guard over an in-memory baseline store.
	 *
	 * @param array<string, mixed>|null $baseline The stored baseline definition, or null for none.
	 * @param IUserSession|null         $session  The session.
	 *
	 * @return ShippedConfigurationGuard The guard.
	 */
	private function guard(?array $baseline, ?IUserSession $session = null): ShippedConfigurationGuard {
		$store = $this->createMock(ShippedBaselineStore::class);
		$store->method('schemaSubject')->willReturnCallback(
			static fn (string $slug): string => ('schema:' . $slug)
		);
		$store->method('read')->willReturn(
			($baseline === null ? null : [
				'definition' => $baseline,
				'app' => 'dossiq',
				'appVersion' => '1.2.0',
				'recordedAt' => '2026-09-01T00:00:00+00:00',
			])
		);
		$store->method('record')->willReturnCallback(
			function (string $subject, array $definition): bool {
				$this->recorded[] = ['subject' => $subject, 'definition' => $definition];
				return true;
			}
		);

		$parts = new DescriptorParts();
		$comparator = new DivergenceComparator(parts: $parts);

		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('insertAuditTrails')->willReturnCallback(
			function (array $entries): array {
				foreach ($entries as $entry) {
					$this->actions[] = (string)$entry->getAction();
				}

				return $entries;
			}
		);

		if ($session === null) {
			$session = $this->createMock(IUserSession::class);
			$session->method('getUser')->willReturn(null);
		}

		return new ShippedConfigurationGuard(
			baselines: $store,
			merge: new GuardedDescriptorMerge(parts: $parts, comparator: $comparator),
			comparator: $comparator,
			audit: $audit,
			session: $session,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end guard()

	/**
	 * A session holding one user.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUserSession The session.
	 */
	private function sessionOf(string $uid): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($uid);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end sessionOf()

	/**
	 * 🔴 With no baseline recorded, the import behaves exactly as it does
	 * today: the incoming definition is written unchanged.
	 *
	 * Inventing a baseline from the incoming descriptor would declare every
	 * local edit ever made to be upstream, and overwrite it on the release
	 * after — the failure arriving through the fix.
	 *
	 * @return void
	 */
	public function testWithNoBaselineTheImportIsUnchanged(): void {
		$guard = $this->guard(baseline: null);

		$live = ['properties' => ['wijk' => ['type' => 'string']]];
		$incoming = ['properties' => ['zaaknummer' => ['type' => 'string']]];

		$result = $guard->guardSchemaUpdate(
			slug: 'zaak',
			live: $live,
			incoming: $incoming,
			app: 'dossiq',
			appVersion: '1.3.0'
		);

		$this->assertFalse($result['guarded'], 'the guard says plainly that it did not guard this import');
		$this->assertSame($incoming, $result['definition'], 'and the incoming definition is written as it stands');
	}//end testWithNoBaselineTheImportIsUnchanged()

	/**
	 * With a baseline, the local addition survives and the upstream change
	 * lands.
	 *
	 * @return void
	 */
	public function testWithABaselineTheLocalAdditionSurvives(): void {
		$baseline = ['properties' => ['zaaknummer' => ['type' => 'string', 'title' => 'Zaaknummer']]];

		$live = $baseline;
		$live['properties']['wijk'] = ['type' => 'string'];

		$incoming = $baseline;
		$incoming['properties']['zaaknummer']['title'] = 'Zaak-ID';

		$result = $this->guard(baseline: $baseline)->guardSchemaUpdate(
			slug: 'zaak',
			live: $live,
			incoming: $incoming,
			app: 'dossiq',
			appVersion: '1.3.0'
		);

		$this->assertTrue($result['guarded']);
		$this->assertArrayHasKey('wijk', $result['definition']['properties']);
		$this->assertSame('Zaak-ID', $result['definition']['properties']['zaaknummer']['title']);
	}//end testWithABaselineTheLocalAdditionSurvives()

	/**
	 * 🔴 An unattended upgrade with conflicts completes, and reports them.
	 *
	 * @return void
	 */
	public function testAnUnattendedUpgradeWithConflictsCompletes(): void {
		$baseline = ['properties' => ['toelichting' => ['maxLength' => 500]]];

		$live = ['properties' => ['toelichting' => ['maxLength' => 2000]]];
		$incoming = ['properties' => ['toelichting' => ['maxLength' => 1000]]];

		$result = $this->guard(baseline: $baseline)->guardSchemaUpdate(
			slug: 'zaak',
			live: $live,
			incoming: $incoming,
			app: 'dossiq',
			appVersion: '1.3.0'
		);

		$this->assertCount(1, $result['conflicts'], 'the conflict is reported');
		$this->assertSame(
			2000,
			$result['definition']['properties']['toelichting']['maxLength'],
			'and nothing was applied over it — the upgrade neither chose nor failed'
		);
	}//end testAnUnattendedUpgradeWithConflictsCompletes()

	/**
	 * The divergence report names the app and the version diverged from.
	 *
	 * @return void
	 */
	public function testTheReportNamesTheVersionDivergedFrom(): void {
		$baseline = ['properties' => ['toelichting' => ['maxLength' => 500]]];
		$live = ['properties' => ['toelichting' => ['maxLength' => 2000]]];

		$report = $this->guard(baseline: $baseline)->divergenceFor(slug: 'zaak', live: $live);

		$this->assertTrue($report['baseline']);
		$this->assertSame('dossiq', $report['app']);
		$this->assertSame('1.2.0', $report['appVersion'], 'which release the instance diverged from');
		$this->assertCount(1, $report['divergences']);
	}//end testTheReportNamesTheVersionDivergedFrom()

	/**
	 * With no baseline, the report says so rather than reporting nothing wrong.
	 *
	 * @return void
	 */
	public function testWithNoBaselineTheReportSaysSo(): void {
		$report = $this->guard(baseline: null)->divergenceFor(slug: 'zaak', live: ['properties' => []]);

		$this->assertFalse($report['baseline'], 'no baseline is a different answer from no divergence');
		$this->assertSame([], $report['divergences']);
	}//end testWithNoBaselineTheReportSaysSo()

	/**
	 * A reset shows its effect and writes nothing.
	 *
	 * @return void
	 */
	public function testAResetShowsItsEffectFirst(): void {
		$baseline = ['properties' => ['toelichting' => ['maxLength' => 500]]];
		$live = ['properties' => ['toelichting' => ['maxLength' => 2000]]];

		$preview = $this->guard(baseline: $baseline)->previewReset(
			slug: 'zaak',
			live: $live,
			path: 'properties.toelichting.maxLength'
		);

		$this->assertTrue($preview['applicable']);
		$this->assertSame(2000, $preview['from']);
		$this->assertSame(500, $preview['to']);
		$this->assertSame(500, $preview['definition']['properties']['toelichting']['maxLength']);
		$this->assertSame([], $this->actions, 'a preview records nothing');
	}//end testAResetShowsItsEffectFirst()

	/**
	 * 🔴 A reset refuses without an actor, so no unattended path performs one.
	 *
	 * @return void
	 */
	public function testAResetRefusesWithoutAnActor(): void {
		$baseline = ['properties' => ['toelichting' => ['maxLength' => 500]]];
		$live = ['properties' => ['toelichting' => ['maxLength' => 2000]]];

		$result = $this->guard(baseline: $baseline)->resetToBaseline(
			slug: 'zaak',
			live: $live,
			path: 'properties.toelichting.maxLength'
		);

		$this->assertFalse($result['applied'], 'a reset is a deliberate act and there is nobody to attribute it to');
		$this->assertStringContainsString('actor', $result['reason'], 'and the refusal says which');
		$this->assertSame(
			2000,
			$result['definition']['properties']['toelichting']['maxLength'],
			'nothing was changed'
		);
		$this->assertSame([], $this->actions);
	}//end testAResetRefusesWithoutAnActor()

	/**
	 * A reset with an actor applies and is on the record.
	 *
	 * @return void
	 */
	public function testAResetWithAnActorIsAudited(): void {
		$baseline = ['properties' => ['toelichting' => ['maxLength' => 500]]];
		$live = ['properties' => ['toelichting' => ['maxLength' => 2000]]];

		$result = $this->guard(baseline: $baseline, session: $this->sessionOf('beheerder'))->resetToBaseline(
			slug: 'zaak',
			live: $live,
			path: 'properties.toelichting.maxLength'
		);

		$this->assertTrue($result['applied']);
		$this->assertSame(500, $result['definition']['properties']['toelichting']['maxLength']);
		$this->assertSame(
			[ShippedConfigurationGuard::ACTION_RESET],
			$this->actions,
			'the trail carries the reset'
		);
	}//end testAResetWithAnActorIsAudited()

	/**
	 * Resetting a locally ADDED part removes it, because it was never shipped.
	 *
	 * @return void
	 */
	public function testResettingALocallyAddedPartRemovesIt(): void {
		$baseline = ['properties' => ['zaaknummer' => ['type' => 'string']]];
		$live = [
			'properties' => [
				'zaaknummer' => ['type' => 'string'],
				'wijk' => ['type' => 'string'],
			],
		];

		$preview = $this->guard(baseline: $baseline)->previewReset(
			slug: 'zaak',
			live: $live,
			path: 'properties.wijk.type'
		);

		$this->assertTrue($preview['applicable']);
		$this->assertArrayNotHasKey(
			'wijk',
			$preview['definition']['properties'],
			'going back to a baseline that never had it means removing it'
		);
	}//end testResettingALocallyAddedPartRemovesIt()

	/**
	 * Resetting a part that already matches is refused, with a reason.
	 *
	 * @return void
	 */
	public function testResettingAnUnchangedPartIsRefused(): void {
		$baseline = ['properties' => ['zaaknummer' => ['type' => 'string']]];

		$preview = $this->guard(baseline: $baseline)->previewReset(
			slug: 'zaak',
			live: $baseline,
			path: 'properties.zaaknummer.type'
		);

		$this->assertFalse($preview['applicable']);
		$this->assertStringContainsString('already matches', $preview['reason']);
	}//end testResettingAnUnchangedPartIsRefused()

	/**
	 * A decision taken during an upgrade is recorded.
	 *
	 * @return void
	 */
	public function testADecisionIsOnTheRecord(): void {
		$baseline = ['properties' => ['toelichting' => ['maxLength' => 500]]];
		$live = ['properties' => ['toelichting' => ['maxLength' => 2000]]];
		$incoming = ['properties' => ['toelichting' => ['maxLength' => 1000]]];

		$result = $this->guard(baseline: $baseline, session: $this->sessionOf('beheerder'))->guardSchemaUpdate(
			slug: 'zaak',
			live: $live,
			incoming: $incoming,
			app: 'dossiq',
			appVersion: '1.3.0',
			decisions: ['properties.toelichting.maxLength']
		);

		$this->assertSame(1000, $result['definition']['properties']['toelichting']['maxLength']);
		$this->assertSame(
			[ShippedConfigurationGuard::ACTION_DECIDED],
			$this->actions,
			'the decision names the part and the actor'
		);
	}//end testADecisionIsOnTheRecord()

	/**
	 * 🔴 D11 (learniq live pass, 3 Oct 2026): a shipped property that never
	 * reached the instance is not a local deletion.
	 *
	 * The baseline already named `personalNumber` and `emergencyContacts`
	 * (recorded by an import whose write did not land them), the stored schema
	 * has neither, and the release also edits one leaf of each. The guard read
	 * the absent property as REMOVED LOCALLY, kept the absence, and reported
	 * "2 part(s) changed on both sides" on every upgrade, so the schema stayed
	 * 14 properties short forever. A property absent from the instance as a
	 * whole, which the app still ships, is put back.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-a-shipped-property-missing-from-the-instance-is-restored-on-upgrade
	 */
	public function testAShippedPropertyMissingFromTheInstanceIsRestored(): void {
		$baseline = [
			'properties' => [
				'firstName' => ['type' => 'string', 'title' => 'Voornaam'],
				'personalNumber' => ['type' => 'string', 'x-notes' => 'BSN or onderwijsnummer'],
				'emergencyContacts' => ['type' => 'array', 'description' => 'Contacts'],
				'allergies' => ['type' => 'string'],
			],
			'required' => ['firstName'],
		];

		// The instance holds only what an earlier import managed to write.
		$live = [
			'properties' => ['firstName' => ['type' => 'string', 'title' => 'Voornaam']],
			'required' => ['firstName'],
		];

		$incoming = $baseline;
		$incoming['properties']['personalNumber']['x-notes'] = 'BSN, onderwijsnummer or a school number';
		$incoming['properties']['emergencyContacts']['description'] = 'Who to call';

		$result = $this->guard(baseline: $baseline)->guardSchemaUpdate(
			slug: 'learner-profile',
			live: $live,
			incoming: $incoming,
			app: 'learniq',
			appVersion: '0.34.33'
		);

		$this->assertSame([], $result['conflicts'], 'a property the instance never had is not a conflict');
		// The merge writes parts in path order, as it does for every guarded
		// import; the set is what this test is about.
		$written = array_keys($result['definition']['properties']);
		sort($written);
		$this->assertSame(
			['allergies', 'emergencyContacts', 'firstName', 'personalNumber'],
			$written,
			'every shipped property is in the definition written'
		);
		$this->assertSame($incoming['properties']['personalNumber'], $result['definition']['properties']['personalNumber']);
		$this->assertSame('Who to call', $result['definition']['properties']['emergencyContacts']['description']);
		$this->assertSame(['type' => 'string'], $result['definition']['properties']['allergies'], 'an unchanged missing property too');
	}//end testAShippedPropertyMissingFromTheInstanceIsRestored()

	/**
	 * A property the instance has, with one leaf removed locally, is still a
	 * local change: only a property missing as a whole is put back.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-a-shipped-property-missing-from-the-instance-is-restored-on-upgrade
	 */
	public function testALeafRemovedLocallyStaysRemoved(): void {
		$baseline = ['properties' => ['toelichting' => ['type' => 'string', 'maxLength' => 500]]];
		$live = ['properties' => ['toelichting' => ['type' => 'string']]];
		$incoming = $baseline;

		$result = $this->guard(baseline: $baseline)->guardSchemaUpdate(
			slug: 'zaak',
			live: $live,
			incoming: $incoming,
			app: 'dossiq',
			appVersion: '1.3.0'
		);

		$this->assertSame(['type' => 'string'], $result['definition']['properties']['toelichting']);
		$this->assertContains('properties.toelichting.maxLength', $result['preserved']);
	}//end testALeafRemovedLocallyStaysRemoved()

	/**
	 * 🔴 With `record: false` the guard records nothing and hands the baseline
	 * back, on both paths, so the import can record it after its write.
	 *
	 * The import used to record the baseline BEFORE writing the schema. When
	 * that write was refused, the instance kept its old properties under a
	 * baseline it never ran, and the next import read every old part as a
	 * local edit and kept it (portaliq's portalPage, 0.4.1 with 0.3.0
	 * properties).
	 *
	 * @return void
	 */
	public function testWithRecordFalseTheBaselineIsReturnedNotRecorded(): void {
		$incoming = ['properties' => ['kind' => ['type' => 'string', 'enum' => ['inbox', 'cases']]]];
		$live = ['properties' => ['kind' => ['type' => 'string', 'enum' => ['inbox']]]];

		$first = $this->guard(baseline: null)->guardSchemaUpdate(
			slug: 'portalPage',
			live: $live,
			incoming: $incoming,
			app: 'portaliq',
			appVersion: '0.56.0',
			record: false
		);

		$this->assertSame([], $this->recorded, 'nothing is recorded before the schema is written');
		$this->assertSame($incoming, $first['baseline'], 'the first baseline is what the app shipped');

		$second = $this->guard(baseline: $live)->guardSchemaUpdate(
			slug: 'portalPage',
			live: $live,
			incoming: $incoming,
			app: 'portaliq',
			appVersion: '0.56.1',
			record: false
		);

		$this->assertSame([], $this->recorded, 'nor on the guarded path');
		$this->assertEqualsCanonicalizing(['inbox', 'cases'], $second['definition']['properties']['kind']['enum'], 'the upstream change lands');
		$this->assertEqualsCanonicalizing(['inbox', 'cases'], $second['baseline']['properties']['kind']['enum'], 'and the baseline to record moves with it');
	}//end testWithRecordFalseTheBaselineIsReturnedNotRecorded()

	/**
	 * By default the guard still records, so the other callers are unchanged.
	 *
	 * @return void
	 */
	public function testByDefaultTheBaselineIsStillRecorded(): void {
		$incoming = ['properties' => ['zaaknummer' => ['type' => 'string']]];

		$this->guard(baseline: null)->guardSchemaUpdate(
			slug: 'zaak',
			live: ['properties' => []],
			incoming: $incoming,
			app: 'dossiq',
			appVersion: '1.3.0'
		);

		$this->assertSame([['subject' => 'schema:zaak', 'definition' => $incoming]], $this->recorded);
	}//end testByDefaultTheBaselineIsStillRecorded()
}//end class
