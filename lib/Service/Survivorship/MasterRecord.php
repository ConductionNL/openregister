<?php

/**
 * The master object whose competing source records are resolved.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Survivorship
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Survivorship;

/**
 * A master's payload (embedded mode reads its source-link field), its uuid
 * (reverse-FK mode filters on it) and its register ref (the reverse-FK source
 * register when the annotation omits one).
 *
 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.1
 */
final class MasterRecord {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data     Master object payload.
	 * @param string               $uuid     Master object uuid.
	 * @param string               $register Master's register ref; empty when unknown.
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.1
	 */
	public function __construct(
		public readonly array $data,
		public readonly string $uuid,
		public readonly string $register='',
	) {
	}//end __construct()
}//end class
