<?php

/**
 * A `_where` condition group that cannot be parsed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
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

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * Carries the message and the node path of the row that failed, so a caller
 * can point at the offending part of the tree rather than the whole parameter.
 *
 * @spec openspec/changes/list-query-condition-groups/specs/zoeken-filteren/spec.md
 */
class ConditionGroupException extends Exception {

	/**
	 * The node path of the failing row, such as `$.children[1].children[0]`.
	 *
	 * @var string
	 */
	private readonly string $path;

	/**
	 * Constructor.
	 *
	 * @param string $message What is wrong with the tree.
	 * @param string $path    The node path of the failing row.
	 */
	public function __construct(string $message, string $path) {
		parent::__construct(message: $message);
		$this->path = $path;
	}//end __construct()

	/**
	 * The node path of the failing row.
	 *
	 * @return string The path, such as `$.children[1].children[0]`.
	 *
	 * @spec openspec/changes/list-query-condition-groups/specs/zoeken-filteren/spec.md
	 */
	public function getPath(): string {
		return $this->path;
	}//end getPath()

	/**
	 * The 400 response body: the message and the path of the failing row.
	 *
	 * @return array{error: string, path: string} The body.
	 *
	 * @spec openspec/changes/list-query-condition-groups/specs/zoeken-filteren/spec.md
	 */
	public function toResponseBody(): array {
		return ['error' => $this->getMessage(), 'path' => $this->path];
	}//end toResponseBody()
}//end class
