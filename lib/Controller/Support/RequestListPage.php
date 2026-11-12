<?php

/**
 * The limit and offset a list request asks for.
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
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

/**
 * The `_limit`, `_offset` and `_page` of a list request; a page with a limit sets the offset.
 *
 * @spec exclude shared controller plumbing, not a capability of its own
 */
final class RequestListPage {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 */
	public function __construct(
		private readonly array $params,
	) {
	}//end __construct()

	/**
	 * The page size asked for, or null without `_limit`.
	 *
	 * @return int|null The limit.
	 *
	 * @spec exclude shared controller plumbing, not a capability of its own
	 */
	public function limit(): ?int {
		return $this->intParam(key: '_limit');
	}//end limit()

	/**
	 * The offset asked for: from `_page` when it comes with a limit, else `_offset`, else null.
	 *
	 * @return int|null The offset.
	 *
	 * @spec exclude shared controller plumbing, not a capability of its own
	 */
	public function offset(): ?int {
		$limit = $this->limit();
		$page = $this->intParam(key: '_page');
		if ($page !== null && $limit !== null) {
			return (($page - 1) * $limit);
		}

		return $this->intParam(key: '_offset');
	}//end offset()

	/**
	 * A parameter as an integer, or null when it is not set.
	 *
	 * @param string $key The parameter name.
	 *
	 * @return int|null The value.
	 */
	private function intParam(string $key): ?int {
		if (isset($this->params[$key]) === true) {
			return (int)$this->params[$key];
		}

		return null;
	}//end intParam()
}//end class
