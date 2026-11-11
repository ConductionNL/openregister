<?php

/**
 * One run of occ openregister:schema:reset-to-shipped.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The operator's arguments and the console of one reset-to-shipped run.
 *
 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
 */
final class ResetToShippedRun {

	/**
	 * Hold the run's console.
	 *
	 * @param InputInterface  $input  The schema and part arguments and the --apply / --actor options.
	 * @param OutputInterface $output The console output stream.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
	 */
	public function __construct(
		public readonly InputInterface $input,
		public readonly OutputInterface $output,
	) {
	}//end __construct()

	/**
	 * The schema argument: an id, uuid or slug.
	 *
	 * @return string The schema reference.
	 *
	 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
	 */
	public function schema(): string {
		return (string)$this->input->getArgument('schema');
	}//end schema()

	/**
	 * The part argument, as a dotted path.
	 *
	 * @return string The part.
	 *
	 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
	 */
	public function part(): string {
		return (string)$this->input->getArgument('part');
	}//end part()

	/**
	 * The --actor option, trimmed; empty when it was not given.
	 *
	 * @return string The administrator's uid.
	 *
	 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
	 */
	public function actor(): string {
		return trim((string)($this->input->getOption('actor') ?? ''));
	}//end actor()
}//end class
