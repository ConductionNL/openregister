<?php

/**
 * OpenRegister application id rename
 *
 * The current and the new application id of one migrate-schema-application run.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use Symfony\Component\Console\Input\InputInterface;

/**
 * The application id schemas and registers move from, and the one they move to.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ApplicationIdRename {

	/**
	 * Hold both ids.
	 *
	 * @param string $from The current application id.
	 * @param string $to   The new application id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly string $from,
		public readonly string $to,
	) {
	}//end __construct()

	/**
	 * Read both ids from the command's from and to arguments.
	 *
	 * @param InputInterface $input The console input.
	 *
	 * @return self The rename.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public static function fromInput(InputInterface $input): self {
		return new self(from: (string)$input->getArgument('from'), to: (string)$input->getArgument('to'));
	}//end fromInput()

	/**
	 * Describe a move of this many schemas and registers between the two ids.
	 *
	 * @param int $schemas   The schemas moved.
	 * @param int $registers The registers moved.
	 *
	 * @return string The sentence, without its verb or full stop.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function describeMove(int $schemas, int $registers): string {
		return sprintf(
			'%d schema(s) and %d register(s) from "%s" to "%s"',
			$schemas,
			$registers,
			$this->from,
			$this->to
		);
	}//end describeMove()
}//end class
