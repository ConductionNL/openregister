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
 * @spec openspec/changes/example-set-export-runs/specs/example-sets/spec.md#requirement-a-fragment-may-give-an-account-an-export-it-made
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTime;
use OCA\OpenRegister\Db\ExportRunMapper;
use OCA\OpenRegister\Service\Export\ExportRunRecorder;
use OCP\Files\IRootFolder;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Writes, proves and removes the exports a fragment declares.
 *
 * @spec openspec/changes/example-set-export-runs/specs/example-sets/spec.md#requirement-a-fragment-may-give-an-account-an-export-it-made
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
	 * @spec openspec/changes/example-set-export-runs/specs/example-sets/spec.md#requirement-a-fragment-may-give-an-account-an-export-it-made
	 */
	public function part(): string {
		return 'exportRuns';
	}//end part()

	/**
	 * The word for one row.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-export-runs/specs/example-sets/spec.md#requirement-a-fragment-may-give-an-account-an-export-it-made
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
	 * @spec openspec/changes/example-set-export-runs/specs/example-sets/spec.md#requirement-a-fragment-may-give-an-account-an-export-it-made
	 */
	public function refusal(array $data, array $users): string {
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

		$bytes = $this->bytes(data: $data);
		if ($bytes === null || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
			return 'an export needs its content (text, or contentBase64 for a binary file), at most '.self::MAX_BYTES.' bytes';
		}

		if (isset($data['producedAt']) === true && $this->moment(value: $data['producedAt']) === null) {
			return 'producedAt is a date and time';
		}

		return '';
	}//end refusal()

	/**
	 * Write the file and the run that names it.
	 *
	 * @param string               $app  The app that ships the fragment.
	 * @param string               $key  The row's key in the fragment.
	 * @param array<string, mixed> $data The filled fields.
	 *
	 * @return array{error: string, entry: array{uuid: string, user: string, path: string}|null}
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is the engine rows' contract.
	 *
	 * @spec openspec/changes/example-set-export-runs/specs/example-sets/spec.md#requirement-a-fragment-may-give-an-account-an-export-it-made
	 */
	public function write(string $app, string $key, array $data): array {
		$user = (string) $data['user'];
		$path = self::FOLDER.'/'.(string) $data['fileName'];
		try {
			$folder = $this->container->get(IRootFolder::class)->getUserFolder($user);
			if ($folder->nodeExists(self::FOLDER) === false) {
				$folder->newFolder(self::FOLDER);
			}

			if ($folder->nodeExists($path) === true) {
				$folder->get($path)->delete();
			}

			$file = $folder->newFile($path, (string) $this->bytes(data: $data));
			$run  = $this->container->get(ExportRunRecorder::class)->record(
				source: self::SOURCE,
				actor: $user,
				format: (string) ($data['format'] ?? pathinfo($path, PATHINFO_EXTENSION)),
				rowCount: (int) ($data['rowCount'] ?? 0),
				filename: (string) $data['fileName'],
				registerName: $this->text(value: ($data['registerName'] ?? null)),
				schemaName: $this->text(value: ($data['schemaName'] ?? null)),
				fileId: (int) $file->getId(),
				filePath: (string) $file->getPath(),
				downloadCount: (int) ($data['downloadCount'] ?? 0)
			);

			$producedAt = $this->moment(value: ($data['producedAt'] ?? null));
			if ($producedAt !== null) {
				// The run reads as made then, and lives its usual time from that moment.
				$run->setProducedAt($producedAt);
				$run->setCreated($producedAt);
				$keep = (int) ($run->getRetentionSeconds() ?? ExportRunRecorder::DEFAULT_RETENTION_SECONDS);
				$run->setExpiresAt((clone $producedAt)->modify('+'.$keep.' seconds'));
				$run = $this->container->get(ExportRunMapper::class)->update($run);
			}
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => ['uuid' => (string) $run->getUuid(), 'user' => $user, 'path' => $path]];
	}//end write()

	/**
	 * Whether the run or its file is gone.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-set-export-runs/specs/example-sets/spec.md#requirement-a-fragment-may-give-an-account-an-export-it-made
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
	 * @spec openspec/changes/example-set-export-runs/specs/example-sets/spec.md#requirement-a-fragment-may-give-an-account-an-export-it-made
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
	 * A moment, or null when the value is none or no date.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return DateTime|null
	 */
	private function moment(mixed $value): ?DateTime {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTime($value);
		} catch (Throwable) {
			return null;
		}
	}//end moment()

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
