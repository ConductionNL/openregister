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
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IL10N;
use OCP\ITempManager;

/**
 * Issue, materialise, claim and purge upload tokens.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
 */
class FormUploadStore implements FormUploadStoreInterface {
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
	public const FOLDER = 'form-uploads';

	/**
	 * How long an unclaimed token lives, in seconds.
	 *
	 * @var int
	 */
	public const TTL = 86400;

	/**
	 * The size cap, in bytes, of a file property that declares none (20 MiB).
	 *
	 * The upload endpoint is a public page, so "no `maxSize`" must not mean
	 * "no limit": it would store whatever PHP lets through for anyone. A
	 * property that declares a limit, larger or smaller, keeps it.
	 *
	 * @var int
	 */
	public const DEFAULT_MAX_BYTES = 20971520;

	/**
	 * Constructor.
	 *
	 * @param FormFileStorage   $storage The app's data store and the clock.
	 * @param ITempManager      $temp    Temporary files the save path reads a claimed upload from.
	 * @param FilePropertyRules $rules   The file property's size and type rules.
	 * @param IL10N             $l10n    Translations, for refusals a resident reads.
	 */
	public function __construct(
		FormFileStorage $storage,
		private readonly ITempManager $temp,
		private readonly FilePropertyRules $rules,
		private readonly IL10N $l10n,
	) {
		$this->appData = $storage->appData;
		$this->time    = $storage->time;

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
		return $this->issueFor(target: new FormUploadTarget(formId: $formId, property: $property, rule: $rule), file: $file);
	}//end issue()

	/**
	 * Hold one uploaded file for its target property and return its token.
	 *
	 * @param FormUploadTarget                                                               $target The form, property and that property's rules.
	 * @param array{name?: mixed, type?: mixed, tmp_name?: mixed, error?: mixed, size?: mixed} $file   The PHP upload array.
	 *
	 * @return array{token: string, expiresAt: int} The token and its expiry (unix seconds).
	 *
	 * @throws FormSubmitRefusedException 422 when the file breaks the property's rules or did not arrive.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
	 */
	public function issueFor(FormUploadTarget $target, array $file): array {
		$fileConfig = $this->fileConfigFor(property: $target->property, rule: $target->rule);
		$path = $this->arrivedPath(property: $target->property, file: $file);
		$meta = [
			'formId' => $target->formId,
			'property' => $target->property,
			'name' => basename((string)($file['name'] ?? 'upload')),
			'type' => (string)($file['type'] ?? 'application/octet-stream'),
			'size' => (int)($file['size'] ?? filesize($path)),
		];
		$this->assertSize(meta: $meta, fileConfig: $fileConfig);
		$this->assertType(meta: $meta, fileConfig: $fileConfig);

		return $this->hold(path: $path, meta: $meta);
	}//end issueFor()

	/**
	 * The file rules of the property an upload is for.
	 *
	 * @param string               $property The destination property.
	 * @param array<string, mixed> $rule     That property's schema definition.
	 *
	 * @return array<string, mixed> The file rules.
	 *
	 * @throws FormSubmitRefusedException 422 when the property holds no files.
	 */
	private function fileConfigFor(string $property, array $rule): array {
		$fileConfig = $this->rules->fileConfigOf(property: $rule);
		if ($fileConfig === null) {
			$message = $this->l10n->t('"%1$s" does not hold files.', [$property]);
			$this->refuse(finding: new FormFinding(property: $property, code: 'property-holds-no-files', message: $message));
		}

		return $fileConfig;
	}//end fileConfigFor()

	/**
	 * The path of an upload that arrived.
	 *
	 * @param string                                                                         $property The destination property.
	 * @param array{name?: mixed, type?: mixed, tmp_name?: mixed, error?: mixed, size?: mixed} $file     The PHP upload array.
	 *
	 * @return string The readable temporary path.
	 *
	 * @throws FormSubmitRefusedException 422 when the file did not arrive.
	 */
	private function arrivedPath(string $property, array $file): string {
		$path = (string)($file['tmp_name'] ?? '');
		if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || is_readable($path) === false) {
			$message = $this->l10n->t('The file did not arrive. Please try again.');
			$this->refuse(finding: new FormFinding(property: $property, code: 'upload-failed', message: $message));
		}

		return $path;
	}//end arrivedPath()

	/**
	 * Refuse a file larger than the property's limit, or than the default cap when it declares none.
	 *
	 * @param array{property: string, size: int} $meta       The upload's record.
	 * @param array<string, mixed>               $fileConfig The property's file rules.
	 *
	 * @return void
	 *
	 * @throws FormSubmitRefusedException 422 when the file is too large.
	 */
	private function assertSize(array $meta, array $fileConfig): void {
		$limit = $this->rules->maxSizeBytes(fileConfig: $fileConfig);
		if ($limit <= 0) {
			$limit = self::DEFAULT_MAX_BYTES;
		}

		if ($meta['size'] > $limit) {
			$message = $this->l10n->t(
				'The file is %1$s MB; "%2$s" takes at most %3$s MB.',
				[$this->megabytes(bytes: $meta['size']), $meta['property'], $this->megabytes(bytes: $limit)]
			);
			$this->refuse(finding: new FormFinding(property: $meta['property'], code: 'file-too-large', message: $message));
		}
	}//end assertSize()

	/**
	 * Refuse a file of a type the property does not take.
	 *
	 * @param array{property: string, type: string} $meta       The upload's record.
	 * @param array<string, mixed>                  $fileConfig The property's file rules.
	 *
	 * @return void
	 *
	 * @throws FormSubmitRefusedException 422 when the type is not allowed.
	 */
	private function assertType(array $meta, array $fileConfig): void {
		$allowed = $this->rules->allowedTypes(fileConfig: $fileConfig);
		if ($allowed !== [] && in_array($meta['type'], $allowed, true) === false) {
			$message = $this->l10n->t('"%1$s" takes these file types: %2$s.', [$meta['property'], implode(', ', $allowed)]);
			$this->refuse(finding: new FormFinding(property: $meta['property'], code: 'file-type-not-allowed', message: $message));
		}
	}//end assertType()

	/**
	 * Store an accepted upload's bytes and record under a fresh token.
	 *
	 * @param string               $path The upload's temporary path.
	 * @param array<string, mixed> $meta The record: formId, property, name, type, size.
	 *
	 * @return array{token: string, expiresAt: int} The token and its expiry (unix seconds).
	 */
	private function hold(string $path, array $meta): array {
		$token = bin2hex(random_bytes(16));
		$meta['expiresAt'] = $this->time->getTime() + self::TTL;
		$folder = $this->folder(create: true);
		$folder->newFile($token . '.bin', (string)file_get_contents($path));
		$folder->newFile($token . '.json', (string)json_encode($meta));

		return ['token' => $token, 'expiresAt' => $meta['expiresAt']];
	}//end hold()

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
		$meta = $this->liveMeta(formId: $formId, token: $token);
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
	 * A token's record, when it is this form's and has not expired.
	 *
	 * @param string $formId The form submitting it.
	 * @param string $token  The token.
	 *
	 * @return array{formId: string, property: string, name: string, type: string, size: int, expiresAt: int} The record.
	 *
	 * @throws FormSubmitRefusedException 422 when the token is unknown, expired, claimed or another form's.
	 */
	private function liveMeta(string $formId, string $token): array {
		$meta = $this->meta(token: $token);
		if ($meta === null || $meta['formId'] !== $formId || $meta['expiresAt'] <= $this->time->getTime()) {
			$message = $this->l10n->t('An uploaded file has expired or is unknown. Please add it again.');
			$this->refuse(finding: new FormFinding(property: '', code: 'upload-token-unknown', message: $message));
		}

		return $meta;
	}//end liveMeta()

	/**
	 * Delete claimed tokens: their bytes now live on the destination object.
	 *
	 * A token claimed for a draft leaves a small record behind (which form,
	 * which property, which draft, until when), so the next autosave or the
	 * final submit that still carries the same token is recognised as the
	 * draft's own and not refused as unknown (see claimedBy()).
	 *
	 * @param array<int, string> $tokens  The tokens.
	 * @param string|null        $draftId The draft the files now sit on; null for a final submit, which leaves no record.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
	 */
	public function claim(array $tokens, ?string $draftId = null): void {
		$folder = $this->folder(create: false);
		if ($folder === null) {
			return;
		}

		$claims = new FormUploadClaims(time: $this->time, folder: $folder);
		foreach ($tokens as $token) {
			$meta = $this->meta(token: $token);
			$this->deleteToken(folder: $folder, token: $token);
			if ($draftId !== null && $meta !== null) {
				$claims->record(token: $token, claim: new FormUploadClaim(formId: $meta['formId'], property: $meta['property'], draftId: $draftId));
			}
		}
	}//end claim()

	/**
	 * A token as the upload for the property a payload put it on, or null when the draft already holds that file.
	 *
	 * @param string      $formId   The form submitting it.
	 * @param string      $property The destination property the payload put the token on.
	 * @param string      $token    The token.
	 * @param string|null $draftId  The draft being saved or left; a token it already claimed is satisfied.
	 *
	 * @return array{name: string, type: string, tmp_name: string, error: int, size: int}|null The upload, null when satisfied.
	 *
	 * @throws FormSubmitRefusedException 422 when the token is unknown, expired, another form's or issued for another property.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
	 */
	public function redeem(string $formId, string $property, string $token, ?string $draftId = null): ?array {
		return $this->redeemClaim(claim: new FormUploadClaim(formId: $formId, property: $property, draftId: $draftId), token: $token);
	}//end redeem()

	/**
	 * A token as the upload for the claim's property, or null when the claim's draft already holds that file.
	 *
	 * @param FormUploadClaim $claim The form, destination property and draft redeeming it.
	 * @param string          $token The token.
	 *
	 * @return array{name: string, type: string, tmp_name: string, error: int, size: int}|null The upload, null when satisfied.
	 *
	 * @throws FormSubmitRefusedException 422 when the token is unknown, expired, another form's or issued for another property.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
	 */
	public function redeemClaim(FormUploadClaim $claim, string $token): ?array {
		$claims = new FormUploadClaims(time: $this->time, folder: $this->folder(create: false));
		if ($claims->isClaimedBy(token: $token, claim: $claim) === true) {
			return null;
		}

		return $this->forProperty(file: $this->materialise(formId: $claim->formId, token: $token), property: $claim->property);
	}//end redeemClaim()

	/**
	 * A materialised upload for the property it was issued for, without that property.
	 *
	 * @param array{property: string, name: string, type: string, tmp_name: string, error: int, size: int} $file     The upload.
	 * @param string                                                                                       $property The token's property.
	 *
	 * @return array{name: string, type: string, tmp_name: string, error: int, size: int} The upload.
	 *
	 * @throws FormSubmitRefusedException 422 when it was issued for another property.
	 */
	private function forProperty(array $file, string $property): array {
		if ($file['property'] !== $property) {
			$message = $this->l10n->t('An uploaded file was sent for another question. Please add it again.');
			$this->refuse(finding: new FormFinding(property: $property, code: 'upload-token-unknown', message: $message));
		}

		unset($file['property']);

		return $file;
	}//end forProperty()

	/**
	 * Claim every token the plans of a submit or an autosave hold.
	 *
	 * @param array<int, array<string, mixed>> $plans   The plans; each carries its `tokens`.
	 * @param string|null                      $draftId The draft the files now sit on; null for a final submit.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
	 */
	public function claimPlanned(array $plans, ?string $draftId = null): void {
		$this->claim(tokens: array_merge([], ...array_map(static fn (array $plan): array => $plan['tokens'], $plans)), draftId: $draftId);
	}//end claimPlanned()

	/**
	 * Delete every expired, unclaimed token, and the bytes of an upload whose record was never written.
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

		return $this->purgeFolder(run: new FormUploadPurgeRun(folder: $folder, now: $this->time->getTime()));
	}//end purge()

	/**
	 * Purge the store folder, file by file in listing order.
	 *
	 * @param FormUploadPurgeRun $run The purge.
	 *
	 * @return int How many files were deleted.
	 */
	private function purgeFolder(FormUploadPurgeRun $run): int {
		$deleted = 0;
		foreach ($run->folder->getDirectoryListing() as $file) {
			[$count, $token] = $this->purgeFile(run: $run, file: $file);
			$deleted += $count;
			if ($token !== null) {
				$run->gone[$token] = true;
			}
		}

		return $deleted;
	}//end purgeFolder()

	/**
	 * Purge one file of the store: an expired record with its bytes, an expired claim, or orphaned bytes.
	 *
	 * @param FormUploadPurgeRun $run  The purge.
	 * @param ISimpleFile        $file A file of the folder.
	 *
	 * @return array{0: int, 1: string|null} How many were deleted, and the token deleted with its record.
	 */
	private function purgeFile(FormUploadPurgeRun $run, ISimpleFile $file): array {
		$name = $file->getName();
		if (str_ends_with($name, '.json') === true) {
			return $this->purgeRecord(run: $run, token: substr($name, 0, -5));
		}

		if (str_ends_with($name, FormUploadClaims::SUFFIX) === true) {
			return [(new FormUploadClaims(time: $this->time))->purgeIfExpired(file: $file), null];
		}

		if ($this->isOrphanedBytes(run: $run, file: $file) === true) {
			$file->delete();

			return [1, null];
		}

		return [0, null];
	}//end purgeFile()

	/**
	 * Delete a token whose record expired or cannot be read.
	 *
	 * @param FormUploadPurgeRun $run   The purge.
	 * @param string             $token The token.
	 *
	 * @return array{0: int, 1: string|null} 1 and the token when deleted, else 0 and null.
	 */
	private function purgeRecord(FormUploadPurgeRun $run, string $token): array {
		$meta = $this->meta(token: $token);
		if ($meta !== null && $meta['expiresAt'] > $run->now) {
			return [0, null];
		}

		$this->deleteToken(folder: $run->folder, token: $token);

		return [1, $token];
	}//end purgeRecord()

	/**
	 * Whether a file is the bytes of an upload whose record was never written, and old enough not to be in flight.
	 * The bytes are written first, so a failed second write leaves a `.bin`
	 * issue() writes the bytes first, so a failed second write leaves a `.bin`
	 * nothing else lists. One younger than a token's lifetime may still be an
	 * upload in flight, and is left alone.
	 *
	 * @param FormUploadPurgeRun $run  The purge, with the tokens it already deleted.
	 * @param ISimpleFile        $file A file of the folder.
	 *
	 * @return bool True when the purge deletes it.
	 */
	private function isOrphanedBytes(FormUploadPurgeRun $run, ISimpleFile $file): bool {
		$name = $file->getName();
		if (str_ends_with($name, '.bin') === false) {
			return false;
		}

		$token = substr($name, 0, -4);

		return isset($run->gone[$token]) === false
			&& $run->folder->fileExists($token . '.json') === false
			&& ($file->getMTime() + self::TTL) <= $run->now;
	}//end isOrphanedBytes()

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
		if ($folder === null || $this->holds(folder: $folder, token: $token) === false) {
			return null;
		}

		return $this->recordOf(json: $folder->getFile($token . '.json')->getContent());
	}//end meta()

	/**
	 * Whether the folder holds both files of a token: its record and its bytes.
	 *
	 * @param ISimpleFolder $folder The store folder.
	 * @param string        $token  The token.
	 *
	 * @return bool True when both exist.
	 */
	private function holds(ISimpleFolder $folder, string $token): bool {
		return $folder->fileExists($token . '.json') === true && $folder->fileExists($token . '.bin') === true;
	}//end holds()

	/**
	 * A stored record, typed, or null when it is not one.
	 *
	 * @param string $json The record file's content.
	 *
	 * @return array{formId: string, property: string, name: string, type: string, size: int, expiresAt: int}|null The record.
	 */
	private function recordOf(string $json): ?array {
		$meta = json_decode($json, true);
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
	}//end recordOf()

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
	 * @param FormFinding $finding The finding.
	 *
	 * @return never
	 *
	 * @throws FormSubmitRefusedException Always, 422.
	 */
	private function refuse(FormFinding $finding): never {
		throw new FormSubmitRefusedException(
			message: $finding->message,
			status: 422,
			findings: [$finding->toArray()]
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
}//end class
