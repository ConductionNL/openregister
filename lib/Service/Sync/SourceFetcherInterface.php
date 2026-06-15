<?php

/**
 * OpenRegister Source Fetcher Interface
 *
 * Contract for the Gather and Fetch stages of the harvest pipeline. A
 * concrete fetcher knows how to talk to a particular source kind (REST API,
 * OData, SOAP/XML, CSV file, another OpenRegister instance). Decoupling the
 * pipeline from transport keeps the orchestration unit-testable: tests
 * inject an in-memory fetcher instead of performing real network I/O.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sync
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Sync;

use OCA\OpenRegister\Db\Source;

/**
 * Transport contract for a sync source.
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
interface SourceFetcherInterface
{

    /**
     * Whether this fetcher can handle the given source type.
     *
     * @param string $type The source type (rest-api, odata, soap, csv-file, openregister)
     *
     * @return bool True when supported
     *
     * @spec openspec/specs/data-sync-harvesting/spec.md
     */
    public function supports(string $type): bool;

    /**
     * Gather stage: return the list of external record identifiers to process.
     *
     * Implementations MUST honour incremental sync when $since is provided,
     * returning only records modified since that checkpoint where the source
     * supports it.
     *
     * @param Source      $source The source being synced
     * @param string|null $since  Incremental checkpoint (ISO-8601 timestamp or delta token), or null for full sync
     *
     * @return list<string> External record identifiers
     *
     * @spec openspec/specs/data-sync-harvesting/spec.md
     */
    public function gather(Source $source, ?string $since=null): array;

    /**
     * Fetch stage: retrieve the full raw payload for a single record.
     *
     * @param Source $source     The source being synced
     * @param string $externalId The external record identifier
     *
     * @return array<string, mixed> The raw record payload
     *
     * @spec openspec/specs/data-sync-harvesting/spec.md
     */
    public function fetch(Source $source, string $externalId): array;
}//end interface
