<?php

/**
 * Upload tokens: file bytes held for a submit that has not happened yet.
 *
 * ADR-117 decision 5: a token is not an intake. It holds bytes, never
 * answers, belongs to one form and one property, and expires after 24
 * hours. A submit claims it; an unclaimed one is purged and counted. The
 * bytes land on the destination object only when the submit succeeds.
 *
 * Each token is two app-data files: `<token>.bin` (the bytes) and
 * `<token>.json` (form, property, name, type, size, expiry).
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
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Service\File\FilePropertyRules;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IL10N;
use OCP\ITempManager;

/**
 * Issue, materialise, claim and purge upload tokens.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
 */
class FormUploadStore {

	/**
	 * The app-data folder.
	 *
	 * @var string
	 */
	public const FOLDER = 'form-uploads';

	/**
	 * How long an unclaimed token lives, in seconds.
	 *
	 * @var int
	 */
	public const TTL = 86400;

	/**
	 * Constructor.
	 *
	 * @param IAppData          $appData The app's data store.
	 * @param ITimeFactory      $time    The clock.
	 * @param ITempManager      $temp    Temporary files the save path reads a claimed upload from.
	 * @param FilePropertyRules $rules   The file property's size and type rules.
	 * @param IL10N             $l10n    Translations, for refusals a resident reads.
	 */
	public function __construct(
		private readonly IAppData $appData,
		private readonly ITimeFactory $time,
		private readonly ITempManager $temp,
		private readonly FilePropertyRules $rules,
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * Hold one uploaded file and return its token.
	 *
	 * @param string                                                             $formId   The form the token belongs to.
	 * @param string                                                             $property The destination property the file is for.
	 * @param array<string, mixed>                                               $rule     That property's schema definition.
	 * @param array{name?: mixed, type?: mixed, tmp_name?: mixed, error?: mixed, size?: mixed} $file The PHP upload array.
	 *
	 * @return array{token: string, expiresAt: int} The token and its expiry (unix seconds).
	 *
	 * @throws FormSubmitRefusedException 422 when the file breaks the property's rules or did not arrive.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
	 */
	public function issue(string $formId, string $property, array $rule, array $file): array {
		$fileConfig = $this->rules->fileConfigOf(property: $rule);
		if ($fileConfig === null) {
			$this->refuse(property: $property, code: 'property-holds-no-files', message: $this->l10n->t('"%1$s" does not hold files.', [$property]));
		}

		$path = (string)($file['tmp_name'] ?? '');
		if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || is_readable($path) === false) {
			$this->refuse(property: $property, code: 'upload-failed', message: $this->l10n->t('The file did not arrive. Please try again.'));
		}

		$size = (int)($file['size'] ?? filesize($path));
		$limit = $this->rules->maxSizeBytes(fileConfig: $fileConfig);
		if ($limit > 0 && $size > $limit) {
			$this->refuse(
				property: $property,
				code: 'file-too-large',
				message: $this->l10n->t(
					'The file is %1$s MB; "%2$s" takes at most %3$s MB.',
					[$this->megabytes(bytes: $size), $property, $this->megabytes(bytes: $limit)]
				)
			);
		}

		$type = (string)($file['type'] ?? 'application/octet-stream');
		$allowed = $this->rules->allowedTypes(fileConfig: $fileConfig);
		if ($allowed !== [] && in_array($type, $allowed, true) === false) {
			$this->refuse(
				property: $property,
				code: 'file-type-not-allowed',
				message: $this->l10n->t('"%1$s" takes these file types: %2$s.', [$property, implode(', ', $allowed)])
			);
		}

		$token = bin2hex(random_bytes(16));
		$expiresAt = $this->time->getTime() + self::TTL;
		$folder = $this->folder(create: true);
		$folder->newFile($token . '.bin', (string)file_get_contents($path));
		$folder->newFile(
			$token . '.json',
			(string)json_encode(
				[
					'formId' => $formId,
					'property' => $property,
					'name' => basename((string)($file['name'] ?? 'upload')),
					'type' => $type,
					'size' => $size,
					'expiresAt' => $expiresAt,
				]
			)
		);

		return ['token' => $token, 'expiresAt' => $expiresAt];
	}//end issue()

	/**
	 * A token as the upload array the save path reads, bytes in a temporary file.
	 *
	 * @param string $formId The form submitting it; a token of another form is unknown here.
	 * @param string $token  The token.
	 *
	 * @return array{property: string, name: string, type: string, tmp_name: string, error: int, size: int} The upload.
	 *
	 * @throws FormSubmitRefusedException 422 when the token is unknown, expired, claimed or another form's.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
	 */
	public function materialise(string $formId, string $token): array {
		$meta = $this->meta(token: $token);
		if ($meta === null || $meta['formId'] !== $formId || $meta['expiresAt'] <= $this->time->getTime()) {
			$message = $this->l10n->t('An uploaded file has expired or is unknown. Please add it again.');
			$this->refuse(property: '', code: 'upload-token-unknown', message: $message);
		}

		$path = $this->temp->getTemporaryFile();
		file_put_contents($path, $this->folder(create: true)->getFile($token . '.bin')->getContent());

		return [
			'property' => $meta['property'],
			'name' => $meta['name'],
			'type' => $meta['type'],
			'tmp_name' => $path,
			'error' => UPLOAD_ERR_OK,
			'size' => $meta['size'],
		];
	}//end materialise()

	/**
	 * Delete claimed tokens: their bytes now live on the destination object.
	 *
	 * @param array<int, string> $tokens The tokens.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
	 */
	public function claim(array $tokens): void {
		$folder = $this->folder(create: false);
		if ($folder === null) {
			return;
		}

		foreach ($tokens as $token) {
			$this->deleteToken(folder: $folder, token: $token);
		}
	}//end claim()

	/**
	 * Delete every expired, unclaimed token.
	 *
	 * @return int How many tokens were deleted, so a zero is a counted zero.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
	 */
	public function purge(): int {
		$folder = $this->folder(create: false);
		if ($folder === null) {
			return 0;
		}

		$now = $this->time->getTime();
		$deleted = 0;
		foreach ($folder->getDirectoryListing() as $file) {
			$name = $file->getName();
			if (str_ends_with($name, '.json') === false) {
				continue;
			}

			$token = substr($name, 0, -5);
			$meta = $this->meta(token: $token);
			if ($meta !== null && $meta['expiresAt'] > $now) {
				continue;
			}

			$this->deleteToken(folder: $folder, token: $token);
			$deleted++;
		}

		return $deleted;
	}//end purge()

	/**
	 * A token's record, or null when absent or unreadable.
	 *
	 * @param string $token The token.
	 *
	 * @return array{formId: string, property: string, name: string, type: string, size: int, expiresAt: int}|null The record.
	 */
	private function meta(string $token): ?array {
		if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
			return null;
		}

		$folder = $this->folder(create: false);
		if ($folder === null || $folder->fileExists($token . '.json') === false || $folder->fileExists($token . '.bin') === false) {
			return null;
		}

		$meta = json_decode($folder->getFile($token . '.json')->getContent(), true);
		if (is_array($meta) === false || is_int($meta['expiresAt'] ?? null) === false) {
			return null;
		}

		return [
			'formId' => (string)($meta['formId'] ?? ''),
			'property' => (string)($meta['property'] ?? ''),
			'name' => (string)($meta['name'] ?? 'upload'),
			'type' => (string)($meta['type'] ?? 'application/octet-stream'),
			'size' => (int)($meta['size'] ?? 0),
			'expiresAt' => $meta['expiresAt'],
		];
	}//end meta()

	/**
	 * Delete a token's two files, whichever exist.
	 *
	 * @param ISimpleFolder $folder The store folder.
	 * @param string        $token  The token.
	 *
	 * @return void
	 */
	private function deleteToken(ISimpleFolder $folder, string $token): void {
		foreach ([$token . '.bin', $token . '.json'] as $name) {
			if ($folder->fileExists($name) === true) {
				$folder->getFile($name)->delete();
			}
		}
	}//end deleteToken()

	/**
	 * Refuse with one finding.
	 *
	 * @param string $property The property.
	 * @param string $code     The code.
	 * @param string $message  The translated message.
	 *
	 * @return never
	 *
	 * @throws FormSubmitRefusedException Always, 422.
	 */
	private function refuse(string $property, string $code, string $message): never {
		throw new FormSubmitRefusedException(
			message: $message,
			status: 422,
			findings: [['property' => $property, 'code' => $code, 'message' => $message]]
		);
	}//end refuse()

	/**
	 * Bytes as megabytes with one decimal, for a message.
	 *
	 * @param int $bytes The size.
	 *
	 * @return string The size in MB.
	 */
	private function megabytes(int $bytes): string {
		return number_format($bytes / 1048576, 1);
	}//end megabytes()

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
