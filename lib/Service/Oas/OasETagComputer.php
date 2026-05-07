<?php

/**
 * OasETagComputer — deterministic ETag for the generated OAS document.
 *
 * The OAS spec is generated from the live route table + schema
 * registrations, so two requests with the same registered shape MUST
 * produce the same ETag. The hash is computed over a canonical-JSON
 * representation (sorted keys) so structurally-equivalent specs that
 * differ only in property order produce the same ETag.
 *
 * Used by OasController to short-circuit identical fetches with a
 * `304 Not Modified` when the client sends an `If-None-Match` matching
 * the current ETag.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Oas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/oas-validation/tasks.md "Performance Impact of Validation / ETag caching"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Oas;

/**
 * Compute and verify ETags for OAS documents.
 */
class OasETagComputer
{
    /**
     * Compute the strong ETag for an OAS document.
     *
     * @param array $oas The generated OAS payload.
     *
     * @return string Strong ETag, quoted per RFC 7232 (e.g. `"abc123"`).
     */
    public function computeETag(array $oas): string
    {
        return '"'.$this->hash(spec: $oas).'"';

    }//end computeETag()

    /**
     * Compute the raw hash (unquoted).
     *
     * @param array $spec The OAS payload.
     *
     * @return string The hex-encoded SHA-256 hash.
     */
    public function hash(array $spec): string
    {
        $canonical = $this->canonicalise(value: $spec);
        return hash('sha256', (string) json_encode($canonical));

    }//end hash()

    /**
     * Test whether an `If-None-Match` header value matches the current ETag.
     *
     * Implements the RFC 7232 weak-comparison rule for `If-None-Match`:
     * a `*` matches any current ETag; a list of ETags matches when any
     * one is equal to the current. Whitespace is stripped.
     *
     * @param string $ifNoneMatch The raw `If-None-Match` header value.
     * @param string $currentETag The currently computed ETag (quoted).
     *
     * @return bool True when a 304 response can be returned.
     */
    public function matches(string $ifNoneMatch, string $currentETag): bool
    {
        $needle = trim($ifNoneMatch);
        if ($needle === '') {
            return false;
        }

        if ($needle === '*') {
            return true;
        }

        foreach (explode(',', $needle) as $candidate) {
            $candidate = trim($candidate);
            // Strip the optional weak prefix per RFC 7232.
            if (str_starts_with($candidate, 'W/') === true) {
                $candidate = substr($candidate, 2);
            }

            if ($candidate === $currentETag) {
                return true;
            }
        }

        return false;

    }//end matches()

    /**
     * Recursively sort object keys to produce a deterministic JSON shape.
     *
     * @param mixed $value The value to canonicalise.
     *
     * @return mixed Canonical-shape value.
     */
    private function canonicalise(mixed $value): mixed
    {
        if (is_array($value) === false) {
            return $value;
        }

        // Numerically-indexed arrays (lists) keep their order;
        // associative arrays (objects) get sorted by key.
        $isList = (array_is_list($value) === true);

        $out = [];
        if ($isList === true) {
            foreach ($value as $item) {
                $out[] = $this->canonicalise(value: $item);
            }

            return $out;
        }

        $keys = array_keys($value);
        sort($keys);
        foreach ($keys as $key) {
            $out[$key] = $this->canonicalise(value: $value[$key]);
        }

        return $out;

    }//end canonicalise()
}//end class
