<?php

/**
 * The filters and the page of a formatted file listing.
 *
 * Reads the filter parameters of a request, keeps the formatted files that
 * match ALL of them, and cuts out the requested page:
 * - _hasLabels / _noLabels: whether a file has labels
 * - labels: at least one of the labels, compared strictly
 * - extension / extensions: the extension, or one of them, ignoring case
 * - minSize / maxSize: the size in bytes, a missing size counting as 0
 * - title / search: text in the title, ignoring case
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

/**
 * Filters and pages formatted files.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class FileListFilter {
	/**
	 * The filter parameters and how each is normalised.
	 *
	 * @var array<string, string>
	 */
	private const PARAMETERS = [
		'_hasLabels' => 'bool',
		'_noLabels' => 'bool',
		'labels' => 'list',
		'extension' => 'trim',
		'extensions' => 'list',
		'minSize' => 'int',
		'maxSize' => 'int',
		'title' => 'trim',
	];

	/**
	 * The filter parameters of a request, normalised; null values are left out
	 * and a comma-separated list becomes an array.
	 *
	 * @param array<string, mixed> $requestParams The request parameters.
	 *
	 * @return array<string, mixed> The filters.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function extract(array $requestParams): array {
		$filters = [];
		foreach (self::PARAMETERS as $key => $kind) {
			$value = $this->normalised(kind: $kind, value: ($requestParams[$key] ?? null));
			if ($value !== null) {
				$filters[$key] = $value;
			}
		}

		$search = ($requestParams['search'] ?? $requestParams['_search'] ?? null);
		if ($search !== null) {
			$filters['search'] = trim($search);
		}

		return $filters;
	}//end extract()

	/**
	 * The formatted files that match every filter, keys preserved.
	 *
	 * @param array<int, array<string, mixed>> $files   The formatted files.
	 * @param array<string, mixed>             $filters The filters.
	 *
	 * @return array<int, array<string, mixed>> The matching files.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function apply(array $files, array $filters): array {
		if (empty($filters) === true) {
			return $files;
		}

		return array_filter(
			$files,
			fn (array $file): bool => $this->matchesLabels(file: $file, filters: $filters) === true
				&& $this->matchesExtension(file: $file, filters: $filters) === true
				&& $this->matchesSize(file: $file, filters: $filters) === true
				&& $this->matchesText(file: $file, filters: $filters) === true
		);
	}//end apply()

	/**
	 * One page of the files, with the paging figures.
	 *
	 * Both `_page`/`_limit` and `page`/`limit` are read. There is no upper
	 * ceiling on the limit: per-object attachment counts are the natural bound,
	 * and a cap silently truncated production listings. The floor of 1 keeps
	 * the arithmetic sound (design.md Decision 4).
	 *
	 * @param array<int, array<string, mixed>> $files         The filtered files.
	 * @param array<string, mixed>             $requestParams The request parameters.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int, page: int, pages: int, limit: int, offset: int}
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function page(array $files, array $requestParams): array {
		$page = max(1, (int)($requestParams['_page'] ?? $requestParams['page'] ?? 1));
		$limit = max(1, (int)($requestParams['_limit'] ?? $requestParams['limit'] ?? 30));
		$offset = (($page - 1) * $limit);
		$total = count($files);

		return [
			'results' => array_slice($files, $offset, $limit),
			'total' => $total,
			'page' => $page,
			'pages' => (int)ceil($total / $limit),
			'limit' => $limit,
			'offset' => $offset,
		];
	}//end page()

	/**
	 * One parameter's value normalised to its kind, or null to leave it out.
	 *
	 * @param string $kind  `bool`, `int`, `trim` or `list`.
	 * @param mixed  $value The raw value.
	 *
	 * @return mixed The normalised value.
	 */
	private function normalised(string $kind, mixed $value): mixed {
		if ($value === null) {
			return null;
		}

		if ($kind === 'list') {
			return $this->listOf(value: $value);
		}

		return match ($kind) {
			'bool' => (bool)$value,
			'int' => (int)$value,
			'trim' => trim($value),
			default => $value,
		};
	}//end normalised()

	/**
	 * A list parameter: a comma-separated string split and trimmed, an array
	 * kept as given, anything else left out.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return array<mixed>|null The list.
	 */
	private function listOf(mixed $value): ?array {
		if (is_string($value) === true) {
			return array_map('trim', explode(',', $value));
		}

		if (is_array($value) === false) {
			return null;
		}

		return $value;
	}//end listOf()

	/**
	 * Whether a file passes `_hasLabels`, `_noLabels` and `labels`.
	 *
	 * @param array<string, mixed> $file    The formatted file.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return bool
	 */
	private function matchesLabels(array $file, array $filters): bool {
		$hasLabels = empty($file['labels']) === false;
		if ($this->matchesLabelPresence(hasLabels: $hasLabels, filters: $filters) === false) {
			return false;
		}

		if (empty($filters['labels']) === true) {
			return true;
		}

		return $this->anyLabelPresent(required: $filters['labels'], fileLabels: ($file['labels'] ?? []));
	}//end matchesLabels()

	/**
	 * Whether a file passes `_hasLabels` and `_noLabels`.
	 *
	 * @param bool                 $hasLabels Whether the file has labels.
	 * @param array<string, mixed> $filters   The filters.
	 *
	 * @return bool
	 */
	private function matchesLabelPresence(bool $hasLabels, array $filters): bool {
		if (($filters['_hasLabels'] ?? null) !== null && $filters['_hasLabels'] !== $hasLabels) {
			return false;
		}

		return ($filters['_noLabels'] ?? null) !== true || $hasLabels === false;
	}//end matchesLabelPresence()

	/**
	 * Whether any required label is among the file's labels, compared strictly.
	 *
	 * @param array<mixed> $required   The labels of which one must be present.
	 * @param array<mixed> $fileLabels The file's labels.
	 *
	 * @return bool
	 */
	private function anyLabelPresent(array $required, array $fileLabels): bool {
		foreach ($required as $requiredLabel) {
			if (in_array($requiredLabel, $fileLabels, true) === true) {
				return true;
			}
		}

		return false;
	}//end anyLabelPresent()

	/**
	 * Whether a file passes `extension` and `extensions`, ignoring case.
	 *
	 * @param array<string, mixed> $file    The formatted file.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return bool
	 */
	private function matchesExtension(array $file, array $filters): bool {
		$fileExtension = $file['extension'] ?? '';
		if (($filters['extension'] ?? null) !== null && strcasecmp($fileExtension, $filters['extension']) !== 0) {
			return false;
		}

		if (empty($filters['extensions']) === true) {
			return true;
		}

		return $this->anyExtensionMatches(fileExtension: $fileExtension, allowed: $filters['extensions']);
	}//end matchesExtension()

	/**
	 * Whether the extension equals one of the allowed ones, ignoring case.
	 *
	 * @param string       $fileExtension The file's extension.
	 * @param array<mixed> $allowed       The allowed extensions.
	 *
	 * @return bool
	 */
	private function anyExtensionMatches(string $fileExtension, array $allowed): bool {
		foreach ($allowed as $allowedExtension) {
			if (strcasecmp($fileExtension, $allowedExtension) === 0) {
				return true;
			}
		}

		return false;
	}//end anyExtensionMatches()

	/**
	 * Whether a file's size lies within `minSize` and `maxSize`.
	 *
	 * @param array<string, mixed> $file    The formatted file.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return bool
	 */
	private function matchesSize(array $file, array $filters): bool {
		$fileSize = $file['size'] ?? 0;
		if (($filters['minSize'] ?? null) !== null && $fileSize < $filters['minSize']) {
			return false;
		}

		return ($filters['maxSize'] ?? null) === null || $fileSize <= $filters['maxSize'];
	}//end matchesSize()

	/**
	 * Whether a file's title contains `title` and `search`, ignoring case.
	 *
	 * @param array<string, mixed> $file    The formatted file.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return bool
	 */
	private function matchesText(array $file, array $filters): bool {
		$fileTitle = $file['title'] ?? '';
		foreach (['title', 'search'] as $key) {
			if (empty($filters[$key]) === false && stripos($fileTitle, $filters[$key]) === false) {
				return false;
			}
		}

		return true;
	}//end matchesText()
}//end class
