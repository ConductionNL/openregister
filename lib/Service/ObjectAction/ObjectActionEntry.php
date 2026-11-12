<?php

/**
 * One declared action as its shape checks read it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

/**
 * A declared action's key and its declared entry.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
 */
final class ObjectActionEntry {

	/**
	 * The action key.
	 *
	 * @var string
	 */
	public readonly string $key;

	/**
	 * The declared entry.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $definition;

	/**
	 * Constructor.
	 *
	 * @param string               $key        The action key.
	 * @param array<string, mixed> $definition The declared entry.
	 */
	public function __construct(string $key, array $definition) {
		$this->key        = $key;
		$this->definition = $definition;
	}//end __construct()
}//end class
