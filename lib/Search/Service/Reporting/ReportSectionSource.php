<?php

/**
 * Where one section of a report template counts.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Reporting
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-configurable-report-templates
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Reporting;

use InvalidArgumentException;

/**
 * The register, schema, filter and date property of one section: the
 * section's own where it names them, else the template's data source.
 */
final class ReportSectionSource {
	/**
	 * Register slug, uuid or id.
	 *
	 * @var string
	 */
	public readonly string $register;

	/**
	 * Schema slug, uuid or id.
	 *
	 * @var string
	 */
	public readonly string $schema;

	/**
	 * The template's filters with the section's on top.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $filter;

	/**
	 * The date property the period narrows.
	 *
	 * @var string
	 */
	public readonly string $dateField;

	/**
	 * Resolve a section's source.
	 *
	 * @param array<string, mixed> $template The template.
	 * @param array<string, mixed> $section  The section.
	 *
	 * @throws InvalidArgumentException When no register or schema is named.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-configurable-report-templates
	 */
	public function __construct(array $template, array $section) {
		$dataSource = $template['dataSource'] ?? [];
		if (is_array($dataSource) === false) {
			$dataSource = [];
		}

		$this->register = (string)($section['register'] ?? $dataSource['register'] ?? '');
		$this->schema = (string)($section['schema'] ?? $dataSource['schema'] ?? '');
		if ($this->register === '' || $this->schema === '') {
			throw new InvalidArgumentException('A report section MUST have a register and a schema, its own or the template\'s `dataSource`.');
		}

		// A section that names its own register or schema counts elsewhere, so the
		// template's filters (written for the template's schema) do not apply to it.
		$inherited = self::map(value: ($dataSource['filters'] ?? []));
		if (isset($section['register']) === true || isset($section['schema']) === true) {
			$inherited = [];
		}

		$this->filter = array_merge($inherited, self::map(value: ($section['filters'] ?? [])));
		$this->dateField = (string)($section['dateField'] ?? $template['dateField'] ?? ReportTemplateGenerator::DEFAULT_DATE_FIELD);
	}//end __construct()

	/**
	 * A filter value as a map; anything else is no filter.
	 *
	 * @param mixed $value The raw filters.
	 *
	 * @return array<string, mixed>
	 */
	private static function map(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end map()
}//end class
