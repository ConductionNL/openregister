<?php

/**
 * OpenRegister TranslationStatusService
 *
 * Public API for the translation sidecar: status updates,
 * per-object completeness queries, search, and bulk discovery
 * ("find objects missing X translation").
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Translation;
use OCA\OpenRegister\Db\TranslationMapper;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCP\IUserSession;

class TranslationStatusService
{
    /**
     * Constructor.
     *
     * @param TranslationMapper  $translationMapper  The translation mapper.
     * @param TranslationHandler $translationHandler The translation handler.
     * @param IUserSession       $userSession        The user session.
     */
    public function __construct(
        private readonly TranslationMapper $translationMapper,
        private readonly TranslationHandler $translationHandler,
        private readonly IUserSession $userSession
    ) {
    }//end __construct()

    /**
     * Update the workflow status for a translation slot.
     *
     * Caller is the human/automation that knows the new status (e.g. a
     * translator's UI promotes draft → human_reviewed). The translator
     * uid is derived from the active session.
     *
     * @param string $objectUuid The object UUID.
     * @param string $property   The property name.
     * @param string $language   The language code.
     * @param string $status     The new workflow status.
     *
     * @return Translation The persisted translation row.
     *
     * @throws InvalidArgumentException When status is invalid or no slot exists.
     *
     * @spec openspec/changes/retrofit-2026-05-24-b-svc-i18n-endpoint-gql-wh/tasks.md#task-3
     */
    public function setStatus(string $objectUuid, string $property, string $language, string $status): Translation
    {
        if (in_array($status, Translation::ALL_STATUSES, true) === false) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid translation status "%s"; expected one of: %s',
                    $status,
                    implode(', ', Translation::ALL_STATUSES)
                )
            );
        }

        $existing = $this->translationMapper->findOne($objectUuid, $property, $language);
        if ($existing === null) {
            throw new InvalidArgumentException(
                sprintf(
                    'No translation slot for object=%s property=%s language=%s — set a value before promoting status.',
                    $objectUuid,
                    $property,
                    $language
                )
            );
        }

        $translator = $this->userSession->getUser()?->getUID();

        return $this->translationMapper->upsert(
            objectUuid: $objectUuid,
            property: $property,
            language: $language,
            value: $existing->getValue(),
            status: $status,
            translator: $translator
        );
    }//end setStatus()

    /**
     * Per-object completeness ratio per language.
     *
     * Returns `[language => ['translated' => int, 'total' => int, 'ratio' => float]]`.
     * `total` is the count of translatable properties on the schema;
     * `translated` is the count of slots with non-empty values for the
     * given language. `ratio` is `translated / total` rounded to 2dp.
     *
     * @param string $objectUuid The object UUID.
     * @param Schema $schema     The schema entity.
     *
     * @return array<string, array{translated: int, total: int, ratio: float}>
     *
     * @spec openspec/changes/retrofit-2026-05-24-b-svc-i18n-endpoint-gql-wh/tasks.md#task-4
     */
    public function completenessForObject(string $objectUuid, Schema $schema): array
    {
        $translatableProps = $this->translationHandler->getTranslatableProperties($schema);
        $total = count($translatableProps);
        if ($total === 0) {
            return [];
        }

        $counts = $this->translationMapper->getCompletenessByObject($objectUuid);
        $out    = [];
        foreach ($counts as $language => $count) {
            $out[$language] = [
                'translated' => $count,
                'total'      => $total,
                'ratio'      => round(min($count, $total) / $total, 2),
            ];
        }

        return $out;
    }//end completenessForObject()

    /**
     * Search translation rows.
     *
     * @param string|null $query          Optional full-text query.
     * @param string|null $language       Optional language filter.
     * @param string|null $status         Optional status filter.
     * @param string|null $objectUuid     Optional object UUID filter.
     * @param int         $limit          Maximum number of results.
     * @param string|null $sourceLanguage Optional source-language filter.
     * @param bool        $isOutOfDate    When true, restrict to status=outdated.
     *
     * @return array<string, mixed>[]
     *
     * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-3
     */
    public function search(
        ?string $query=null,
        ?string $language=null,
        ?string $status=null,
        ?string $objectUuid=null,
        int $limit=100,
        ?string $sourceLanguage=null,
        bool $isOutOfDate=false
    ): array {
        $rows = $this->translationMapper->search(
            query: $query,
            language: $language,
            status: $status,
            objectUuid: $objectUuid,
            limit: $limit,
            sourceLanguage: $sourceLanguage,
            isOutOfDate: $isOutOfDate
        );
        return array_map(fn(Translation $t) => $t->jsonSerialize(), $rows);
    }//end search()

    /**
     * Search translation rows and attach the source-language value side-by-side.
     *
     * Used to power `GET /api/translations/search?compareToSource=true` so
     * editorial tooling can render the source vs target value pair without
     * a follow-up round trip.
     *
     * @param string|null $query          Optional full-text query.
     * @param string|null $language       Optional language filter.
     * @param string|null $status         Optional status filter.
     * @param string|null $objectUuid     Optional object UUID filter.
     * @param int         $limit          Maximum number of results.
     * @param string|null $sourceLanguage Optional source-language filter.
     * @param bool        $isOutOfDate    When true, restrict to status=outdated.
     *
     * @return array<string, mixed>[] Each row carries an extra `sourceValue` field.
     *
     * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-3
     */
    public function searchWithSourceValues(
        ?string $query=null,
        ?string $language=null,
        ?string $status=null,
        ?string $objectUuid=null,
        int $limit=100,
        ?string $sourceLanguage=null,
        bool $isOutOfDate=false
    ): array {
        $rows = $this->translationMapper->search(
            query: $query,
            language: $language,
            status: $status,
            objectUuid: $objectUuid,
            limit: $limit,
            sourceLanguage: $sourceLanguage,
            isOutOfDate: $isOutOfDate
        );

        // Resolve (uuid, property, sourceLanguage) -> sourceValue lookup once.
        $cache = [];
        $out   = [];
        foreach ($rows as $row) {
            $entry  = $row->jsonSerialize();
            $uuid   = (string) $row->getObjectUuid();
            $prop   = (string) $row->getProperty();
            $source = (string) ($row->getSourceLanguage() ?? '');

            $key = $uuid.'|'.$prop.'|'.$source;
            if ($source === '') {
                $entry['sourceValue'] = null;
            } else if (isset($cache[$key]) === true) {
                $entry['sourceValue'] = $cache[$key];
            } else {
                $sourceRow = $this->translationMapper->findOne($uuid, $prop, $source);
                if ($sourceRow !== null) {
                    $value = $sourceRow->getValue();
                } else {
                    $value = null;
                }

                $cache[$key]          = $value;
                $entry['sourceValue'] = $value;
            }

            $out[] = $entry;
        }//end foreach

        return $out;
    }//end searchWithSourceValues()

    /**
     * Mark every non-source-language Translation row for a property as `outdated`.
     *
     * When the source-language value for a translatable property changes,
     * every derived translation becomes stale by definition; flipping the
     * status surface immediately surfaces the staleness to translators +
     * editorial tooling. Rows already in `outdated` (or `draft`) status are
     * not re-flipped.
     *
     * @param string $objectUuid     UUID of the object whose translations to flip.
     * @param string $property       Property whose derived translations to flip.
     * @param string $sourceLanguage Resolved source language for the property.
     *
     * @return int Number of derived rows transitioned to `outdated`.
     *
     * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-2
     */
    public function markDerivedTranslationsOutdated(
        string $objectUuid,
        string $property,
        string $sourceLanguage
    ): int {
        if ($objectUuid === '' || $property === '' || $sourceLanguage === '') {
            return 0;
        }

        return $this->translationMapper->markDerivedOutdated($objectUuid, $property, $sourceLanguage);
    }//end markDerivedTranslationsOutdated()

    /**
     * Find objects missing translation slots in a language.
     *
     * Returns the subset of `$candidateUuids` missing at least one
     * translatable-property value in `$language`.
     *
     * @param string   $language       The language code to check.
     * @param Schema   $schema         The schema describing translatable properties.
     * @param string[] $candidateUuids List of object UUIDs to consider.
     *
     * @return string[] Subset of `$candidateUuids` lacking the language.
     *
     * @spec openspec/changes/retrofit-2026-05-24-b-svc-i18n-endpoint-gql-wh/tasks.md#task-6
     */
    public function findObjectsMissingLanguage(string $language, Schema $schema, array $candidateUuids): array
    {
        $properties = $this->translationHandler->getTranslatableProperties($schema);
        return $this->translationMapper->findObjectsMissingLanguage($language, $properties, $candidateUuids);
    }//end findObjectsMissingLanguage()
}//end class
