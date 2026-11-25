<?php

/**
 * Lists every #[Sheddable] controller method, so the set of routes refused
 * under database pressure cannot grow or shrink unseen (design D-4).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Service\Resilience\Sheddable;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
class SheddableRoutesTest extends TestCase {

	/**
	 * The sheddable methods: exports, aggregations, GraphQL, bulk operations and report runs.
	 *
	 * A change here is a change to what a caseworker can still do while the
	 * database struggles; review it as such.
	 */
	private const SHEDDABLE = [
		'OCA\\OpenRegister\\Controller\\BulkController::delete',
		'OCA\\OpenRegister\\Controller\\BulkController::deleteRegister',
		'OCA\\OpenRegister\\Controller\\BulkController::deleteSchema',
		'OCA\\OpenRegister\\Controller\\BulkController::deleteSchemaObjects',
		'OCA\\OpenRegister\\Controller\\BulkController::runSchemaValidation',
		'OCA\\OpenRegister\\Controller\\BulkController::save',
		'OCA\\OpenRegister\\Controller\\BulkJobsController::create',
		'OCA\\OpenRegister\\Controller\\ObjectExportController::export',
		'OCA\\OpenRegister\\Search\\Controller\\AggregationController::aggregate',
		'OCA\\OpenRegister\\Search\\Controller\\AggregationController::grouped',
		'OCA\\OpenRegister\\Search\\Controller\\AggregationController::timeseries',
		'OCA\\OpenRegister\\Search\\Controller\\AggregationController::value',
		'OCA\\OpenRegister\\Search\\Controller\\GraphQLController::execute',
		'OCA\\OpenRegister\\Search\\Controller\\ReportTemplateController::generate',
		'OCA\\OpenRegister\\Search\\Controller\\ReportsController::preview',
		'OCA\\OpenRegister\\Search\\Controller\\ReportsController::render',
	];

	public function testTheSheddableSetIsExactlyTheHeavyRoutes(): void {
		$this->assertSame(self::SHEDDABLE, self::sheddableMethods());
	}

	public function testNoSingleObjectReadOrWriteIsSheddable(): void {
		foreach (['index', 'show', 'create', 'update', 'patch', 'destroy'] as $method) {
			$this->assertNotContains('OCA\\OpenRegister\\Controller\\ObjectsController::' . $method, self::sheddableMethods());
		}
	}

	/**
	 * Every method in lib/ carrying #[Sheddable], found by reading the source.
	 *
	 * The source is read rather than every class loaded, so a class that
	 * cannot be autoloaded in a unit run does not hide an attribute.
	 *
	 * @return list<string> `Class::method`, sorted.
	 */
	private static function sheddableMethods(): array {
		$found = [];
		$lib   = dirname(__DIR__, 3) . '/lib';
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib));
		foreach ($files as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$source = (string) file_get_contents($file->getPathname());
			$attributes = preg_match_all('/^\s*#\[Sheddable\]\s*$/m', $source);
			if ($attributes === 0) {
				continue;
			}

			preg_match('/^namespace ([^;]+);/m', $source, $namespace);
			preg_match('/^(?:final |abstract )?class (\w+)/m', $source, $class);
			preg_match_all('/^\s*#\[Sheddable\]\s*(?:#\[[^\]]*\]\s*)*public function (\w+)\(/m', $source, $methods);
			foreach ($methods[1] as $method) {
				$fqcn = $namespace[1] . '\\' . $class[1];
				self::assertNotSame([], (new \ReflectionMethod($fqcn, $method))->getAttributes(Sheddable::class), $fqcn . '::' . $method . ' must carry the real attribute.');
				$found[] = $fqcn . '::' . $method;
			}

			self::assertSame($attributes, count($methods[1]), $file->getPathname() . ' has a #[Sheddable] that is not on a public method.');
		}

		sort($found);
		return $found;
	}
}
