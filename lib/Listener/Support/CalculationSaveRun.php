<?php

/**
 * OpenRegister CalculationSaveRun
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Calculation\SequenceContext;

/**
 * One save's calculation pass: the object, its schema slug, the sequence
 * context (create only) and the evaluation payload as it grows.
 *
 * Each calculation evaluates against the payload as the calculations before
 * it left it, so a later calculation may read an earlier one.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class CalculationSaveRun {

	/**
	 * Whether a calculation wrote a value that differs from the payload.
	 *
	 * @var boolean
	 */
	private bool $changed = false;

	/**
	 * Start a pass.
	 *
	 * @param ObjectEntity               $object          The object being saved.
	 * @param string                     $slug            The schema's slug.
	 * @param SequenceContext|null       $sequenceContext The create-only running-number context.
	 * @param array<string, mixed>       $payload         The evaluation payload.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly string $slug,
		public readonly ?SequenceContext $sequenceContext,
		private array $payload,
	) {
	}//end __construct()

	/**
	 * The payload as the calculations so far left it.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function payload(): array {
		return $this->payload;
	}//end payload()

	/**
	 * Write one calculated value, noting a change only when it differs.
	 *
	 * @param string $name  The calculation's name.
	 * @param mixed  $value The serialised value.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function write(string $name, mixed $value): void {
		if (($this->payload[$name] ?? null) !== $value) {
			$this->payload[$name] = $value;
			$this->changed = true;
		}
	}//end write()

	/**
	 * Whether any calculation changed the payload.
	 *
	 * @return boolean
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function changed(): bool {
		return $this->changed;
	}//end changed()
}//end class
