<?php
/**
 * ExpressionEvaluatorInterface: evaluate a declared calculation against an object payload, as a render asks it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * The evaluation of one expression, as the object render (Core) asks it.
 *
 * A render evaluates a schema's virtual calculations against the rendered data;
 * it never reserves a sequence number, so it asks for the evaluation alone. The
 * area that owns the expression language binds its evaluator in its own wiring.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
interface ExpressionEvaluatorInterface {

	/**
	 * Evaluate an expression against an object payload.
	 *
	 * @param array<string, mixed> $object     The object's data.
	 * @param mixed                $expression Expression AST (scalar literal or array).
	 *
	 * @return mixed The computed value.
	 *
	 * @throws \RuntimeException When the expression is malformed or references unknown properties or operators.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function evaluate(array $object, mixed $expression): mixed;
}//end interface
