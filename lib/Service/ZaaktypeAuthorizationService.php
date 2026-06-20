<?php

/**
 * Zaaktype Authorization Service
 *
 * Configuration- and mapping-layer for zaaktype-scoped authorization
 * (rbac-zaaktype). This service does NOT introduce a new authorization
 * engine — enforcement remains the responsibility of the existing RBAC
 * primitives (PermissionHandler, MagicRbacHandler, PropertyRbacHandler,
 * ConditionMatcher). It provides:
 *
 *   - The canonical ZGW `vertrouwelijkheidaanduiding` (confidentiality) ordinal
 *     ordering and helpers to translate a maximum clearance level into an
 *     existing `$in` conditional-match clause that the RBAC engine already
 *     enforces at both the PHP and SQL layers.
 *   - A mapping from a ZGW Autorisaties API "autorisatie" record (zaaktype +
 *     scopes + maxVertrouwelijkheidaanduiding) onto an OpenRegister schema
 *     `authorization` block expressed purely in the existing group / conditional
 *     vocabulary.
 *   - A read-only extraction of the (schema x action x principal) permission
 *     matrix from a resolved `authorization` block, for admin matrix views and
 *     CSV export.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @since 2.0.0 Initial implementation for zaaktype-scoped authorization config.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Zaaktype-scoped authorization configuration and ZGW mapping helper.
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity) ZGW scope and matrix mapping branch per scope verb and per principal kind.
 */
class ZaaktypeAuthorizationService
{

    /**
     * Canonical ZGW vertrouwelijkheidaanduiding (confidentiality) levels, ordered
     * least-restrictive (index 0) to most-restrictive (index 7).
     *
     * Source: ZGW Catalogi API `VertrouwelijkheidaanduidingEnum`. The ordinal
     * position drives "a user cleared to level N may see objects at level N or
     * below" decisions. Changing the order here changes the security semantics
     * for every consumer, so it is treated as a single source of truth.
     *
     * @var string[]
     */
    public const VERTROUWELIJKHEIDAANDUIDING_LEVELS = [
        'openbaar',
        'beperkt_openbaar',
        'intern',
        'zaakvertrouwelijk',
        'vertrouwelijk',
        'confidentieel',
        'geheim',
        'zeer_geheim',
    ];

    /**
     * Mapping of the four ZGW CRUD scope suffixes to OpenRegister action verbs.
     *
     * ZGW scopes follow the pattern `zaken.lezen` / `zaken.aanmaken` /
     * `zaken.bijwerken` / `zaken.verwijderen`. The suffix after the final `.`
     * determines the action.
     *
     * @var array<string, string>
     */
    private const ZGW_SCOPE_SUFFIX_TO_ACTION = [
        'lezen'       => 'read',
        'aanmaken'    => 'create',
        'bijwerken'   => 'update',
        'verwijderen' => 'delete',
    ];

    /**
     * Return the ordinal (0-based) position of a confidentiality level.
     *
     * @param string $level The vertrouwelijkheidaanduiding value.
     *
     * @return int|null The 0-based ordinal, or null when the level is unknown.
     *
     * @spec openspec/specs/rbac-zaaktype/spec.md
     */
    public function confidentialityOrdinal(string $level): ?int
    {
        $index = array_search($level, self::VERTROUWELIJKHEIDAANDUIDING_LEVELS, true);
        if ($index === false) {
            return null;
        }

        return $index;
    }//end confidentialityOrdinal()

    /**
     * Return every confidentiality level at or below a maximum clearance.
     *
     * Used to build the allow-list for an `$in` match clause: a principal cleared
     * to `$maxLevel` may access objects whose vertrouwelijkheidaanduiding is in
     * the returned set. Fail closed: an unknown `$maxLevel` returns an empty list
     * (which, when fed to `buildConfidentialityMatch()`, yields a match that the
     * engine can never satisfy).
     *
     * @param string $maxLevel The maximum clearance level (inclusive).
     *
     * @return string[] Allowed levels, ordered least- to most-restrictive.
     *
     * @spec openspec/specs/rbac-zaaktype/spec.md
     */
    public function levelsAtOrBelow(string $maxLevel): array
    {
        // Derive the allow-list from the same ordinal comparison used for
        // single-object access decisions, so the list-filter allow-set and the
        // single-object verdict can never disagree (no list-vs-find drift).
        return array_values(
            array_filter(
                self::VERTROUWELIJKHEIDAANDUIDING_LEVELS,
                fn(string $level): bool => $this->isAccessibleAtClearance(maxLevel: $maxLevel, objectLevel: $level)
            )
        );
    }//end levelsAtOrBelow()

    /**
     * Decide whether a principal cleared to `$maxLevel` may access an object at
     * `$objectLevel`.
     *
     * Fail closed: an unknown clearance or an unknown object level denies access.
     * This is the canonical ordinal comparison the audit-denial reason
     * `confidentiality_level_exceeded` is derived from.
     *
     * @param string $maxLevel    The principal's maximum clearance.
     * @param string $objectLevel The object's vertrouwelijkheidaanduiding.
     *
     * @return bool True only when objectLevel <= maxLevel by ZGW ordinal.
     *
     * @spec openspec/specs/rbac-zaaktype/spec.md
     */
    public function isAccessibleAtClearance(string $maxLevel, string $objectLevel): bool
    {
        $maxOrdinal    = $this->confidentialityOrdinal(level: $maxLevel);
        $objectOrdinal = $this->confidentialityOrdinal(level: $objectLevel);

        if ($maxOrdinal === null || $objectOrdinal === null) {
            return false;
        }

        return $objectOrdinal <= $maxOrdinal;
    }//end isAccessibleAtClearance()

    /**
     * Build a conditional-match clause that limits a rule to objects at or below
     * a maximum vertrouwelijkheidaanduiding.
     *
     * The clause is expressed in the existing `$in` operator vocabulary so the
     * unchanged RBAC engine enforces it at both the PHP (ConditionMatcher) and
     * SQL (MagicRbacHandler) layers. The property name is configurable to match
     * the schema's actual field (default `vertrouwelijkheidaanduiding`).
     *
     * @param string $maxLevel The maximum clearance level (inclusive).
     * @param string $property The object property holding the confidentiality level.
     *
     * @return array<string, array<string, string[]>> A `match` clause:
     *         `{ "<property>": { "$in": [<allowed levels>] } }`.
     *
     * @spec openspec/specs/rbac-zaaktype/spec.md
     */
    public function buildConfidentialityMatch(string $maxLevel, string $property='vertrouwelijkheidaanduiding'): array
    {
        return [$property => ['$in' => $this->levelsAtOrBelow(maxLevel: $maxLevel)]];
    }//end buildConfidentialityMatch()

    /**
     * Map a single ZGW Autorisaties "autorisatie" record onto an OpenRegister
     * schema `authorization` block.
     *
     * Mapping (per the rbac-zaaktype spec "ZGW Autorisaties API concepts" req):
     *   - Each ZGW `scope` (e.g. `zaken.lezen`) maps to an OpenRegister action
     *     (`read`) granted to the supplied `$group` (the Nextcloud group that
     *     represents the ZGW Applicatie's identity / scope).
     *   - A `maxVertrouwelijkheidaanduiding` (when present) attaches an `$in`
     *     conditional match to EVERY mapped action so the engine filters objects
     *     above the clearance at query time.
     *
     * The returned block is purely the existing group / conditional vocabulary —
     * it can be merged into a schema's `authorization` and enforced unchanged.
     * Unknown scope suffixes are skipped (fail closed: they grant nothing).
     *
     * @param array<int, string> $scopes   ZGW scopes, e.g. ['zaken.lezen', 'zaken.aanmaken'].
     * @param string             $group    The Nextcloud group representing the ZGW Applicatie.
     * @param string|null        $maxLevel Optional maxVertrouwelijkheidaanduiding.
     * @param string             $property Confidentiality property name on the schema.
     *
     * @return array<string, array<int, mixed>> An `authorization`-shaped block.
     *
     * @spec openspec/specs/rbac-zaaktype/spec.md
     */
    public function mapZgwAutorisatie(
        array $scopes,
        string $group,
        ?string $maxLevel=null,
        string $property='vertrouwelijkheidaanduiding'
    ): array {
        $authorization = [];

        foreach ($scopes as $scope) {
            if (is_string($scope) === false || $scope === '') {
                continue;
            }

            $action = $this->scopeToAction(scope: $scope);
            if ($action === null) {
                // Unknown scope suffix grants nothing (fail closed).
                continue;
            }

            // A clearance limit turns the grant into a conditional rule; an
            // unrestricted grant (heeftAlleAutorisaties-style per-zaaktype) is a
            // bare group string.
            if ($maxLevel !== null) {
                $entry = [
                    'group' => $group,
                    'match' => $this->buildConfidentialityMatch(maxLevel: $maxLevel, property: $property),
                ];
            } else {
                $entry = $group;
            }

            if (isset($authorization[$action]) === false) {
                $authorization[$action] = [];
            }

            $authorization[$action][] = $entry;
        }//end foreach

        return $authorization;
    }//end mapZgwAutorisatie()

    /**
     * Translate a single ZGW scope string to an OpenRegister action verb.
     *
     * @param string $scope A ZGW scope, e.g. `zaken.lezen`.
     *
     * @return string|null The action (`read`/`create`/`update`/`delete`), or null
     *                     when the scope suffix is not a recognised CRUD verb.
     *
     * @spec openspec/specs/rbac-zaaktype/spec.md
     */
    public function scopeToAction(string $scope): ?string
    {
        $dot = strrpos($scope, '.');
        if ($dot === false) {
            $suffix = $scope;
        } else {
            $suffix = substr($scope, ($dot + 1));
        }

        return self::ZGW_SCOPE_SUFFIX_TO_ACTION[$suffix] ?? null;
    }//end scopeToAction()

    /**
     * Extract a read-only permission matrix from a resolved authorization block.
     *
     * Produces one row per (action x principal), distinguishing group entries,
     * `user:`/`{user}` overrides, and conditional (`match`) entries. This is the
     * canonical representation the admin matrix view and CSV export render; it is
     * derived from the SAME `authorization` block the engine enforces, so it can
     * never drift from the actual access decisions.
     *
     * @param array<string, mixed>|null $authorization A resolved authorization block.
     *
     * @return array<int, array{action: string, principal: string, kind: string, conditional: bool}>
     *         One entry per (action, principal) grant.
     *
     * @spec openspec/specs/rbac-zaaktype/spec.md
     */
    public function extractPermissionMatrix(?array $authorization): array
    {
        if (is_array($authorization) === false || $authorization === []) {
            return [];
        }

        $rows = [];

        foreach ($authorization as $action => $entries) {
            // Skip non-action keys (e.g. inheritFromPublic, roles) and malformed lists.
            if (in_array($action, ['read', 'create', 'update', 'delete', 'list'], true) === false) {
                continue;
            }

            if (is_array($entries) === false) {
                continue;
            }

            foreach ($entries as $entry) {
                $row = $this->matrixRowForEntry(action: (string) $action, entry: $entry);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }//end foreach

        return $rows;
    }//end extractPermissionMatrix()

    /**
     * Build a single permission-matrix row for one authorization entry.
     *
     * @param string $action The CRUD action the entry grants.
     * @param mixed  $entry  A single authorization entry (string or array).
     *
     * @return array{action: string, principal: string, kind: string, conditional: bool}|null
     *         The row, or null when the entry is malformed.
     */
    private function matrixRowForEntry(string $action, mixed $entry): ?array
    {
        // Bare string entry: a group or a `user:<uid>` override.
        if (is_string($entry) === true) {
            if (str_starts_with($entry, 'user:') === true) {
                return [
                    'action'      => $action,
                    'principal'   => $entry,
                    'kind'        => 'user',
                    'conditional' => false,
                ];
            }

            return [
                'action'      => $action,
                'principal'   => $entry,
                'kind'        => 'group',
                'conditional' => false,
            ];
        }

        if (is_array($entry) === false) {
            return null;
        }

        $conditional = isset($entry['match']) === true && empty($entry['match']) === false;

        // Complex user override: { "user": "<uid>", "match"?: {...} }.
        if (isset($entry['user']) === true && is_string($entry['user']) === true) {
            return [
                'action'      => $action,
                'principal'   => 'user:'.$entry['user'],
                'kind'        => 'user',
                'conditional' => $conditional,
            ];
        }

        // Complex group rule: { "group": "<g>", "match"?: {...} }.
        if (isset($entry['group']) === true && is_string($entry['group']) === true) {
            return [
                'action'      => $action,
                'principal'   => $entry['group'],
                'kind'        => 'group',
                'conditional' => $conditional,
            ];
        }

        return null;
    }//end matrixRowForEntry()
}//end class
