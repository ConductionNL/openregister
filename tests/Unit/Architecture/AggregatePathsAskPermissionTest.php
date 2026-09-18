<?php

/**
 * Every live path that summarises a schema property asks whether it may.
 *
 * 🔴 THIS TEST EXISTS BECAUSE THE LEAK WAS FOUND BY LOOKING, NOT BY FAILING.
 * `MagicFacetHandler` returned the distinct values of governed columns to
 * everybody who could list the register, for as long as property-level
 * authorization has existed, and no test anywhere went red. Nothing on screen
 * suggested it either: the render path strips the property from object bodies
 * correctly, so the field was invisible where people looked and legible where
 * nobody did.
 *
 * 🔑 SO THE LIST IS DERIVED FROM THE SOURCE, NOT WRITTEN OUT HERE. A path added
 * next month is covered the day it is written rather than the day somebody
 * remembers this file. The allowlist carries a REASON per entry, because
 * "excluded" without one is indistinguishable from "forgotten".
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Structural: aggregate paths and the read rule.
 *
 * @coversNothing
 */
class AggregatePathsAskPermissionTest extends TestCase {

	/**
	 * Paths that match the shape but owe no check, each with the reason.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED = [



		// GUARDED AT THE BOUNDARY, BY ITS ONLY CALLER. `aggregate()` here has
		// exactly one call site, `AggregationRunner::run()`, which refuses an
		// aggregate over a property the caller may not read before reaching it.
		// A second gate here would be a second answer to one question.
		'lib/Service/ObjectSource/DbalObjectSourceProvider.php' => 'guarded by its only caller, AggregationRunner',

		// Delegate to a guarded path and compute nothing themselves. The gate
		// belongs where the values are produced, not at every layer that passes
		// a request along; repeating it would multiply the places it can drift.
		'lib/Controller/AggregationController.php' => 'delegates to AggregationRunner',
		'lib/Controller/ObjectsController.php' => 'delegates to ObjectService and FacetHandler',
		'lib/Db/MagicMapper.php' => 'delegates to its handlers',
		'lib/Service/ObjectService.php' => 'delegates to FacetHandler and the mappers',
		'lib/Db/AbstractObjectMapper.php' => 'base class; concrete mappers carry the gate',

		// Filters ROWS. A property a caller may not read is stripped from every
		// object body by the render path, and a WHERE over a column returns no
		// value to anybody.
		'lib/Db/MagicMapper/MagicSearchHandler.php' => 'filters rows; property reads are stripped by the render path',

		// Validate or persist a CONFIGURATION that names a property. They never
		// read a value. `ViewService` checks a kanban groupByField exists;
		// `TimeseriesRequestValidator` checks a request's shape.
		'lib/Service/ViewService.php' => 'validates config naming a property, reads no value',
		'lib/Service/Aggregation/TimeseriesRequestValidator.php' => 'validates request shape, reads no value',

		// The entity and its persistence. They hold property definitions; they
		// never summarise a value.
		'lib/Db/Schema.php' => 'entity holding definitions, summarises nothing',
		'lib/Db/SchemaMapper.php' => 'persistence, summarises nothing',
		'lib/Service/Schemas/SchemaCacheHandler.php' => 'caches definitions, summarises nothing',
	];

	/**
	 * Facet handlers that match the shape's spirit but are never instantiated.
	 *
	 * 🔑 THESE ARE AN ASSERTION, NOT A NOTE. Listing them as "allowed" would
	 * have excused them from a check they are not subject to, and an allowlist
	 * entry nothing matches is dead weight that hides drift. So instead the
	 * suite asserts they remain UNINSTANTIATED: the day one of them is wired up
	 * it becomes a live aggregate path and has to answer the question, and this
	 * test is what says so.
	 *
	 * @var array<int, string>
	 */
	private const DEAD_FACET_HANDLERS = [
		'lib/Db/ObjectHandlers/HyperFacetHandler.php',
		'lib/Db/ObjectHandlers/MariaDbFacetHandler.php',
		'lib/Db/ObjectHandlers/MetaDataFacetHandler.php',
		'lib/Db/ObjectHandlers/OptimizedFacetHandler.php',
	];

	/**
	 * The repository root.
	 *
	 * @return string The path.
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * Whether a file both knows schema properties and summarises them.
	 *
	 * @param string $source The file's source.
	 *
	 * @return bool Whether it matches the shape.
	 */
	private function matchesTheShape(string $source): bool {
		$knowsProperties = (str_contains($source, 'getProperties()') === true
			|| str_contains($source, 'Schema $schema') === true);

		$summarises = (str_contains($source, 'groupBy') === true
			|| str_contains($source, 'GROUP BY') === true
			|| str_contains($source, 'facetable') === true);

		return ($knowsProperties === true && $summarises === true);
	}//end matchesTheShape()

	/**
	 * Files that take a Schema and summarise a property.
	 *
	 * @return array<int, string> Relative paths.
	 */
	private function aggregatePaths(): array {
		$root  = $this->root();
		$found = [];

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root . '/lib', FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			if ($this->matchesTheShape((string)file_get_contents($file->getPathname())) === true) {
				$found[] = str_replace($root . '/', '', $file->getPathname());
			}
		}

		sort($found);

		return $found;
	}//end aggregatePaths()

	/**
	 * Whether a file asks the read rule at all.
	 *
	 * @param string $source The file's source.
	 *
	 * @return bool Whether it asks.
	 */
	private function asksTheReadRule(string $source): bool {
		return (str_contains($source, 'AggregateVisibility') === true
			|| str_contains($source, 'canReadProperty') === true
			|| str_contains($source, 'callerMayFacet') === true
			|| str_contains($source, 'filterReadableProperties') === true);
	}//end asksTheReadRule()

	/**
	 * Every aggregate path asks the read rule, or carries a reason.
	 *
	 * @return void
	 */
	public function testEveryAggregatePathAsksOrCarriesAReason(): void {
		$root      = $this->root();
		$unguarded = [];

		foreach ($this->aggregatePaths() as $path) {
			if (array_key_exists($path, self::ALLOWED) === true) {
				continue;
			}

			if ($this->asksTheReadRule((string)file_get_contents($root . '/' . $path)) === false) {
				$unguarded[] = $path;
			}
		}

		$this->assertSame(
			[],
			$unguarded,
			'These paths summarise a schema property without asking whether the caller may read it. '
			. "An aggregate is a read of the column for everybody it is shown to:\n - "
			. implode("\n - ", $unguarded)
		);
	}//end testEveryAggregatePathAsksOrCarriesAReason()

	/**
	 * Every allowlist entry carries a reason.
	 *
	 * "Excluded" without one is indistinguishable from "forgotten".
	 *
	 * @return void
	 */
	public function testEveryAllowlistEntryCarriesAReason(): void {
		foreach (self::ALLOWED as $path => $reason) {
			$this->assertNotSame('', trim($reason), $path . ' is excluded without a reason.');
		}
	}//end testEveryAllowlistEntryCarriesAReason()

	/**
	 * The derivation finds more than the allowlist.
	 *
	 * The control. Without it, a typo in the shape test would make the suite
	 * pass by finding no paths at all, which is the failure mode of every
	 * derived test.
	 *
	 * @return void
	 */
	public function testTheDerivationCatchesPathsThatDoAsk(): void {
		$root   = $this->root();
		$asking = [];

		foreach ($this->aggregatePaths() as $path) {
			if (array_key_exists($path, self::ALLOWED) === true) {
				continue;
			}

			if ($this->asksTheReadRule((string)file_get_contents($root . '/' . $path)) === true) {
				$asking[] = $path;
			}
		}

		// If the shape matched only allowlisted paths, it would report green
		// while being blind to every path it is supposed to police. Finding the
		// GUARDED ones is the proof that it would find an unguarded one.
		$this->assertNotSame(
			[],
			$asking,
			'The shape matched no guarded path, so it would not catch an unguarded one either.'
		);
	}//end testTheDerivationCatchesPathsThatDoAsk()

	/**
	 * Every allowlist entry is a path the shape actually matches.
	 *
	 * An entry nothing matches excuses nothing, and quietly accumulates: it
	 * reads as a considered exception while being a leftover.
	 *
	 * @return void
	 */
	public function testEveryAllowlistEntryIsStillMatchedByTheShape(): void {
		$matched = $this->aggregatePaths();

		foreach (array_keys(self::ALLOWED) as $path) {
			$this->assertContains(
				$path,
				$matched,
				$path . ' is allowlisted but the shape no longer matches it, so the entry excuses nothing.'
			);
		}
	}//end testEveryAllowlistEntryIsStillMatchedByTheShape()

	/**
	 * The dead facet handlers are still dead.
	 *
	 * The day one is wired up it becomes a live aggregate path and owes the
	 * read question. This is what says so.
	 *
	 * @return void
	 */
	public function testTheDeadFacetHandlersAreStillDead(): void {
		$root = $this->root();

		foreach (self::DEAD_FACET_HANDLERS as $path) {
			$this->assertFileExists($root . '/' . $path);

			$class = basename($path, '.php');
			$references = 0;

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($root . '/lib', FilesystemIterator::SKIP_DOTS)
			);

			foreach ($iterator as $file) {
				if ($file->isFile() === false || $file->getExtension() !== 'php') {
					continue;
				}

				if (str_ends_with($file->getPathname(), $path) === true) {
					continue;
				}

				$source = (string)file_get_contents($file->getPathname());
				if (str_contains($source, 'new ' . $class . '(') === true
					|| str_contains($source, $class . '::class') === true
				) {
					$references++;
				}
			}

			$this->assertSame(
				0,
				$references,
				$class . ' is now instantiated, so it is a live aggregate path and owes the read question.'
			);
		}
	}//end testTheDeadFacetHandlersAreStillDead()

	/**
	 * Every allowlist entry still names a file that exists.
	 *
	 * A stale entry excuses nothing and hides that the path it named has moved.
	 *
	 * @return void
	 */
	public function testTheAllowlistHasNoStaleEntries(): void {
		foreach (array_keys(self::ALLOWED) as $path) {
			$this->assertFileExists($this->root() . '/' . $path, $path . ' is allowlisted but gone.');
		}
	}//end testTheAllowlistHasNoStaleEntries()
}//end class
