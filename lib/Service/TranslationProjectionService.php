<?php

/**
 * OpenRegister TranslationProjectionService
 *
 * Keeps the `openregister_translations` sidecar in sync with the
 * authoritative JSONB property data on each object. Called by
 * `TranslationProjectionListener` on object create / update / delete.
 *
 * The JSONB on the object remains the source of truth; the sidecar
 * is a derived projection optimised for per-language search,
 * completeness queries, and workflow status tracking.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Translation;
use OCA\OpenRegister\Db\TranslationMapper;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class TranslationProjectionService
{
    /**
     * Constructor.
     *
     * @param TranslationMapper  $translationMapper  The translation mapper.
     * @param TranslationHandler $translationHandler The translation handler.
     * @param SchemaMapper       $schemaMapper       The schema mapper.
     * @param IUserSession       $userSession        The user session.
     * @param LoggerInterface    $logger             The logger.
     */
    public function __construct(
        private readonly TranslationMapper $translationMapper,
        private readonly TranslationHandler $translationHandler,
        private readonly SchemaMapper $schemaMapper,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Project the given object's translatable property data into the sidecar.
     *
     * Behaviour:
     *  - For each translatable property declared on the schema, walk
     *    the object's data shape `{lang: value, ...}` (or fall back to
     *    a single-language string treated as the register's default).
     *  - Upsert one row per (uuid, property, language) that has a
     *    non-empty value.
     *  - Delete any pre-existing row whose (property, language) no
     *    longer has a value on the object.
     *
     * Status defaults to `draft` on first projection; subsequent saves
     * preserve whatever status the slot already had (the projection
     * doesn't second-guess workflow state — that's `TranslationStatusService`'s job).
     *
     * @param ObjectEntity $object The object to project.
     *
     * @return void
     */
    public function project(ObjectEntity $object): void
    {
        $uuid = $object->getUuid();
        if ($uuid === null || $uuid === '') {
            return;
        }

        try {
            $schema = $this->loadSchema(object: $object);
            if ($schema === null) {
                return;
            }

            $translatableProps = $this->translationHandler->getTranslatableProperties($schema);
            if (count($translatableProps) === 0) {
                // Schema has no translatable properties; make sure no
                // stale rows remain (e.g. property used to be translatable).
                $existing = $this->translationMapper->findByObject($uuid);
                foreach ($existing as $row) {
                    if (in_array((string) $row->getProperty(), $translatableProps, true) === false) {
                        try {
                            $this->translationMapper->delete($row);
                        } catch (\Throwable $e) {
                            // Best effort.
                        }
                    }
                }

                return;
            }

            $data       = (array) ($object->getObject() ?? []);
            $translator = $this->userSession->getUser()?->getUID();

            // Build the desired set of (property, language, value) tuples.
            $desired = [];
            foreach ($translatableProps as $property) {
                $value = $data[$property] ?? null;

                if (is_array($value) === true) {
                    // Language-keyed shape: {nl: "...", en: "..."}.
                    foreach ($value as $lang => $langValue) {
                        if (is_string($lang) === false || $lang === '') {
                            continue;
                        }

                        $stringValue = $this->valueToString(value: $langValue);
                        if ($stringValue !== null && $stringValue !== '') {
                            $desired[$property][$lang] = $stringValue;
                        }
                    }
                } else if (is_string($value) === true && $value !== '') {
                    // Legacy single-language shape; credit the register's default language.
                    // We don't have register here without an extra mapper hop; the projection
                    // can be re-run after register language config changes if needed.
                    $defaultLang = 'nl';
                    $desired[$property][$defaultLang] = $value;
                }
            }//end foreach

            // Upsert every desired slot.
            $upsertedKeys = [];
            foreach ($desired as $property => $byLang) {
                foreach ($byLang as $lang => $stringValue) {
                    $this->translationMapper->upsert(
                        objectUuid: $uuid,
                        property: $property,
                        language: $lang,
                        value: $stringValue,
                        status: null,
                    // Preserve existing or default to draft on insert.
                        translator: $translator
                    );
                    $upsertedKeys[] = $property.'|'.$lang;
                }
            }

            // Delete rows that no longer have a corresponding desired slot.
            $existing = $this->translationMapper->findByObject($uuid);
            foreach ($existing as $row) {
                $key = $row->getProperty().'|'.$row->getLanguage();
                if (in_array($key, $upsertedKeys, true) === false) {
                    try {
                        $this->translationMapper->delete($row);
                    } catch (\Throwable $e) {
                        // Best effort.
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('[TranslationProjection] failed for object %s: %s', $uuid, $e->getMessage())
            );
        }//end try
    }//end project()

    /**
     * Drop every translation row for the given object.
     *
     * @param ObjectEntity $object The object to purge translations for.
     *
     * @return void
     */
    public function purge(ObjectEntity $object): void
    {
        $uuid = $object->getUuid();
        if ($uuid === null || $uuid === '') {
            return;
        }

        try {
            $this->translationMapper->deleteByObject($uuid);
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('[TranslationProjection] purge failed for object %s: %s', $uuid, $e->getMessage())
            );
        }
    }//end purge()

    /**
     * Resolve a schema entity from an object's schema reference.
     *
     * @param ObjectEntity $object The object whose schema reference should be resolved.
     *
     * @return Schema|null The resolved schema, or null when not resolvable.
     */
    private function loadSchema(ObjectEntity $object): ?Schema
    {
        $ref = $object->getSchema();
        if ($ref === null || $ref === '') {
            return null;
        }

        try {
            return $this->schemaMapper->find($ref, _rbac: false, _multitenancy: false);
        } catch (\Throwable $e) {
            return null;
        }
    }//end loadSchema()

    /**
     * Coerce a translatable value to a string for storage.
     *
     * Most properties are strings; we accept scalar / null / arrays-of-strings and return
     * either a string or null.
     *
     * @param mixed $value The value to coerce.
     *
     * @return string|null The coerced string, or null when not coercible.
     */
    private function valueToString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) === true) {
            return $value;
        }

        if (is_scalar($value) === true) {
            return (string) $value;
        }

        if (is_array($value) === true) {
            // Translatable-array property (rare); flatten to JSON for searchability.
            return json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        return null;
    }//end valueToString()
}//end class
