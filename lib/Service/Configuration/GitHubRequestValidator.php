<?php

/**
 * OpenRegister GitHub Request Validator.
 *
 * Pure-function validators for the GitHub issues proxy request shape: repo slug, page size,
 * title length, body length, specRef format, sort key, labels filter. Each method returns
 * either `null` (continue) or a `JSONResponse` (short-circuit with a 400 + structured
 * error_code). No DI dependencies — the class is intentionally stateless so it can be
 * instantiated cheaply and the methods can be tested in isolation.
 *
 * Separated from `GitHubGuards` so each class stays under PHPMD's TooManyPublicMethods
 * threshold and the two responsibilities (input validation vs. policy enforcement) stay
 * clearly distinct.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Stateless input validators for the GitHub issues proxy endpoints.
 *
 * @package OCA\OpenRegister\Service\Configuration
 *
 * @psalm-suppress UnusedClass
 */
class GitHubRequestValidator
{
    /**
     * Allowed `sort` query parameter values (task 1.20).
     */
    private const SORT_ALLOWLIST = ['reactions-+1', 'created', 'updated', 'comments'];

    /**
     * Maximum number of comma-separated `labels` entries (task 1.20b).
     */
    private const LABELS_MAX_COUNT = 8;

    /**
     * Maximum length of any single label name (task 1.20b).
     */
    private const LABEL_MAX_LENGTH = 50;

    /**
     * SpecRef format regex: kebab-case slug (task 1.16).
     */
    private const SPECREF_REGEX = '/^[a-z0-9][a-z0-9-]*[a-z0-9]$/';

    /**
     * Maximum specRef length (task 1.16).
     */
    private const SPECREF_MAX_LENGTH = 80;

    /**
     * Single per-label regex (task 1.20b).
     */
    private const LABEL_REGEX = '/^[a-z][a-z0-9_-]*$/';

    /**
     * Validate the repo parameter against the strict `<owner>/<repo>` regex.
     *
     * @param string $repo Caller-supplied slug.
     *
     * @return JSONResponse|null Null on success, 400 `repo_invalid_format` on failure.
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-3
     */
    public function validateRepoFormat(string $repo): ?JSONResponse
    {
        if (preg_match('#^[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*$#', $repo) === 1) {
            return null;
        }

        return new JSONResponse(['error' => 'repo_invalid_format'], Http::STATUS_BAD_REQUEST);
    }//end validateRepoFormat()

    /**
     * Validate `per_page` is in [1, 100].
     *
     * @param int $perPage Caller-supplied page size.
     *
     * @return JSONResponse|null
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-3
     */
    public function validatePerPage(int $perPage): ?JSONResponse
    {
        if ($perPage >= 1 && $perPage <= 100) {
            return null;
        }

        return new JSONResponse(['error' => 'per_page_out_of_range'], Http::STATUS_BAD_REQUEST);
    }//end validatePerPage()

    /**
     * Validate POST `title` length (3-200 chars).
     *
     * @param string $title Caller-supplied title.
     *
     * @return JSONResponse|null
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-3
     */
    public function validateTitleLength(string $title): ?JSONResponse
    {
        $length = strlen($title);
        if ($length >= 3 && $length <= 200) {
            return null;
        }

        return new JSONResponse(['error' => 'title_invalid_length'], Http::STATUS_BAD_REQUEST);
    }//end validateTitleLength()

    /**
     * Validate POST `body` is ≥ 10 characters.
     *
     * @param string $body Caller-supplied body.
     *
     * @return JSONResponse|null
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-3
     */
    public function validateBodyLength(string $body): ?JSONResponse
    {
        if (strlen($body) >= 10) {
            return null;
        }

        return new JSONResponse(['error' => 'body_invalid_length'], Http::STATUS_BAD_REQUEST);
    }//end validateBodyLength()

    /**
     * Validate optional specRef field (task 1.16).
     *
     * @param string|null $specRef Caller-supplied slug, or null.
     *
     * @return JSONResponse|null Null on success/absence, 400 `specref_invalid_format` on failure.
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-16
     */
    public function validateSpecRef(?string $specRef): ?JSONResponse
    {
        if ($specRef === null) {
            return null;
        }

        if (strlen($specRef) > self::SPECREF_MAX_LENGTH) {
            return new JSONResponse(['error' => 'specref_invalid_format'], Http::STATUS_BAD_REQUEST);
        }

        if (preg_match(self::SPECREF_REGEX, $specRef) === 1) {
            return null;
        }

        return new JSONResponse(['error' => 'specref_invalid_format'], Http::STATUS_BAD_REQUEST);
    }//end validateSpecRef()

    /**
     * Validate the GET `sort` parameter (task 1.20).
     *
     * @param string $sort Caller-supplied sort key.
     *
     * @return JSONResponse|null Null on allowed value, 400 `sort_invalid_value` otherwise.
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-20
     */
    public function validateSort(string $sort): ?JSONResponse
    {
        if (in_array($sort, self::SORT_ALLOWLIST, true) === true) {
            return null;
        }

        return new JSONResponse(['error' => 'sort_invalid_value'], Http::STATUS_BAD_REQUEST);
    }//end validateSort()

    /**
     * Validate optional GET `labels` filter (task 1.20b).
     *
     * @param array<string>|null $labels Parsed label list, or null when filter absent.
     *
     * @return JSONResponse|null Null on success/absence, 400 with structured error_code on failure.
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-20b
     */
    public function validateLabels(?array $labels): ?JSONResponse
    {
        if ($labels === null || $labels === []) {
            return null;
        }

        if (count($labels) > self::LABELS_MAX_COUNT) {
            return new JSONResponse(['error' => 'labels_too_many'], Http::STATUS_BAD_REQUEST);
        }

        foreach ($labels as $label) {
            if (strlen($label) > self::LABEL_MAX_LENGTH) {
                return new JSONResponse(['error' => 'labels_invalid_format'], Http::STATUS_BAD_REQUEST);
            }

            if (preg_match(self::LABEL_REGEX, $label) !== 1) {
                return new JSONResponse(['error' => 'labels_invalid_format'], Http::STATUS_BAD_REQUEST);
            }
        }

        return null;
    }//end validateLabels()
}//end class
