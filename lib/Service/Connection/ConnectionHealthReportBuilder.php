<?php

/**
 * OpenRegister connection health report builder.
 *
 * Builds the daily connection report from the rows integriq already keeps: what
 * needs attention, per source system what the scheduled pulls did in the
 * lookback, and every connection by app with its status. The same rows go into
 * a formula-safe CSV.
 *
 * A row needs attention when a connection reports `error`, `unavailable` or
 * `limited`, or `simulated` on a connection that is not reported-only; when a
 * synchronization had a failed run in the lookback; when a synchronization ran
 * in the window before the lookback and not in it; or when a run has been
 * `running` for more than six hours.
 *
 * Only a source's `name` is read into the report, never its configuration.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Connection
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-the-report-says-what-needs-attention-and-what-each-source-delivered
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Connection;

use DateTimeImmutable;
use OCP\IL10N;

/**
 * Turn connection and run rows into the report's three parts and its CSV.
 *
 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-the-report-says-what-needs-attention-and-what-each-source-delivered
 */
class ConnectionHealthReportBuilder {

	/**
	 * At most this many connection rows are read.
	 */
	public const CONNECTION_CAP = 1000;

	/**
	 * At most this many run rows are read, newest first.
	 */
	public const RUN_CAP = 5000;

	/**
	 * At most this many synchronizations and sources are read to name the runs.
	 */
	public const NAME_CAP = 1000;

	/**
	 * A run still running after this many seconds is stuck.
	 */
	public const STUCK_SECONDS = (6 * 3600);

	/**
	 * Connection statuses that need attention on any connection.
	 *
	 * @var array<int, string>
	 */
	private const ATTENTION_STATUSES = ['error', 'unavailable', 'limited'];

	/**
	 * The CSV columns.
	 *
	 * @var array<int, string>
	 */
	private const CSV_COLUMNS = [
		'section',
		'app',
		'source',
		'name',
		'status',
		'runs',
		'succeeded',
		'failed',
		'found',
		'created',
		'updated',
		'invalid',
		'last',
		'message',
	];

	/**
	 * Constructor.
	 *
	 * @param ConnectionRegistryReader $reader The bounded reads.
	 * @param IL10N                    $l10n   The fixed sentences.
	 *
	 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-the-report-says-what-needs-attention-and-what-each-source-delivered
	 */
	public function __construct(
		private readonly ConnectionRegistryReader $reader,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Build the report for the lookback ending now.
	 *
	 * @param int               $lookbackHours The lookback, in hours.
	 * @param DateTimeImmutable $now           The end of the lookback.
	 *
	 * @return ConnectionHealthReport The report.
	 *
	 * @throws ConnectionRegistryMissingException When register `integriq` does not exist.
	 *
	 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-the-report-says-what-needs-attention-and-what-each-source-delivered
	 */
	public function build(int $lookbackHours, DateTimeImmutable $now): ConnectionHealthReport {
		$connections = $this->reader->rows(schemaSlug: 'app_connection', limit: self::CONNECTION_CAP);
		$runs = $this->reader->rows(schemaSlug: 'synchronization_run', limit: self::RUN_CAP, sort: ['startedAt' => 'DESC']);
		$names = $this->synchronizationNames();

		$windowStart = ($now->getTimestamp() - ($lookbackHours * 3600));
		$previousStart = ($windowStart - ($lookbackHours * 3600));
		$current = $this->runsBetween(runs: $runs, from: $windowStart, to: $now->getTimestamp());
		$previous = $this->runsBetween(runs: $runs, from: $previousStart, to: $windowStart);

		$sources = $this->sourceRows(runs: $current, names: $names);
		$attention = array_merge(
			$this->connectionAttention(connections: $connections),
			$this->runAttention(sources: $sources),
			$this->stoppedAttention(current: $current, previous: $previous, names: $names),
			$this->stuckAttention(runs: $current, now: $now->getTimestamp(), names: $names)
		);
		$all = $this->connectionRows(connections: $connections);

		$notes = [];
		if (count($connections) >= self::CONNECTION_CAP) {
			$notes[] = $this->l10n->t('%d connections shown; there are more.', [self::CONNECTION_CAP]);
		}

		if (count($runs) >= self::RUN_CAP) {
			$notes[] = $this->l10n->t('%d runs shown; more ran.', [self::RUN_CAP]);
		}

		return new ConnectionHealthReport(
			attention: count($attention),
			subject: $this->subject(attention: count($attention), now: $now),
			lines: $this->lines(attention: $attention, sources: $sources, all: $all, notes: $notes),
			csv: $this->csv(attention: $attention, sources: $sources, all: $all),
			rowCount: (count($attention) + count($sources) + count($all))
		);
	}//end build()

	/**
	 * Synchronization names and their source names, by synchronization id.
	 *
	 * @return array<string, array{name: string, source: string}> The names.
	 */
	private function synchronizationNames(): array {
		$sourceNames = [];
		foreach ($this->reader->rows(schemaSlug: 'source', limit: self::NAME_CAP) as $source) {
			$sourceNames[$this->idOf(row: $source)] = trim((string)($source['name'] ?? ''));
		}

		$names = [];
		foreach ($this->reader->rows(schemaSlug: 'synchronization', limit: self::NAME_CAP) as $sync) {
			$names[$this->idOf(row: $sync)] = [
				'name' => trim((string)($sync['name'] ?? '')),
				'source' => ($sourceNames[(string)($sync['sourceId'] ?? '')] ?? ''),
			];
		}

		return $names;
	}//end synchronizationNames()

	/**
	 * The runs that started in [from, to).
	 *
	 * @param array<int, array<string, mixed>> $runs The runs.
	 * @param int                              $from Unix time, inclusive.
	 * @param int                              $to   Unix time, exclusive.
	 *
	 * @return array<int, array<string, mixed>> The runs in the window.
	 */
	private function runsBetween(array $runs, int $from, int $to): array {
		return array_values(
			array_filter(
				$runs,
				function (array $run) use ($from, $to): bool {
					$started = $this->time(value: ($run['startedAt'] ?? null));
					return $started !== null && $started >= $from && $started < $to;
				}
			)
		);
	}//end runsBetween()

	/**
	 * Per synchronization: the runs, their outcomes and what they delivered.
	 *
	 * @param array<int, array<string, mixed>>                   $runs  The runs in the lookback.
	 * @param array<string, array{name: string, source: string}> $names The names.
	 *
	 * @return array<string, array<string, mixed>> The rows, by synchronization id, sorted by source and name.
	 */
	private function sourceRows(array $runs, array $names): array {
		$rows = [];
		foreach ($runs as $run) {
			$syncId = (string)($run['synchronizationId'] ?? '');
			$row = ($rows[$syncId] ?? $this->emptySourceRow(syncId: $syncId, names: $names));
			$row['runs']++;
			$status = (string)($run['status'] ?? '');
			if ($status === 'success') {
				$row['succeeded']++;
			}

			if ($status === 'failed') {
				$row['failed']++;
				if ($row['message'] === '') {
					$row['message'] = trim((string)($run['message'] ?? ''));
				}
			}

			foreach (['found', 'created', 'updated', 'invalid'] as $count) {
				$row[$count] += (int)($run[$count] ?? 0);
			}

			$finished = trim((string)($run['finishedAt'] ?? ''));
			if ($finished !== '' && strcmp($finished, $row['last']) > 0) {
				$row['last'] = $finished;
			}

			$rows[$syncId] = $row;
		}

		uasort($rows, static fn (array $a, array $b): int => strcmp($a['source'] . '/' . $a['name'], $b['source'] . '/' . $b['name']));
		return $rows;
	}//end sourceRows()

	/**
	 * A source row with nothing counted yet.
	 *
	 * @param string                                             $syncId The synchronization id.
	 * @param array<string, array{name: string, source: string}> $names  The names.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function emptySourceRow(string $syncId, array $names): array {
		return [
			'source' => ($names[$syncId]['source'] ?? ''),
			'name' => $this->synchronizationName(syncId: $syncId, names: $names),
			'runs' => 0,
			'succeeded' => 0,
			'failed' => 0,
			'found' => 0,
			'created' => 0,
			'updated' => 0,
			'invalid' => 0,
			'last' => '',
			'message' => '',
		];
	}//end emptySourceRow()

	/**
	 * Connections whose status needs attention.
	 *
	 * @param array<int, array<string, mixed>> $connections The connection rows.
	 *
	 * @return array<int, array<string, string>> The attention rows.
	 */
	private function connectionAttention(array $connections): array {
		$out = [];
		foreach ($connections as $connection) {
			$status = (string)($connection['status'] ?? '');
			$simulatedWhereRealExpected = ($status === 'simulated' && ($connection['reportedOnly'] ?? false) !== true);
			if (in_array($status, self::ATTENTION_STATUSES, true) === false && $simulatedWhereRealExpected === false) {
				continue;
			}

			$out[] = $this->attentionRow(
				app: (string)($connection['app'] ?? ''),
				name: $this->connectionName(connection: $connection),
				status: $status,
				message: (string)($connection['statusMessage'] ?? ''),
				since: (string)($connection['checkedAt'] ?? ''),
				link: (string)($connection['settingsUrl'] ?? '')
			);
		}

		return $out;
	}//end connectionAttention()

	/**
	 * Synchronizations with a failed run in the lookback.
	 *
	 * @param array<string, array<string, mixed>> $sources The per-synchronization rows.
	 *
	 * @return array<int, array<string, string>> The attention rows.
	 */
	private function runAttention(array $sources): array {
		$out = [];
		foreach ($sources as $row) {
			if ($row['failed'] === 0) {
				continue;
			}

			$out[] = $this->attentionRow(
				app: $row['source'],
				name: $row['name'],
				status: $this->l10n->t('%1$d of %2$d runs failed', [$row['failed'], $row['runs']]),
				message: $row['message'],
				since: $row['last'],
				link: ''
			);
		}

		return $out;
	}//end runAttention()

	/**
	 * Synchronizations that ran in the window before the lookback and not in it.
	 *
	 * @param array<int, array<string, mixed>>                   $current  The runs in the lookback.
	 * @param array<int, array<string, mixed>>                   $previous The runs in the window before.
	 * @param array<string, array{name: string, source: string}> $names    The names.
	 *
	 * @return array<int, array<string, string>> The attention rows.
	 */
	private function stoppedAttention(array $current, array $previous, array $names): array {
		$ranNow = array_flip(array_map(static fn (array $run): string => (string)($run['synchronizationId'] ?? ''), $current));
		$lastBefore = [];
		foreach ($previous as $run) {
			$syncId = (string)($run['synchronizationId'] ?? '');
			if (isset($ranNow[$syncId]) === false && isset($lastBefore[$syncId]) === false) {
				$lastBefore[$syncId] = (string)($run['startedAt'] ?? '');
			}
		}

		$out = [];
		foreach ($lastBefore as $syncId => $startedAt) {
			$out[] = $this->attentionRow(
				app: ($names[$syncId]['source'] ?? ''),
				name: $this->synchronizationName(syncId: (string)$syncId, names: $names),
				status: $this->l10n->t('stopped running'),
				message: $this->l10n->t('No run in this period; the last one started at %s.', [$startedAt]),
				since: $startedAt,
				link: ''
			);
		}

		return $out;
	}//end stoppedAttention()

	/**
	 * Runs still running after six hours.
	 *
	 * @param array<int, array<string, mixed>>                   $runs  The runs in the lookback.
	 * @param int                                                $now   Unix time now.
	 * @param array<string, array{name: string, source: string}> $names The names.
	 *
	 * @return array<int, array<string, string>> The attention rows.
	 */
	private function stuckAttention(array $runs, int $now, array $names): array {
		$out = [];
		foreach ($runs as $run) {
			$started = $this->time(value: ($run['startedAt'] ?? null));
			if ((string)($run['status'] ?? '') !== 'running' || $started === null || ($now - $started) <= self::STUCK_SECONDS) {
				continue;
			}

			$syncId = (string)($run['synchronizationId'] ?? '');
			$out[] = $this->attentionRow(
				app: ($names[$syncId]['source'] ?? ''),
				name: $this->synchronizationName(syncId: $syncId, names: $names),
				status: $this->l10n->t('stuck'),
				message: $this->l10n->t('Still running after more than 6 hours.'),
				since: (string)($run['startedAt'] ?? ''),
				link: ''
			);
		}

		return $out;
	}//end stuckAttention()

	/**
	 * Every connection, grouped by app, then by title.
	 *
	 * @param array<int, array<string, mixed>> $connections The connection rows.
	 *
	 * @return array<int, array<string, string>> The rows.
	 */
	private function connectionRows(array $connections): array {
		$rows = array_map(
			fn (array $connection): array => [
				'app' => (string)($connection['app'] ?? ''),
				'name' => $this->connectionName(connection: $connection),
				'status' => (string)($connection['status'] ?? ''),
			],
			$connections
		);
		usort($rows, static fn (array $a, array $b): int => strcmp($a['app'] . '/' . $a['name'], $b['app'] . '/' . $b['name']));
		return $rows;
	}//end connectionRows()

	/**
	 * One attention row.
	 *
	 * @param string $app     The app, or the source system for a synchronization.
	 * @param string $name    The connection or synchronization.
	 * @param string $status  The status or failure.
	 * @param string $message The message.
	 * @param string $since   Since when.
	 * @param string $link    Where to act.
	 *
	 * @return array<string, string> The row.
	 */
	private function attentionRow(string $app, string $name, string $status, string $message, string $since, string $link): array {
		return ['app' => $app, 'name' => $name, 'status' => $status, 'message' => trim($message), 'since' => $since, 'link' => $link];
	}//end attentionRow()

	/**
	 * The mail subject.
	 *
	 * @param int               $attention How many rows need attention.
	 * @param DateTimeImmutable $now       The report time.
	 *
	 * @return string The subject.
	 */
	private function subject(int $attention, DateTimeImmutable $now): string {
		$date = $now->format('Y-m-d');
		if ($attention === 0) {
			return $this->l10n->t('Connection report %s: all clear', [$date]);
		}

		return $this->l10n->t('Connection report %1$s: %2$d need attention', [$date, $attention]);
	}//end subject()

	/**
	 * The mail body lines: what needs attention, runs per source system, all connections.
	 *
	 * @param array<int, array<string, string>>   $attention The attention rows.
	 * @param array<string, array<string, mixed>> $sources   The per-synchronization rows.
	 * @param array<int, array<string, string>>   $all       Every connection.
	 * @param array<int, string>                  $notes     Caps that were hit.
	 *
	 * @return array<int, string> The lines.
	 */
	private function lines(array $attention, array $sources, array $all, array $notes): array {
		$lines = [$this->l10n->t('Needs attention')];
		if ($attention === []) {
			$lines[] = $this->l10n->t('Nothing needs attention.');
		}

		foreach ($attention as $row) {
			$lines[] = trim(
				sprintf('%s: %s. %s %s %s', $this->label(app: $row['app'], name: $row['name']), $row['status'], $row['message'], $row['since'], $row['link'])
			);
		}

		$lines[] = $this->l10n->t('Runs per source system');
		if ($sources === []) {
			$lines[] = $this->l10n->t('No scheduled pulls ran in this period.');
		}

		foreach ($sources as $row) {
			$lines[] = $this->l10n->t(
				'%1$s / %2$s: %3$d runs, %4$d succeeded, %5$d failed, %6$d found, %7$d created, %8$d updated, %9$d invalid, '
				. 'last finished %10$s.',
				[
					$row['source'],
					$row['name'],
					$row['runs'],
					$row['succeeded'],
					$row['failed'],
					$row['found'],
					$row['created'],
					$row['updated'],
					$row['invalid'],
					$row['last'],
				]
			);
		}

		$lines[] = $this->l10n->t('All connections');
		foreach ($all as $row) {
			$lines[] = sprintf('%s: %s (%s)', $row['app'], $row['name'], $row['status']);
		}

		return array_merge($lines, $notes);
	}//end lines()

	/**
	 * The CSV attachment: a byte order mark, a header and the rows of the three parts.
	 *
	 * @param array<int, array<string, string>>   $attention The attention rows.
	 * @param array<string, array<string, mixed>> $sources   The per-synchronization rows.
	 * @param array<int, array<string, string>>   $all       Every connection.
	 *
	 * @return string The CSV.
	 */
	private function csv(array $attention, array $sources, array $all): string {
		$rows = [self::CSV_COLUMNS];
		foreach ($attention as $row) {
			$rows[] = $this->csvRow(
				cells: [
					'section' => 'attention',
					'app' => $row['app'],
					'name' => $row['name'],
					'status' => $row['status'],
					'last' => $row['since'],
					'message' => $row['message'],
				]
			);
		}

		foreach ($sources as $row) {
			$rows[] = $this->csvRow(cells: array_merge($row, ['section' => 'runs']));
		}

		foreach ($all as $row) {
			$rows[] = $this->csvRow(cells: array_merge($row, ['section' => 'connections']));
		}

		$handle = fopen('php://temp', 'r+');
		foreach ($rows as $row) {
			fputcsv($handle, $row, ',', '"', '');
		}

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		return "\xEF\xBB\xBF" . $csv;
	}//end csv()

	/**
	 * One CSV row in column order, every text cell formula-safe.
	 *
	 * @param array<string, mixed> $cells The cells by column.
	 *
	 * @return array<int, string> The row.
	 */
	private function csvRow(array $cells): array {
		$row = [];
		foreach (self::CSV_COLUMNS as $column) {
			$row[] = $this->formulaSafe(value: (string)($cells[$column] ?? ''));
		}

		return $row;
	}//end csvRow()

	/**
	 * Prefix a cell a spreadsheet would read as a formula.
	 *
	 * @param string $value The cell.
	 *
	 * @return string The safe cell.
	 */
	private function formulaSafe(string $value): string {
		if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) === true) {
			return "'" . $value;
		}

		return $value;
	}//end formulaSafe()

	/**
	 * `app / name`, or the name alone when there is no app or source.
	 *
	 * @param string $app  The app or source system.
	 * @param string $name The connection or synchronization.
	 *
	 * @return string The label.
	 */
	private function label(string $app, string $name): string {
		if ($app === '') {
			return $name;
		}

		return $app . ' / ' . $name;
	}//end label()

	/**
	 * A synchronization's name, else its id.
	 *
	 * @param string                                             $syncId The synchronization id.
	 * @param array<string, array{name: string, source: string}> $names  The names.
	 *
	 * @return string The name.
	 */
	private function synchronizationName(string $syncId, array $names): string {
		$name = ($names[$syncId]['name'] ?? '');
		if ($name === '') {
			return $syncId;
		}

		return $name;
	}//end synchronizationName()

	/**
	 * A connection's display name.
	 *
	 * @param array<string, mixed> $connection The connection row.
	 *
	 * @return string The title, else its key.
	 */
	private function connectionName(array $connection): string {
		$title = trim((string)($connection['title'] ?? ''));
		if ($title !== '') {
			return $title;
		}

		return (string)($connection['key'] ?? '');
	}//end connectionName()

	/**
	 * A row's id: `id`, else `@self.id`, else `uuid`.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id.
	 */
	private function idOf(array $row): string {
		return (string)($row['id'] ?? $row['@self']['id'] ?? $row['uuid'] ?? '');
	}//end idOf()

	/**
	 * A time value as Unix time.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int|null The time, or null when empty or unreadable.
	 */
	private function time(mixed $value): ?int {
		$text = trim((string)$value);
		if ($text === '') {
			return null;
		}

		$time = strtotime($text);
		if ($time === false) {
			return null;
		}

		return $time;
	}//end time()
}//end class
