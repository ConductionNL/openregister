<?php

/**
 * The input checks the GitHub-issues endpoints run on a request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

use OCP\AppFramework\Http\JSONResponse;

/**
 * The input checks the GitHub-issues endpoints run on a request.
 *
 * Bound to GitHubRequestValidator in ContractBindings. Every method is described on GitHubRequestValidator.
 */
interface GitHubRequestValidatorInterface {

	/**
	 * Validate the repo parameter against the strict `<owner>/<repo>` regex.
	 *
	 * @param string $repo Caller-supplied slug.
	 *
	 * @return JSONResponse|null Null on success, 400 `repo_invalid_format` on failure.
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-3
	 */
	public function validateRepoFormat(string $repo): ?JSONResponse;

	/**
	 * Validate `per_page` is in [1, 100].
	 *
	 * @param int $perPage Caller-supplied page size.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-3
	 */
	public function validatePerPage(int $perPage): ?JSONResponse;

	/**
	 * Validate POST `title` length (3-200 chars).
	 *
	 * @param string $title Caller-supplied title.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-3
	 */
	public function validateTitleLength(string $title): ?JSONResponse;

	/**
	 * Validate POST `body` is ≥ 10 characters.
	 *
	 * @param string $body Caller-supplied body.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-3
	 */
	public function validateBodyLength(string $body): ?JSONResponse;

	/**
	 * Validate optional specRef field (task 1.16).
	 *
	 * @param string|null $specRef Caller-supplied slug, or null.
	 *
	 * @return JSONResponse|null Null on success/absence, 400 `specref_invalid_format` on failure.
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-16
	 */
	public function validateSpecRef(?string $specRef): ?JSONResponse;

	/**
	 * Validate the GET `sort` parameter (task 1.20).
	 *
	 * @param string $sort Caller-supplied sort key.
	 *
	 * @return JSONResponse|null Null on allowed value, 400 `sort_invalid_value` otherwise.
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-20
	 */
	public function validateSort(string $sort): ?JSONResponse;

	/**
	 * Validate optional GET `labels` filter (task 1.20b).
	 *
	 * @param array<string>|null $labels Parsed label list, or null when filter absent.
	 *
	 * @return JSONResponse|null Null on success/absence, 400 with structured error_code on failure.
	 *
	 * @spec openspec/specs/github-issue-proxy/spec.md#issues-list-endpoint
	 */
	public function validateLabels(?array $labels): ?JSONResponse;
}//end interface
