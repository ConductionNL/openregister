<?php

/**
 * The one resolved answer to "what happens to this object, and when".
 *
 * 🔴 THIS EXISTS BECAUSE THE ANSWER WAS SCATTERED AND THE ABSTRACT SLOT WAS
 * EMPTY. `@self._retention` has been declared in `ObjectEntity::getObjectArray()`
 * since add-archival-annotation-support, and `setArchivalRetention()` — the only
 * way to fill it — was called from exactly one place in the whole repository: a
 * unit test. Every consumer that asked an object what its archival constraints
 * were therefore got nothing, while the facts themselves sat in five different
 * shapes nobody had merged:
 *
 *   1. `retention.archiefnominatie` / `bewaartermijn` / `archiefactiedatum`,
 *      written by {@see \OCA\OpenRegister\Service\RetentionService::applyArchivalMetadata()}
 *      but only for schemas whose `archive` block is enabled;
 *   2. `retention.annotation`, the `x-openregister-archival` evaluation written
 *      by RenderObject, in `effectiveRetention` / `matchedRule` / `expiresAt`;
 *   3. `retention.legalHold`, which overrides both and which neither of the
 *      other two mentions;
 *   4. the object's OWN archival properties, in the ZGW zaak vocabulary, which
 *      an app implementing that API declares as ordinary schema fields;
 *   5. `@self.tmlo` — the block {@see \OCA\OpenRegister\Service\TmloService}
 *      populates and validates and {@see \OCA\OpenRegister\Service\Edepot\MdtoXmlGenerator}
 *      exports from. THIS ONE WAS MISSED on the first pass, so an object whose
 *      archival metadata lived only in `tmlo` resolved to no decision at all —
 *      the exact silence this class exists to end, reproduced one level up.
 *      Recorded as gap A1 in openspec/changes/archival-conformance.
 *
 * VOCABULARY. The resolved keys are MDTO CONCEPTS IN ENGLISH. MDTO supersedes
 * TMLO, so `archiefnominatie` is the superseded spelling of MDTO's `waardering`
 * and the abstract key is `appraisal`. The stored blocks keep their own
 * spellings — this class reads them all and answers in one.
 *
 * It reads; it never writes to storage and it never decides that anything may be
 * destroyed. That stays with RetentionService and the destruction pipeline.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Merges every archival source an object carries into one resolved
 * `@self._retention` decision, in MDTO concepts with English keys.
 */
class ArchivalDecisionResolver {

    /**
     * Record states, in the Archiefwet lifecycle TmloService models.
     *
     * @var array<string, string>
     */
    private const STATE_ALIASES = [
        'actief' => 'active',
        'semi_statisch' => 'semi_static',
        'overgebracht' => 'transferred',
        'vernietigd' => 'destroyed',
        // 🔴 A SECOND VOCABULARY FOR THE SAME FIELD NAME. RetentionService
        // writes `retention.archiefstatus = 'nog_te_archiveren'`, which is not
        // in TmloService::VALID_ARCHIEFSTATUS and which that service's own
        // validator would reject. So `archiefstatus` means one of two different
        // things depending on which block it sits in.
        //
        // "Still to be archived" is a record that is live and not yet
        // transferred, which is `actief` in the Archiefwet lifecycle, so it
        // maps there and the abstract layer stays coherent. The underlying
        // collision is real and is recorded as gap A4 in
        // openspec/changes/archival-conformance; this mapping makes the
        // abstract answer usable, it does not fix the two writers.
        'nog_te_archiveren' => 'active',
        // GAP A4 IS NOW FIXED AT THE WRITER. RetentionService writes
        // RecordState::ACTIVE and the rest of the Archiefwet lifecycle in
        // English, sharing one vocabulary with this layer. The Dutch keys above
        // stay because stored data is not migrated: they are a compatibility
        // shim for records written before the change, not a translation between
        // two live conventions. TmloService keeps its own Dutch spellings for
        // `tmlo.archiefstatus`, which is its own block with its own transition
        // matrix and its own MDTO export mapping; that is a separate move.
        //
        // The English spellings map to themselves so a stored value that is
        // already canonical still resolves rather than falling through.
        'active' => 'active',
        'semi_static' => 'semi_static',
        'transferred' => 'transferred',
        'destroyed' => 'destroyed',
    ];

    /**
     * States after which the record may not be changed.
     *
     * Mirrors `RetentionService::IMMUTABLE_STATUSES`. A record that has been
     * transferred to an e-Depot or destroyed is no longer this system's to
     * alter, and a consumer needs to know that to grey an edit rather than
     * offer one the server will refuse.
     *
     * @var array<int, string>
     */
    private const IMMUTABLE_STATES = ['transferred', 'destroyed'];

    /**
     * Resolve an object's archival decision.
     *
     * Returns null when the object has nothing to say about its own archiving.
     * Null means the key is omitted from `@self` entirely, which is deliberate:
     * an empty `_retention: {}` reads as "we looked and there is no obligation",
     * and a records officer must be able to tell that apart from "this schema
     * never declared one".
     *
     * @param ObjectEntity $entity The object to resolve for.
     *
     * @return array<string, mixed>|null The resolved decision, or null when there is none.
     *
     * @spec openspec/specs/retention-management/spec.md
     */
    public function resolve(ObjectEntity $entity): ?array {
        $retention = ($entity->getRetention() ?? []);
        if (is_array($retention) === false) {
            $retention = [];
        }

        // GAP A1: the TMLO block is a first-class source, not an afterthought.
        // TmloService writes it, MdtoXmlGenerator exports from it, and until
        // this line it was the one source `_retention` could not see.
        $tmlo = ($entity->getTmlo() ?? []);
        if (is_array($tmlo) === false) {
            $tmlo = [];
        }

        $annotation = ($retention['annotation'] ?? []);
        if (is_array($annotation) === false) {
            $annotation = [];
        }

        $declared = $this->declaredArchivalFields(entity: $entity);

        $decision = [
            'appraisal' => $this->normaliseAppraisal(
                value: $this->firstNonEmpty(
                    values: [
                        ($retention['archiefnominatie'] ?? null),
                        ($tmlo['archiefnominatie'] ?? null),
                        ($declared['appraisal'] ?? null),
                    ]
                )
            ),
            'disposalCategory' => $this->firstNonEmpty(
                values: [
                    ($retention['classification'] ?? null),
                    ($tmlo['classification'] ?? null),
                    ($tmlo['vernietigingsCategorie'] ?? null),
                ]
            ),
            'legalHold' => $this->resolveLegalHold(entity: $entity, retention: $retention),
        ];

        $decision['retentionPeriod'] = $this->firstNonEmpty(
            values: [
                ($retention['bewaartermijn'] ?? null),
                ($tmlo['bewaarTermijn'] ?? null),
                ($annotation['effectiveRetention'] ?? null),
            ]
        );

        $decision['disposalDate'] = $this->firstNonEmpty(
            values: [
                ($retention['archiefactiedatum'] ?? null),
                ($tmlo['archiefactiedatum'] ?? null),
                ($declared['disposalDate'] ?? null),
                ($annotation['expiresAt'] ?? null),
            ]
        );

        $decision = $this->withRecordState(decision: $decision, retention: $retention, tmlo: $tmlo, declared: $declared);

        // WHERE the answer came from, so a reader can tell a selectielijst
        // obligation from a schema default from a hand-set date. Without this a
        // records officer sees a destruction date and cannot say who claimed it.
        $decision['basis'] = $this->resolveBasis(retention: $retention, tmlo: $tmlo, annotation: $annotation, declared: $declared);
        $decision['source'] = $this->stringOrNull(value: ($retention['selectielijstBron'] ?? null));
        $decision = $this->withSourceProvenance(decision: $decision, retention: $retention);

        // The raw annotation evaluation is passed through rather than folded
        // away: `matchedRule` is the only thing that says WHICH rule in the
        // schema's `x-openregister-archival` block fired, and debugging a wrong
        // destruction date without it means re-evaluating the whole annotation
        // by hand.
        if ($annotation !== []) {
            $decision['annotation'] = $annotation;
        }

        // Nothing was established. Distinct from "kept forever": every field is
        // absent, not merely permissive, so the object genuinely has no
        // archival claim on it and the key is omitted.
        $established = array_filter(
            $decision,
            static function ($value) {
                return $value !== null && $value !== [] && $value !== false;
            }
        );

        if ($established === []) {
            return null;
        }

        return $decision;
    }//end resolve()

    /**
     * Add the Archiefwet record state, and whether it is final.
     *
     * GAP A2. TmloService models `actief → semi_statisch → overgebracht |
     * vernietigd` with a transition matrix and treats the last two as
     * immutable, and none of that reached the abstract layer. Whether a record
     * has been transferred to an e-Depot or destroyed is the single most
     * consequential archival fact about it.
     *
     * `immutable` is derived rather than stored so a consumer never has to know
     * the lifecycle to act on it: it can grey an edit from the boolean alone.
     *
     * @param array<string, mixed> $decision The decision so far.
     * @param array<string, mixed> $retention The stored retention block.
     * @param array<string, mixed> $tmlo The stored TMLO block.
     * @param array<string, string> $declared The record's own archival fields.
     *
     * @return array<string, mixed> The decision, with state.
     *
     * @spec openspec/specs/retention-management/spec.md
     */
    private function withRecordState(array $decision, array $retention, array $tmlo, array $declared): array {
        $raw = $this->firstNonEmpty(
            values: [
                ($retention['archiefstatus'] ?? null),
                ($tmlo['archiefstatus'] ?? null),
                ($declared['recordState'] ?? null),
            ]
        );

        if ($raw === null) {
            $decision['recordState'] = null;
            return $decision;
        }

        // An unknown state passes through for the same reason an unknown
        // appraisal does: reporting "no state" for a record that carries one is
        // the failure mode that hides a records obligation.
        $state = (self::STATE_ALIASES[$raw] ?? $raw);
        $decision['recordState'] = $state;
        $decision['immutable'] = in_array($state, self::IMMUTABLE_STATES, true);

        return $decision;
    }//end withRecordState()

    /**
     * Read the ZGW archival properties the object itself declares.
     *
     * 🔴 THIS IS WHAT MAKES `_retention` ABSTRACT RATHER THAN THEORETICAL.
     * `archiefnominatie` / `archiefactiedatum` / `archiefstatus` are the ZGW
     * contract, and an app that implements the zaak API declares them as
     * ordinary schema properties on its own record — dossiq derives them from a
     * case's resultaattype on close (zrc-021) and writes them there. Reading
     * only `@self.retention` would have left `_retention` empty on exactly the
     * records that HAVE an archival decision, while each app kept reading its
     * own field names, which is the per-app duplication this resolver exists to
     * end.
     *
     * These are not app-specific names: openregister already speaks the same
     * vocabulary in `retention`, in Dutch.
     *
     * `@self.retention` still WINS where both are present: that is a decision
     * recorded against the object through the retention service, and a schema
     * property is what an app wrote for its own API consumers.
     *
     * Both spellings are accepted for each field, because an app writes
     * whichever its own datamodel uses — dossiq's is English by policy.
     *
     * @param ObjectEntity $entity The object being resolved.
     *
     * @return array<string, string> The declared fields, keyed abstractly.
     *
     * @spec openspec/specs/retention-management/spec.md
     */
    private function declaredArchivalFields(ObjectEntity $entity): array {
        $object = ($entity->getObject() ?? []);
        if (is_array($object) === false) {
            return [];
        }

        $map = [
            'appraisal' => ['archiveNomination', 'archiefnominatie'],
            'disposalDate' => ['archiveActionDate', 'archiefactiedatum'],
            'recordState' => ['archiveStatus', 'archiefstatus'],
        ];

        $found = [];
        foreach ($map as $key => $candidates) {
            foreach ($candidates as $candidate) {
                $value = $this->stringOrNull(value: ($object[$candidate] ?? null));
                if ($value !== null) {
                    $found[$key] = $value;
                    break;
                }
            }
        }

        return $found;
    }//end declaredArchivalFields()

    /**
     * Normalise an appraisal to MDTO's `waardering` vocabulary, in English.
     *
     * @param string|null $value The stored appraisal.
     *
     * @return string|null The canonical appraisal, the raw string when it is one
     *                     this resolver does not know, or null when absent.
     *
     * @spec openspec/specs/retention-management/spec.md
     */
    private function normaliseAppraisal(?string $value): ?string {
        if ($value === null) {
            return null;
        }

        // An unknown value is passed through rather than dropped. Dropping it
        // would report "no appraisal" for an object that carries one this
        // resolver has not been taught, which is the failure mode that hides a
        // records obligation.
        // {@see Appraisal} is the ONE home for this vocabulary. It used to be a
        // private constant here, which meant the destruction and transfer
        // sweeps — the two places that have to ACT on an appraisal — could not
        // see it and wrote their own string literals instead.
        return (Appraisal::CANONICAL[$value] ?? $value);
    }//end normaliseAppraisal()

    /**
     * Resolve the legal-hold state.
     *
     * Reports an inactive hold as `['active' => false]` rather than null when
     * the object has hold HISTORY, because "held in the past and released" is a
     * different fact from "never held", and an archivist reads the difference.
     *
     * @param ObjectEntity $entity The object being resolved.
     * @param array<string, mixed> $retention The stored retention block.
     *
     * @return array<string, mixed>|null The hold state, or null when never held.
     *
     * @spec openspec/specs/archival-destruction-workflow/spec.md
     */
    private function resolveLegalHold(ObjectEntity $entity, array $retention): ?array {
        $hold = ($retention['legalHold'] ?? null);
        if (is_array($hold) === false) {
            return null;
        }

        $active = $entity->hasActiveLegalHold();
        $state = ['active' => $active];

        if ($active === true) {
            $state['reason'] = $this->stringOrNull(value: ($hold['reason'] ?? null));
            $state['placedBy'] = $this->stringOrNull(value: ($hold['placedBy'] ?? null));
            $state['placedDate'] = $this->stringOrNull(value: ($hold['placedDate'] ?? null));
        }

        $history = ($hold['history'] ?? []);
        if (is_array($history) === true && $history !== []) {
            $state['releasedCount'] = count($history);
        }

        return $state;
    }//end resolveLegalHold()

    /**
     * Add the selection list's version and the moment it was consulted.
     *
     * GAP B1. The list's NAME is not its VERSION, and the same category
     * carries different retention periods across selectielijst revisions. A
     * decision recorded with only the name says which list it came from but
     * not which list AS IT STOOD, which is the provenance an audit asks for.
     *
     * Each key is omitted rather than nulled when nothing was recorded: an
     * absent key is silence, and a null is a claim that the version was looked
     * for and found to be nothing.
     *
     * @param array $decision  The decision so far
     * @param array $retention The object's stored retention block
     *
     * @return array The decision, with whatever provenance exists
     *
     * @spec openspec/specs/archival-destruction-workflow/spec.md
     */
    private function withSourceProvenance(array $decision, array $retention): array {
        $version = $this->stringOrNull(value: ($retention['selectionListVersion'] ?? null));
        if ($version !== null) {
            $decision['sourceVersion'] = $version;
        }

        $consultedAt = $this->stringOrNull(value: ($retention['selectionListConsultedAt'] ?? null));
        if ($consultedAt !== null) {
            $decision['sourceConsultedAt'] = $consultedAt;
        }

        return $decision;
    }//end withSourceProvenance()

    /**
     * Decide which authority the retention period rests on.
     *
     * GAP B2: `selectielijst_not_consulted` is its own answer. When a schema
     * declares a `classification` — meaning it EXPECTS a selectielijst to be
     * applied — and no `selectielijstBron` came back, the honest report is that
     * the list was never consulted, not that the schema decided. An instance
     * that has simply not configured `selectielijstRegister` otherwise gets a
     * plausible-looking default with no signal at all.
     *
     * @param array<string, mixed> $retention The stored retention block.
     * @param array<string, mixed> $tmlo The stored TMLO block.
     * @param array<string, mixed> $annotation The schema annotation evaluation.
     * @param array<string, string> $declared The record's own archival fields.
     *
     * @return string|null The basis, or null when nothing established one.
     *
     * @spec openspec/specs/retention-management/spec.md
     */
    private function resolveBasis(array $retention, array $tmlo, array $annotation, array $declared): ?string {
        if ($this->stringOrNull(value: ($retention['selectielijstBron'] ?? null)) !== null) {
            return 'selection_list';
        }

        $expectedList = $this->stringOrNull(value: ($retention['classification'] ?? null));
        if ($expectedList !== null) {
            return 'selection_list_not_consulted';
        }

        if ($this->stringOrNull(value: ($retention['bewaartermijn'] ?? null)) !== null) {
            return 'schema';
        }

        if (($tmlo['bewaarTermijn'] ?? null) !== null || ($tmlo['archiefnominatie'] ?? null) !== null) {
            return 'tmlo';
        }

        if ($declared !== []) {
            return 'record';
        }

        if ($this->stringOrNull(value: ($annotation['effectiveRetention'] ?? null)) !== null) {
            return 'schema_annotation';
        }

        return null;
    }//end resolveBasis()

    /**
     * Return the first value that is a non-empty string.
     *
     * @param array<int, mixed> $values Candidates in priority order.
     *
     * @return string|null The first usable value, or null.
     *
     * @spec openspec/specs/retention-management/spec.md
     */
    private function firstNonEmpty(array $values): ?string {
        foreach ($values as $value) {
            $candidate = $this->stringOrNull(value: $value);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }//end firstNonEmpty()

    /**
     * Coerce a value to a non-empty string, or null.
     *
     * @param mixed $value The value to coerce.
     *
     * @return string|null The trimmed string, or null when empty or not scalar.
     *
     * @spec openspec/specs/retention-management/spec.md
     */
    private function stringOrNull(mixed $value): ?string {
        if (is_string($value) === false) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }//end stringOrNull()

}//end class
