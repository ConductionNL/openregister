<?php

/**
 * FieldEncryptionHandlerInterface: what callers outside the component use of FieldEncryptionHandler.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Schema;
use OCP\Security\ICrypto;

/**
 * Declares only what callers outside the component use of FieldEncryptionHandler; the rest
 * of the class stays its own.
 */
interface FieldEncryptionHandlerInterface {

	/**
	 * Whether a value is an OpenRegister v1 encryption envelope.
	 *
	 * @param mixed $value The value to inspect.
	 *
	 * @return bool True when the value is a string carrying the envelope prefix.
	 */
	public function isEnvelope(mixed $value): bool;

	/**
	 * Encrypt a single plaintext value into its stored envelope form.
	 *
	 * @param string $plaintext The plaintext value.
	 *
	 * @return string The envelope-tagged ciphertext.
	 */
	public function encryptValue(string $plaintext): string;

	/**
	 * Encrypt every schema-flagged property present in the given data.
	 *
	 * Idempotent: a value that is already an envelope is left untouched, so
	 * calling this twice on the same data (e.g. a resave of an already-encrypted
	 * object, or a re-run of the migration command) never double-encrypts.
	 * Only string, non-empty values are encrypted — a null/empty value has
	 * nothing to protect and is passed through unchanged; a non-string value
	 * (array/object-typed property) is left as-is and logged, since ICrypto
	 * operates on strings (documented v1 limitation in design.md).
	 *
	 * @param array $data The object data about to be persisted.
	 * @param Schema $schema The object's schema (source of the encrypted-property list).
	 *
	 * @return array The data with flagged properties replaced by their envelope form.
	 *
	 * @spec openspec/specs/field-level-encryption/spec.md#requirement-flagged-properties-are-encrypted-on-save
	 */
	public function encryptProperties(array $data, Schema $schema): array;
}//end interface
