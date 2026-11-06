<?php

/**
 * OpenRegister object request parameters
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

/**
 * Turns raw request values into the shapes the object endpoints work with.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class ObjectRequestParams {

	/**
	 * Old extend names and the names that replaced them.
	 */
	private const EXTEND_ALIASES = [
		'@self.schema'   => '_schema',
		'@self.register' => '_register',
	];

	/**
	 * The listing configuration of a request.
	 *
	 * @param array<string, mixed> $params The request parameters, without the route keys.
	 *
	 * @return array{
	 *     limit: int,
	 *     offset: int|null,
	 *     page: int|null,
	 *     filters: array,
	 *     sort: array,
	 *     _search: mixed|null,
	 *     _extend: array|null,
	 *     _fields: mixed|null,
	 *     _unset: mixed|null,
	 *     ids: null
	 * }
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function listing(array $params): array {
		return ($this->paging(params: $params) + [
			'filters' => $params,
			'sort'    => $this->order(order: $params['order'] ?? $params['_order'] ?? []),
			'_search' => ($params['_search'] ?? null),
			'_extend' => $this->extend(extend: $params['extend'] ?? $params['_extend'] ?? null),
			'_fields' => ($params['fields'] ?? $params['_fields'] ?? null),
			'_unset'  => ($params['unset'] ?? $params['_unset'] ?? null),
			'ids'     => null,
		]);
	}//end listing()

	/**
	 * The order parameter as an array; a JSON string is decoded, anything else is empty.
	 *
	 * @param mixed $order The order parameter from the request.
	 *
	 * @return array The order.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function order(mixed $order): array {
		if (is_array($order) === true) {
			return $order;
		}

		if (is_string($order) === false) {
			return [];
		}

		$decoded = json_decode($order, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return [];
	}//end order()

	/**
	 * The extend parameter as an array, with old names replaced.
	 *
	 * @param mixed $extend The extend parameter: a comma-separated string or an array.
	 *
	 * @return array|null The extend list, or null for any other value.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function extend(mixed $extend): ?array {
		if (is_string($extend) === true) {
			$extend = explode(',', $extend);
		}

		if (is_array($extend) === false) {
			return null;
		}

		return array_map(fn (mixed $value): mixed => $this->currentExtendName(value: $value), $extend);
	}//end extend()

	/**
	 * A multi-value parameter as a list: an array or a comma-separated string.
	 *
	 * @param mixed  $param        The parameter value.
	 * @param string $defaultValue The value when the parameter is null or empty.
	 *
	 * @return array The values.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function multiValue(mixed $param, string $defaultValue): array {
		if ($param === null || $param === '') {
			return [$defaultValue];
		}

		if (is_array($param) === true) {
			return $this->distinct(values: $param);
		}

		return $this->commaValues(param: $param);
	}//end multiValue()

	/**
	 * Form values with JSON arrays and objects decoded.
	 *
	 * Nested values of a multipart/form-data request arrive as JSON-encoded strings.
	 *
	 * @param array $data The form values.
	 *
	 * @return array The values, JSON arrays and objects decoded.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function decodedFormValues(array $data): array {
		return array_map(fn (mixed $value): mixed => $this->decodedFormValue(value: $value), $data);
	}//end decodedFormValues()

	/**
	 * The limit, offset and page of a request; without an offset, the page gives it.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return array{limit: int, offset: int|null, page: int|null} The paging.
	 */
	private function paging(array $params): array {
		$limit = (int)($params['limit'] ?? $params['_limit'] ?? 20);
		$page  = $this->optionalInt(params: $params, name: 'page');

		return [
			'limit'  => $limit,
			'offset' => ($this->optionalInt(params: $params, name: 'offset') ?? $this->pageOffset(page: $page, limit: $limit)),
			'page'   => $page,
		];
	}//end paging()

	/**
	 * An integer parameter under its own name or its underscore name; its own name wins.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param string               $name   The name without underscore.
	 *
	 * @return int|null The value, or null when neither is set.
	 */
	private function optionalInt(array $params, string $name): ?int {
		$value = ($params[$name] ?? $params['_'.$name] ?? null);
		if ($value === null) {
			return null;
		}

		return (int)$value;
	}//end optionalInt()

	/**
	 * The offset of a page.
	 *
	 * @param int|null $page  The page, counted from 1.
	 * @param int      $limit The page size.
	 *
	 * @return int|null The offset, or null without a page.
	 */
	private function pageOffset(?int $page, int $limit): ?int {
		if ($page === null) {
			return null;
		}

		return (($page - 1) * $limit);
	}//end pageOffset()

	/**
	 * The current name of one extend value; anything but an old name stays as it is.
	 *
	 * @param mixed $value The extend value.
	 *
	 * @return mixed The value.
	 */
	private function currentExtendName(mixed $value): mixed {
		if (is_string($value) === false) {
			return $value;
		}

		return (self::EXTEND_ALIASES[$value] ?? $value);
	}//end currentExtendName()

	/**
	 * The trimmed parts of a comma-separated string, or the value itself as the only entry.
	 *
	 * @param mixed $param The value.
	 *
	 * @return array The values.
	 */
	private function commaValues(mixed $param): array {
		if (is_string($param) === true && str_contains($param, ',') === true) {
			return $this->distinct(values: array_map('trim', explode(',', $param)));
		}

		return [$param];
	}//end commaValues()

	/**
	 * The non-empty values, once each, as a list.
	 *
	 * @param array $values The values.
	 *
	 * @return array The list.
	 */
	private function distinct(array $values): array {
		return array_values(array_unique(array_filter($values)));
	}//end distinct()

	/**
	 * One form value, decoded when it is a string holding a JSON array or object.
	 *
	 * @param mixed $value The form value.
	 *
	 * @return mixed The decoded array, or the value.
	 */
	private function decodedFormValue(mixed $value): mixed {
		if (is_string($value) === false) {
			return $value;
		}

		$trimmed = trim($value);
		if (in_array(($trimmed[0] ?? ''), ['[', '{'], true) === false) {
			return $value;
		}

		$decoded = json_decode($trimmed, associative: true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return $value;
	}//end decodedFormValue()
}//end class
