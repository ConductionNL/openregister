<?php

/**
 * Substitutes `{{dotted.path}}` placeholders in a step's configured values.
 *
 * Every node that takes authored values resolves them against the item —
 * `openconnector.source-call` its endpoint, body and query, `hermiq.agent-step`
 * its prompt, `openregister.object-write` its fields. This is that rule, in one
 * place, for OpenRegister's own nodes.
 *
 * It is deliberately NOT OpenConnector's `FlowTemplate`. That class belongs to
 * an app which may not be installed, and OpenRegister's built-in nodes must not
 * depend on one that isn't.
 *
 * A value that is EXACTLY one placeholder keeps the resolved value's TYPE, so a
 * number stays a number and a list stays a list. That matters wherever the
 * value is compared rather than printed: `"7"` and `7` are not the same filter.
 * Anything else is substituted inline and stringified.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Renders authored values against an item's record.
 */
final class FlowValueTemplate {

	/**
	 * Render a value and REPORT the placeholders that came out empty.
	 *
	 * {@see render()} cannot fail. A path the item does not carry resolves to
	 * null and is substituted as the empty string, so `"ZZ issue-lock {{repo}}"`
	 * silently becomes `"ZZ issue-lock "` and a caller that meant to constrain
	 * something ends up constraining something else. That is invisible wherever
	 * the rendered value is a QUESTION rather than a payload: an empty filter
	 * value matches nothing, the step returns zero rows, and the run reports
	 * success having done nothing.
	 *
	 * This variant renders exactly as `render()` does and additionally returns
	 * every placeholder path that could not be filled, so a caller for whom an
	 * empty value is meaningless can refuse rather than proceed. It does not
	 * decide that itself: `render()` stays the default, because omitting a
	 * FIELD legitimately narrows what is written.
	 *
	 * A path counts as unfilled when the item does not carry it, when it
	 * carries null, or when it carries the empty string. All three produce the
	 * same empty rendering, so telling them apart would only let one of them
	 * through.
	 *
	 * @param mixed $value The authored value.
	 * @param array $json The item's record.
	 *
	 * @return array{value: mixed, unresolved: array<int, string>} The rendered
	 *                                                             value, and the placeholder paths that rendered empty.
	 *
	 * @spec openspec/specs/flow-nodes/spec.md
	 */
	public static function renderTracked(mixed $value, array $json): array {
		return FlowValueRenderer::renderTracked(value: $value, json: $json);
	}//end renderTracked()

	/**
	 * Render a value — scalar, list or map — against an item's record.
	 *
	 * @param mixed $value The authored value.
	 * @param array $json The item's record.
	 *
	 * @return mixed The rendered value.
	 */
	public static function render(mixed $value, array $json): mixed {
		return FlowValueRenderer::render(value: $value, json: $json);
	}//end render()
}//end class
