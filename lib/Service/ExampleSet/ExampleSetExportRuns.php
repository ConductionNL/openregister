<?php

/**
 * OpenRegister Example Set Export Runs
 *
 * Exports a set's accounts made: a small real file in the account's Exports
 * folder and the export run that names it, as an export the register served
 * would leave behind. A reader's "Your exports" list then has rows to show,
 * each downloadable. Remove takes the file and the run away.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-an-account-an-export-it-made
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCA\OpenRegister\Db\ExportRun;
use OCA\OpenRegister\Db\ExportRunMapper;
use OCA\OpenRegister\Service\Export\ExportRunRecorder;
use OCA\OpenRegister\Service\Export\ExportRunContent;
use OCA\OpenRegister\Service\Export\ExportRunFile;
use OCA\OpenRegister\Service\Export\ExportRunOrigin;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Writes, proves and removes the exports a fragment declares.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-an-account-an-export-it-made
 */
class ExampleSetExportRuns extends ExampleSetEngineRows {
	/**
	 * The fields a set may write.
	 */
	public const FIELDS = [
		'user',
		'fileName',
		'format',
		'content',
		'contentBase64',
		'rowCount',
		'registerName',
		'schemaName',
		'producedAt',
		'downloadCount',
	];

	/**
	 * The folder in the account's files the export goes to.
	 */
	public const FOLDER = 'Exports';

	/**
	 * The source a run of the set names.
	 */
	public const SOURCE = 'example-set';

	/**
	 * The largest file a set may write, in bytes: an example, not a data dump.
	 */
	private const MAX_BYTES = 65536;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Hands out the files, the run mapper and the run recorder on first use.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The fragment part and record part.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-an-account-an-export-it-made
	 */
	public function part(): string {
		return 'exportRuns';
	}//end part()

	/**
	 * The word for one row.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-an-account-an-export-it-made
	 */
	public function noun(): string {
		return 'export';
	}//end noun()

	/**
	 * Why an export cannot be written, or ''.
	 *
	 * @param array<string, mixed> $data  The filled fields.
	 * @param array<int, string>   $users The account ids the set creates or adopts.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-an-account-an-export-it-made
	 */
	public function refusal(array $data, array $users): string {
		$why = $this->whoAndWhereRefusal(data: $data, users: $users);
		if ($why !== '') {
			return $why;
		}

		return $this->contentRefusal(data: $data);
	}//end refusal()

	/**
	 * Why the fields, the account or the file name of an export cannot be used, or ''.
	 *
	 * @param array<string, mixed> $data  The filled fields.
	 * @param array<int, string>   $users The account ids the set creates or adopts.
	 *
	 * @return string
	 */
	private function whoAndWhereRefusal(array $data, array $users): string {
		$unknown = array_diff(array_keys($data), self::FIELDS);
		if ($unknown !== []) {
			return 'field "'.implode('", "', $unknown).'" is not an export field a set may write';
		}

		if (in_array((string) ($data['user'] ?? ''), $users, true) === false) {
			return 'user "'.(string) ($data['user'] ?? '').'" is not an account the set creates or adopts';
		}

		if ($this->plainName(name: (string) ($data['fileName'] ?? '')) === false) {
			return 'fileName is a plain file name in the Exports folder';
		}

		return '';
	}//end whoAndWhereRefusal()

	/**
	 * Why the content or the production moment of an export cannot be used, or ''.
	 *
	 * @param array<string, mixed> $data The filled fields.
	 *
	 * @return string
	 */
	private function contentRefusal(array $data): string {
		if ($this->usableBytes(bytes: $this->bytes(data: $data)) === false) {
			return 'an export needs its content (text, or contentBase64 for a binary file), at most '.self::MAX_BYTES.' bytes';
		}

		if (isset($data['producedAt']) === true && ExampleSetDateValue::moment(value: $data['producedAt']) === null) {
			return 'producedAt is a date and time';
		}

		return '';
	}//end contentRefusal()

	/**
	 * Whether the file's bytes are there and no more than a set may write.
	 *
	 * @param string|null $bytes The bytes, or null when none are usable.
	 *
	 * @return bool
	 */
	private function usableBytes(?string $bytes): bool {
		return $bytes !== null && $bytes !== '' && strlen($bytes) <= self::MAX_BYTES;
	}//end usableBytes()

	/**
	 * Write the file and the run that names it.
	 *
	 * @param ExampleSetRow $row The row: its app, its key in the fragment and its filled fields.
	 *
	 * @return array{error: string, entry: array{uuid: string, user: string, path: string}|null}
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-an-account-an-export-it-made
	 */
	public function write(ExampleSetRow $row): array {
		$data = $row->data;
		$user = (string) $data['user'];
		$path = self::exportPath(data: $data);
		try {
			$file = $this->replaceFile(data: $data, path: $path);
			$run  = $this->recordRun(data: $data, file: $file);
			$run  = $this->backdate(run: $run, data: $data);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => ['uuid' => (string) $run->getUuid(), 'user' => $user, 'path' => $path]];
	}//end write()

	/**
	 * Where the export lands in the account's files.
	 *
	 * @param array<string, mixed> $data The filled fields.
	 *
	 * @return string The path, relative to the account's folder.
	 */
	private static function exportPath(array $data): string {
		return self::FOLDER.'/'.(string) $data['fileName'];
	}//end exportPath()

	/**
	 * Write the file into the account's export folder, replacing one already there.
	 *
	 * @param array<string, mixed> $data The filled fields.
	 * @param string               $path The path, relative to the account's folder.
	 *
	 * @return File The written file.
	 */
	private function replaceFile(array $data, string $path): File {
		$folder = $this->container->get(IRootFolder::class)->getUserFolder((string) $data['user']);
		if ($folder->nodeExists(self::FOLDER) === false) {
			$folder->newFolder(self::FOLDER);
		}

		if ($folder->nodeExists($path) === true) {
			$folder->get($path)->delete();
		}

		return $folder->newFile($path, (string) $this->bytes(data: $data));
	}//end replaceFile()

	/**
	 * Record the run that names the file.
	 *
	 * @param array<string, mixed> $data The filled fields.
	 * @param File                 $file The written file.
	 *
	 * @return ExportRun The recorded run.
	 */
	private function recordRun(array $data, File $file): ExportRun {
		return $this->container->get(ExportRunRecorder::class)->record(
			origin: new ExportRunOrigin(
				source: self::SOURCE,
				actor: (string) $data['user']
			),
			content: $this->runContent(data: $data),
			file: self::runFile(data: $data, file: $file)
		);
	}//end recordRun()

	/**
	 * What the run says the export holds.
	 *
	 * @param array<string, mixed> $data The filled fields.
	 *
	 * @return ExportRunContent The content.
	 */
	private function runContent(array $data): ExportRunContent {
		return new ExportRunContent(
			format: (string) ($data['format'] ?? pathinfo(self::exportPath(data: $data), PATHINFO_EXTENSION)),
			rowCount: (int) ($data['rowCount'] ?? 0),
			registerName: $this->text(value: ($data['registerName'] ?? null)),
			schemaName: $this->text(value: ($data['schemaName'] ?? null))
		);
	}//end runContent()

	/**
	 * The file the run names.
	 *
	 * @param array<string, mixed> $data The filled fields.
	 * @param File                 $file The written file.
	 *
	 * @return ExportRunFile The run's file.
	 */
	private static function runFile(array $data, File $file): ExportRunFile {
		return new ExportRunFile(
			filename: (string) $data['fileName'],
			fileId: (int) $file->getId(),
			filePath: (string) $file->getPath(),
			downloadCount: (int) ($data['downloadCount'] ?? 0)
		);
	}//end runFile()

	/**
	 * Date the run to its declared moment, when one is given.
	 *
	 * @param ExportRun            $run  The recorded run.
	 * @param array<string, mixed> $data The filled fields.
	 *
	 * @return ExportRun The run, updated when it was backdated.
	 */
	private function backdate(ExportRun $run, array $data): ExportRun {
		$producedAt = ExampleSetDateValue::moment(value: ($data['producedAt'] ?? null));
		if ($producedAt === null) {
			return $run;
		}

		// The run reads as made then, and lives its usual time from that moment.
		$run->setProducedAt($producedAt);
		$run->setCreated($producedAt);
		$keep = (int) ($run->getRetentionSeconds() ?? ExportRunRecorder::DEFAULT_RETENTION_SECONDS);
		$run->setExpiresAt((clone $producedAt)->modify('+'.$keep.' seconds'));

		return $this->container->get(ExportRunMapper::class)->update($run);
	}//end backdate()

	/**
	 * Whether the run or its file is gone.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-an-account-an-export-it-made
	 */
	public function lost(array $entry): bool {
		try {
			$this->container->get(ExportRunMapper::class)->findByUuid((string) $entry['uuid']);
			$this->container->get(IRootFolder::class)->getUserFolder((string) $entry['user'])->get((string) $entry['path']);
		} catch (Throwable) {
			return true;
		}

		return false;
	}//end lost()

	/**
	 * Delete the file and the run; a part already gone is not a failure.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return string `removed` or `failed`.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-an-account-an-export-it-made
	 */
	public function revert(array $entry): string {
		try {
			$folder = $this->container->get(IRootFolder::class)->getUserFolder((string) $entry['user']);
			if ($folder->nodeExists((string) $entry['path']) === true) {
				$folder->get((string) $entry['path'])->delete();
			}

			$mapper = $this->container->get(ExportRunMapper::class);
			$mapper->delete($mapper->findByUuid((string) $entry['uuid']));
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return 'removed';
		} catch (Throwable) {
			return 'failed';
		}

		return 'removed';
	}//end revert()

	/**
	 * Whether a name is a plain file name: no folder, no hidden file, no NUL.
	 *
	 * @param string $name The declared file name.
	 *
	 * @return bool
	 */
	private function plainName(string $name): bool {
		return preg_match('/^[^\/\\\\\x00]+$/', $name) === 1 && str_starts_with($name, '.') === false;
	}//end plainName()

	/**
	 * The file's bytes: the text content, or the decoded contentBase64; null when neither is usable.
	 *
	 * @param array<string, mixed> $data The filled fields.
	 *
	 * @return string|null
	 */
	private function bytes(array $data): ?string {
		if (is_string($data['contentBase64'] ?? null) === true) {
			$decoded = base64_decode($data['contentBase64'], true);
			if ($decoded === false) {
				return null;
			}

			return $decoded;
		}

		if (is_string($data['content'] ?? null) === true) {
			return $data['content'];
		}

		return null;
	}//end bytes()

	/**
	 * A text, or null for none.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return string|null
	 */
	private function text(mixed $value): ?string {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end text()
}//end class
