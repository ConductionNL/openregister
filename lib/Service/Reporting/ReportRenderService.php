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
 *   - pdf                -> PdfReportWriter (HTML pipeline routed through Dompdf)
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
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Reporting;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\GraphQL\GraphQLService;
use Psr\Log\LoggerInterface;

/**
 * Composes a dashboard object into a rendered file for export or
 * scheduled delivery.
 */
class ReportRenderService
{

    /**
     * Supported formats.
     *
     * @var array<int, string>
     */
    public const FORMATS = ['csv', 'xlsx', 'ods', 'html', 'pdf'];

    /**
     * Constructor.
     *
     * @param AggregationRunner       $aggregationRunner Server-side aggregation runner.
     * @param GraphQLService          $graphqlService    GraphQL executor.
     * @param SpreadsheetReportWriter $spreadsheetWriter CSV / XLSX / ODS writer.
     * @param HtmlReportWriter        $htmlWriter        HTML writer.
     * @param PdfReportWriter         $pdfWriter         PDF writer (Dompdf).
     * @param LoggerInterface         $logger            Logger.
     */
    public function __construct(
        private readonly AggregationRunner $aggregationRunner,
        private readonly GraphQLService $graphqlService,
        private readonly SpreadsheetReportWriter $spreadsheetWriter,
        private readonly HtmlReportWriter $htmlWriter,
        private readonly PdfReportWriter $pdfWriter,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * Render a dashboard to the chosen format.
     *
     * @param ObjectEntity|array $dashboard Dashboard object or its
     *                                      `getObject()` payload.
     * @param string             $format    One of `FORMATS`.
     *
     * @return array{mime: string, filename: string, bytes: string}
     *
     * @throws InvalidArgumentException When the format is unsupported
     *                                  or the dashboard is malformed.
     */
    public function render($dashboard, string $format='xlsx'): array
    {
        if (in_array(needle: $format, haystack: self::FORMATS, strict: true) === false) {
            throw new InvalidArgumentException(
                sprintf('Unsupported render format "%s"; expected one of: %s', $format, implode(', ', self::FORMATS))
            );
        }

        $payload = $this->normalisePayload(dashboard: $dashboard);
        if (is_array($payload['widgets'] ?? null) === false) {
            throw new InvalidArgumentException('Dashboard MUST carry a `widgets` array.');
        }

        $resolvedWidgets = [];
        foreach ($payload['widgets'] as $widget) {
            $resolvedWidgets[] = [
                'widget' => $widget,
                'data'   => $this->resolveWidgetData(widget: $widget),
            ];
        }

        $title    = (string) ($payload['titel'] ?? $payload['title'] ?? 'dashboard');
        $slug     = $this->slugify(value: $title);
        $stamp    = (new DateTime())->format('Y-m-d_His');
        $filename = sprintf('%s_%s.%s', $slug, $stamp, $format);

        if ($format === 'html') {
            $bytes = $this->htmlWriter->write(
                dashboard: $payload,
                resolvedWidgets: $resolvedWidgets
            );
            return [
                'mime'     => 'text/html; charset=utf-8',
                'filename' => $filename,
                'bytes'    => $bytes,
            ];
        }

        if ($format === 'pdf') {
            $bytes = $this->pdfWriter->write(
                dashboard: $payload,
                resolvedWidgets: $resolvedWidgets
            );
            return [
                'mime'     => 'application/pdf',
                'filename' => $filename,
                'bytes'    => $bytes,
            ];
        }

        $bytes = $this->spreadsheetWriter->write(
            dashboard: $payload,
            resolvedWidgets: $resolvedWidgets,
            format: $format
        );
        return [
            'mime'     => $this->mimeFor(format: $format),
            'filename' => $filename,
            'bytes'    => $bytes,
        ];

    }//end render()

    /**
     * Resolve a single widget's data.
     *
     * @param array<string, mixed> $widget Widget descriptor with `dataSource`.
     *
     * @return array<string, mixed>|null
     */
    private function resolveWidgetData(array $widget): ?array
    {
        $dataSource = $widget['dataSource'] ?? null;
        if (is_array($dataSource) === false) {
            return null;
        }

        $mode = (string) ($dataSource['mode'] ?? 'aggregation');
        try {
            if ($mode === 'aggregation') {
                $register    = (string) ($dataSource['register'] ?? '');
                $schema      = (string) ($dataSource['schema'] ?? '');
                $aggregation = (string) ($dataSource['aggregation'] ?? '');
                if ($register === '' || $schema === '' || $aggregation === '') {
                    return null;
                }

                // BypassRbac: report widgets render for viewers whose
                // authoritative reason is dashboard-read, not the
                // schema's `list` permission. The dashboard's own
                // RBAC gate already filtered the viewer at load time.
                return $this->aggregationRunner->run(
                    registerRef: $register,
                    schemaRef: $schema,
                    name: $aggregation,
                    bypassRbac: true
                );
            }

            if ($mode === 'graphql') {
                $query = (string) ($dataSource['graphqlQuery'] ?? '');
                if ($query === '') {
                    return null;
                }

                return $this->graphqlService->execute(query: $query);
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[ReportRenderService] Widget data resolution failed',
                context: [
                    'widget' => ($widget['title'] ?? ''),
                    'mode'   => $mode,
                    'error'  => $e->getMessage(),
                ]
            );
            return null;
        }//end try

        // `statistics` mode is only honoured client-side because the
        // backing endpoint is multi-call; for server-side renders the
        // operator should declare an explicit aggregation.
        return null;

    }//end resolveWidgetData()

    /**
     * Coerce the dashboard input into its data array.
     *
     * @param ObjectEntity|array $dashboard Dashboard or its payload.
     *
     * @return array<string, mixed>
     */
    private function normalisePayload($dashboard): array
    {
        if ($dashboard instanceof ObjectEntity === true) {
            $payload = $dashboard->getObject();
            return is_array($payload) === true ? $payload : [];
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
    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'dashboard';

    }//end slugify()

    /**
     * MIME type per format.
     *
     * @param string $format Format identifier.
     *
     * @return string MIME type.
     */
    private function mimeFor(string $format): string
    {
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
