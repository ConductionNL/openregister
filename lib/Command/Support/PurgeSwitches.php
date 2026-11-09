<?php

/**
 * OpenRegister purge switches
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command\Support
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

namespace OCA\OpenRegister\Command\Support;

/**
 * The --force and --apply switches an operator passed to a purge run.
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
 */
final readonly class PurgeSwitches {
	/**
	 * Hold the switches.
	 *
	 * @param bool $force Whether archival and live rows may be purged.
	 * @param bool $apply Whether to actually write.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public function __construct(
		public bool $force,
		public bool $apply,
	) {
	}//end __construct()
}//end class
