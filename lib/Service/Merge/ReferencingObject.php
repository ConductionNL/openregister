<?php

/**
 * ReferencingObject: one object the relation index says references a losing record.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Merge
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Merge;

/**
 * The identity and data of an object that points at a losing record.
 *
 * @spec openspec/changes/mdm-merge-relinks-every-reference/specs/mdm-merge/spec.md#requirement-a-merge-relinks-every-reference-to-the-losing-record
 */
final readonly class ReferencingObject {

	/**
	 * Constructor.
	 *
	 * @param string               $uuid     The object's uuid.
	 * @param string|null          $register Its register.
	 * @param string|null          $schema   Its schema.
	 * @param array<string, mixed> $data     Its stored data.
	 */
	public function __construct(
		public string $uuid,
		public ?string $register,
		public ?string $schema,
		public array $data,
	) {
	}//end __construct()
}//end class
