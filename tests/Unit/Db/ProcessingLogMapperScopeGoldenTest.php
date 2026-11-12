<?php

/**
 * ProcessingLogMapper reads keep their period, tenant and confidentiality scope.
 *
 * The query each read builds is pinned against a golden file recorded on the
 * code before the scope filters were split into small units: the tenant and
 * the confidentiality predicates must be present exactly when they were, so a
 * read scoped to one organisation never reaches another one's entries and a
 * read without the FG's confidential access never reaches a confidential entry.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\ProcessingLogMapper;
use OCA\OpenRegister\Db\ProcessingLogReadScope;
use OCA\OpenRegister\Db\ProcessingLog\ProcessingLogSubject;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\ProcessingLogMapper
 * @uses   \OCA\OpenRegister\Db\ProcessingLogEntry
 */
class ProcessingLogMapperScopeGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * The golden file.
	 */
	private const GOLDEN = __DIR__ . '/fixtures/processing-log-mapper-scope-golden.json';

	/**
	 * Every scoped read, by name.
	 *
	 * @return array<string, callable(ProcessingLogMapper): mixed>
	 */
	private static function reads(): array {
		$from = new DateTime('2026-01-02 03:04:05');
		$to = new DateTime('2026-02-03 04:05:06');

		return [
			'subject, tenant scoped, confidential refused' =>
				static fn (ProcessingLogMapper $m): array => $m->findForSubject(new ProcessingLogSubject('BSN', '111'), new ProcessingLogReadScope(null, null, 'org-1', false)),
			'subject, all tenants, confidential allowed' =>
				static fn (ProcessingLogMapper $m): array => $m->findForSubject(new ProcessingLogSubject('BSN', '111'), new ProcessingLogReadScope(null, null, null, true)),
			'subject, empty tenant, confidential refused' =>
				static fn (ProcessingLogMapper $m): array => $m->findForSubject(new ProcessingLogSubject('BSN', '111'), new ProcessingLogReadScope(null, null, '', false)),
			'subject, period, tenant scoped, confidential allowed' =>
				static fn (ProcessingLogMapper $m): array => $m->findForSubject(new ProcessingLogSubject('BSN', '111'), new ProcessingLogReadScope($from, $to, 'org-2', true)),
			'subject, lower bound only' => static fn (ProcessingLogMapper $m): array => $m->findForSubject(new ProcessingLogSubject('BSN', '111'), new ProcessingLogReadScope($from)),
			'filtered, tenant scoped, confidential refused' =>
				static fn (ProcessingLogMapper $m): array => $m->findListed(new ListQuery(limit: 10, offset: 20, filters: ['actor' => 'anna']), new ProcessingLogReadScope(null, null, 'org-1', false)),
			'filtered, all tenants, confidential allowed' =>
				static fn (ProcessingLogMapper $m): array => $m->findListed(new ListQuery(limit: 100, offset: 0), new ProcessingLogReadScope(null, null, null, true)),
			'filtered, period, defaults' => static fn (ProcessingLogMapper $m): array => $m->findListed(new ListQuery(limit: 100, offset: 0, filters: ['register_id' => 3]), new ProcessingLogReadScope($from, $to)),
			'filtered, upper bound only, tenant scoped' => static fn (ProcessingLogMapper $m): array => $m->findListed(new ListQuery(limit: 100, offset: 0), new ProcessingLogReadScope(null, $to, 'org-3')),
		];
	}//end reads()

	/**
	 * The query log of every read.
	 *
	 * @return array<string, list<string>>
	 */
	private function logs(): array {
		$logs = [];
		foreach (self::reads() as $name => $read) {
			$this->queryLog = [];
			$read($this->mapper());
			$logs[$name] = $this->queryLog;
		}

		return $logs;
	}//end logs()

	/**
	 * A mapper over the recording query builder.
	 *
	 * @return ProcessingLogMapper
	 */
	private function mapper(): ProcessingLogMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		$db->method('escapeLikeParameter')->willReturnCallback(static fn (string $value): string => addcslashes($value, '\\_%'));
		return new ProcessingLogMapper($db);
	}//end mapper()

	/**
	 * Every read builds the query it built before.
	 *
	 * @return void
	 */
	public function testEveryReadBuildsThePinnedQuery(): void {
		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);

		$this->assertSame($golden, $this->logs());
	}//end testEveryReadBuildsThePinnedQuery()

	/**
	 * A tenant scoped read without confidential access carries both refusals.
	 *
	 * @return void
	 */
	public function testAScopedReadRefusesOtherTenantsAndConfidentialEntries(): void {
		$logs = $this->logs();

		foreach (['subject, tenant scoped, confidential refused', 'filtered, tenant scoped, confidential refused'] as $name) {
			$log = implode("\n", $logs[$name]);
			$this->assertStringContainsString('eq(organisation_id', $log);
			$this->assertStringContainsString('= org-1, ', $log);
			$this->assertStringContainsString('eq(confidential', $log);
		}
	}//end testAScopedReadRefusesOtherTenantsAndConfidentialEntries()

	/**
	 * An unscoped read with confidential access carries neither predicate.
	 *
	 * @return void
	 */
	public function testAnUnscopedConfidentialReadIsNotNarrowed(): void {
		$logs = $this->logs();

		foreach (['subject, all tenants, confidential allowed', 'filtered, all tenants, confidential allowed'] as $name) {
			$log = implode("\n", $logs[$name]);
			$this->assertStringNotContainsString('organisation_id', $log);
			$this->assertStringNotContainsString('confidential', $log);
		}
	}//end testAnUnscopedConfidentialReadIsNotNarrowed()
}//end class
