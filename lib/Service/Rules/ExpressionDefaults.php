<?php

/**
 * OpenRegister ExpressionDefaults
 *
 * The rules engine's answer to the Core expression-defaults port: the resolver
 * over the calculation evaluator, scoped to the request's user session.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\Core\ExpressionDefaultsInterface;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolvers;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCP\IUserSession;

/**
 * Derives a schema's expression defaults on create, refusing what cannot be derived.
 *
 * The resolver is built per call, as the save path built it before: it holds no
 * state between calls, so building it per create costs an object.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ExpressionDefaults implements ExpressionDefaultsInterface {

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession The session the `$userId`-style placeholders read.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * The object with every declared expression default the caller left empty derived onto it.
	 *
	 * @param Schema               $schema The schema being written against.
	 * @param array<string, mixed> $data   The object being created.
	 *
	 * @return array<string, mixed> The object with the derived defaults written onto it.
	 *
	 * @throws ExpressionDefaultException When an expression cannot be evaluated.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function apply(Schema $schema, array $data): array {
		$properties = ($schema->getProperties() ?? []);
		if (is_array($properties) === false || $properties === []) {
			return $data;
		}

		$resolver = new ExpressionDefaultResolver(
			evaluator: new CalculationEvaluator(
				placeholders: PlaceholderResolvers::forSession(userSession: $this->userSession)
			)
		);

		if ($resolver->declaresAny(properties: $properties) === false) {
			return $data;
		}

		return $resolver->apply(properties: $properties, data: $data);
	}//end apply()
}//end class
