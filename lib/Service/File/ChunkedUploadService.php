<?php

/**
 * ChunkedUploadService: uploads a large file in parts, resumably, and creates
 * the file only once every byte is there and matches its declared SHA-256.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-a-large-file-uploads-in-parts-with-stated-boundaries-req-lfh-002
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-an-interrupted-upload-resumes-and-states-its-completeness-req-lfh-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use DateInterval;
use DateTime;
use DateTimeInterface;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Kernel\Contracts\UploadSettingsReaderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\UploadSession;
use OCA\OpenRegister\Db\UploadSessionMapper;
use OCA\OpenRegister\Files\Controller\Support\FileActionRefusal;
use OCA\OpenRegister\Service\FileWriterInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Upload sessions: open, store a part, report, complete, expire.
 *
 * A session states the declared name, size and SHA-256 and cuts the size into
 * parts of `upload.partSizeBytes`; the last part may be shorter. A part is
 * stored only when its length is exactly its range's, under the app's data
 * folder `uploads/{uploadId}`, never in the object's folder. Completion fails
 * closed: a missing part, a size or a checksum that does not match, or a
 * refused upload check creates no file and keeps the parts for a retry until
 * the session expires. The file is created through FileWriterInterface::addFile(),
 * the path `FilesController::create()` uses, so it gets the same upload checks.
 *
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-a-large-file-uploads-in-parts-with-stated-boundaries-req-lfh-002
 */
class ChunkedUploadService {

	/**
	 * The app data folder the parts live under.
	 *
	 * @var string
	 */
	public const FOLDER = 'uploads';

	/**
	 * Bytes copied per read while assembling.
	 *
	 * @var int
	 */
	private const COPY_BUFFER = 1048576;

	/**
	 * The app's data store, resolved on first use.
	 *
	 * @var IAppData|null
	 */
	private ?IAppData $appData = null;

	/**
	 * Constructor.
	 *
	 * @param UploadSessionMapper $sessions       The sessions table.
	 * @param IAppDataFactory     $appDataFactory The app data store's factory.
	 * @param UploadSettingsReaderInterface $settings The part size and session lifetime.
	 * @param FileWriterInterface $files          Creates the assembled file on the object.
	 * @param ITimeFactory        $clock          The time.
	 * @param LoggerInterface     $logger         Logger.
	 */
	public function __construct(
		private readonly UploadSessionMapper $sessions,
		private readonly IAppDataFactory $appDataFactory,
		private readonly UploadSettingsReaderInterface $settings,
		private readonly FileWriterInterface $files,
		private readonly ITimeFactory $clock,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Open a session for a declared file on an object.
	 *
	 * @param ObjectEntity $object The object the file is for; the caller's update right is checked before.
	 * @param string       $userId The user opening it.
	 * @param array        $declared The request body: name, size, sha256.
	 *
	 * @return array<string, mixed> The plan: uploadId, partSize, partCount, parts, expiresAt.
	 *
	 * @throws FileActionRefusal With 400 for a missing name, a size that is not positive or a SHA-256 that is not 64 hex digits.
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-2-1
	 */
	public function open(ObjectEntity $object, string $userId, array $declared): array {
		$name   = trim((string) ($declared['name'] ?? ''));
		$size   = ($declared['size'] ?? null);
		$sha256 = strtolower((string) ($declared['sha256'] ?? ''));
		if ($name === '' || str_contains($name, '/') === true) {
			throw new FileActionRefusal(message: 'A file name without a slash is required', code: 400);
		}

		if (is_string($size) === true && ctype_digit($size) === true) {
			$size = (int) $size;
		}

		if (is_int($size) === false || $size < 1) {
			throw new FileActionRefusal(message: 'size must be a positive whole number of bytes', code: 400);
		}

		if (preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
			throw new FileActionRefusal(message: 'sha256 must be the 64 hex digit SHA-256 of the file', code: 400);
		}

		$session = new UploadSession();
		$session->setUploadId(Uuid::v4()->toRfc4122());
		$session->setUserId($userId);
		$session->setObjectUuid((string) ($object->getUuid() ?? $object->getId()));
		$session->setName($name);
		$session->setSize($size);
		$session->setSha256($sha256);
		$session->setPartSize($this->settings->uploadSettings()['partSizeBytes']);
		$session->setReceived('[]');
		$session->setCreated($this->now());
		$session->setExpiresAt($this->expiry());
		$this->sessions->insert($session);

		return $this->plan(session: $session);
	}//end open()

	/**
	 * The session the caller may use: theirs, on this object, and not expired.
	 *
	 * Anything else is answered as not there, so a session id of somebody
	 * else's tells nothing about it.
	 *
	 * @param string       $uploadId The upload id.
	 * @param string       $userId   The caller.
	 * @param ObjectEntity $object   The object the route names.
	 *
	 * @return UploadSession
	 *
	 * @throws FileActionRefusal With 404 when it is not the caller's usable session.
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-2-2
	 */
	public function sessionFor(string $uploadId, string $userId, ObjectEntity $object): UploadSession {
		$session = $this->sessions->findByUploadId(uploadId: $uploadId);
		$objectIds = [(string) $object->getUuid(), (string) $object->getId()];
		if ($session === null
			|| $userId === ''
			|| hash_equals($session->getUserId(), $userId) === false
			|| in_array($session->getObjectUuid(), $objectIds, true) === false
			|| $this->isExpired(session: $session) === true
		) {
			throw new FileActionRefusal(message: 'Upload session not found', code: 404);
		}

		return $session;
	}//end sessionFor()

	/**
	 * Store part n; a part whose length differs from its range is refused and nothing is stored.
	 *
	 * @param UploadSession $session The caller's session.
	 * @param int           $n       The part number, from 1.
	 * @param string        $bytes   The part's bytes.
	 *
	 * @return array<string, mixed> The session status after it.
	 *
	 * @throws FileActionRefusal With 400 for a part number outside the plan or a wrong length.
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-2-1
	 */
	public function storePart(UploadSession $session, int $n, string $bytes): array {
		if ($n < 1 || $n > $session->partCount()) {
			throw new FileActionRefusal(message: sprintf('Part %d is not in this upload; it has parts 1 to %d', $n, $session->partCount()), code: 400);
		}

		$expected = $session->partLength(n: $n);
		if (strlen($bytes) !== $expected) {
			throw new FileActionRefusal(
				message: sprintf('Part %d must be %d bytes, received %d', $n, $expected, strlen($bytes)),
				code: 400
			);
		}

		$this->partsFolder(session: $session, create: true)->newFile(self::partName(n: $n), $bytes);
		$session->markReceived(n: $n);
		$session->setExpiresAt($this->expiry());
		$this->sessions->update($session);

		return $this->status(session: $session);
	}//end storePart()

	/**
	 * The plan and what has arrived: received, missing and complete.
	 *
	 * @param UploadSession $session The session.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-2-1
	 */
	public function status(UploadSession $session): array {
		$missing = $session->missingParts();

		return $this->plan(session: $session) + [
			'name' => $session->getName(),
			'size' => $session->getSize(),
			'sha256' => $session->getSha256(),
			'received' => $session->receivedParts(),
			'missing' => $missing,
			'complete' => ($missing === []),
		];
	}//end status()

	/**
	 * Assemble the parts, verify size and SHA-256 and create the file on the object.
	 *
	 * Fails closed: nothing is created when a part is missing, the size or the
	 * checksum differs, or the file creation refuses the bytes; the parts stay
	 * for a retry. On success the parts and the session are removed.
	 *
	 * @param UploadSession $session The caller's session.
	 * @param ObjectEntity  $object  The object, its update right checked again by the caller.
	 *
	 * @return File The created file.
	 *
	 * @throws FileActionRefusal With 409 naming the missing parts or the mismatch.
	 * @throws \Exception        When the file creation refuses the upload (the shared upload checks).
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-3-1
	 */
	public function complete(UploadSession $session, ObjectEntity $object): File {
		$missing = $session->missingParts();
		if ($missing !== []) {
			throw new FileActionRefusal(message: 'The upload is not complete; missing parts: ' . implode(', ', $missing), code: 409);
		}

		$assembled = $this->assemble(session: $session);
		try {
			if ($assembled['size'] !== $session->getSize()) {
				throw new FileActionRefusal(
					message: sprintf('Size mismatch: declared %d bytes, assembled %d', $session->getSize(), $assembled['size']),
					code: 409
				);
			}

			if (hash_equals($session->getSha256(), $assembled['sha256']) === false) {
				throw new FileActionRefusal(message: 'Checksum mismatch: the assembled bytes do not have the declared SHA-256', code: 409);
			}

			rewind($assembled['stream']);
			$file = $this->files->addFile(
				target: new FileTarget(object: $object),
				upload: new FileUpload(fileName: $session->getName(), content: $assembled['stream'], share: false)
			);
		} finally {
			fclose($assembled['stream']);
		}

		$this->discard(session: $session);

		return $file;
	}//end complete()

	/**
	 * Remove every session that expired, with its parts.
	 *
	 * @return int How many were removed.
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-3-2
	 */
	public function removeExpired(): int {
		$removed = 0;
		foreach ($this->sessions->findExpired(now: $this->now()) as $session) {
			try {
				$this->discard(session: $session);
				$removed++;
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[ChunkedUploadService] Could not remove an expired upload session',
					context: ['file' => __FILE__, 'line' => __LINE__, 'uploadId' => $session->getUploadId(), 'error' => $e->getMessage()]
				);
			}
		}

		return $removed;
	}//end removeExpired()

	/**
	 * The plan a session states: id, part size and count, every part's range and the expiry.
	 *
	 * @param UploadSession $session The session.
	 *
	 * @return array<string, mixed>
	 */
	private function plan(UploadSession $session): array {
		$parts = [];
		for ($n = 1; $n <= $session->partCount(); $n++) {
			$parts[] = $session->partRange(n: $n);
		}

		return [
			'uploadId' => $session->getUploadId(),
			'partSize' => $session->getPartSize(),
			'partCount' => $session->partCount(),
			'parts' => $parts,
			'expiresAt' => $session->getExpiresAt()?->format(DateTimeInterface::ATOM),
		];
	}//end plan()

	/**
	 * The parts, in order, copied into one temporary stream with its size and SHA-256.
	 *
	 * @param UploadSession $session The session.
	 *
	 * @return array{stream: resource, size: int, sha256: string}
	 *
	 * @throws FileActionRefusal With 409 when a part recorded as received cannot be read.
	 */
	private function assemble(UploadSession $session): array {
		$folder = $this->partsFolder(session: $session, create: false);
		$stream = fopen('php://temp/maxmemory:' . (8 * self::COPY_BUFFER), 'w+b');
		$hash   = hash_init('sha256');
		$size   = 0;
		for ($n = 1; $n <= $session->partCount(); $n++) {
			try {
				$part = $folder?->getFile(self::partName(n: $n))->read();
			} catch (NotFoundException) {
				$part = null;
			}

			if (is_resource($part) === false) {
				fclose($stream);
				throw new FileActionRefusal(message: sprintf('The upload is not complete; missing parts: %d', $n), code: 409);
			}

			while (feof($part) === false) {
				$chunk = (string) fread($part, self::COPY_BUFFER);
				hash_update($hash, $chunk);
				$size += strlen($chunk);
				fwrite($stream, $chunk);
			}

			fclose($part);
		}

		return ['stream' => $stream, 'size' => $size, 'sha256' => hash_final($hash)];
	}//end assemble()

	/**
	 * Delete the session's parts and its row.
	 *
	 * @param UploadSession $session The session.
	 *
	 * @return void
	 */
	private function discard(UploadSession $session): void {
		$this->partsFolder(session: $session, create: false)?->delete();
		$this->sessions->delete($session);
	}//end discard()

	/**
	 * The session's parts folder, `uploads/{uploadId}` in the app's data store.
	 *
	 * @param UploadSession $session The session.
	 * @param bool          $create  Create it when absent.
	 *
	 * @return ISimpleFolder|null Null only when absent and not created.
	 *
	 * @psalm-return ($create is true ? ISimpleFolder : ISimpleFolder|null)
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Found, or found or made.
	 */
	private function partsFolder(UploadSession $session, bool $create): ?ISimpleFolder {
		$path = self::FOLDER . '/' . $session->getUploadId();
		$this->appData ??= $this->appDataFactory->get(AppIdentity::APP_ID);
		try {
			return $this->appData->getFolder($path);
		} catch (NotFoundException) {
			if ($create === false) {
				return null;
			}

			return $this->appData->newFolder($path);
		}
	}//end partsFolder()

	/**
	 * The file name part n is stored under.
	 *
	 * @param int $n The part number.
	 *
	 * @return string
	 */
	private static function partName(int $n): string {
		return sprintf('part-%06d', $n);
	}//end partName()

	/**
	 * Whether the session's expiry has passed.
	 *
	 * @param UploadSession $session The session.
	 *
	 * @return bool
	 */
	private function isExpired(UploadSession $session): bool {
		$expiresAt = $session->getExpiresAt();

		return $expiresAt !== null && $expiresAt < $this->now();
	}//end isExpired()

	/**
	 * Now, from the clock.
	 *
	 * @return DateTime
	 */
	private function now(): DateTime {
		return DateTime::createFromImmutable($this->clock->now());
	}//end now()

	/**
	 * When a session touched now expires: now plus `upload.sessionTtl`.
	 *
	 * @return DateTime
	 */
	private function expiry(): DateTime {
		return $this->now()->add(new DateInterval($this->settings->uploadSettings()['sessionTtl']));
	}//end expiry()
}//end class
