<?php

/**
 * OpenRegister Handoff Kind Contracts
 *
 * The versioned kind-contract map for the semantic-object-handoff engine
 * (ADR-051). Keys are canonical kind URIs under the OpenRegister namespace
 * (`https://openregister.app/ns#`); values declare the mandatory and optional
 * contract field names an implementing schema binds via its `handoffContract`
 * block and an emitting schema maps via `x-openregister-handoff`.
 *
 * This is DATA, deliberately kept in one place so the dialect validator, the
 * binding validator, and the engine all consult a single source. The seed
 * kinds mirror the hydra contract specs 1:1:
 * - `ns#Case`  — hydra/openspec/changes/semantic-object-handoff/specs/handoff-contract-case/spec.md
 * - `ns#Quote`, `ns#Contract`, `ns#Invoice` —
 *   hydra/openspec/changes/semantic-object-handoff/specs/handoff-contract-order-chain/spec.md
 *
 * New kinds are added by extending this map alongside a hydra contract spec —
 * never per-app (ADR-051: no app or schema slug is hard-coded here).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Handoff
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Handoff;

/**
 * Static registry of the canonical handoff kind contracts (field sets).
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 *   (Requirement: Kind contract binding on the implementing schema)
 */
final class HandoffKindContracts
{

    /**
     * The canonical OpenRegister kind namespace (ADR-048 vocabulary layer).
     *
     * @var string
     */
    public const NAMESPACE_URI = 'https://openregister.app/ns#';

    /**
     * Contract map keyed by kind URI. Each entry lists the mandatory fields an
     * implementer MUST bind (and an emitter MUST map) and the optional fields
     * that MAY be bound/mapped. Field semantics live in the hydra contract
     * specs; this map carries only the names the validators + engine need.
     *
     * @var array<string, array{mandatory: array<int, string>, optional: array<int, string>}>
     */
    private const CONTRACTS = [
        self::NAMESPACE_URI.'Case'     => [
            'mandatory' => ['title', 'summary', 'channel', 'source'],
            'optional'  => ['requester', 'priority'],
        ],
        self::NAMESPACE_URI.'Quote'    => [
            'mandatory' => ['title', 'counterparty', 'currency', 'totalAmount', 'source'],
            'optional'  => ['lines', 'validUntil'],
        ],
        self::NAMESPACE_URI.'Contract' => [
            'mandatory' => ['title', 'counterparty', 'currency', 'totalAmount', 'startDate', 'source'],
            'optional'  => ['endDate'],
        ],
        self::NAMESPACE_URI.'Invoice'  => [
            'mandatory' => ['counterparty', 'currency', 'totalAmount', 'source'],
            'optional'  => ['lines', 'dueDate'],
        ],
    ];

    /**
     * Whether the given kind URI carries a known contract.
     *
     * @param string $kindUri The canonical kind URI.
     *
     * @return bool True when a contract is registered for the kind.
     *
     * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
     *   (Requirement: Kind contract binding on the implementing schema)
     */
    public static function isContractKind(string $kindUri): bool
    {
        return array_key_exists($kindUri, self::CONTRACTS);

    }//end isContractKind()

    /**
     * The mandatory contract field names for a kind (empty for unknown kinds).
     *
     * @param string $kindUri The canonical kind URI.
     *
     * @return array<int, string> Mandatory field names.
     *
     * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
     *   (Requirement: Kind contract binding on the implementing schema)
     */
    public static function mandatoryFields(string $kindUri): array
    {
        return self::CONTRACTS[$kindUri]['mandatory'] ?? [];

    }//end mandatoryFields()

    /**
     * All contract field names (mandatory + optional) for a kind.
     *
     * @param string $kindUri The canonical kind URI.
     *
     * @return array<int, string> All declared field names (empty for unknown kinds).
     *
     * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
     *   (Requirement: `x-openregister-handoff` declarative dialect)
     */
    public static function allFields(string $kindUri): array
    {
        $contract = (self::CONTRACTS[$kindUri] ?? null);
        if ($contract === null) {
            return [];
        }

        return array_values(array_merge($contract['mandatory'], $contract['optional']));

    }//end allFields()

    /**
     * All registered kind URIs.
     *
     * @return array<int, string> The known contract-carrying kind URIs.
     *
     * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
     *   (Requirement: Kind contract binding on the implementing schema)
     */
    public static function kinds(): array
    {
        return array_keys(self::CONTRACTS);

    }//end kinds()
}//end class
