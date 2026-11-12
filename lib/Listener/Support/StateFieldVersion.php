<?php

/**
 * OpenRegister StateFieldVersion
 *
 * One version of an object as the state field rules read it: its data and the
 * lifecycle state that data puts it in.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener\Support;

/**
 * An object's data and the state it is in.
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */
final class StateFieldVersion {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data  The object's data.
	 * @param string|null          $state The state that data is in.
	 */
	public function __construct(
		public readonly array $data,
		public readonly ?string $state,
	) {
	}//end __construct()
}//end class
