<?php

/**
 * Golden pin for MetricDescriptor::fromArray().
 *
 * Records, for a broad table of manifest `metrics[]` entries, either the
 * descriptor fromArray() built or the exact validation message it raised,
 * before the validation was split into smaller methods. Validation order
 * matters (the first failing rule names the message), so entries that break
 * several rules at once are included.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Observability\MetricDescriptor;
use OCA\OpenRegister\AppHost\Observability\ObservabilityValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins every MetricDescriptor::fromArray() outcome recorded on the pre-refactor code.
 */
class MetricDescriptorGoldenTest extends TestCase {
	/**
	 * Recorded outcomes: the entry as JSON, then the built descriptor as JSON
	 * or `ERR ` followed by the validation message.
	 *
	 * @return array<string, array{string, string}> The cases.
	 */
	public static function recordedOutcomes(): array {
		return [
			'entry 01' => ['[]', 'ERR Metric "name" must be a valid Prometheus metric name.'],
			'entry 02' => ['{"name":5}', 'ERR Metric "name" must be a valid Prometheus metric name.'],
			'entry 03' => ['{"name":"1abc"}', 'ERR Metric "name" must be a valid Prometheus metric name.'],
			'entry 04' => ['{"name":"a-b"}', 'ERR Metric "name" must be a valid Prometheus metric name.'],
			'entry 05' => ['{"name":"ok"}', 'ERR Metric "ok" requires a "source" object.'],
			'entry 06' => ['{"name":"ok","type":"histogram"}', 'ERR Invalid metric type "histogram" (allowed: gauge, counter).'],
			'entry 07' => ['{"name":"ok","type":["x"]}', 'ERR Invalid metric type "array" (allowed: gauge, counter).'],
			'entry 08' => ['{"name":"ok","type":3}', 'ERR Invalid metric type "3" (allowed: gauge, counter).'],
			'entry 09' => ['{"name":"ok","source":"x"}', 'ERR Metric "ok" requires a "source" object.'],
			'entry 10' => ['{"name":"ok","source":[]}', 'ERR Unknown metric source kind "NULL" (allowed: objectCount, objectSum, tableCount, appConfig, provider).'],
			'entry 11' => ['{"name":"ok","source":{"kind":"nope"}}', 'ERR Unknown metric source kind "nope" (allowed: objectCount, objectSum, tableCount, appConfig, provider).'],
			'entry 12' => ['{"name":"ok","source":{"kind":null}}', 'ERR Unknown metric source kind "NULL" (allowed: objectCount, objectSum, tableCount, appConfig, provider).'],
			'entry 13' => ['{"name":"ok","source":{"kind":1.5}}', 'ERR Unknown metric source kind "1.5" (allowed: objectCount, objectSum, tableCount, appConfig, provider).'],
			'entry 14' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case"}}', '{"name":"ok","type":"gauge","kind":"objectCount","source":{"kind":"objectCount","schema":"case"},"help":null,"cacheTtl":0}'],
			'entry 15' => ['{"name":"_Ok9","type":"counter","source":{"kind":"objectCount","schema":"case"},"help":"Help","cacheTtl":30}', '{"name":"_Ok9","type":"counter","kind":"objectCount","source":{"kind":"objectCount","schema":"case"},"help":"Help","cacheTtl":30}'],
			'entry 16' => ['{"name":"ok","source":{"kind":"objectCount"}}', 'ERR Metric "ok" objectCount source requires a "schema" slug.'],
			'entry 17' => ['{"name":"ok","source":{"kind":"objectSum","schema":""}}', 'ERR Metric "ok" objectSum source requires a "schema" slug.'],
			'entry 18' => ['{"name":"ok","source":{"kind":"objectCount","schema":5}}', 'ERR Metric "ok" objectCount source requires a "schema" slug.'],
			'entry 19' => ['{"name":"ok","source":{"kind":"objectCount","schema":null}}', 'ERR Metric "ok" objectCount source requires a "schema" slug.'],
			'entry 20' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case","register":5}}', 'ERR Metric "ok" "register" must be a slug string.'],
			'entry 21' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case","register":null}}', '{"name":"ok","type":"gauge","kind":"objectCount","source":{"kind":"objectCount","schema":"case","register":null},"help":null,"cacheTtl":0}'],
			'entry 22' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case","register":"r"}}', '{"name":"ok","type":"gauge","kind":"objectCount","source":{"kind":"objectCount","schema":"case","register":"r"},"help":null,"cacheTtl":0}'],
			'entry 23' => ['{"name":"ok","source":{"kind":"objectSum","schema":"s"}}', 'ERR Metric "ok" objectSum source requires a numeric "field".'],
			'entry 24' => ['{"name":"ok","source":{"kind":"objectSum","schema":"s","field":""}}', 'ERR Metric "ok" objectSum source requires a numeric "field".'],
			'entry 25' => ['{"name":"ok","source":{"kind":"objectSum","schema":"s","field":7}}', 'ERR Metric "ok" objectSum source requires a numeric "field".'],
			'entry 26' => ['{"name":"ok","source":{"kind":"objectSum","schema":"s","field":"amount"}}', '{"name":"ok","type":"gauge","kind":"objectSum","source":{"kind":"objectSum","schema":"s","field":"amount"},"help":null,"cacheTtl":0}'],
			'entry 27' => ['{"name":"ok","source":{"kind":"objectSum","schema":5,"field":""}}', 'ERR Metric "ok" objectSum source requires a "schema" slug.'],
			'entry 28' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case","groupBy":"x"}}', 'ERR Metric "ok" groupBy must be an array.'],
			'entry 29' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case","groupBy":null}}', '{"name":"ok","type":"gauge","kind":"objectCount","source":{"kind":"objectCount","schema":"case","groupBy":null},"help":null,"cacheTtl":0}'],
			'entry 30' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case","groupBy":["a.b","c_1"]}}', '{"name":"ok","type":"gauge","kind":"objectCount","source":{"kind":"objectCount","schema":"case","groupBy":["a.b","c_1"]},"help":null,"cacheTtl":0}'],
			'entry 31' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case","groupBy":["a-b"]}}', 'ERR Metric "ok" groupBy field "a-b" is invalid.'],
			'entry 32' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case","groupBy":[5]}}', 'ERR Metric "ok" groupBy field "5" is invalid.'],
			'entry 33' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case","groupBy":[["x"]]}}', 'ERR Metric "ok" groupBy field "array" is invalid.'],
			'entry 34' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case","groupBy":[]}}', '{"name":"ok","type":"gauge","kind":"objectCount","source":{"kind":"objectCount","schema":"case","groupBy":[]},"help":null,"cacheTtl":0}'],
			'entry 35' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case","filter":"x"}}', 'ERR Metric "ok" filter must be an object.'],
			'entry 36' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case","filter":[]}}', '{"name":"ok","type":"gauge","kind":"objectCount","source":{"kind":"objectCount","schema":"case","filter":[]},"help":null,"cacheTtl":0}'],
			'entry 37' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case","filter":{"status":{"eq":"open","like":"x%"}}}}', '{"name":"ok","type":"gauge","kind":"objectCount","source":{"kind":"objectCount","schema":"case","filter":{"status":{"eq":"open","like":"x%"}}},"help":null,"cacheTtl":0}'],
			'entry 38' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case","filter":{"5":{"eq":1}}}}', 'ERR Metric "ok" filter field "5" is invalid.'],
			'entry 39' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case","filter":{"a b":{"eq":1}}}}', 'ERR Metric "ok" filter field "a b" is invalid.'],
			'entry 40' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case","filter":{"status":"open"}}}', 'ERR Metric "ok" filter "status" must map operators to values.'],
			'entry 41' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case","filter":{"status":{"in":[1]}}}}', 'ERR Metric "ok" filter operator "in" is not allowed (allowed: eq, neq, lt, lte, gt, gte, like).'],
			'entry 42' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case","filter":{"status":["x"]}}}', 'ERR Metric "ok" filter operator "0" is not allowed (allowed: eq, neq, lt, lte, gt, gte, like).'],
			'entry 43' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case","filter":{"status":[]}}}', '{"name":"ok","type":"gauge","kind":"objectCount","source":{"kind":"objectCount","schema":"case","filter":{"status":[]}},"help":null,"cacheTtl":0}'],
			'entry 44' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case","groupBy":["bad!"],"filter":"x"}}', 'ERR Metric "ok" groupBy field "bad!" is invalid.'],
			'entry 45' => ['{"name":"ok","source":{"kind":"tableCount"}}', 'ERR Metric "ok" tableCount "table" must match /^[a-z0-9_]+$/ (got "NULL").'],
			'entry 46' => ['{"name":"ok","source":{"kind":"tableCount","table":"Bad"}}', 'ERR Metric "ok" tableCount "table" must match /^[a-z0-9_]+$/ (got "Bad").'],
			'entry 47' => ['{"name":"ok","source":{"kind":"tableCount","table":["x"]}}', 'ERR Metric "ok" tableCount "table" must match /^[a-z0-9_]+$/ (got "array").'],
			'entry 48' => ['{"name":"ok","source":{"kind":"tableCount","table":"x;drop"}}', 'ERR Metric "ok" tableCount "table" must match /^[a-z0-9_]+$/ (got "x;drop").'],
			'entry 49' => ['{"name":"ok","source":{"kind":"tableCount","table":"oc_jobs"}}', '{"name":"ok","type":"gauge","kind":"tableCount","source":{"kind":"tableCount","table":"oc_jobs"},"help":null,"cacheTtl":0}'],
			'entry 50' => ['{"name":"ok","source":{"kind":"tableCount","table":"oc_jobs","groupBy":["s"],"filter":{"s":{"neq":1}},"labelMap":{"s":"status"},"labelDefaults":{"s":0}}}', '{"name":"ok","type":"gauge","kind":"tableCount","source":{"kind":"tableCount","table":"oc_jobs","groupBy":["s"],"filter":{"s":{"neq":1}},"labelMap":{"s":"status"},"labelDefaults":{"s":0}},"help":null,"cacheTtl":0}'],
			'entry 51' => ['{"name":"ok","source":{"kind":"tableCount","table":"oc_jobs","labelMap":"x"}}', 'ERR Metric "ok" labelMap must be an object.'],
			'entry 52' => ['{"name":"ok","source":{"kind":"tableCount","table":"oc_jobs","labelMap":null}}', '{"name":"ok","type":"gauge","kind":"tableCount","source":{"kind":"tableCount","table":"oc_jobs","labelMap":null},"help":null,"cacheTtl":0}'],
			'entry 53' => ['{"name":"ok","source":{"kind":"tableCount","table":"oc_jobs","labelMap":{"5":"x"}}}', 'ERR Metric "ok" labelMap column "5" is invalid.'],
			'entry 54' => ['{"name":"ok","source":{"kind":"tableCount","table":"oc_jobs","labelMap":{"a.b":"x"}}}', 'ERR Metric "ok" labelMap column "a.b" is invalid.'],
			'entry 55' => ['{"name":"ok","source":{"kind":"tableCount","table":"oc_jobs","labelMap":{"a":["x"]}}}', 'ERR Metric "ok" labelMap value for "a" must be scalar.'],
			'entry 56' => ['{"name":"ok","source":{"kind":"tableCount","table":"oc_jobs","labelMap":{"a":null}}}', 'ERR Metric "ok" labelMap value for "a" must be scalar.'],
			'entry 57' => ['{"name":"ok","source":{"kind":"tableCount","table":"oc_jobs","labelDefaults":"x"}}', 'ERR Metric "ok" labelDefaults must be an object.'],
			'entry 58' => ['{"name":"ok","source":{"kind":"tableCount","table":"oc_jobs","labelDefaults":{"a":true,"b":1.5}}}', '{"name":"ok","type":"gauge","kind":"tableCount","source":{"kind":"tableCount","table":"oc_jobs","labelDefaults":{"a":true,"b":1.5}},"help":null,"cacheTtl":0}'],
			'entry 59' => ['{"name":"ok","source":{"kind":"tableCount","table":"oc_jobs","labelDefaults":{"a":[]}}}', 'ERR Metric "ok" labelDefaults value for "a" must be scalar.'],
			'entry 60' => ['{"name":"ok","source":{"kind":"tableCount","table":"oc_jobs","groupBy":"x","labelMap":"y"}}', 'ERR Metric "ok" groupBy must be an array.'],
			'entry 61' => ['{"name":"ok","source":{"kind":"tableCount","table":"oc_jobs","filter":{"s":{"bad":1}},"labelMap":"y"}}', 'ERR Metric "ok" filter operator "bad" is not allowed (allowed: eq, neq, lt, lte, gt, gte, like).'],
			'entry 62' => ['{"name":"ok","source":{"kind":"appConfig"}}', 'ERR Metric "ok" appConfig source requires a "key".'],
			'entry 63' => ['{"name":"ok","source":{"kind":"appConfig","key":""}}', 'ERR Metric "ok" appConfig source requires a "key".'],
			'entry 64' => ['{"name":"ok","source":{"kind":"appConfig","key":5}}', 'ERR Metric "ok" appConfig source requires a "key".'],
			'entry 65' => ['{"name":"ok","source":{"kind":"appConfig","key":"k","groupBy":"ignored"}}', '{"name":"ok","type":"gauge","kind":"appConfig","source":{"kind":"appConfig","key":"k","groupBy":"ignored"},"help":null,"cacheTtl":0}'],
			'entry 66' => ['{"name":"ok","source":{"kind":"provider","anything":1}}', '{"name":"ok","type":"gauge","kind":"provider","source":{"kind":"provider","anything":1},"help":null,"cacheTtl":0}'],
			'entry 67' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case"},"cacheTtl":-1}', 'ERR Metric "ok" cacheTtl must be a non-negative integer.'],
			'entry 68' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case"},"cacheTtl":"5"}', 'ERR Metric "ok" cacheTtl must be a non-negative integer.'],
			'entry 69' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case"},"cacheTtl":0}', '{"name":"ok","type":"gauge","kind":"objectCount","source":{"kind":"objectCount","schema":"case"},"help":null,"cacheTtl":0}'],
			'entry 70' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case"},"cacheTtl":null}', '{"name":"ok","type":"gauge","kind":"objectCount","source":{"kind":"objectCount","schema":"case"},"help":null,"cacheTtl":0}'],
			'entry 71' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case"},"help":5}', 'ERR Metric "ok" help must be a string.'],
			'entry 72' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case"},"help":null}', '{"name":"ok","type":"gauge","kind":"objectCount","source":{"kind":"objectCount","schema":"case"},"help":null,"cacheTtl":0}'],
			'entry 73' => ['{"name":"ok","source":{"kind":"objectCount","schema":"case"},"help":5,"cacheTtl":-1}', 'ERR Metric "ok" cacheTtl must be a non-negative integer.'],
			'entry 74' => ['{"name":"ok","type":"x","source":"y"}', 'ERR Invalid metric type "x" (allowed: gauge, counter).'],
			'entry 75' => ['{"name":"ok","source":{"kind":"objectSum"},"cacheTtl":-1}', 'ERR Metric "ok" objectSum source requires a "schema" slug.'],
		];
	}//end recordedOutcomes()

	/**
	 * Each entry still builds the recorded descriptor or raises the recorded message.
	 *
	 * @param string $entryJson The raw manifest entry, as JSON.
	 * @param string $expected  The recorded outcome.
	 *
	 * @return void
	 */
	#[DataProvider('recordedOutcomes')]
	public function testOutcomeMatchesRecording(string $entryJson, string $expected): void {
		$this->assertSame($expected, $this->outcome(json_decode($entryJson, true)));
	}//end testOutcomeMatchesRecording()

	/**
	 * Run fromArray() and render its outcome the way the recording did.
	 *
	 * @param array<string, mixed> $entry The raw manifest entry.
	 *
	 * @return string The descriptor as JSON, or `ERR ` and the message.
	 */
	private function outcome(array $entry): string {
		try {
			$descriptor = MetricDescriptor::fromArray($entry);
		} catch (ObservabilityValidationException $e) {
			return 'ERR '.$e->getMessage();
		}

		return (string)json_encode(
			[
				'name' => $descriptor->name,
				'type' => $descriptor->type,
				'kind' => $descriptor->kind,
				'source' => $descriptor->source,
				'help' => $descriptor->help,
				'cacheTtl' => $descriptor->cacheTtl,
			]
		);
	}//end outcome()
}//end class
