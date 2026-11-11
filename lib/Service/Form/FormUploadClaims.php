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
	 * @param ITimeFactory       $time   The clock.
	 * @param ISimpleFolder|null $folder The store folder; null when the store holds nothing yet.
	 */
	public function __construct(
		private readonly ITimeFactory $time,
		private readonly ?ISimpleFolder $folder = null,
	) {
	}//end __construct()

	/**
	 * Write the record of a claimed token.
	 *
	 * @param string          $token The token.
	 * @param FormUploadClaim $claim The form, property and draft that claimed it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
	 */
	public function record(string $token, FormUploadClaim $claim): void {
		$this->folder?->newFile(
			$token . self::SUFFIX,
			(string)json_encode(
				[
					'formId' => $claim->formId,
					'property' => $claim->property,
					'draftId' => $claim->draftId,
					'expiresAt' => $this->time->getTime() + FormUploadStore::TTL,
				]
			)
		);
	}//end record()

	/**
	 * Whether a token was already claimed by this draft for this property of this form, and the record is still live.
	 *
	 * @param string          $token The token.
	 * @param FormUploadClaim $claim The form, property and draft; a claim of no draft claims nothing.
	 *
	 * @return bool True when the draft already holds the file this token named.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
	 */
	public function isClaimedBy(string $token, FormUploadClaim $claim): bool {
		if ($claim->draftId === null || $this->folder === null) {
			return false;
		}

		$record = $this->recordOf(folder: $this->folder, token: $token);

		return $record !== null && $this->matches(record: $record, claim: $claim);
	}//end isClaimedBy()

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
	 * @param array<mixed>    $record The record.
	 * @param FormUploadClaim $claim  The form, property and draft it must name.
	 *
	 * @return bool True when every value matches and the record has not expired.
	 */
	private function matches(array $record, FormUploadClaim $claim): bool {
		if ($claim->isNamedBy(record: $record) === false) {
			return false;
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
