<?php

/**
 * OpenRegister Schema Import — GgmSnapshot loader.
 *
 * Loads and indexes a normalised GGM (Gemeentelijk Gegevensmodel) intermediate
 * — either the bundled, versioned snapshot or an uploaded GGM export already
 * normalised to the same shape — into a queryable model of objecttypes and
 * their attribuutsoorten. Pure parsing; the path/array is injected so unit
 * tests and the upload path share the code.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\SchemaImport
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

namespace OCA\OpenRegister\Service\SchemaImport;

use OCA\OpenRegister\Exception\SchemaImportException;

/**
 * Indexed view over a normalised GGM release.
 *
 * @spec openspec/changes/schema-import-standards/specs/schema-import/spec.md
 */
class GgmSnapshot
{

    /**
     * Objecttypes indexed by id (uppercased).
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $objecttypes = null;

    /**
     * The release version of the loaded snapshot.
     *
     * @var string
     */
    private string $loadedVersion = '';

    /**
     * Constructor.
     *
     * Exactly one source is used: an in-memory normalised intermediate (for an
     * uploaded export) takes precedence over the bundled snapshot file.
     *
     * @param string                    $snapshotFile The absolute path to the bundled normalised snapshot.
     * @param string                    $version      The bundled snapshot version identifier.
     * @param array<string, mixed>|null $override     An in-memory normalised intermediate, or null.
     */
    public function __construct(
        private readonly string $snapshotFile,
        private readonly string $version,
        private readonly ?array $override=null
    ) {
    }//end __construct()

    /**
     * Build a snapshot backed by an in-memory normalised intermediate (upload).
     *
     * @param array<string, mixed> $normalised The normalised intermediate.
     *
     * @return self The snapshot.
     *
     * @spec openspec/changes/schema-import-standards/specs/schema-import/spec.md
     */
    public static function fromNormalised(array $normalised): self
    {
        return new self('', (string) ($normalised['version'] ?? 'upload'), $normalised);
    }//end fromNormalised()

    /**
     * The release version of the loaded snapshot.
     *
     * @return string The version.
     *
     * @spec openspec/changes/schema-import-standards/specs/schema-import/spec.md
     */
    public function version(): string
    {
        $this->ensureParsed();
        if ($this->loadedVersion !== '') {
            return $this->loadedVersion;
        }

        return $this->version;
    }//end version()

    /**
     * Resolve an objecttype by id (case-insensitive) or by Dutch name.
     *
     * @param string $reference The objecttype id or name.
     *
     * @return array<string, mixed>|null The objecttype record, or null when unknown.
     *
     * @spec openspec/changes/schema-import-standards/specs/schema-import/spec.md
     */
    public function findObjecttype(string $reference): ?array
    {
        $this->ensureParsed();

        $key = strtoupper(trim($reference));
        if (isset($this->objecttypes[$key]) === true) {
            return $this->objecttypes[$key];
        }

        // Fall back to a case-insensitive name match.
        foreach ($this->objecttypes as $objecttype) {
            if (strcasecmp((string) $objecttype['naam'], trim($reference)) === 0) {
                return $objecttype;
            }
        }

        return null;
    }//end findObjecttype()

    /**
     * Search objecttypes by a case-insensitive name/definition term.
     *
     * @param string $query A search term; empty returns all.
     *
     * @return array<int, array<string, mixed>> Matching objecttype records.
     *
     * @spec openspec/changes/schema-import-standards/specs/schema-import/spec.md
     */
    public function searchObjecttypes(string $query): array
    {
        $this->ensureParsed();

        $needle  = strtolower(trim($query));
        $results = [];
        foreach ($this->objecttypes as $objecttype) {
            if ($needle === ''
                || str_contains(strtolower((string) $objecttype['naam']), $needle) === true
                || str_contains(strtolower((string) $objecttype['definitie']), $needle) === true
            ) {
                $results[] = $objecttype;
            }
        }

        return $results;
    }//end searchObjecttypes()

    /**
     * Parse + index the snapshot once.
     *
     * @return void
     *
     * @throws SchemaImportException When the source is missing or malformed.
     */
    private function ensureParsed(): void
    {
        if ($this->objecttypes !== null) {
            return;
        }

        if ($this->override !== null) {
            $decoded = $this->override;
        } else {
            if (is_file($this->snapshotFile) === false) {
                throw new SchemaImportException(message: 'GGM snapshot file is missing: '.$this->snapshotFile, httpStatus: 500);
            }

            $raw     = (string) file_get_contents($this->snapshotFile);
            $decoded = json_decode($raw, associative: true);
        }

        if (is_array($decoded) === false || isset($decoded['objecttypen']) === false
            || is_array($decoded['objecttypen']) === false
        ) {
            throw new SchemaImportException(
                message: 'GGM source is not a valid normalised GGM intermediate (missing "objecttypen").',
                httpStatus: 422
            );
        }

        $this->loadedVersion = (string) ($decoded['version'] ?? $this->version);
        $this->objecttypes   = [];
        foreach ($decoded['objecttypen'] as $objecttype) {
            if (is_array($objecttype) === false || isset($objecttype['id']) === false) {
                continue;
            }

            $this->objecttypes[strtoupper((string) $objecttype['id'])] = $objecttype;
        }
    }//end ensureParsed()
}//end class
