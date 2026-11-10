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
 * (reference, id, receivedAt, confirmation), never the submitted payload, and
 * a binding: a hash of who submitted and of what. The key is client-chosen, so
 * it alone never earns somebody the stored answer: another subject, or the
 * same key with another payload, is refused with a 409 that reveals nothing.
 *
 * A key is reserved (an exclusive lock) while its submit runs, so a double
 * click or a parallel retry with the same key waits out with a 409 instead of
 * writing a second object. That needs a real locking provider (Redis, or the
 * database locking of a default install); an instance whose provider is the
 * no-op one (no memory cache configured for locking) reserves nothing, so a
 * parallel retry can write a second object. The stored answer still repeats
 * for every retry after the first submit finished. The store says so once in
 * the log when it finds itself on such an instance.
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

use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Find, remember and purge submit answers by key.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */
class FormIdempotencyStore implements FormIdempotencyStoreInterface {
	/**
	 * The app's data store.
	 *
	 * @var IAppData
	 */
	private readonly IAppData $appData;

	/**
	 * The clock.
	 *
	 * @var ITimeFactory
	 */
	private readonly ITimeFactory $time;


	use AppDataFolder;

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
	 * Whether the no-op warning went out in this process.
	 *
	 * @var bool
	 */
	private static bool $warned = false;

	/**
	 * Constructor.
	 *
	 * @param FormFileStorage  $storage The app's data store and the clock.
	 * @param ILockingProvider $locks   Reserves a key while its submit runs.
	 * @param IL10N            $l10n    Translations, for refusals a resident reads.
	 * @param LoggerInterface|null $logger Says once that reservation is a no-op on this instance.
	 */
	public function __construct(
		FormFileStorage $storage,
		private readonly ILockingProvider $locks,
		private readonly IL10N $l10n,
		private readonly ?LoggerInterface $logger = null,
	) {
		$this->appData = $storage->appData;
		$this->time    = $storage->time;

	}//end __construct()

	/**
	 * What a stored answer is bound to: the submitter and the payload.
	 *
	 * @param string               $subject The submitting subject (a user id, a scope value, or an anonymous marker).
	 * @param array<string, mixed> $payload What was submitted.
	 *
	 * @return string The binding.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function bindingFor(string $subject, array $payload): string {
		return hash('sha256', $subject . "\0" . json_encode($this->canonical(value: $payload)));
	}//end bindingFor()

	/**
	 * Reserve a key for the length of its submit.
	 *
	 * @param string $scope The form scope.
	 * @param string $key   The Idempotency-Key.
	 *
	 * @return void
	 *
	 * @throws FormSubmitRefusedException 409 when a submit with this key is still running.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function reserve(string $scope, string $key): void {
		$this->warnWhenReservationIsANoop();

		try {
			$this->locks->acquireLock($this->lockPath(scope: $scope, key: $key), ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			throw new FormSubmitRefusedException(
				message: $this->l10n->t('This form is already being sent. Please wait a moment.'),
				status: 409
			);
		}
	}//end reserve()

	/**
	 * Whether the locking provider reserves nothing (the no-op provider of an instance without a locking cache).
	 *
	 * @return bool True when a parallel retry with one key is not held back.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function reservationIsANoop(): bool {
		return str_ends_with($this->locks::class, 'NoopLockingProvider') === true;
	}//end reservationIsANoop()

	/**
	 * Log once per process that a key is not reserved on this instance.
	 *
	 * @return void
	 */
	private function warnWhenReservationIsANoop(): void {
		if (self::$warned === true || $this->reservationIsANoop() === false) {
			return;
		}

		self::$warned = true;
		$this->logger?->warning(
			message: '[FormIdempotencyStore] The locking provider is a no-op: an Idempotency-Key is not reserved while its submit runs, '
				. 'so a parallel retry can write a second object. Configure a locking cache (memcache.locking) to close this.',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end warnWhenReservationIsANoop()

	/**
	 * Release a key reserved by reserve().
	 *
	 * @param string $scope The form scope.
	 * @param string $key   The Idempotency-Key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function release(string $scope, string $key): void {
		try {
			$this->locks->releaseLock($this->lockPath(scope: $scope, key: $key), ILockingProvider::LOCK_EXCLUSIVE);
		} catch (\Throwable) {
			// A lock that is already gone leaves nothing to release.
			return;
		}
	}//end release()

	/**
	 * The answer remembered for a request, or null when none is live.
	 *
	 * @param IdempotentRequest $request The scope, the key and the binding of this request.
	 *
	 * @return array<string, mixed>|null The answer.
	 *
	 * @throws FormSubmitRefusedException 409 when the key was used by another subject or for another payload.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function answerFor(IdempotentRequest $request): ?array {
		$entry = $this->liveEntry(scope: $request->scope, key: $request->key);
		if ($entry === null) {
			return null;
		}

		if (hash_equals($entry['binding'], $request->binding) === false) {
			// The same answer to a different asker would hand over their
			// reference and confirmation; say only that the key is taken.
			throw new FormSubmitRefusedException(
				message: $this->l10n->t('This Idempotency-Key was already used for another submission.'),
				status: 409
			);
		}

		return $entry['response'];
	}//end answerFor()

	/**
	 * The live entry under a key; an expired or unreadable one is deleted on sight.
	 *
	 * @param string $scope The form scope.
	 * @param string $key   The Idempotency-Key.
	 *
	 * @return array{expiresAt: int, binding: string, response: array<string, mixed>}|null The entry.
	 */
	private function liveEntry(string $scope, string $key): ?array {
		$file = $this->existingFile(scope: $scope, key: $key);
		if ($file === null) {
			return null;
		}

		$entry = $this->read(file: $file);
		if ($entry === null || $entry['expiresAt'] <= $this->time->getTime()) {
			$file->delete();

			return null;
		}

		return $entry;
	}//end liveEntry()

	/**
	 * The file an answer under a key is stored in, or null when there is none.
	 *
	 * @param string $scope The form scope.
	 * @param string $key   The Idempotency-Key.
	 *
	 * @return ISimpleFile|null The file.
	 */
	private function existingFile(string $scope, string $key): ?ISimpleFile {
		$folder = $this->folder(create: false);
		$name = $this->name(scope: $scope, key: $key);
		if ($folder === null || $folder->fileExists($name) === false) {
			return null;
		}

		return $folder->getFile($name);
	}//end existingFile()

	/**
	 * Remember the answer to a request for 24 hours.
	 *
	 * @param IdempotentRequest    $request  The scope, the key and the binding of the request that got it.
	 * @param array<string, mixed> $response The answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function keep(IdempotentRequest $request, array $response): void {
		$folder = $this->folder(create: true);
		$content = (string)json_encode(['expiresAt' => $this->time->getTime() + self::TTL, 'binding' => $request->binding, 'response' => $response]);
		$name = $this->name(scope: $request->scope, key: $request->key);
		if ($folder->fileExists($name) === true) {
			$folder->getFile($name)->putContent($content);

			return;
		}

		$folder->newFile($name, $content);
	}//end keep()

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
			$deleted += $this->purgeIfExpired(file: $file, now: $now);
		}

		return $deleted;
	}//end purge()

	/**
	 * Delete one answer file when it expired or cannot be read.
	 *
	 * @param ISimpleFile $file The file.
	 * @param int         $now  The moment of the purge.
	 *
	 * @return int 1 when deleted, else 0.
	 */
	private function purgeIfExpired(ISimpleFile $file, int $now): int {
		$entry = $this->read(file: $file);
		if ($entry !== null && $entry['expiresAt'] > $now) {
			return 0;
		}

		$file->delete();

		return 1;
	}//end purgeIfExpired()

	/**
	 * The stored entry of a file, or null when unreadable.
	 *
	 * @param ISimpleFile $file The file.
	 *
	 * @return array{expiresAt: int, binding: string, response: array<string, mixed>}|null The entry.
	 */
	private function read(ISimpleFile $file): ?array {
		$entry = json_decode($file->getContent(), true);
		if (is_array($entry) === false || is_int($entry['expiresAt'] ?? null) === false || is_array($entry['response'] ?? null) === false) {
			return null;
		}

		// An entry without a binding (written before bindings) matches nobody.
		return ['expiresAt' => $entry['expiresAt'], 'binding' => (string)($entry['binding'] ?? ''), 'response' => $entry['response']];
	}//end read()

	/**
	 * A value with every map's keys in order, so equal payloads hash equally.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The value, keys sorted at every level.
	 */
	private function canonical(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		foreach ($value as $name => $item) {
			$value[$name] = $this->canonical(value: $item);
		}

		if (array_is_list($value) === false) {
			ksort($value);
		}

		return $value;
	}//end canonical()

	/**
	 * The lock path for a scope and key: the file's own hash, under this app.
	 *
	 * @param string $scope The form scope.
	 * @param string $key   The key.
	 *
	 * @return string The lock path.
	 */
	private function lockPath(string $scope, string $key): string {
		return 'openregister/form-submit-key/' . hash('sha256', $scope . "\0" . $key);
	}//end lockPath()

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
}//end class
