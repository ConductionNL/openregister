<?php

/**
 * OpenRegister WidgetAnnotationValidator
 *
 * Schema-save validation for the `x-openregister-widgets` annotation.
 * Returns a list of errors; empty = valid.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
final class WidgetAnnotationValidator implements WidgetAnnotationValidatorInterface {

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
	 *
	 * @spec openspec/specs/aggregations-backend-native/spec.md
	 */
	public function validate(array $schema): array {
		if (isset($schema['x-openregister-widgets']) === false) {
			return [];
		}

		$widgets = $schema['x-openregister-widgets'];
		if (is_array($widgets) === false || count($widgets) === 0) {
			return [$this->finding(code: 'widgets-empty', message: 'x-openregister-widgets must declare at least one widget.')];
		}

		return $this->validateList(widgets: $widgets);
	}//end validate()

	/**
	 * Validate every entry of a non-empty widget list.
	 *
	 * @param array<int|string, mixed> $widgets The widget list.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function validateList(array $widgets): array {
		$errors = [];
		foreach ($widgets as $index => $widget) {
			$errors = array_merge($errors, $this->validateEntry(label: sprintf('widgets[%s]', (string)$index), widget: $widget));
		}

		return $errors;
	}//end validateList()

	/**
	 * Validate one entry of the widget list.
	 *
	 * @param string $label  Human-readable index label.
	 * @param mixed  $widget The entry.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function validateEntry(string $label, mixed $widget): array {
		if (is_array($widget) === false) {
			return [$this->finding(code: 'widget-malformed', message: sprintf('%s must be an object.', $label))];
		}

		return array_merge(
			$this->validateType(label: $label, widget: $widget),
			$this->validateTitle(label: $label, widget: $widget),
			$this->validateSourceAndOptions(label: $label, widget: $widget)
		);
	}//end validateEntry()

	/**
	 * Validate the widget `type`.
	 *
	 * @param string               $label  Human-readable index label.
	 * @param array<string, mixed> $widget Widget descriptor.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function validateType(string $label, array $widget): array {
		$type = (string)($widget['type'] ?? '');
		if (in_array(needle: $type, haystack: self::VALID_TYPES, strict: true) === true) {
			return [];
		}

		$message = sprintf('%s type "%s" is not in [%s].', $label, $type, implode(', ', self::VALID_TYPES));
		return [$this->finding(code: 'widget-bad-type', message: $message)];
	}//end validateType()

	/**
	 * Validate the widget `title`.
	 *
	 * @param string               $label  Human-readable index label.
	 * @param array<string, mixed> $widget Widget descriptor.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function validateTitle(string $label, array $widget): array {
		if ((string)($widget['title'] ?? '') !== '') {
			return [];
		}

		return [$this->finding(code: 'widget-title-missing', message: sprintf('%s requires a non-empty title.', $label))];
	}//end validateTitle()

	/**
	 * Validate the `dataSource` and then the `options`.
	 *
	 * A missing dataSource or an unknown mode stops here: the mode-specific
	 * fields and the options are not checked.
	 *
	 * @param string               $label  Human-readable index label.
	 * @param array<string, mixed> $widget Widget descriptor.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function validateSourceAndOptions(string $label, array $widget): array {
		$dataSource = ($widget['dataSource'] ?? null);
		$stop = $this->validateSourceShape(label: $label, dataSource: $dataSource);
		if ($stop !== []) {
			return $stop;
		}

		return array_merge(
			$this->validateModeFields(label: $label, dataSource: $dataSource),
			$this->validateOptions(label: $label, widget: $widget)
		);
	}//end validateSourceAndOptions()

	/**
	 * The dataSource must be an object with a known mode.
	 *
	 * @param string $label      Human-readable index label.
	 * @param mixed  $dataSource The dataSource value.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function validateSourceShape(string $label, mixed $dataSource): array {
		if (is_array($dataSource) === false) {
			return [$this->finding(code: 'widget-datasource-missing', message: sprintf('%s requires a dataSource object.', $label))];
		}

		return $this->validateMode(label: $label, dataSource: $dataSource);
	}//end validateSourceShape()

	/**
	 * Validate the dataSource `mode`.
	 *
	 * @param string               $label      Human-readable index label.
	 * @param array<string, mixed> $dataSource The dataSource object.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function validateMode(string $label, array $dataSource): array {
		$mode = (string)($dataSource['mode'] ?? '');
		if (in_array(needle: $mode, haystack: self::VALID_MODES, strict: true) === true) {
			return [];
		}

		$message = sprintf('%s dataSource.mode "%s" is not in [%s].', $label, $mode, implode(', ', self::VALID_MODES));
		return [$this->finding(code: 'widget-datasource-bad-mode', message: $message)];
	}//end validateMode()

	/**
	 * Validate the fields the dataSource's (valid) mode requires.
	 *
	 * @param string               $label      Human-readable index label.
	 * @param array<string, mixed> $dataSource The dataSource object.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function validateModeFields(string $label, array $dataSource): array {
		return match ((string)$dataSource['mode']) {
			'aggregation' => $this->validateAggregationSource(label: $label, dataSource: $dataSource),
			'graphql' => $this->validateGraphqlSource(label: $label, dataSource: $dataSource),
			default => [],
		};
	}//end validateModeFields()

	/**
	 * An aggregation source names its register, schema and aggregation.
	 *
	 * @param string               $label      Human-readable index label.
	 * @param array<string, mixed> $dataSource The dataSource object.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function validateAggregationSource(string $label, array $dataSource): array {
		$errors = [];
		foreach (['register', 'schema', 'aggregation'] as $key) {
			if ((string)($dataSource[$key] ?? '') === '') {
				$message = sprintf('%s dataSource.%s is required when mode is "aggregation".', $label, $key);
				$errors[] = $this->finding(code: 'widget-datasource-aggregation-incomplete', message: $message);
			}
		}

		return $errors;
	}//end validateAggregationSource()

	/**
	 * A graphql source carries its query.
	 *
	 * @param string               $label      Human-readable index label.
	 * @param array<string, mixed> $dataSource The dataSource object.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function validateGraphqlSource(string $label, array $dataSource): array {
		if ((string)($dataSource['graphqlQuery'] ?? '') !== '') {
			return [];
		}

		$message = sprintf('%s dataSource.graphqlQuery is required when mode is "graphql".', $label);
		return [$this->finding(code: 'widget-datasource-graphql-incomplete', message: $message)];
	}//end validateGraphqlSource()

	/**
	 * The optional `options` must be an object when present.
	 *
	 * @param string               $label  Human-readable index label.
	 * @param array<string, mixed> $widget Widget descriptor.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function validateOptions(string $label, array $widget): array {
		if (isset($widget['options']) === true && is_array($widget['options']) === false) {
			return [$this->finding(code: 'widget-options-malformed', message: sprintf('%s options must be an object when present.', $label))];
		}

		return [];
	}//end validateOptions()

	/**
	 * One finding.
	 *
	 * @param string $code    Stable machine code.
	 * @param string $message Human-readable message.
	 *
	 * @return array{code: string, message: string}
	 */
	private function finding(string $code, string $message): array {
		return ['code' => $code, 'message' => $message];
	}//end finding()
}//end class
