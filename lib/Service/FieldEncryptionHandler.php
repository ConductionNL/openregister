<?php

/**
 * OpenRegister field-level encryption handler
 *
 * Encrypts and decrypts individual object property values flagged
 * `x-openregister-encrypted: true` in their schema definition. This is the
 * data-platform primitive that lets any app store municipal case data
 * (BSN, medical, financial) at rest without reimplementing crypto per app.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/field-level-object-encryption/specs/field-level-encryption/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\FieldDecryptionException;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Encrypts/decrypts schema-flagged object properties at the save/render boundary.
 *
 * Reuses Nextcloud's `OCP\Security\ICrypto` (AES-256-GCM + HMAC, keyed off the
 * instance secret in config.php) — the exact same primitive OpenRegister already
 * uses for source credentials ({@see \OCA\OpenRegister\Controller\SourcesController})
 * and the tenant audit-trail HMAC key ({@see TenantKeyService}). No new crypto is
 * introduced; key material is never handled directly by this class.
 *
 * Envelope format: every encrypted value is stored as
 * `ENVELOPE_PREFIX . ICrypto::encrypt($plaintext)`. The prefix disambiguates
 * ciphertext from plaintext during a mixed rollout (a field flagged encrypted
 * "today" may still hold plaintext from before the flag was set, until the
 * migration command — {@see \OCA\OpenRegister\Command\EncryptFieldCommand} —
 * or a subsequent save re-encrypts it) and lets decryption fail fast on a
 * value that was never encrypted rather than passing garbage to ICrypto.
 *
 * @package OCA\OpenRegister\Service
 *
 * @psalm-suppress UnusedClass
 */
class FieldEncryptionHandler
{
    /**
     * Prefix that marks a stored value as an OpenRegister encryption envelope.
     *
     * Versioned so a future envelope format change (e.g. a different AEAD
     * construction) can be introduced without breaking values already encrypted
     * under v1 — decryption dispatches on the version segment.
     *
     * @var string
     */
    public const ENVELOPE_PREFIX = 'openregister:enc:v1:';

    /**
     * Constructor.
     *
     * @param ICrypto         $crypto Nextcloud crypto service (encrypt/decrypt at rest).
     * @param LoggerInterface $logger PSR logger.
     */
    public function __construct(
        private readonly ICrypto $crypto,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Whether a value is an OpenRegister v1 encryption envelope.
     *
     * @param mixed $value The value to inspect.
     *
     * @return bool True when the value is a string carrying the envelope prefix.
     */
    public function isEnvelope(mixed $value): bool
    {
        return is_string($value) === true && str_starts_with($value, self::ENVELOPE_PREFIX) === true;
    }//end isEnvelope()

    /**
     * Encrypt a single plaintext value into its stored envelope form.
     *
     * @param string $plaintext The plaintext value.
     *
     * @return string The envelope-tagged ciphertext.
     */
    public function encryptValue(string $plaintext): string
    {
        return self::ENVELOPE_PREFIX.$this->crypto->encrypt($plaintext);
    }//end encryptValue()

    /**
     * Decrypt a single envelope value back to plaintext.
     *
     * @param string $envelope The envelope-tagged ciphertext.
     *
     * @return string The decrypted plaintext.
     *
     * @throws FieldDecryptionException When the value is not a recognised
     *                                  envelope, or ICrypto fails to decrypt it
     *                                  (wrong/rotated instance secret, corrupted
     *                                  ciphertext). Never swallowed — the fleet
     *                                  lesson is that a swallowed catch here
     *                                  looks like a healthy app with a dead
     *                                  feature (silent data loss).
     */
    public function decryptValue(string $envelope): string
    {
        if ($this->isEnvelope(value: $envelope) === false) {
            throw new FieldDecryptionException(
                message: 'Value is not an OpenRegister v1 encryption envelope; cannot decrypt.'
            );
        }

        $ciphertext = substr($envelope, strlen(self::ENVELOPE_PREFIX));

        try {
            return $this->crypto->decrypt($ciphertext);
        } catch (\Throwable $e) {
            throw new FieldDecryptionException(
                message: 'Failed to decrypt field value: '.$e->getMessage(),
                previous: $e
            );
        }
    }//end decryptValue()

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
     * @param array  $data   The object data about to be persisted.
     * @param Schema $schema The object's schema (source of the encrypted-property list).
     *
     * @return array The data with flagged properties replaced by their envelope form.
     *
     * @spec openspec/changes/field-level-object-encryption/specs/field-level-encryption/spec.md#requirement-flagged-properties-are-encrypted-on-save
     */
    public function encryptProperties(array $data, Schema $schema): array
    {
        if ($schema->hasEncryptedProperties() === false) {
            return $data;
        }

        foreach ($schema->getEncryptedProperties() as $property) {
            if (array_key_exists($property, $data) === false) {
                continue;
            }

            $value = $data[$property];

            if ($value === null || $value === '') {
                continue;
            }

            if (is_string($value) === false) {
                $this->logger->warning(
                    message: '[FieldEncryptionHandler] Skipped non-string value for encrypted property',
                    context: [
                        'file'     => __FILE__,
                        'line'     => __LINE__,
                        'property' => $property,
                        'type'     => gettype($value),
                    ]
                );
                continue;
            }

            if ($this->isEnvelope(value: $value) === true) {
                // Already encrypted — idempotent no-op.
                continue;
            }

            $data[$property] = $this->encryptValue(plaintext: $value);
        }//end foreach

        return $data;
    }//end encryptProperties()

    /**
     * Decrypt every schema-flagged property present in the given data.
     *
     * Only decrypts properties that are STILL PRESENT in `$data`. This is the
     * composition point with RBAC/writeOnly redaction: the caller (RenderObject)
     * runs this AFTER stripping fields the caller is not authorised to see, so
     * an unauthorized reader never reaches this method for a field it should
     * not have — it gets the same absent-field redaction every other property
     * gets, never ciphertext, never plaintext.
     *
     * A value that is not (yet) an envelope is returned unchanged — the mixed
     * plaintext/ciphertext rollout state described in design.md. A value that
     * IS an envelope but fails to decrypt (missing/rotated instance secret,
     * corrupted data) never fails silently: by default it is replaced with a
     * structured error marker and logged at ERROR (so a single bad row does not
     * 500 an entire list endpoint); pass `$throwOnFailure: true` for contexts
     * that must fail loud instead (the migration command, single-object
     * integrity checks).
     *
     * @param array  $data           The rendered object data.
     * @param Schema $schema         The object's schema.
     * @param bool   $throwOnFailure When true, rethrow decryption failures instead
     *                               of substituting an error marker.
     *
     * @return array The data with flagged properties decrypted where possible.
     *
     * @throws FieldDecryptionException When `$throwOnFailure` is true and decryption fails.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag selects fail-loud vs
     *                                              fail-marked disposition, matching the
     *                                              established _rbac/_multitenancy flag idiom.
     *
     * @spec openspec/changes/field-level-object-encryption/specs/field-level-encryption/spec.md#requirement-authorized-reads-are-decrypted-unauthorized-reads-never-see-ciphertext
     */
    public function decryptProperties(array $data, Schema $schema, bool $throwOnFailure=false): array
    {
        if ($schema->hasEncryptedProperties() === false) {
            return $data;
        }

        foreach ($schema->getEncryptedProperties() as $property) {
            if (array_key_exists($property, $data) === false) {
                continue;
            }

            $value = $data[$property];

            if (is_string($value) === false || $this->isEnvelope(value: $value) === false) {
                // Not encrypted yet (mixed rollout state) or not a string — pass through.
                continue;
            }

            try {
                $data[$property] = $this->decryptValue(envelope: $value);
            } catch (FieldDecryptionException $e) {
                $this->logger->error(
                    message: '[FieldEncryptionHandler] Decryption failed for property',
                    context: [
                        'file'     => __FILE__,
                        'line'     => __LINE__,
                        'property' => $property,
                        'schema'   => $schema->getId(),
                        'error'    => $e->getMessage(),
                    ]
                );

                if ($throwOnFailure === true) {
                    throw $e;
                }

                $data[$property] = [
                    '@openregister_decryption_error' => true,
                    'message'                        => 'This field could not be decrypted (key unavailable or rotated).',
                ];
            }//end try
        }//end foreach

        return $data;
    }//end decryptProperties()
}//end class
