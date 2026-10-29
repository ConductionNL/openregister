<?php

/**
 * OpenRegister rematerialise run
 *
 * The state one run of the rematerialise-calculations command shares between
 * its steps: the target, the calculations it evaluates, the console and the tally.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One run of the rematerialise-calculations command over a (register, schema).
 */
final class RematerialiseRun {

	/**
	 * Every calculation the schema declares, by name.
	 *
	 * @var array<array-key, mixed>
	 */
	public readonly array $calculations;

	/**
	 * The calculations declared with `materialise: true`, by name, in declared order.
	 *
	 * @var array<array-key, array<string, mixed>>
	 */
	public readonly array $materialised;

	/**
	 * The declared cross-object references, or null when there are none.
	 *
	 * @var array<array-key, mixed>|null
	 */
	public readonly ?array $referenceSpecs;

	/**
	 * The declared aggregate-references, or null when there are none.
	 *
	 * @var array<array-key, mixed>|null
	 */
	public readonly ?array $aggregateSpecs;

	/**
	 * Whether the run only reports and saves nothing.
	 *
	 * @var boolean
	 */
	public bool $dryRun = false;

	/**
	 * Rows saved and materialised, or that would be in a dry run.
	 *
	 * @var integer
	 */
	public int $touched = 0;

	/**
	 * Rows whose calculations already hold their stored values.
	 *
	 * @var integer
	 */
	public int $unchanged = 0;

	/**
	 * Failed evaluations, aggregates and saves.
	 *
	 * @var integer
	 */
	public int $failed = 0;

	/**
	 * Read the calculations, references and aggregate-references off the schema.
	 *
	 * @param Register        $register The register the rows live in.
	 * @param Schema          $schema   The schema that declares the calculations.
	 * @param OutputInterface $output   The console the run reports to.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function __construct(
		public readonly Register $register,
		public readonly Schema $schema,
		public readonly OutputInterface $output,
	) {
		$config = ($schema->getConfiguration() ?? []);
		$calculations = ($config['x-openregister-calculations'] ?? null);
		if (is_array($calculations) === false) {
			$calculations = [];
		}

		$this->calculations = $calculations;
		$this->materialised = array_filter(
			$calculations,
			static fn ($spec): bool => is_array($spec) === true && ($spec['materialise'] ?? false) === true
		);
		$this->referenceSpecs = self::nonEmptyList(value: ($config['x-openregister-references'] ?? null));
		$this->aggregateSpecs = self::nonEmptyList(value: ($config['x-openregister-aggregate-refs'] ?? null));
	}//end __construct()

	/**
	 * The calculations declared with `fallback: true`: an app's own save may keep its value.
	 *
	 * @return array<array-key, mixed> The fallback calculations, by name.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function fallbacks(): array {
		return array_filter(
			$this->calculations,
			static fn ($spec): bool => is_array($spec) === true && ($spec['fallback'] ?? false) === true
		);
	}//end fallbacks()

	/**
	 * The command's exit code: failure when anything failed.
	 *
	 * @return int The Symfony exit code.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function exitCode(): int {
		if ($this->failed > 0) {
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}//end exitCode()

	/**
	 * A declaration block when it is a non-empty array, null otherwise.
	 *
	 * @param mixed $value The configuration value.
	 *
	 * @return array<array-key, mixed>|null The block, or null.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	private static function nonEmptyList(mixed $value): ?array {
		if (is_array($value) === false || count($value) === 0) {
			return null;
		}

		return $value;
	}//end nonEmptyList()
}//end class
