<?php

/**
 * OpenRegister BulkTranslationService
 *
 * Fills missing translation slots on register objects via a configured
 * `TranslationProviderInterface`. The service writes:
 *   1. The resulting translation onto the object's JSONB property
 *      (preserves the source-of-truth contract; sidecar projection
 *      rebuilds from there on the next save).
 *   2. The translation slot in the sidecar with status
 *      `machine_translated` and `translator = "provider:{identifier}"`.
 *
 * The actual saveObject path is left to the caller — this service is
 * a pure translation step that returns the patch the caller applies.
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
use OCA\OpenRegister\Service\Translation\TranslationProviderInterface;
use Psr\Log\LoggerInterface;

class BulkTranslationService
{
    /**
     * Constructor.
     *
     * @param TranslationProviderInterface $provider           The translation provider.
     * @param TranslationMapper            $translationMapper  The translation mapper.
     * @param TranslationHandler           $translationHandler The translation handler.
     * @param SchemaMapper                 $schemaMapper       The schema mapper.
     * @param LoggerInterface              $logger             The logger.
     */
    public function __construct(
        private readonly TranslationProviderInterface $provider,
        private readonly TranslationMapper $translationMapper,
        private readonly TranslationHandler $translationHandler,
        private readonly SchemaMapper $schemaMapper,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Translate an object's translatable properties from one language to another.
     *
     * Fills only slots that are currently empty in the target language.
     *
     * Returns a per-property patch:
     *   `[propertyName => translatedValue]`
     * The caller merges this into the object's `{lang: value}` JSONB
     * map for `$toLang` and persists via the standard save path —
     * which triggers the projection listener to populate the sidecar.
     *
     * Note: this method also writes directly to the sidecar so the
     * translation is queryable immediately, even before the caller
     * persists the object. Callers who don't want that immediate
     * write should invoke the provider directly.
     *
     * @param ObjectEntity  $object     The object to translate.
     * @param string        $fromLang   Source language code.
     * @param string        $toLang     Target language code.
     * @param string[]|null $properties Optional whitelist of property
     *                                  names to translate; null = all.
     *
     * @return array{translated: array<string, string>, skipped: array<string, string>}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function translateObject(
        ObjectEntity $object,
        string $fromLang,
        string $toLang,
        ?array $properties=null
    ): array {
        $translated = [];
        $skipped    = [];

        if ($fromLang === $toLang) {
            return ['translated' => [], 'skipped' => ['_global' => 'fromLang === toLang']];
        }

        $schema = $this->loadSchema(object: $object);
        if ($schema === null) {
            return ['translated' => [], 'skipped' => ['_global' => 'schema-not-resolvable']];
        }

        $translatableProps = $this->translationHandler->getTranslatableProperties($schema);
        if (count($translatableProps) === 0) {
            return ['translated' => [], 'skipped' => ['_global' => 'no-translatable-properties']];
        }

        $data       = (array) ($object->getObject() ?? []);
        $translator = 'provider:'.$this->provider->getIdentifier();

        foreach ($translatableProps as $property) {
            if (is_array($properties) === true && in_array($property, $properties, true) === false) {
                continue;
            }

            $existing = $data[$property] ?? null;

            // Source value lookup.
            $sourceValue = null;
            if (is_array($existing) === true && isset($existing[$fromLang]) === true) {
                $sourceValue = $existing[$fromLang];
            } else if (is_string($existing) === true && $fromLang === 'nl') {
                // Legacy single-language fallback — treat plain string as NL.
                $sourceValue = $existing;
            }

            if (is_string($sourceValue) === false || $sourceValue === '') {
                $skipped[$property] = 'no-source-value';
                continue;
            }

            // Don't overwrite an existing target translation. Skip if
            // the slot is already filled (any non-empty value, regardless
            // of status — promotion is the operator's job).
            if (is_array($existing) === true
                && isset($existing[$toLang]) === true
                && is_string($existing[$toLang]) === true
                && $existing[$toLang] !== ''
            ) {
                $skipped[$property] = 'target-slot-already-filled';
                continue;
            }

            try {
                $translatedValue = $this->provider->translate($sourceValue, $fromLang, $toLang);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    sprintf(
                        '[BulkTranslationService] provider %s failed for %s/%s -> %s: %s',
                        $this->provider->getIdentifier(),
                        $object->getUuid(),
                        $property,
                        $toLang,
                        $e->getMessage()
                    )
                );
                $skipped[$property] = 'provider-error: '.$e->getMessage();
                continue;
            }

            if (is_string($translatedValue) === false || $translatedValue === '') {
                $skipped[$property] = 'provider-returned-empty';
                continue;
            }

            $translated[$property] = $translatedValue;

            // Mirror into the sidecar immediately so search/completeness
            // queries see the new translation without waiting for the
            // caller to persist the object.
            try {
                $this->translationMapper->upsert(
                    objectUuid: (string) $object->getUuid(),
                    property: $property,
                    language: $toLang,
                    value: $translatedValue,
                    status: Translation::STATUS_MACHINE_TRANSLATED,
                    translator: $translator
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    sprintf(
                        '[BulkTranslationService] sidecar upsert failed for %s/%s/%s: %s',
                        $object->getUuid(),
                        $property,
                        $toLang,
                        $e->getMessage()
                    )
                );
            }
        }//end foreach

        return ['translated' => $translated, 'skipped' => $skipped];
    }//end translateObject()

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
}//end class
