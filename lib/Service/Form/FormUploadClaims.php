<?php

/**
 * The record an upload token leaves when a draft claims it.
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

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * Written when a draft claims a token, read when the same draft sends the token again, purged when it expires.
 *
 * It holds which form, which property and which draft, for as long as the
 * token itself lived, so a later autosave or the final submit of THAT draft
 * that still carries the token is satisfied by the file the draft already
 * holds. A token claimed by another draft, for another property or form, or
 * expired, is not.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
 */
class FormUploadClaims {

	/**
	 * The suffix of the record file next to a token's name.
	 *
	 * @var string
	 */
	public const SUFFIX = '.claimed';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The clock.
	 */
	public function __construct(
		private readonly ITimeFactory $time,
	) {

	}//end __construct()

	/**
	 * Write the record of a claimed token.
	 *
	 * @param ISimpleFolder        $folder  The store folder.
	 * @param string               $token   The token.
	 * @param array<string, mixed> $meta    The token's record (formId, property).
	 * @param string               $draftId The draft that claimed it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
	 */
	public function record(ISimpleFolder $folder, string $token, array $meta, string $draftId): void {
		$folder->newFile(
			$token . self::SUFFIX,
			(string)json_encode(
				[
					'formId' => $meta['formId'] ?? '',
					'property' => $meta['property'] ?? '',
					'draftId' => $draftId,
					'expiresAt' => $this->time->getTime() + FormUploadStore::TTL,
				]
			)
		);
	}//end record()

	/**
	 * Whether a token was already claimed by this draft for this property of this form, and the record is still live.
	 *
	 * @param ISimpleFolder|null $folder   The store folder; null when it does not exist yet.
	 * @param string             $formId   The form submitting it.
	 * @param string             $token    The token.
	 * @param string             $property The destination property the payload put it on.
	 * @param string|null        $draftId  The draft being saved or left; null (a final submit of no draft) claims nothing.
	 *
	 * @return bool True when the draft already holds the file this token named.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
	 */
	public function isClaimedBy(?ISimpleFolder $folder, string $formId, string $token, string $property, ?string $draftId): bool {
		if ($draftId === null || $folder === null) {
			return false;
		}

		return $this->recordMatches(folder: $folder, token: $token, expected: ['formId' => $formId, 'property' => $property, 'draftId' => $draftId]);
	}//end isClaimedBy()

	/**
	 * Whether a token's claim record exists and names exactly the expected form, property and draft.
	 *
	 * @param ISimpleFolder         $folder   The store folder.
	 * @param string                $token    The token.
	 * @param array<string, string> $expected Key => the value the record must hold.
	 *
	 * @return bool True when the record matches and is still live.
	 */
	private function recordMatches(ISimpleFolder $folder, string $token, array $expected): bool {
		$record = $this->recordOf(folder: $folder, token: $token);
		if ($record === null) {
			return false;
		}

		return $this->matches(record: $record, expected: $expected);
	}//end recordMatches()

	/**
	 * The claim record of a token, or null when the token is malformed, unclaimed or the record unreadable.
	 *
	 * @param ISimpleFolder $folder The store folder.
	 * @param string        $token  The token.
	 *
	 * @return array<mixed>|null The record.
	 */
	private function recordOf(ISimpleFolder $folder, string $token): ?array {
		if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1 || $folder->fileExists($token . self::SUFFIX) === false) {
			return null;
		}

		$record = json_decode($folder->getFile($token . self::SUFFIX)->getContent(), true);
		if (is_array($record) === false) {
			return null;
		}

		return $record;
	}//end recordOf()

	/**
	 * Whether a claim record names exactly the expected form, property and draft, and is still live.
	 *
	 * @param array<mixed>          $record   The record.
	 * @param array<string, string> $expected Key => the value the record must hold.
	 *
	 * @return bool True when every value matches and the record has not expired.
	 */
	private function matches(array $record, array $expected): bool {
		foreach ($expected as $key => $value) {
			if (($record[$key] ?? null) !== $value) {
				return false;
			}
		}

		return (int)($record['expiresAt'] ?? 0) > $this->time->getTime();
	}//end matches()

	/**
	 * Delete a record file that is expired or unreadable.
	 *
	 * @param ISimpleFile $file The record file.
	 *
	 * @return int 1 when the file was deleted, else 0.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
	 */
	public function purgeIfExpired(ISimpleFile $file): int {
		$record = json_decode($file->getContent(), true);
		if (is_array($record) === true && (int)($record['expiresAt'] ?? 0) > $this->time->getTime()) {
			return 0;
		}

		$file->delete();

		return 1;
	}//end purgeIfExpired()
}//end class
