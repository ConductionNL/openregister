<?php

/**
 * Golden: the exact audit rows and baselines the shipped-configuration guard writes.
 *
 * Pins, before the ADR-011 refactor of the guard's upgrade and reset entry
 * points, every `changed` payload the trail receives (schema, path, app,
 * decision, via; schema, path, from, to), the actor on each row, the
 * baselines recorded with their release, and the refused outcomes: a reset
 * without an actor and a reset of an unchanged part write nothing.
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
use OCA\OpenRegister\Service\ShippedBaseline\PartReset;
use OCA\OpenRegister\Service\ShippedBaseline\ResetRequest;
use OCA\OpenRegister\Service\ShippedBaseline\SchemaUpgrade;
use OCA\OpenRegister\Service\ShippedBaseline\SchemaUpgradeOptions;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedAuthorizationDecision;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineStore;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedConfigurationGuard;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedDefinition;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden audit rows and baselines of ShippedConfigurationGuard.
 */
class ShippedConfigurationGuardAuditGoldenTest extends TestCase {

	/**
	 * Audit rows written: action, user and changed payload.
	 *
	 * @var array<int, array{action: string, user: string, changed: mixed}>
	 */
	private array $rows = [];

	/**
	 * Baselines recorded: subject, definition, app, appVersion.
	 *
	 * @var array<int, array{subject: string, definition: mixed, app: string, appVersion: string}>
	 */
	private array $baselines = [];

	/**
	 * A guard over a mocked store and trail.
	 *
	 * @param array<string, mixed>|null         $baseline The stored baseline definition, or null for none.
	 * @param string|null                       $actor    The acting user id, or null for no session.
	 * @param ShippedAuthorizationDecision|null $decision The administrator's decision, when one is in effect.
	 *
	 * @return ShippedConfigurationGuard The guard.
	 */
	private function guard(?array $baseline, ?string $actor = null, ?ShippedAuthorizationDecision $decision = null): ShippedConfigurationGuard {
		$store = $this->createMock(ShippedBaselineStore::class);
		$store->method('schemaSubject')->willReturnCallback(static fn (string $slug): string => ('schema:' . $slug));
		$store->method('read')->willReturn(
			($baseline === null ? null : ['definition' => $baseline, 'app' => 'dossiq', 'appVersion' => '1.2.0', 'recordedAt' => 'x'])
		);
		$store->method('record')->willReturnCallback(
			function (mixed ...$args): bool {
				$this->baselines[] = $this->normalisedBaseline(args: $args);
				return true;
			}
		);

		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('insertAuditTrails')->willReturnCallback(
			function (array $entries): array {
				foreach ($entries as $entry) {
					$this->rows[] = ['action' => (string)$entry->getAction(), 'user' => (string)$entry->getUser(), 'changed' => $entry->getChanged()];
				}

				return $entries;
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($actor !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($actor);
			$user->method('getDisplayName')->willReturn($actor);
		}

		$session->method('getUser')->willReturn($user);

		$parts = new DescriptorParts();
		$comparator = new DivergenceComparator(parts: $parts);

		return new ShippedConfigurationGuard(
			baselines: $store,
			merge: new GuardedDescriptorMerge(parts: $parts, comparator: $comparator, decision: $decision),
			comparator: $comparator,
			audit: $audit,
			session: $session,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end guard()

	/**
	 * One recorded baseline, whatever shape the store's record() takes.
	 *
	 * @param array<int|string, mixed> $args The arguments record() received.
	 *
	 * @return array{subject: string, definition: mixed, app: string, appVersion: string}
	 */
	private function normalisedBaseline(array $args): array {
		$args = array_values($args);
		if (($args[1] ?? null) instanceof ShippedDefinition) {
			return ['subject' => $args[0], 'definition' => $args[1]->definition, 'app' => $args[1]->app, 'appVersion' => $args[1]->appVersion];
		}

		return ['subject' => $args[0], 'definition' => $args[1], 'app' => $args[2], 'appVersion' => $args[3]];
	}//end normalisedBaseline()

	/**
	 * A first import records the shipped definition with its release, and writes no audit row.
	 *
	 * @return void
	 */
	public function testAFirstImportRecordsTheReleaseAndNoRow(): void {
		$incoming = ['title' => 'Zaak'];
		$result = $this->guard(baseline: null)->guardUpgrade(upgrade: new SchemaUpgrade(slug: 'zaak', live: [], incoming: new ShippedDefinition(definition: $incoming, app: 'dossiq', appVersion: '1.3.0')));

		$this->assertSame(['definition' => $incoming, 'baseline' => $incoming, 'guarded' => false, 'applied' => [], 'preserved' => [], 'conflicts' => []], $result);
		$this->assertSame([['subject' => 'schema:zaak', 'definition' => $incoming, 'app' => 'dossiq', 'appVersion' => '1.3.0']], $this->baselines);
		$this->assertSame([], $this->rows);
	}//end testAFirstImportRecordsTheReleaseAndNoRow()

	/**
	 * With record: false nothing is recorded, on either branch.
	 *
	 * @return void
	 */
	public function testRecordFalseRecordsNothing(): void {
		$this->guard(baseline: null)->guardUpgrade(upgrade: new SchemaUpgrade(slug: 'zaak', live: [], incoming: new ShippedDefinition(definition: ['a' => 1], app: 'dossiq', appVersion: '1.3.0')), options: new SchemaUpgradeOptions(record: false));
		$this->guard(baseline: ['a' => 1])->guardUpgrade(upgrade: new SchemaUpgrade(slug: 'zaak', live: ['a' => 1], incoming: new ShippedDefinition(definition: ['a' => 2], app: 'dossiq', appVersion: '1.3.0')), options: new SchemaUpgradeOptions(record: false));

		$this->assertSame([], $this->baselines);
	}//end testRecordFalseRecordsNothing()

	/**
	 * An administrator's decision and an app-only authorization change each write one exact row; the baseline goes with the release.
	 *
	 * @return void
	 */
	public function testDecisionAndAppliedAuthorizationRows(): void {
		$baseline = ['authorization' => ['read' => ['a']], 'properties' => ['t' => ['maxLength' => 500]]];
		$live = ['authorization' => ['read' => ['a']], 'properties' => ['t' => ['maxLength' => 2000]]];
		$incoming = ['authorization' => ['read' => ['b']], 'properties' => ['t' => ['maxLength' => 1000]]];

		$result = $this->guard(baseline: $baseline, actor: 'beheerder')->guardUpgrade(upgrade: new SchemaUpgrade(slug: 'zaak', live: $live, incoming: new ShippedDefinition(definition: $incoming, app: 'dossiq', appVersion: '1.3.0')), options: new SchemaUpgradeOptions(decisions: ['properties.t.maxLength']));

		$this->assertTrue($result['guarded']);
		$this->assertSame($incoming, $result['definition']);
		$this->assertSame(
			[
				['action' => ShippedConfigurationGuard::ACTION_DECIDED, 'user' => 'beheerder', 'changed' => ['schema' => 'zaak', 'path' => 'authorization', 'app' => 'dossiq', 'decision' => 'applied-shipped']],
				['action' => ShippedConfigurationGuard::ACTION_DECIDED, 'user' => 'beheerder', 'changed' => ['schema' => 'zaak', 'path' => 'properties.t.maxLength', 'app' => 'dossiq', 'decision' => 'accept-shipped']],
			],
			$this->rows
		);
		$this->assertCount(1, $this->baselines);
		$this->assertSame(['schema:zaak', 'dossiq', '1.3.0'], [$this->baselines[0]['subject'], $this->baselines[0]['app'], $this->baselines[0]['appVersion']]);
	}//end testDecisionAndAppliedAuthorizationRows()

	/**
	 * A re-import that replaces a local authorization block writes the row with `via`, unattended as `system`.
	 *
	 * @return void
	 */
	public function testAReplacedBlockRowCarriesVia(): void {
		$baseline = ['authorization' => ['read' => ['authenticated']]];
		$live = ['authorization' => ['read' => ['staff']]];
		$incoming = ['authorization' => ['read' => ['shipped']]];

		$decision = new ShippedAuthorizationDecision();
		$guard = $this->guard(baseline: $baseline, decision: $decision);
		$decision->run(
			via: 'occ import',
			operation: static fn (): array => $guard->guardUpgrade(upgrade: new SchemaUpgrade(slug: 'p', live: $live, incoming: new ShippedDefinition(definition: $incoming, app: 'portaliq', appVersion: '0.9.0')))
		);

		$this->assertSame(
			[['action' => ShippedConfigurationGuard::ACTION_DECIDED, 'user' => 'system', 'changed' => ['schema' => 'p', 'path' => 'authorization', 'app' => 'portaliq', 'decision' => 'accept-shipped', 'via' => 'occ import']]],
			$this->rows
		);
	}//end testAReplacedBlockRowCarriesVia()

	/**
	 * Allowed: a reset with an actor writes one exact reset row. Refused: without an actor, or for an unchanged part, nothing is written.
	 *
	 * @return void
	 */
	public function testResetRowsAllowedAndRefused(): void {
		$baseline = ['properties' => ['t' => ['maxLength' => 500]]];
		$live = ['properties' => ['t' => ['maxLength' => 2000]]];

		$refused = $this->guard(baseline: $baseline)->resetToBaseline(slug: 'zaak', live: $live, path: 'properties.t.maxLength');
		$this->assertFalse($refused['applied']);
		$this->assertSame($live, $refused['definition']);

		$unchanged = $this->guard(baseline: $baseline, actor: 'beheerder')->resetToBaseline(slug: 'zaak', live: $baseline, path: 'properties.t.maxLength');
		$this->assertFalse($unchanged['applied']);
		$this->assertSame('"properties.t.maxLength" already matches what was shipped', $unchanged['reason']);
		$this->assertSame([], $this->rows);

		$neither = $this->guard(baseline: $baseline, actor: 'beheerder')->previewPartReset(request: new ResetRequest(slug: 'zaak', live: $live, path: 'nope'));
		$this->assertSame(['applicable' => false, 'reason' => '"nope" is in neither the shipped baseline nor the live definition', 'from' => null, 'to' => null, 'definition' => $live], $neither);

		$applied = $this->guard(baseline: $baseline, actor: 'beheerder')->resetToBaseline(slug: 'zaak', live: $live, path: 'properties.t.maxLength');
		$this->assertTrue($applied['applied']);
		$this->assertSame($baseline, $applied['definition']);
		$this->assertSame(
			[['action' => ShippedConfigurationGuard::ACTION_RESET, 'user' => 'beheerder', 'changed' => ['schema' => 'zaak', 'path' => 'properties.t.maxLength', 'from' => 2000, 'to' => 500]]],
			$this->rows
		);

		$this->rows = [];
		$this->guard(baseline: $baseline, actor: 'beheerder')->recordPartReset(slug: 's', reset: new PartReset(path: 'p', from: 1, to: 2));
		$this->assertSame([['action' => ShippedConfigurationGuard::ACTION_RESET, 'user' => 'beheerder', 'changed' => ['schema' => 's', 'path' => 'p', 'from' => 1, 'to' => 2]]], $this->rows);
	}//end testResetRowsAllowedAndRefused()

	/**
	 * recordShippedSchema() records the definition under the schema subject with its release.
	 *
	 * @return void
	 */
	public function testRecordShippedRecordsTheRelease(): void {
		$this->assertTrue($this->guard(baseline: null)->recordShippedSchema(slug: 'zaak', shipped: new ShippedDefinition(definition: ['a' => 1], app: 'dossiq', appVersion: '2.0.0')));
		$this->assertSame([['subject' => 'schema:zaak', 'definition' => ['a' => 1], 'app' => 'dossiq', 'appVersion' => '2.0.0']], $this->baselines);
	}//end testRecordShippedRecordsTheRelease()
}//end class
