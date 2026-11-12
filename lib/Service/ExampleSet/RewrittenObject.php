<?php

/**
 * OpenRegister Rewritten Object
 *
 * What a refresh wrote for one object, by its record key, as the record follows it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-rewrites-every-object-the-set-declares
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The record key of a rewritten object, `<app>/<key>`, and what the rewrite wrote for it.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-rewrites-every-object-the-set-declares
 */
final class RewrittenObject {

	/**
	 * The record key of the object, `<app>/<key>`.
	 *
	 * @var string
	 */
	public readonly string $name;

	/**
	 * What this run wrote for it.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $data;

	/**
	 * Constructor.
	 *
	 * @param string               $name The record key of the object, `<app>/<key>`.
	 * @param array<string, mixed> $data What this run wrote for it.
	 */
	public function __construct(string $name, array $data) {
		$this->name = $name;
		$this->data = $data;
	}//end __construct()
}//end class
