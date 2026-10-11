<?php

/**
 * Remembers a submit's answer for 24 hours under its Idempotency-Key.
 *
 * A resident whose connection drops after pressing send presses it again.
 * Without this, the second press creates a second case. With it, the second
 * press gets the first answer, the same reference, and nothing is created.
 *
 * Stored in app data, one small JSON file per key, named by a hash of the
 * form scope and the key so a key never names a path. It holds the answer
 * (reference, id, receivedAt, confirmation), never the submitted payload.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * Find, remember and purge submit answers by key.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */
class FormIdempotencyStore {

	/**
	 * The app-data folder.
	 *
	 * @var string
	 */
	public const FOLDER = 'form-submit-keys';

	/**
	 * How long an answer is repeated, in seconds.
	 *
	 * @var int
	 */
	public const TTL = 86400;

	/**
	 * Constructor.
	 *
	 * @param IAppData     $appData The app's data store.
	 * @param ITimeFactory $time    The clock.
	 */
	public function __construct(
		private readonly IAppData $appData,
		private readonly ITimeFactory $time,
	) {

	}//end __construct()

	/**
	 * The answer remembered under a key, or null when none is live.
	 *
	 * @param string $scope The form scope (form id, or the caller's own).
	 * @param string $key   The Idempotency-Key.
	 *
	 * @return array<string, mixed>|null The answer.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function find(string $scope, string $key): ?array {
		$folder = $this->folder(create: false);
		$name = $this->name(scope: $scope, key: $key);
		if ($folder === null || $folder->fileExists($name) === false) {
			return null;
		}

		$file = $folder->getFile($name);
		$entry = $this->read(file: $file);
		if ($entry === null || $entry['expiresAt'] <= $this->time->getTime()) {
			$file->delete();

			return null;
		}

		return $entry['response'];
	}//end find()

	/**
	 * Remember an answer under a key for 24 hours.
	 *
	 * @param string               $scope    The form scope.
	 * @param string               $key      The Idempotency-Key.
	 * @param array<string, mixed> $response The answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function remember(string $scope, string $key, array $response): void {
		$folder = $this->folder(create: true);
		$content = (string)json_encode(['expiresAt' => $this->time->getTime() + self::TTL, 'response' => $response]);
		$name = $this->name(scope: $scope, key: $key);
		if ($folder->fileExists($name) === true) {
			$folder->getFile($name)->putContent($content);

			return;
		}

		$folder->newFile($name, $content);
	}//end remember()

	/**
	 * Delete every expired answer.
	 *
	 * @return int How many were deleted, so a zero is a counted zero.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
	 */
	public function purge(): int {
		$folder = $this->folder(create: false);
		if ($folder === null) {
			return 0;
		}

		$deleted = 0;
		$now = $this->time->getTime();
		foreach ($folder->getDirectoryListing() as $file) {
			$entry = $this->read(file: $file);
			if ($entry !== null && $entry['expiresAt'] > $now) {
				continue;
			}

			$file->delete();
			$deleted++;
		}

		return $deleted;
	}//end purge()

	/**
	 * The stored entry of a file, or null when unreadable.
	 *
	 * @param ISimpleFile $file The file.
	 *
	 * @return array{expiresAt: int, response: array<string, mixed>}|null The entry.
	 */
	private function read(ISimpleFile $file): ?array {
		$entry = json_decode($file->getContent(), true);
		if (is_array($entry) === false || is_int($entry['expiresAt'] ?? null) === false || is_array($entry['response'] ?? null) === false) {
			return null;
		}

		return ['expiresAt' => $entry['expiresAt'], 'response' => $entry['response']];
	}//end read()

	/**
	 * The file name for a scope and key: a hash, so a key never names a path.
	 *
	 * @param string $scope The form scope.
	 * @param string $key   The key.
	 *
	 * @return string The file name.
	 */
	private function name(string $scope, string $key): string {
		return hash('sha256', $scope . "\0" . $key) . '.json';
	}//end name()

	/**
	 * The store's folder.
	 *
	 * @param bool $create Create it when absent.
	 *
	 * @return ISimpleFolder|null The folder; null only when absent and not created.
	 *
	 * @psalm-return ($create is true ? ISimpleFolder : ISimpleFolder|null)
	 */
	private function folder(bool $create): ?ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			if ($create === false) {
				return null;
			}

			return $this->appData->newFolder(self::FOLDER);
		}
	}//end folder()
}//end class
