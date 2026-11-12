<?php

/**
 * OpenRegister VirtualCalculations
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use DateTimeInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;
use Psr\Log\LoggerInterface;

/**
 * Evaluates a schema's virtual calculations against rendered data.
 *
 * Materialised calculations are persisted on save and skipped here.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class VirtualCalculations {

	/**
	 * Constructor.
	 *
	 * @param CalculationEvaluatorInterface $evaluator Evaluates an expression.
	 * @param LoggerInterface               $logger    Logs a calculation that failed.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function __construct(
		private readonly CalculationEvaluatorInterface $evaluator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The calculations a schema declares.
	 *
	 * @param Schema|null $schema The schema.
	 *
	 * @return array<int|string, mixed> The declarations by name.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public static function declaredBy(?Schema $schema): array {
		$calculations = (($schema?->getConfiguration() ?? [])['x-openregister-calculations'] ?? null);
		if (is_array($calculations) === false) {
			return [];
		}

		return $calculations;
	}//end declaredBy()

	/**
	 * The value of every virtual calculation; a failed one is left out.
	 *
	 * @param array<int|string, mixed> $calculations The declarations by name.
	 * @param array<string, mixed>     $payload      The payload.
	 *
	 * @return array<string, mixed> The values by name.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function values(array $calculations, array $payload): array {
		$values = [];
		foreach ($calculations as $name => $spec) {
			if (is_array($spec) === false || ($spec['materialise'] ?? false) === true) {
				continue;
			}

			try {
				$values[(string)$name] = $this->value(spec: $spec, payload: $payload);
			} catch (\Throwable $e) {
				$this->logger->debug(sprintf('[RenderObject] Virtual calculation "%s" failed: %s', (string)$name, $e->getMessage()));
			}
		}

		return $values;
	}//end values()

	/**
	 * The value of one calculation; a date comes back as ISO 8601.
	 *
	 * @param array<string, mixed> $spec    The declaration.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return mixed The value.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	private function value(array $spec, array $payload): mixed {
		$value = $this->evaluator->evaluate($payload, $spec['expression'] ?? null);
		if ($value instanceof DateTimeInterface) {
			return $value->format(DateTimeInterface::ATOM);
		}

		return $value;
	}//end value()
}//end class
