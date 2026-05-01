<?php

/**
 * HTML report writer.
 *
 * Produces a self-contained HTML document showing each widget as a
 * styled card. Operators can browser-print to PDF directly. Phase 2b
 * pipes this same output through `PdfReportWriter` (Dompdf) for
 * server-side PDF rendering — the writer itself stays unchanged.
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

/**
 * Render a resolved dashboard as a single HTML document.
 */
class HtmlReportWriter
{
    /**
     * Render the HTML.
     *
     * @param array<string, mixed>                               $dashboard       Dashboard payload.
     * @param array<int, array{widget: array, data: array|null}> $resolvedWidgets Widget+data tuples.
     *
     * @return string Rendered HTML bytes.
     */
    public function write(array $dashboard, array $resolvedWidgets): string
    {
        $title       = $this->escape(value: (string) ($dashboard['titel'] ?? 'Dashboard'));
        $description = $this->escape(value: (string) ($dashboard['beschrijving'] ?? ''));
        $generated   = (new DateTime())->format(DateTime::ATOM);

        $widgetsHtml = '';
        foreach ($resolvedWidgets as $entry) {
            $widgetsHtml .= $this->renderWidget(widget: $entry['widget'], data: $entry['data']);
        }

        $css = $this->css();

        return <<<HTML
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<title>{$title}</title>
<style>{$css}</style>
</head>
<body>
<header class="report-header">
<h1>{$title}</h1>
<p class="report-description">{$description}</p>
<p class="report-meta">Generated: {$generated}</p>
</header>
<main class="report-grid">
{$widgetsHtml}
</main>
</body>
</html>
HTML;

    }//end write()

    /**
     * Render a single widget card.
     *
     * @param array<string, mixed>      $widget Widget descriptor.
     * @param array<string, mixed>|null $data   Resolved widget data.
     *
     * @return string
     */
    private function renderWidget(array $widget, ?array $data): string
    {
        $title    = $this->escape(value: (string) ($widget['title'] ?? ''));
        $subtitle = $this->escape(value: (string) ($widget['subtitle'] ?? ''));
        $type     = (string) ($widget['type'] ?? 'kpi');

        if ($data === null) {
            $bodyHtml = '<p class="widget-error">No data available — the data source did not resolve.</p>';
        } else {
            $bodyHtml = $this->renderBody(widget: $widget, data: $data, type: $type);
        }

        $subtitleBlock = $subtitle !== '' ? "<p class=\"widget-subtitle\">{$subtitle}</p>" : '';

        return <<<HTML
<section class="widget widget-{$this->escape(value: $type)}">
<header class="widget-header">
<h2>{$title}</h2>
{$subtitleBlock}
</header>
<div class="widget-body">{$bodyHtml}</div>
</section>
HTML;

    }//end renderWidget()

    /**
     * Render the widget body based on type + data shape.
     *
     * @param array<string, mixed> $widget Widget descriptor.
     * @param array<string, mixed> $data   Resolved data.
     * @param string               $type   Widget type.
     *
     * @return string HTML.
     */
    private function renderBody(array $widget, array $data, string $type): string
    {
        $valueField = (string) ($widget['options']['valueField'] ?? 'value');

        // Group-based widgets.
        $groups = ($data['groups'] ?? null);
        if (is_array($groups) === true && $groups !== []) {
            return $this->renderGroupTable(groups: $groups, valueField: $valueField);
        }

        // Scalar value — render headline.
        $headline = $this->extractHeadline(data: $data, valueField: $valueField);
        if ($type === 'stats') {
            return $this->renderStatsBlock(data: $data);
        }

        return '<div class="widget-headline">'.$this->escape(value: $headline).'</div>';

    }//end renderBody()

    /**
     * Render an HTML table of group key/value rows.
     *
     * @param array<int, array<string, mixed>> $groups     Rows.
     * @param string                           $valueField Which value field to read.
     *
     * @return string
     */
    private function renderGroupTable(array $groups, string $valueField): string
    {
        $rows = '';
        foreach ($groups as $group) {
            $key   = $this->escape(value: (string) ($group['key'] ?? $group['label'] ?? ''));
            $value = $group[$valueField] ?? $group['value'] ?? $group['count'] ?? '';
            $rows .= '<tr><td>'.$key.'</td><td class="num">'.$this->escape(value: (string) $value).'</td></tr>';
        }

        return <<<HTML
<table class="widget-table">
<thead><tr><th>Key</th><th class="num">Value</th></tr></thead>
<tbody>{$rows}</tbody>
</table>
HTML;

    }//end renderGroupTable()

    /**
     * Render a stats block — definition list of metric/value rows.
     *
     * @param array<string, mixed> $data Resolved data.
     *
     * @return string
     */
    private function renderStatsBlock(array $data): string
    {
        $rows = '';
        foreach (['name', 'metric', 'value', 'count', 'sum', 'avg', 'min', 'max', 'count_distinct'] as $key) {
            if (array_key_exists($key, $data) === true && $data[$key] !== null) {
                $rows .= '<dt>'.$this->escape(value: $key).'</dt><dd>'.$this->escape(value: (string) $data[$key]).'</dd>';
            }
        }

        if ($rows === '') {
            return '<p class="widget-empty">No metric values</p>';
        }

        return '<dl class="widget-stats">'.$rows.'</dl>';

    }//end renderStatsBlock()

    /**
     * Pull the headline value out of an aggregation result.
     *
     * @param array<string, mixed> $data       Resolved data.
     * @param string               $valueField Preferred field.
     *
     * @return string Stringified headline.
     */
    private function extractHeadline(array $data, string $valueField): string
    {
        foreach ([$valueField, 'value', 'count', 'sum', 'avg', 'min', 'max'] as $key) {
            if (array_key_exists($key, $data) === true && $data[$key] !== null) {
                return (string) $data[$key];
            }
        }

        return '—';

    }//end extractHeadline()

    /**
     * Inline stylesheet — print-friendly + WCAG-AA contrast.
     *
     * @return string CSS source.
     */
    private function css(): string
    {
        return <<<CSS
* { box-sizing: border-box; }
body {
    font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    margin: 24px;
    color: #1f1f1f;
    background: #fff;
}
.report-header h1 { margin: 0 0 8px; font-size: 24px; }
.report-description { color: #555; margin: 0 0 4px; }
.report-meta { color: #888; font-size: 12px; margin: 0 0 24px; }
.report-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 16px;
}
.widget {
    background: #fff;
    border: 1px solid #d0d0d0;
    border-radius: 8px;
    padding: 16px;
    page-break-inside: avoid;
}
.widget-header h2 {
    margin: 0 0 4px;
    font-size: 13px;
    color: #555;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.widget-subtitle { margin: 0 0 12px; font-size: 12px; color: #888; }
.widget-headline { font-size: 32px; font-weight: 700; line-height: 1; }
.widget-error { color: #b30000; font-size: 13px; }
.widget-empty { color: #888; font-size: 13px; }
.widget-table { width: 100%; border-collapse: collapse; }
.widget-table th, .widget-table td { padding: 6px 8px; border-bottom: 1px solid #eee; text-align: left; }
.widget-table th { color: #555; font-size: 12px; }
.widget-table td.num, .widget-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
.widget-stats { display: grid; grid-template-columns: 1fr auto; gap: 4px 12px; margin: 0; }
.widget-stats dt { color: #555; font-size: 12px; text-transform: capitalize; }
.widget-stats dd { margin: 0; font-weight: 600; font-family: monospace; text-align: right; }
@media print {
    body { margin: 12px; }
    .widget { break-inside: avoid; }
}
CSS;

    }//end css()

    /**
     * HTML-escape helper.
     *
     * @param string $value Raw value.
     *
     * @return string Escaped.
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    }//end escape()
}//end class
