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
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Renders authored values against an item's record.
 */
final class FlowValueTemplate
{

    /**
     * The placeholder shape: `{{ dotted.path }}`.
     *
     * @var string
     */
    private const PLACEHOLDER = '/\{\{\s*([A-Za-z0-9_@.]+)\s*\}\}/';

    /**
     * The same shape, anchored — a value that is ONLY a placeholder.
     *
     * @var string
     */
    private const WHOLE = '/^\{\{\s*([A-Za-z0-9_@.]+)\s*\}\}$/';

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
     * @param array $json  The item's record.
     *
     * @return array{value: mixed, unresolved: array<int, string>} The rendered
     *               value, and the placeholder paths that rendered empty.
     *
     * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
     */
    public static function renderTracked(mixed $value, array $json): array
    {
        $unresolved = [];
        $rendered   = self::renderCollecting(value: $value, json: $json, unresolved: $unresolved);

        return [
            'value'      => $rendered,
            'unresolved' => array_values(array_unique($unresolved)),
        ];

    }//end renderTracked()

    /**
     * Render recursively, collecting the paths that came out empty.
     *
     * @param mixed              $value      The authored value.
     * @param array              $json       The item's record.
     * @param array<int, string> $unresolved Collects the empty placeholder paths.
     *
     * @return mixed The rendered value.
     */
    private static function renderCollecting(mixed $value, array $json, array &$unresolved): mixed
    {
        if (is_array($value) === true) {
            $rendered = [];
            foreach ($value as $key => $entry) {
                $rendered[$key] = self::renderCollecting(value: $entry, json: $json, unresolved: $unresolved);
            }

            return $rendered;
        }

        if (is_string($value) === false) {
            return $value;
        }

        $whole = [];
        if (preg_match(self::WHOLE, $value, $whole) === 1) {
            $resolved = self::valueAt(path: $whole[1], json: $json);
            if (self::isEmptyValue(value: $resolved) === true) {
                $unresolved[] = $whole[1];
            }

            return $resolved;
        }

        return (string) preg_replace_callback(
            self::PLACEHOLDER,
            static function (array $matches) use ($json, &$unresolved): string {
                $resolved = self::valueAt(path: $matches[1], json: $json);
                if (self::isEmptyValue(value: $resolved) === true) {
                    $unresolved[] = $matches[1];
                }

                if (is_array($resolved) === true) {
                    return (string) json_encode($resolved);
                }

                return (string) $resolved;
            },
            $value
        );

    }//end renderCollecting()

    /**
     * Whether a resolved value renders as nothing at all.
     *
     * `false`, `0` and `[]` are deliberately NOT empty: they are answers, and a
     * whole-value placeholder keeps their type, so `{"enabled": "{{wanted}}"}`
     * resolving to `false` is a real constraint rather than a missing one.
     *
     * @param mixed $value The resolved value.
     *
     * @return boolean Whether it renders as nothing.
     */
    private static function isEmptyValue(mixed $value): bool
    {
        return ($value === null || $value === '');

    }//end isEmptyValue()

    /**
     * Render a value — scalar, list or map — against an item's record.
     *
     * @param mixed $value The authored value.
     * @param array $json  The item's record.
     *
     * @return mixed The rendered value.
     */
    public static function render(mixed $value, array $json): mixed
    {
        if (is_array($value) === true) {
            $rendered = [];
            foreach ($value as $key => $entry) {
                $rendered[$key] = self::render(value: $entry, json: $json);
            }

            return $rendered;
        }

        if (is_string($value) === false) {
            return $value;
        }

        $whole = [];
        if (preg_match(self::WHOLE, $value, $whole) === 1) {
            return self::valueAt(path: $whole[1], json: $json);
        }

        return (string) preg_replace_callback(
            self::PLACEHOLDER,
            static function (array $matches) use ($json): string {
                $resolved = self::valueAt(path: $matches[1], json: $json);
                if (is_array($resolved) === true) {
                    return (string) json_encode($resolved);
                }

                return (string) $resolved;
            },
            $value
        );

    }//end render()

    /**
     * The value at a dotted path, or null when the path is absent.
     *
     * @param string $path The dotted path.
     * @param array  $json The item's record.
     *
     * @return mixed The value, or null.
     */
    private static function valueAt(string $path, array $json): mixed
    {
        $cursor = $json;
        foreach (explode('.', $path) as $segment) {
            if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;

    }//end valueAt()
}//end class
