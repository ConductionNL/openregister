<?php

/**
 * SeedReport: what one run of the seed directory did, per file.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

/**
 * The report of one seed run: the digest, the moment, and per file the counts
 * of imported, skipped and failed registers, schemas and objects, with every
 * failure named.
 *
 * The counts come from the import's RESULT, never from what the file held: an
 * entity the importer dropped without a word is not in the result and so is
 * never counted as imported.
 *
 * `imported` counts what the import wrote or found current, `skipped` what it
 * left alone as unchanged, `failed` what it refused. A run is `success` only
 * when nothing failed, `failed` when something failed and nothing imported,
 * and `partial` otherwise; it is never reported as success with a failure in it.
 *
 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
 */
final class SeedReport {

	/**
	 * Nothing failed.
	 */
	public const STATUS_SUCCESS = 'success';

	/**
	 * Something failed and something else imported.
	 */
	public const STATUS_PARTIAL = 'partial';

	/**
	 * Something failed and nothing imported, or the directory itself could not be read.
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * A file the import wrote from.
	 */
	public const FILE_IMPORTED = 'imported';

	/**
	 * A file whose content the import already holds.
	 */
	public const FILE_UNCHANGED = 'unchanged';

	/**
	 * A file refused before it was read: outside the directory or too large.
	 */
	public const FILE_REFUSED = 'refused';

	/**
	 * A file whose import failed as a whole, or partly.
	 */
	public const FILE_FAILED = 'failed';

	/**
	 * The entity kinds a file reports on.
	 */
	public const KINDS = ['registers', 'schemas', 'objects'];

	/**
	 * Build a report.
	 *
	 * @param string                           $directory The seed directory as configured.
	 * @param string                           $digest    The SHA-256 over the directory's file names and contents ('' when it could not be read).
	 * @param string                           $moment    When the run happened, ISO 8601.
	 * @param list<array<string, mixed>>       $files     One entry per file, from {@see fileFromResult()}, {@see refusedFile()} or {@see failedFile()}.
	 * @param list<string>                     $problems  What kept the whole directory from being read.
	 */
	public function __construct(
		public readonly string $directory,
		public readonly string $digest,
		public readonly string $moment,
		public readonly array $files,
		public readonly array $problems = [],
	) {
	}//end __construct()

	/**
	 * The file entry for an import result.
	 *
	 * @param string               $file   The file name.
	 * @param array<string, mixed> $result What the import returned.
	 *
	 * @return array<string, mixed> The file entry.
	 *
	 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
	 */
	public static function fileFromResult(string $file, array $result): array {
		$failures = [];
		foreach (($result['failed']['schemas'] ?? []) as $schema) {
			// A schema rejected for lacking a slug is named by its key in the file.
			$name = (string)($schema['slug'] ?? '');
			if ($name === '') {
				$name = (string)($schema['key'] ?? '');
			}

			$failures[] = self::failure(file: $file, entity: 'schema', slug: $name, reason: (string)($schema['error'] ?? ''));
		}

		foreach (($result['failed']['objects'] ?? []) as $object) {
			$failures[] = self::failure(file: $file, entity: 'object', slug: (string)($object['slug'] ?? ''), reason: (string)($object['error'] ?? ''));
		}

		$failedRegisters = (int)($result['skipped']['registers'] ?? 0);
		if ($failedRegisters > 0) {
			$failures[] = self::failure(
				file: $file,
				entity: 'register',
				slug: '',
				reason: $failedRegisters . ' register(s) could not be imported; the log names them'
			);
		}

		$failedSchemas  = max(count($result['failed']['schemas'] ?? []), (int)($result['skipped']['schemas'] ?? 0));
		$failedObjects  = count($result['failed']['objects'] ?? []);
		$skippedObjects = (int)($result['unchanged']['objects'] ?? 0) + max(0, (int)($result['skipped']['objects'] ?? 0) - $failedObjects);

		$counts = [
			'registers' => ['imported' => count($result['registers'] ?? []), 'skipped' => 0, 'failed' => $failedRegisters],
			'schemas'   => ['imported' => count($result['schemas'] ?? []), 'skipped' => 0, 'failed' => $failedSchemas],
			'objects'   => ['imported' => count($result['objects'] ?? []), 'skipped' => $skippedObjects, 'failed' => $failedObjects],
		];

		return [
			'file'     => $file,
			'status'   => self::fileStatus(counts: $counts),
			'counts'   => $counts,
			'failures' => $failures,
		];
	}//end fileFromResult()

	/**
	 * The entry for a file refused before it was read.
	 *
	 * @param string $file   The file name.
	 * @param string $reason Why it was refused.
	 *
	 * @return array<string, mixed> The file entry.
	 *
	 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
	 */
	public static function refusedFile(string $file, string $reason): array {
		return [
			'file'     => $file,
			'status'   => self::FILE_REFUSED,
			'counts'   => self::zeroCounts(),
			'failures' => [self::failure(file: $file, entity: 'file', slug: '', reason: $reason)],
		];
	}//end refusedFile()

	/**
	 * The entry for a file whose import failed as a whole.
	 *
	 * @param string $file   The file name.
	 * @param string $reason Why it failed.
	 *
	 * @return array<string, mixed> The file entry.
	 *
	 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
	 */
	public static function failedFile(string $file, string $reason): array {
		return [
			'file'     => $file,
			'status'   => self::FILE_FAILED,
			'counts'   => self::zeroCounts(),
			'failures' => [self::failure(file: $file, entity: 'file', slug: '', reason: $reason)],
		];
	}//end failedFile()

	/**
	 * The run's status: success, partial or failed.
	 *
	 * @return string One of the STATUS_ constants.
	 *
	 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
	 */
	public function status(): string {
		if ($this->problems !== []) {
			return self::STATUS_FAILED;
		}

		if ($this->total(column: 'failed') + $this->fileFailures() === 0) {
			return self::STATUS_SUCCESS;
		}

		if ($this->total(column: 'imported') === 0) {
			return self::STATUS_FAILED;
		}

		return self::STATUS_PARTIAL;
	}//end status()

	/**
	 * Every failure of the run, in file order.
	 *
	 * @return list<array{file: string, entity: string, slug: string, reason: string}>
	 *
	 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
	 */
	public function failures(): array {
		$failures = [];
		foreach ($this->files as $file) {
			foreach (($file['failures'] ?? []) as $failure) {
				$failures[] = $failure;
			}
		}

		return $failures;
	}//end failures()

	/**
	 * One count summed over every file and kind.
	 *
	 * @param string $column `imported`, `skipped` or `failed`.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
	 */
	public function total(string $column): int {
		$total = 0;
		foreach ($this->files as $file) {
			foreach (self::KINDS as $kind) {
				$total += (int)($file['counts'][$kind][$column] ?? 0);
			}
		}

		return $total;
	}//end total()

	/**
	 * The report as stored, returned by the API and printed.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
	 */
	public function toArray(): array {
		return [
			'status'    => $this->status(),
			'directory' => $this->directory,
			'digest'    => $this->digest,
			'moment'    => $this->moment,
			'problems'  => $this->problems,
			'totals'    => [
				'imported' => $this->total(column: 'imported'),
				'skipped'  => $this->total(column: 'skipped'),
				'failed'   => $this->total(column: 'failed') + $this->fileFailures(),
			],
			'files'     => $this->files,
		];
	}//end toArray()

	/**
	 * One line saying what the run did, for the log and the console.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
	 */
	public function summary(): string {
		$line = sprintf(
			'Seed %s: %d file(s), %d imported, %d skipped, %d failure(s)',
			$this->status(),
			count($this->files),
			$this->total(column: 'imported'),
			$this->total(column: 'skipped'),
			count($this->failures())
		);

		if ($this->problems !== []) {
			$line .= ': ' . implode('; ', $this->problems);
		}

		return $line;
	}//end summary()

	/**
	 * The failures of whole files: refused, unreadable or failing as a whole.
	 *
	 * They carry no register, schema or object count, so the failed total adds
	 * them to the counted ones.
	 *
	 * @return int
	 */
	private function fileFailures(): int {
		$files = 0;
		foreach ($this->failures() as $failure) {
			if ($failure['entity'] === 'file') {
				$files++;
			}
		}

		return $files;
	}//end fileFailures()

	/**
	 * A file's status from its counts.
	 *
	 * @param array<string, array{imported: int, skipped: int, failed: int}> $counts The counts.
	 *
	 * @return string One of the FILE_ constants.
	 */
	private static function fileStatus(array $counts): string {
		$imported = 0;
		$failed   = 0;
		foreach ($counts as $count) {
			$imported += $count['imported'];
			$failed   += $count['failed'];
		}

		if ($failed > 0) {
			return self::FILE_FAILED;
		}

		if ($imported > 0) {
			return self::FILE_IMPORTED;
		}

		return self::FILE_UNCHANGED;
	}//end fileStatus()

	/**
	 * Counts of zero for every kind.
	 *
	 * @return array<string, array{imported: int, skipped: int, failed: int}>
	 */
	private static function zeroCounts(): array {
		$counts = [];
		foreach (self::KINDS as $kind) {
			$counts[$kind] = ['imported' => 0, 'skipped' => 0, 'failed' => 0];
		}

		return $counts;
	}//end zeroCounts()

	/**
	 * One named failure.
	 *
	 * @param string $file   The file.
	 * @param string $entity `register`, `schema`, `object` or `file`.
	 * @param string $slug   The entity's slug, '' when it has none or is a file.
	 * @param string $reason Why it failed.
	 *
	 * @return array{file: string, entity: string, slug: string, reason: string}
	 */
	private static function failure(string $file, string $entity, string $slug, string $reason): array {
		return ['file' => $file, 'entity' => $entity, 'slug' => $slug, 'reason' => $reason];
	}//end failure()
}//end class
