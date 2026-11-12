<?php

/**
 * OpenRegister Fragment Declarations
 *
 * What one fragment declares of one record part, as the record lets go of what it no longer declares.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-the-record-lets-go-of-a-held-entry-the-set-no-longer-declares
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The app's record-key prefix, `<app>/`, and the record keys of the declarations of one part in a fragment.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-the-record-lets-go-of-a-held-entry-the-set-no-longer-declares
 */
final class FragmentDeclarations {

	/**
	 * The app's prefix, `<app>/`.
	 *
	 * @var string
	 */
	public readonly string $prefix;

	/**
	 * The record keys the fragment declares.
	 *
	 * @var array<string, bool>
	 */
	public readonly array $keys;

	/**
	 * Constructor.
	 *
	 * @param string              $prefix The app's prefix, `<app>/`.
	 * @param array<string, bool> $keys   The record keys the fragment declares.
	 */
	public function __construct(string $prefix, array $keys) {
		$this->prefix = $prefix;
		$this->keys   = $keys;
	}//end __construct()
}//end class
