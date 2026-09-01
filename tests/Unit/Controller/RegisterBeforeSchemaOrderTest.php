<?php

declare(strict_types=1);

/*
 * A controller must name the register BEFORE the schema.
 *
 * `ObjectService::setSchema()` resolves a slug scoped to whatever register is
 * currently set, and the service is reused across many operations in one
 * process. So `setSchema($s)` followed by `setRegister($r)` resolves the slug
 * against a register LEFT BEHIND by an unrelated call, while reading exactly
 * like the correct order.
 *
 * Measured on the development instance 2026-09-01:
 * `GET /objects/dossiq/case/{id}/notes` answered 404 "Object not found". The
 * object resolved fine at `/objects/dossiq/case/{id}`, and the identical
 * resolution chain succeeded from `occ`. Under HTTP it threw
 * `Schema slug "case" is not carried by register "buildiq"` — an app the
 * request never mentioned, whose own `case` schema the global slug lookup had
 * reached first.
 *
 * Every object sub-resource was affected, in every app on the instance: notes,
 * tags, relations, emails, polls, talk, bookmarks, deck, calendar, tasks,
 * time-tracker and the rest. All of them rendered their EMPTY state, so a case
 * with notes said "No notes yet" rather than that it could not ask. 25
 * controllers carried the inverted order; `FilesController` did not, and its
 * docblock already described this bug.
 *
 * `ObjectService::setRegister()` re-resolves a pending schema ref for exactly
 * this reason, and its own comment names this helper as the shape that inverted
 * the two. That repair cannot help here: the throw happens inside `setSchema()`
 * itself, before `setRegister()` is ever reached.
 *
 * This test is static on purpose. The runtime symptom needs a second register
 * that happens to share a slug AND a prior operation that left its register
 * set, which is a state no unit test would naturally reproduce and exactly the
 * state a live instance reaches constantly.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\OpenRegister\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

class RegisterBeforeSchemaOrderTest extends TestCase {

	/**
	 * Every controller source file.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function controllerFiles(): array {
		$dir   = __DIR__ . '/../../../lib/Controller';
		$cases = [];
		foreach (glob($dir . '/*.php') as $file) {
			$cases[basename($file)] = [$file];
		}

		return $cases;
	}//end controllerFiles()

	/**
	 * `setSchema()` must never immediately precede `setRegister()`.
	 *
	 * @param string $file The controller file to scan.
	 *
	 * @return void
	 *
	 * @dataProvider controllerFiles
	 */
	public function testRegisterIsNamedBeforeSchema(string $file): void {
		$source = file_get_contents($file);
		$this->assertIsString($source, 'controller source is readable');

		$inverted = preg_match_all(
			'/setSchema\(\s*[^)]*\s*\)\s*;\s*\n\s*\$this->objectService->setRegister\(/',
			$source
		);

		$this->assertSame(
			0,
			$inverted,
			basename($file) . ' calls setSchema() before setRegister(). '
			. 'setSchema() scopes its slug lookup to the register currently set on the '
			. 'shared ObjectService, so this resolves the slug against whatever register '
			. 'an unrelated earlier operation left behind. Name the register first.'
		);
	}//end testRegisterIsNamedBeforeSchema()
}//end class
