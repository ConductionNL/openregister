<?php

/**
 * SeedDirectoryImporter: import the operator's mounted seed directory.
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

use DateTimeInterface;
use OCA\OpenRegister\Service\AppConfigurationImporterInterface;
use OCA\OpenRegister\Service\CauseFrame;
use OCA\OpenRegister\Service\WriteCause;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Imports every `*.json` file directly in the directory `config.php` names as
 * `openregister.seed_directory`, in lexical order, through the same
 * configuration import as the import API, under the write cause `seed`.
 *
 * Each file imports under its own app id (`openregister-seed.<name>`), so the
 * importer's content-hash check skips a file whose content it already holds and
 * matches registers, schemas and objects by slug or uuid: a second run updates
 * or skips, it never duplicates.
 *
 * A file that resolves outside the directory or is larger than 50 MB is refused
 * and named without being read; a file that does not parse is named and the
 * others still import. Every run stores its report and the directory's digest.
 *
 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
 */
class SeedDirectoryImporter {

	/**
	 * The `config.php` key naming the directory.
	 */
	public const CONFIG_KEY = 'openregister.seed_directory';

	/**
	 * The largest file imported, in bytes (50 MB).
	 */
	public const MAX_FILE_BYTES = 52428800;

	/**
	 * The app config key holding the digest of the last run.
	 */
	public const DIGEST_KEY = 'seed_directory_digest';

	/**
	 * The app config key holding the last report as JSON.
	 */
	public const REPORT_KEY = 'seed_directory_report';

	/**
	 * The prefix of the app id each file imports under.
	 */
	public const APP_ID_PREFIX = 'openregister-seed.';

	/**
	 * Build the importer.
	 *
	 * @param IConfig                           $config    Reads `openregister.seed_directory` from config.php.
	 * @param IAppConfig                        $appConfig Keeps the digest and the last report.
	 * @param AppConfigurationImporterInterface $importer  The configuration import the import API uses.
	 * @param ITimeFactory                      $time      The moment of a run.
	 * @param LoggerInterface                   $logger    Receives every run's summary.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly IAppConfig $appConfig,
		private readonly AppConfigurationImporterInterface $importer,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Import the directory, whatever its digest.
	 *
	 * @return SeedReport|null The report; null when no directory is configured.
	 *
	 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md#requirement-an-installation-is-seeded-from-an-operators-mounted-directory-without-the-admin-req-smd-001
	 */
	public function import(): ?SeedReport {
		return $this->run(onlyWhenChanged: false);
	}//end import()

	/**
	 * Import the directory only when its digest differs from the last run's.
	 *
	 * @return SeedReport|null The report; null when no directory is configured or nothing changed.
	 *
	 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md#requirement-an-installation-is-seeded-from-an-operators-mounted-directory-without-the-admin-req-smd-001
	 */
	public function importIfChanged(): ?SeedReport {
		return $this->run(onlyWhenChanged: true);
	}//end importIfChanged()

	/**
	 * The last stored report.
	 *
	 * @return array<string, mixed>|null The report as {@see SeedReport::toArray()} gave it; null when none ran.
	 *
	 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md#requirement-every-seed-run-reports-what-it-did-and-a-partial-run-says-it-is-partial-req-smd-002
	 */
	public function lastReport(): ?array {
		$stored = $this->appConfig->getValueString('openregister', self::REPORT_KEY, '', lazy: true);
		if ($stored === '') {
			return null;
		}

		$report = json_decode($stored, true);
		if (is_array($report) === false) {
			return null;
		}

		return $report;
	}//end lastReport()

	/**
	 * Run the seed.
	 *
	 * @param bool $onlyWhenChanged Skip when the digest equals the last run's.
	 *
	 * @return SeedReport|null
	 */
	private function run(bool $onlyWhenChanged): ?SeedReport {
		$directory = trim($this->config->getSystemValueString(self::CONFIG_KEY, ''));
		if ($directory === '') {
			return null;
		}

		$problem = $this->directoryProblem(directory: $directory);
		if ($problem !== null) {
			$report = new SeedReport(directory: $directory, digest: '', moment: $this->moment(), files: [], problems: [$problem]);

			return $this->finish(report: $report, digest: null);
		}

		$entries = $this->entries(directory: $directory);
		$digest  = $this->digest(entries: $entries);
		if ($onlyWhenChanged === true && $digest === $this->appConfig->getValueString('openregister', self::DIGEST_KEY, '')) {
			return null;
		}

		$files = [];
		foreach ($entries as $entry) {
			$files[] = $this->importEntry(entry: $entry, digest: $digest);
		}

		return $this->finish(report: new SeedReport(directory: $directory, digest: $digest, moment: $this->moment(), files: $files), digest: $digest);
	}//end run()

	/**
	 * Why the directory cannot be read, or null when it can.
	 *
	 * @param string $directory The configured path.
	 *
	 * @return string|null
	 */
	private function directoryProblem(string $directory): ?string {
		if (str_starts_with($directory, '/') === false) {
			return 'the seed directory "' . $directory . '" is not an absolute path';
		}

		if (is_dir($directory) === false || is_readable($directory) === false) {
			return 'the seed directory "' . $directory . '" is not a readable directory';
		}

		return null;
	}//end directoryProblem()

	/**
	 * Every `*.json` file directly in the directory, in lexical order, each
	 * read or refused.
	 *
	 * @param string $directory The directory.
	 *
	 * @return list<array{name: string, refused: string|null, content: string|null}>
	 */
	private function entries(string $directory): array {
		$listing = scandir($directory);
		if ($listing === false) {
			$listing = [];
		}

		$names = [];
		foreach ($listing as $name) {
			if (str_ends_with(strtolower($name), '.json') === true && is_dir($directory . '/' . $name) === false) {
				$names[] = $name;
			}
		}

		sort($names, SORT_STRING);

		$root    = (string)realpath($directory);
		$entries = [];
		foreach ($names as $name) {
			$entries[] = $this->entry(root: $root, path: $directory . '/' . $name, name: $name);
		}

		return $entries;
	}//end entries()

	/**
	 * One file, read, or refused with the reason.
	 *
	 * @param string $root The directory's real path.
	 * @param string $path The file's path.
	 * @param string $name The file's name.
	 *
	 * @return array{name: string, refused: string|null, content: string|null}
	 */
	private function entry(string $root, string $path, string $name): array {
		$real = realpath($path);
		if ($real === false || str_starts_with($real, $root . '/') === false) {
			return ['name' => $name, 'refused' => 'resolves outside the seed directory', 'content' => null];
		}

		$size = filesize($real);
		if ($size === false || $size > self::MAX_FILE_BYTES) {
			return ['name' => $name, 'refused' => 'is larger than 50 MB', 'content' => null];
		}

		$content = file_get_contents($real);
		if ($content === false) {
			return ['name' => $name, 'refused' => 'could not be read', 'content' => null];
		}

		return ['name' => $name, 'refused' => null, 'content' => $content];
	}//end entry()

	/**
	 * The SHA-256 over the file names and the contents of the files read.
	 *
	 * A refused file contributes its name and reason, never its target's content.
	 *
	 * @param list<array{name: string, refused: string|null, content: string|null}> $entries The files.
	 *
	 * @return string
	 */
	private function digest(array $entries): string {
		$context = hash_init('sha256');
		foreach ($entries as $entry) {
			hash_update($context, $entry['name'] . "\0" . ($entry['refused'] ?? hash('sha256', (string)$entry['content'])) . "\0");
		}

		return hash_final($context);
	}//end digest()

	/**
	 * Import one file and report it.
	 *
	 * @param array{name: string, refused: string|null, content: string|null} $entry  The file.
	 * @param string                                                         $digest The run's digest, named as the run of every write.
	 *
	 * @return array<string, mixed> The file entry of the report.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SeedReport's named constructors and WriteCause::runAs, the static cause-frame helper every caused write uses.
	 */
	private function importEntry(array $entry, string $digest): array {
		if ($entry['refused'] !== null) {
			return SeedReport::refusedFile(file: $entry['name'], reason: $entry['name'] . ' ' . $entry['refused']);
		}

		$data = json_decode((string)$entry['content'], true);
		if (is_array($data) === false) {
			return SeedReport::failedFile(file: $entry['name'], reason: $entry['name'] . ' is not valid JSON: ' . json_last_error_msg());
		}

		try {
			$result = WriteCause::runAs(
				frame: new CauseFrame(cause: WriteCause::SEED, run: 'seed-' . substr($digest, 0, 12)),
				operation: fn (): array => $this->importer->importFromApp(
					appId: self::APP_ID_PREFIX . $this->baseName(name: $entry['name']),
					data: $data,
					version: $this->version(data: $data),
					force: false
				)
			);
		} catch (Throwable $e) {
			return SeedReport::failedFile(file: $entry['name'], reason: $entry['name'] . ' could not be imported: ' . $e->getMessage());
		}

		return SeedReport::fileFromResult(file: $entry['name'], result: $result);
	}//end importEntry()

	/**
	 * The file name without `.json`, lower-cased and limited to safe characters.
	 *
	 * @param string $name The file name.
	 *
	 * @return string
	 */
	private function baseName(string $name): string {
		return (string)preg_replace('/[^a-z0-9._-]+/', '-', strtolower(substr($name, 0, -5)));
	}//end baseName()

	/**
	 * The version a file declares (`version`, else `info.version`), else 1.0.0.
	 *
	 * @param array<string, mixed> $data The file's content.
	 *
	 * @return string
	 */
	private function version(array $data): string {
		$version = $data['version'] ?? $data['info']['version'] ?? '1.0.0';
		if (is_scalar($version) === false || (string)$version === '') {
			return '1.0.0';
		}

		return (string)$version;
	}//end version()

	/**
	 * Store and log a finished run.
	 *
	 * @param SeedReport  $report The report.
	 * @param string|null $digest The digest to remember; null when the directory was not read.
	 *
	 * @return SeedReport
	 */
	private function finish(SeedReport $report, ?string $digest): SeedReport {
		if ($digest !== null) {
			$this->appConfig->setValueString('openregister', self::DIGEST_KEY, $digest);
		}

		$this->appConfig->setValueString('openregister', self::REPORT_KEY, (string)json_encode($report->toArray()), lazy: true);

		$context = ['status' => $report->status(), 'failures' => $report->failures()];
		if ($report->status() === SeedReport::STATUS_SUCCESS) {
			$this->logger->info(message: '[SeedDirectoryImporter] ' . $report->summary(), context: $context);
			return $report;
		}

		$this->logger->warning(message: '[SeedDirectoryImporter] ' . $report->summary(), context: $context);

		return $report;
	}//end finish()

	/**
	 * Now, ISO 8601.
	 *
	 * @return string
	 */
	private function moment(): string {
		return $this->time->getDateTime()->format(DateTimeInterface::ATOM);
	}//end moment()
}//end class
