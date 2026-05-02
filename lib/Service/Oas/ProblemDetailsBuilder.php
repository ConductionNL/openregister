<?php

/**
 * ProblemDetailsBuilder — RFC 7807 problem-json response shape builder.
 *
 * RFC 7807 (https://datatracker.ietf.org/doc/html/rfc7807) standardises
 * machine-readable error payloads for HTTP APIs. Every problem document
 * carries:
 *
 *   - `type`     a URI identifying the problem class (default `about:blank`)
 *   - `title`    short human-readable summary
 *   - `status`   the HTTP status code, copied into the body
 *   - `detail`   per-occurrence explanation
 *   - `instance` URI identifying this specific occurrence
 *
 * Plus arbitrary custom extensions (e.g. `errors[]`, `code`).
 *
 * Errors emitted from OR controllers go through this builder so every
 * HTTP error response carries the same machine-readable shape and the
 * `Content-Type: application/problem+json` header.
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
 * @spec openspec/changes/oas-validation/tasks.md "API-46 Problem Details (RFC 7807)"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Oas;

/**
 * Builds RFC 7807 problem-details response payloads.
 */
class ProblemDetailsBuilder
{

    public const CONTENT_TYPE = 'application/problem+json';

    private const DEFAULT_TYPE = 'about:blank';

    /**
     * Build a problem document from the input parts.
     *
     * @param int    $status     HTTP status code (4xx / 5xx).
     * @param string $title      Short summary.
     * @param string $detail     Per-occurrence explanation.
     * @param string $type       Problem-class URI; defaults to `about:blank`.
     * @param string $instance   Instance URI; '' = omit.
     * @param array  $extensions Free-form extension fields (e.g. `errors`, `code`).
     *
     * @return array<string, mixed> The problem-json payload.
     */
    public function build(
        int $status,
        string $title,
        string $detail='',
        string $type=self::DEFAULT_TYPE,
        string $instance='',
        array $extensions=[]
    ): array {
        $problem = [
            'type'   => ($type !== '' ? $type : self::DEFAULT_TYPE),
            'title'  => $title,
            'status' => $status,
        ];

        if ($detail !== '') {
            $problem['detail'] = $detail;
        }

        if ($instance !== '') {
            $problem['instance'] = $instance;
        }

        // Extensions never overwrite the standard fields — RFC 7807
        // says custom fields MUST NOT clash. Filter them.
        foreach ($extensions as $key => $value) {
            if (in_array($key, ['type', 'title', 'status', 'detail', 'instance'], true) === true) {
                continue;
            }

            $problem[$key] = $value;
        }

        return $problem;

    }//end build()

    /**
     * Wrap a list of validation errors as a 422 problem document.
     *
     * @param array  $errors   List of validation errors (each free-form, typically `{path, message}`).
     * @param string $detail   Optional human-readable explanation.
     * @param string $instance Instance URI, '' = omit.
     *
     * @return array<string, mixed>
     */
    public function validationFailed(array $errors, string $detail='', string $instance=''): array
    {
        return $this->build(
            status: 422,
            title: 'Validation failed',
            detail: $detail,
            type: 'about:blank',
            instance: $instance,
            extensions: ['errors' => $errors]
        );

    }//end validationFailed()

    /**
     * Build a "not found" problem document (404).
     *
     * @param string $detail   Human-readable explanation.
     * @param string $instance Instance URI, '' = omit.
     *
     * @return array<string, mixed>
     */
    public function notFound(string $detail='', string $instance=''): array
    {
        return $this->build(
            status: 404,
            title: 'Not found',
            detail: $detail,
            instance: $instance
        );

    }//end notFound()

    /**
     * Build a "conflict" problem document (409 — e.g. lock conflict, ETag mismatch).
     *
     * @param string $detail   Human-readable explanation.
     * @param string $instance Instance URI, '' = omit.
     *
     * @return array<string, mixed>
     */
    public function conflict(string $detail='', string $instance=''): array
    {
        return $this->build(
            status: 409,
            title: 'Conflict',
            detail: $detail,
            instance: $instance
        );

    }//end conflict()
}//end class
