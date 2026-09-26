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
		$store->method('record')->willReturn(true);

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
}//end class
