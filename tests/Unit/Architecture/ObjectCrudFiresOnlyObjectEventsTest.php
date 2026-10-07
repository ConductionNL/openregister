<?php

/**
 * Object CRUD fires object events only.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Nothing on the object create/update/patch/delete/bulk/import path, and no
 * listener of an object event, writes a schema or a register or dispatches a
 * schema or register event.
 *
 * Ruben, 2026-10-07: an event fires at the level where the change happens. A
 * change to an object is an object event (plus its notification), never a
 * schema or register event. Audited by hand on 2026-10-07 and found clean;
 * this test keeps it that way. A real need for a schema or register write
 * from this path belongs in a reviewed exception below, with its reason.
 *
 * @spec openspec/changes/events-at-the-level-of-change/specs/event-driven-architecture/spec.md#requirement-object-crud-fires-object-events-only
 */
class ObjectCrudFiresOnlyObjectEventsTest extends TestCase {
	/**
	 * What a schema- or register-level write or event looks like in source.
	 *
	 * @var array<int, string>
	 */
	private const FORBIDDEN = [
		'/new\s+\\\\?(?:OCA\\\\OpenRegister\\\\Event\\\\)?(?:Schema|Register)(?:Updated|Created|Deleted)Event\s*\(/',
		'/(?:schemaMapper|registerMapper|SchemaMapper|RegisterMapper)\)?\s*->\s*(?:update|updateFromArray|insert|insertOrUpdate|delete)\s*\(/',
		'/(?:schemaService|registerService)\s*->\s*(?:update|updateFromArray|createFromArray|delete)\s*\(/',
		'/->\s*update\(\s*[\'"]openregister_(?:schemas|registers)[\'"]/',
	];

	/**
	 * The object CRUD path: services, mappers and the controller.
	 *
	 * @var array<int, string>
	 */
	private const OBJECT_PATH = [
		'lib/Service/Object',
		'lib/Service/ObjectService.php',
		'lib/Db/MagicMapper.php',
		'lib/Db/MagicMapper',
		'lib/Db/ObjectEntity.php',
		'lib/Controller/ObjectsController.php',
		'lib/Controller/BulkController.php',
		'lib/Service/ImportService.php',
		'lib/Service/Import',
	];

	/**
	 * The app root.
	 *
	 * @return string The path.
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * Every PHP file under the given paths.
	 *
	 * @param array<int, string> $paths Paths relative to the app root.
	 *
	 * @return array<int, string> Absolute file paths.
	 */
	private function phpFiles(array $paths): array {
		$files = [];
		foreach ($paths as $path) {
			$absolute = $this->root() . '/' . $path;
			if (is_file($absolute) === true) {
				$files[] = $absolute;
				continue;
			}

			if (is_dir($absolute) === false) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS));
			foreach ($iterator as $file) {
				if ($file->getExtension() === 'php') {
					$files[] = $file->getPathname();
				}
			}
		}

		return $files;
	}//end phpFiles()

	/**
	 * Every listener that handles an object lifecycle event.
	 *
	 * @return array<int, string> Absolute file paths.
	 */
	private function objectListeners(): array {
		$listeners = [];
		foreach ($this->phpFiles(['lib/Listener']) as $file) {
			if (preg_match('/instanceof\s+Object(?:Created|Updated|Deleted|Creating|Updating|Deleting)Event/', (string)file_get_contents($file)) === 1) {
				$listeners[] = $file;
			}
		}

		return $listeners;
	}//end objectListeners()

	/**
	 * The schema- or register-level writes and events in one file.
	 *
	 * @param string $file Absolute path.
	 *
	 * @return array<int, string> "file:line: code" per hit.
	 */
	private function hits(string $file): array {
		$hits = [];
		foreach (file($file) as $number => $line) {
			$code = ltrim($line);
			if (str_starts_with($code, '*') === true || str_starts_with($code, '//') === true || str_starts_with($code, '/*') === true) {
				continue;
			}

			foreach (self::FORBIDDEN as $pattern) {
				if (preg_match($pattern, $line) === 1) {
					$hits[] = str_replace($this->root() . '/', '', $file) . ':' . ($number + 1) . ': ' . trim($line);
				}
			}
		}

		return $hits;
	}//end hits()

	/**
	 * The scan sees the files it claims to, and its patterns catch the real thing.
	 *
	 * Without this the test below could pass by scanning nothing.
	 *
	 * @return void
	 */
	public function testTheScanIsNotEmptyAndItsPatternsBite(): void {
		$this->assertGreaterThan(20, count($this->phpFiles(self::OBJECT_PATH)));
		$this->assertGreaterThan(3, count($this->objectListeners()));
		$this->assertContains($this->root() . '/lib/Listener/ActivityEventListener.php', $this->objectListeners());

		// The schema and register mappers themselves must trip the patterns.
		$this->assertNotSame([], $this->hits($this->root() . '/lib/Db/SchemaMapper.php'));
		$this->assertNotSame([], $this->hits($this->root() . '/lib/Db/RegisterMapper.php'));
		$this->assertNotSame([], $this->hits($this->root() . '/lib/Service/LinkedEntityService.php'));
	}//end testTheScanIsNotEmptyAndItsPatternsBite()

	/**
	 * The object CRUD path writes no schema or register and fires no schema or register event.
	 *
	 * @return void
	 */
	public function testTheObjectPathTouchesNoSchemaOrRegister(): void {
		$hits = [];
		foreach ($this->phpFiles(self::OBJECT_PATH) as $file) {
			$hits = array_merge($hits, $this->hits($file));
		}

		$this->assertSame([], $hits, "Object CRUD must fire object events only:\n" . implode("\n", $hits));
	}//end testTheObjectPathTouchesNoSchemaOrRegister()

	/**
	 * No listener of an object event writes a schema or register or fires their events.
	 *
	 * @return void
	 */
	public function testObjectEventListenersTouchNoSchemaOrRegister(): void {
		$hits = [];
		foreach ($this->objectListeners() as $file) {
			$hits = array_merge($hits, $this->hits($file));
		}

		$this->assertSame([], $hits, "An object event listener must not write a schema or register:\n" . implode("\n", $hits));
	}//end testObjectEventListenersTouchNoSchemaOrRegister()
}//end class
