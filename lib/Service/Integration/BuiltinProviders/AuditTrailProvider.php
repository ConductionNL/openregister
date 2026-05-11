<?php

/**
 * AuditTrailProvider — exposes audit-trail entries for an OR object
 * via the IntegrationProvider contract.
 *
 * Storage strategy is `query-time` (AD-22): audit-trail entries are
 * OR's own data; the provider always reads live from `AuditTrailMapper`
 * rather than maintaining a parallel link table. Mutation methods
 * throw NotImplementedException — audit-trail entries are immutable
 * by construction.
 *
 * Always available — no required NC app — so `requiredApp` returns
 * null and `isEnabled()` is hardcoded true.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\BuiltinProviders
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-16
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\BuiltinProviders;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\IL10N;

/**
 * Audit-trail integration provider (read-only, query-time).
 */
class AuditTrailProvider extends AbstractIntegrationProvider
{

    /**
     * Constructor.
     *
     * @param AuditTrailMapper $mapper Audit-trail mapper.
     * @param IL10N            $l10n   Localisation.
     *
     * @return void
     */
    public function __construct(
        private AuditTrailMapper $mapper,
        private IL10N $l10n,
    ) {
    }//end __construct()

    public function getId(): string
    {
        return 'audit-trail';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Audit trail');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'History';
    }//end getIcon()

    public function getGroup(): ?string
    {
        return 'core';
    }//end getGroup()

    public function getRequiredApp(): ?string
    {
        return null;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return 'query-time';
    }//end getStorageStrategy()

    public function isEnabled(): bool
    {
        return true;
    }//end isEnabled()

    /**
     * List audit-trail entries for an OR object.
     *
     * Best-effort delegation to `AuditTrailMapper::findAllByObject` /
     * `findAll` depending on the mapper's exposed API. Returns an
     * empty list rather than 500 when the mapper signature doesn't
     * match — the umbrella's controller refactor (tasks 18-22) will
     * tighten this.
     *
     * @param string              $register Register slug or numeric id.
     * @param string              $schema   Schema slug or numeric id.
     * @param string              $objectId Object uuid.
     * @param array<string,mixed> $filters  Reserved.
     *
     * @return array<int,array<string,mixed>>
     */
    public function list(string $register, string $schema, string $objectId, array $filters = []): array
    {
        try {
            if (method_exists($this->mapper, 'findAllByObject') === true) {
                $entries = $this->mapper->findAllByObject($objectId);
                return $this->normalize($entries);
            }

            if (method_exists($this->mapper, 'findAll') === true) {
                $entries = $this->mapper->findAll(filters: ['object' => $objectId]);
                return $this->normalize($entries);
            }
        } catch (\Throwable $e) {
            // AuditTrail history is a soft surface — never block the
            // detail page on a stale or missing audit row.
        }

        return [];
    }//end list()

    /**
     * Convert mapper output (Entity[] or array[]) into the shape
     * IntegrationProvider::list() promises.
     *
     * @param mixed $entries Mapper output.
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalize($entries): array
    {
        if (is_array($entries) === false) {
            return [];
        }

        $rows = [];
        foreach ($entries as $entry) {
            if (is_object($entry) === true && method_exists($entry, 'jsonSerialize') === true) {
                $serialised = $entry->jsonSerialize();
                $rows[]     = is_array($serialised) === true ? $serialised : ['value' => $serialised];
                continue;
            }

            if (is_array($entry) === true) {
                $rows[] = $entry;
            }
        }

        return $rows;
    }//end normalize()

}//end class
