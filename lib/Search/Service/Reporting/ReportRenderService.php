<?php

/**
 * Rapportage report renderer.
 *
 * Composes a dashboard object (an instance of the operator-imported
 * `dashboard` schema in the `reports` register) into a rendered file.
 * Resolves every widget's data via the existing AggregationRunner /
 * GraphQLService, then dispatches to the chosen format writer:
 *
 *   - csv  / xlsx / ods  -> SpreadsheetReportWriter (one sheet per widget)
 *   - html               -> HtmlReportWriter (browser print-to-PDF works on this)
 *   - pdf                -> PdfReportWriter (the HTML written as ODT, converted by the office server)
 *
 * Usage from a controller:
 *
 *   $payload = $this->renderService->render(
 *       dashboard: $dashboard,
 *       format: 'xlsx'
 *   );
 *   // $payload = ['mime' => 'application/vnd...', 'filename' => '...', 'bytes' => '...']
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Reporting
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Reporting;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Aggregation\AggregationRunnerInterface;
use OCA\OpenRegister\Service\Aggregation\NamedAggregationRef;
use OCA\OpenRegister\Service\Aggregation\NamedAggregationOptions;
use OCA\OpenRegister\Search\Service\GraphQL\GraphQLService;
use Psr\Log\LoggerInterface;

/**
 * Composes a dashboard object into a rendered file for export or
 * scheduled delivery.
 */
class ReportRenderService {

	/**
	 * Supported formats.
	 *
	 * @var array<int, string>
	 */
	public const FORMATS = ['csv', 'xlsx', 'ods', 'html', 'pdf'];

	/**
	 * Constructor.
	 *
	 * @param AggregationRunnerInterface $aggregationRunner Server-side aggregation runner.
	 * @param GraphQLService             $graphqlService    GraphQL executor.
	 * @param ReportWriters              $writers           The spreadsheet, HTML and PDF writers.
	 * @param LoggerInterface            $logger            Logger.
	 */
	public function __construct(
		private readonly AggregationRunnerInterface $aggregationRunner,
		private readonly GraphQLService $graphqlService,
		private readonly ReportWriters $writers,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Render a dashboard to the chosen format.
	 *
	 * @param ObjectEntity|array $dashboard Dashboard object or its
	 *                                      `getObject()` payload.
	 * @param string $format One of `FORMATS`.
	 *
	 * @return array{mime: string, filename: string, bytes: string}
	 *
	 * @throws InvalidArgumentException When the format is unsupported
	 *                                  or the dashboard is malformed.
	 * @throws \OCA\OpenRegister\Exception\OfficeServerRequiredException When a PDF is asked for and no office server converts it.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-1
	 */
	public function render($dashboard, string $format = 'xlsx'): array {
		$this->assertSupportedFormat(format: $format);
		$payload = $this->widgetPayload(dashboard: $dashboard);
		$content = new ReportContent(dashboard: $payload, resolvedWidgets: $this->resolveWidgets(widgets: $payload['widgets']));
		$filename = $this->filenameFor(payload: $payload, format: $format);

		return [
			'mime' => $this->mimeFor(format: $format),
			'filename' => $filename,
			'bytes' => $this->write(content: $content, format: $format),
		];
	}//end render()

	/**
	 * Refuse a format outside `FORMATS`.
	 *
	 * @param string $format The requested format.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the format is unsupported.
	 */
	private function assertSupportedFormat(string $format): void {
		if (in_array(needle: $format, haystack: self::FORMATS, strict: true) === false) {
			throw new InvalidArgumentException(
				sprintf('Unsupported render format "%s"; expected one of: %s', $format, implode(', ', self::FORMATS))
			);
		}
	}//end assertSupportedFormat()

	/**
	 * The dashboard payload, refused when it carries no widgets array.
	 *
	 * @param ObjectEntity|array $dashboard Dashboard or its payload.
	 *
	 * @return array<string, mixed> The payload, with a `widgets` array.
	 *
	 * @throws InvalidArgumentException When the dashboard has no widgets array.
	 */
	private function widgetPayload($dashboard): array {
		$payload = $this->normalisePayload(dashboard: $dashboard);
		if (is_array($payload['widgets'] ?? null) === false) {
			throw new InvalidArgumentException('Dashboard MUST carry a `widgets` array.');
		}

		return $payload;
	}//end widgetPayload()

	/**
	 * Pair every widget with its resolved data.
	 *
	 * @param array<int, mixed> $widgets The dashboard's widgets.
	 *
	 * @return array<int, array{widget: mixed, data: array<string, mixed>|null}>
	 */
	private function resolveWidgets(array $widgets): array {
		$resolvedWidgets = [];
		foreach ($widgets as $widget) {
			$resolvedWidgets[] = [
				'widget' => $widget,
				'data' => $this->resolveWidgetData(widget: $widget),
			];
		}

		return $resolvedWidgets;
	}//end resolveWidgets()

	/**
	 * The download filename: the slugged title and a timestamp.
	 *
	 * @param array<string, mixed> $payload The dashboard payload.
	 * @param string               $format  The render format.
	 *
	 * @return string
	 */
	private function filenameFor(array $payload, string $format): string {
		$title = (string)($payload['titel'] ?? $payload['title'] ?? 'dashboard');
		$slug = $this->slugify(value: $title);
		$stamp = (new DateTime())->format('Y-m-d_His');

		return sprintf('%s_%s.%s', $slug, $stamp, $format);
	}//end filenameFor()

	/**
	 * Write the report with the writer of its format.
	 *
	 * @param ReportContent $content The dashboard and its resolved widgets.
	 * @param string        $format  The render format.
	 *
	 * @return string The rendered bytes.
	 *
	 * @throws \OCA\OpenRegister\Exception\OfficeServerRequiredException When a PDF is asked for and no office server converts it.
	 */
	private function write(ReportContent $content, string $format): string {
		if ($format === 'html') {
			return $this->writers->htmlWriter->write(
				dashboard: $content->dashboard,
				resolvedWidgets: $content->resolvedWidgets
			);
		}

		if ($format === 'pdf') {
			return $this->writers->pdfWriter->write(
				dashboard: $content->dashboard,
				resolvedWidgets: $content->resolvedWidgets
			);
		}

		return $this->writers->spreadsheetWriter->write(content: $content, format: $format);
	}//end write()

	/**
	 * Resolve a single widget's data.
	 *
	 * @param array<string, mixed> $widget Widget descriptor with `dataSource`.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-2
	 */
	private function resolveWidgetData(array $widget): ?array {
		$dataSource = $widget['dataSource'] ?? null;
		if (is_array($dataSource) === false) {
			return null;
		}

		$mode = (string)($dataSource['mode'] ?? 'aggregation');
		try {
			return $this->fetchWidgetData(dataSource: $dataSource, mode: $mode);
		} catch (\Throwable $e) {
			$this->logWidgetFailure(widget: $widget, error: $e);
			return null;
		}
	}//end resolveWidgetData()

	/**
	 * Fetch a data source's data by its mode.
	 *
	 * @param array<string, mixed> $dataSource The widget's data source.
	 * @param string               $mode       Its mode.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws \Throwable When the aggregation or the query fails.
	 */
	private function fetchWidgetData(array $dataSource, string $mode): ?array {
		if ($mode === 'aggregation') {
			return $this->runAggregation(dataSource: $dataSource);
		}

		if ($mode === 'graphql') {
			return $this->runGraphQL(dataSource: $dataSource);
		}

		// `statistics` mode is only honoured client-side because the
		// backing endpoint is multi-call; for server-side renders the
		// operator should declare an explicit aggregation.
		return null;
	}//end fetchWidgetData()

	/**
	 * Run a data source's named aggregation, or null when it names none.
	 *
	 * @param array<string, mixed> $dataSource The widget's data source.
	 *
	 * @return array<string, mixed>|null
	 */
	private function runAggregation(array $dataSource): ?array {
		$register = (string)($dataSource['register'] ?? '');
		$schema = (string)($dataSource['schema'] ?? '');
		$aggregation = (string)($dataSource['aggregation'] ?? '');
		if ($register === '' || $schema === '' || $aggregation === '') {
			return null;
		}

		// BypassRbac: report widgets render for viewers whose
		// authoritative reason is dashboard-read, not the
		// schema's `list` permission. The dashboard's own
		// RBAC gate already filtered the viewer at load time.
		return $this->aggregationRunner->run(
			ref: new NamedAggregationRef(register: $register, schema: $schema, name: $aggregation),
			options: new NamedAggregationOptions(bypassRbac: true)
		);
	}//end runAggregation()

	/**
	 * Run a data source's GraphQL query, or null when it has none.
	 *
	 * @param array<string, mixed> $dataSource The widget's data source.
	 *
	 * @return array<string, mixed>|null
	 */
	private function runGraphQL(array $dataSource): ?array {
		$query = (string)($dataSource['graphqlQuery'] ?? '');
		if ($query === '') {
			return null;
		}

		return $this->graphqlService->execute(query: $query);
	}//end runGraphQL()

	/**
	 * Log a widget whose data could not be resolved.
	 *
	 * @param array<string, mixed> $widget The widget.
	 * @param \Throwable           $error  Why it failed.
	 *
	 * @return void
	 */
	private function logWidgetFailure(array $widget, \Throwable $error): void {
		$this->logger->warning(
			message: '[ReportRenderService] Widget data resolution failed',
			context: [
				'widget' => ($widget['title'] ?? ''),
				'mode' => (string)($widget['dataSource']['mode'] ?? 'aggregation'),
				'error' => $error->getMessage(),
			]
		);
	}//end logWidgetFailure()

	/**
	 * Coerce the dashboard input into its data array.
	 *
	 * @param ObjectEntity|array $dashboard Dashboard or its payload.
	 *
	 * @return array<string, mixed>
	 */
	private function normalisePayload($dashboard): array {
		if ($dashboard instanceof ObjectEntity === true) {
			$payload = $dashboard->getObject();
			if (is_array($payload) === true) {
				return $payload;
			}

			return [];
		}

		if (is_array($dashboard) === true) {
			// Already a payload, OR a jsonSerialize() envelope where
			// the dashboard fields live at the top level alongside
			// `@self`.
			return $dashboard;
		}

		return [];
	}//end normalisePayload()

	/**
	 * Slugify a title for a filename.
	 *
	 * @param string $value Title.
	 *
	 * @return string Slugged title.
	 */
	private function slugify(string $value): string {
		$slug = strtolower(trim($value));
		$slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;
		$slug = trim($slug, '-');
		if ($slug !== '') {
			return $slug;
		}

		return 'dashboard';
	}//end slugify()

	/**
	 * MIME type per format.
	 *
	 * @param string $format Format identifier.
	 *
	 * @return string MIME type.
	 */
	private function mimeFor(string $format): string {
		if ($format === 'csv') {
			return 'text/csv; charset=utf-8';
		}

		if ($format === 'ods') {
			return 'application/vnd.oasis.opendocument.spreadsheet';
		}

		if ($format === 'html') {
			return 'text/html; charset=utf-8';
		}

		if ($format === 'pdf') {
			return 'application/pdf';
		}

		return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
	}//end mimeFor()
}//end class
