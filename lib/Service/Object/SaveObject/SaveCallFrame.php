<?php

/**
 * OpenRegister SaveCallFrame
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/reference-existence-validation/spec.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

/**
 * One object whose save is in flight.
 *
 * @spec openspec/specs/reference-existence-validation/spec.md
 */
final class SaveCallFrame {

	/**
	 * Constructor.
	 *
	 * @param string      $schemaSlug The schema slug, or its id when it has none.
	 * @param string      $uuid       The object uuid; empty when not yet known.
	 * @param string|null $register   The register identifier.
	 */
	public function __construct(
		public readonly string $schemaSlug,
		public readonly string $uuid,
		public readonly ?string $register,
	) {
	}//end __construct()

	/**
	 * The key this frame is found under on the stack.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/specs/reference-existence-validation/spec.md
	 */
	public function key(): string {
		return $this->schemaSlug . ':' . $this->uuid;
	}//end key()
}//end class
