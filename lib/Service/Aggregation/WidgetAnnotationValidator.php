<?php

/**
 * OpenRegister WidgetAnnotationValidator
 *
 * Schema-save validation for the `x-openregister-widgets` annotation.
 * Returns a list of errors; empty = valid.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
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

namespace OCA\OpenRegister\Service\Aggregation;

/**
 * Validates the `x-openregister-widgets` schema annotation shape.
 *
 * The annotation is an ordered list of widget descriptors used when
 * a schema declares default widgets (e.g. a `dashboard` schema in the
 * `reports` register). Each widget MUST shape as:
 *
 *   { type, title, dataSource: { mode, ... }, options? }
 *
 * - `type` MUST be one of the documented widget types.
 * - `title` MUST be a non-empty string.
 * - `dataSource.mode` MUST be one of `aggregation` / `graphql` /
 *   `statistics`.
 * - When mode = `aggregation` → `register`, `schema`, `aggregation`
 *   are REQUIRED.
 * - When mode = `graphql`     → `graphqlQuery` is REQUIRED.
 *
 * Cross-register existence checks (does the target aggregation
 * actually exist on the target schema?) intentionally stay out of
 * scope — operators may import schemas in any order and the target
 * can be in another bundle. The render path silently degrades when
 * a target is missing; this validator only catches local shape bugs
 * the operator can fix on the spot.
 */
final class WidgetAnnotationValidator
{

    /**
     * Widget types supported by the Vue renderer (kept in sync with
     * `src/views/reports/ReportView.vue`'s widget map).
     *
     * @var array<int, string>
     */
    private const VALID_TYPES = [
        'kpi',
        'chart',
        'table',
        'stats',
        'sparkline',
        'tile',
    ];

    /**
     * Data-source modes accepted by `ReportRenderService::resolveWidgetData`.
     *
     * @var array<int, string>
     */
    private const VALID_MODES = ['aggregation', 'graphql', 'statistics'];

    /**
     * Validate the widget annotation.
     *
     * @param array<string, mixed> $schema Full schema definition.
     *
     * @return array<int, array{code: string, message: string}>
     */
    public function validate(array $schema): array
    {
        if (isset($schema['x-openregister-widgets']) === false) {
            return [];
        }

        $widgets = $schema['x-openregister-widgets'];
        if (is_array($widgets) === false || count($widgets) === 0) {
            return [
                [
                    'code'    => 'widgets-empty',
                    'message' => 'x-openregister-widgets must declare at least one widget.',
                ],
            ];
        }

        $errors = [];
        foreach ($widgets as $index => $widget) {
            $label = sprintf('widgets[%s]', (string) $index);
            if (is_array($widget) === false) {
                $errors[] = [
                    'code'    => 'widget-malformed',
                    'message' => sprintf('%s must be an object.', $label),
                ];
                continue;
            }

            $errors = array_merge($errors, $this->validateOne(label: $label, widget: $widget));
        }

        return $errors;

    }//end validate()

    /**
     * Validate a single widget descriptor.
     *
     * @param string               $label  Human-readable index label.
     * @param array<string, mixed> $widget Widget descriptor.
     *
     * @return array<int, array{code: string, message: string}>
     */
    private function validateOne(string $label, array $widget): array
    {
        $errors = [];

        $type = (string) ($widget['type'] ?? '');
        if (in_array(needle: $type, haystack: self::VALID_TYPES, strict: true) === false) {
            $errors[] = [
                'code'    => 'widget-bad-type',
                'message' => sprintf(
                    '%s type "%s" is not in [%s].',
                    $label,
                    $type,
                    implode(', ', self::VALID_TYPES)
                ),
            ];
        }

        $title = (string) ($widget['title'] ?? '');
        if ($title === '') {
            $errors[] = [
                'code'    => 'widget-title-missing',
                'message' => sprintf('%s requires a non-empty title.', $label),
            ];
        }

        $dataSource = ($widget['dataSource'] ?? null);
        if (is_array($dataSource) === false) {
            $errors[] = [
                'code'    => 'widget-datasource-missing',
                'message' => sprintf('%s requires a dataSource object.', $label),
            ];
            return $errors;
        }

        $mode = (string) ($dataSource['mode'] ?? '');
        if (in_array(needle: $mode, haystack: self::VALID_MODES, strict: true) === false) {
            $errors[] = [
                'code'    => 'widget-datasource-bad-mode',
                'message' => sprintf(
                    '%s dataSource.mode "%s" is not in [%s].',
                    $label,
                    $mode,
                    implode(', ', self::VALID_MODES)
                ),
            ];
            return $errors;
        }

        if ($mode === 'aggregation') {
            foreach (['register', 'schema', 'aggregation'] as $key) {
                $value = (string) ($dataSource[$key] ?? '');
                if ($value === '') {
                    $errors[] = [
                        'code'    => 'widget-datasource-aggregation-incomplete',
                        'message' => sprintf(
                            '%s dataSource.%s is required when mode is "aggregation".',
                            $label,
                            $key
                        ),
                    ];
                }
            }
        }

        if ($mode === 'graphql') {
            $query = (string) ($dataSource['graphqlQuery'] ?? '');
            if ($query === '') {
                $errors[] = [
                    'code'    => 'widget-datasource-graphql-incomplete',
                    'message' => sprintf(
                        '%s dataSource.graphqlQuery is required when mode is "graphql".',
                        $label
                    ),
                ];
            }
        }

        if (isset($widget['options']) === true && is_array($widget['options']) === false) {
            $errors[] = [
                'code'    => 'widget-options-malformed',
                'message' => sprintf('%s options must be an object when present.', $label),
            ];
        }

        return $errors;

    }//end validateOne()
}//end class
