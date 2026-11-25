<?php

/**
 * Report template generator.
 *
 * Generates a report from a report template: an object of the
 * `report-templates` schema that declares its data source (register, schema,
 * filters), the date property a period narrows, and its sections. Each
 * section is one aggregation over that source in the requested period:
 *
 *   - `total`   one number;
 *   - `grouped` a count (or sum, avg, min, max) per value of `groupBy`, with
 *               every key the section lists in `rows` present, 0 included;
 *   - `series`  a count per `interval` bucket (day, week, month, quarter,
 *               year) of the date property, every bucket present.
 *
 * A section with `compareWithPreviousYear` is followed by the same section
 * over the period one year earlier. Sections run with the caller's rights:
 * a section the caller may not read refuses the whole report, so a report
 * is never handed out with a section silently missing.
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

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Turns a report template and a period into a rendered report.
 */
class ReportTemplateGenerator {
	/**
	 * The section types a template may declare.
	 *
	 * @var array<int, string>
	 */
	public const SECTION_TYPES = ['total', 'grouped', 'series'];

	/**
	 * The date property a period narrows when the template names none.
	 *
	 * @var string
	 */
	public const DEFAULT_DATE_FIELD = '_created';

	/**
	 * Constructor.
	 *
	 * @param ReportSectionQuery  $sectionQuery  Counts each section with the caller's rights.
	 * @param ReportRenderService $renderService Writes the report in the chosen format.
	 */
	public function __construct(
		private readonly ReportSectionQuery $sectionQuery,
		private readonly ReportRenderService $renderService,
	) {
	}//end __construct()

	/**
	 * Generate a report from a template over a period.
	 *
	 * @param array<string, mixed> $template The template object's data.
	 * @param ReportPeriod         $period   The period, both bounds inclusive dates.
	 * @param string               $format   One of `ReportRenderService::FORMATS`.
	 *
	 * @return array{mime: string, filename: string, bytes: string}
	 *
	 * @throws InvalidArgumentException When the template, a section or the format is invalid.
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller may not read a section's schema.
	 * @throws \RuntimeException When a section's register or schema does not exist.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-configurable-report-templates
	 */
	public function generate(array $template, ReportPeriod $period, string $format): array {
		$sections = $this->sections(template: $template);
		$resolved = [];
		foreach ($sections as $section) {
			$resolved[] = $this->resolvedSection(template: $template, section: $section, period: $period);
			if (($section['compareWithPreviousYear'] ?? false) === true) {
				$resolved[] = $this->resolvedSection(template: $template, section: $section, period: $period->previousYear());
			}
		}

		$content = new ReportContent(
			dashboard: $this->reportPayload(template: $template, period: $period, widgets: array_column($resolved, 'widget')),
			resolvedWidgets: $resolved
		);

		return $this->renderService->renderContent(content: $content, format: $format);
	}//end generate()

	/**
	 * The template's sections, each checked before anything is read.
	 *
	 * @param array<string, mixed> $template The template.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws InvalidArgumentException When the template has no sections or one is malformed.
	 */
	private function sections(array $template): array {
		$sections = ($template['sections'] ?? null);
		if (is_array($sections) === false || $sections === []) {
			throw new InvalidArgumentException('A report template MUST carry a non-empty `sections` array.');
		}

		foreach ($sections as $index => $section) {
			$this->assertSection(section: $section, index: $index);
		}

		return array_values($sections);
	}//end sections()

	/**
	 * Refuse a section without a known type or the field its type needs.
	 *
	 * @param mixed      $section The section.
	 * @param int|string $index   Its position, for the message.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the section is malformed.
	 */
	private function assertSection(mixed $section, int|string $index): void {
		$type = '';
		if (is_array($section) === true) {
			$type = (string)($section['type'] ?? 'grouped');
		}

		if (in_array(needle: $type, haystack: self::SECTION_TYPES, strict: true) === false) {
			throw new InvalidArgumentException(
				sprintf('Section %s MUST have a type of: %s', (string)$index, implode(', ', self::SECTION_TYPES))
			);
		}

		if ($type === 'grouped' && is_string($section['groupBy'] ?? null) === false) {
			throw new InvalidArgumentException(sprintf('Grouped section %s MUST name `groupBy`', (string)$index));
		}
	}//end assertSection()

	/**
	 * One section as a widget and its data over a period.
	 *
	 * @param array<string, mixed> $template The template.
	 * @param array<string, mixed> $section  The section.
	 * @param ReportPeriod         $period   The period.
	 *
	 * @return array{widget: array<string, mixed>, data: array<string, mixed>}
	 */
	private function resolvedSection(array $template, array $section, ReportPeriod $period): array {
		$source = new ReportSectionSource(template: $template, section: $section);
		$data = $this->sectionQuery->data(source: $source, section: $section, period: $period);

		return [
			'widget' => [
				'title' => (string)($section['title'] ?? $section['key'] ?? ''),
				'subtitle' => $period->label(),
				'type' => (string)($section['type'] ?? 'grouped'),
				'dataSource' => ['mode' => 'aggregation', 'register' => $source->register, 'schema' => $source->schema],
			],
			'data' => $data,
		];
	}//end resolvedSection()






	/**
	 * The report's own payload as the writers read it.
	 *
	 * @param array<string, mixed>             $template The template.
	 * @param ReportPeriod                     $period   The period.
	 * @param array<int, array<string, mixed>> $widgets  The section widgets.
	 *
	 * @return array<string, mixed>
	 */
	private function reportPayload(array $template, ReportPeriod $period, array $widgets): array {
		$branding = ($template['branding'] ?? []);
		$organisation = '';
		if (is_array($branding) === true) {
			$organisation = (string)($branding['organisation'] ?? '');
		}

		return [
			'titel' => trim(sprintf('%s %s', (string)($template['name'] ?? 'Report'), $period->label())),
			'beschrijving' => (string)($template['description'] ?? ''),
			'category' => $organisation,
			'generated' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
			'widgets' => $widgets,
		];
	}//end reportPayload()
}//end class
