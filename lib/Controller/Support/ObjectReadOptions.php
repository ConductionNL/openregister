<?php

/**
 * OpenRegister ObjectReadOptions
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
 * How one object is rendered for its reader: the options of the request and the reader's scope.
 *
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 */
final class ObjectReadOptions {
	/**
	 * The extensions asked for, normalised.
	 *
	 * @var mixed
	 */
	public readonly mixed $extend;

	/**
	 * The `_filter` keys.
	 *
	 * @var mixed
	 */
	public readonly mixed $filter;

	/**
	 * The `_fields` keys.
	 *
	 * @var mixed
	 */
	public readonly mixed $fields;

	/**
	 * The `_unset` keys.
	 *
	 * @var mixed
	 */
	public readonly mixed $unset;

	/**
	 * Whether RBAC and multitenancy apply: true for everyone but an admin.
	 *
	 * @var boolean
	 */
	public readonly bool $scoped;

	/**
	 * Read the options from the request parameters.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param bool                 $admin  Whether the caller is an admin.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function __construct(array $params, bool $admin) {
		$this->extend = (new ObjectRequestParams())->extend(extend: ($params['extend'] ?? $params['_extend'] ?? null));
		$this->filter = $this->split(value: ($params['filter'] ?? $params['_filter'] ?? null));
		$this->fields = $this->split(value: ($params['fields'] ?? $params['_fields'] ?? null));
		$this->unset = $this->split(value: ($params['unset'] ?? $params['_unset'] ?? null));
		$this->scoped = ($admin === false);
	}//end __construct()

	/**
	 * A comma-separated string as a list; anything else as it came.
	 *
	 * @param mixed $value The parameter.
	 *
	 * @return mixed The value.
	 */
	private function split(mixed $value): mixed {
		if (is_string($value) === true) {
			return explode(',', $value);
		}

		return $value;
	}//end split()
}//end class
