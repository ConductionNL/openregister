<?php

/**
 * OpenRegister TranslationStatusService
 *
 * Public API for the translation sidecar: status updates,
 * per-object completeness queries, search, and bulk discovery
 * ("find objects missing X translation").
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
     */
    public function setStatus(string $objectUuid, string $property, string $language, string $status): Translation
    {
        if (in_array($status, Translation::ALL_STATUSES, true) === false) {
            throw new InvalidArgumentException(
                sprintf('Invalid translation status "%s"; expected one of: %s', $status, implode(', ', Translation::ALL_STATUSES))
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
     * @return array<string, array{translated: int, total: int, ratio: float}>
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
     * @return array<string, mixed>[]
     */
    public function search(
        ?string $query=null,
        ?string $language=null,
        ?string $status=null,
        ?string $objectUuid=null,
        int $limit=100
    ): array {
        $rows = $this->translationMapper->search($query, $language, $status, $objectUuid, $limit);
        return array_map(fn(Translation $t) => $t->jsonSerialize(), $rows);
    }//end search()

    /**
     * Find objects in `$candidateUuids` that are missing at least one
     * translatable-property value in `$language`.
     *
     * @param string[] $candidateUuids
     *
     * @return string[] Subset of `$candidateUuids` lacking the language.
     */
    public function findObjectsMissingLanguage(string $language, Schema $schema, array $candidateUuids): array
    {
        $properties = $this->translationHandler->getTranslatableProperties($schema);
        return $this->translationMapper->findObjectsMissingLanguage($language, $properties, $candidateUuids);
    }//end findObjectsMissingLanguage()
}//end class
