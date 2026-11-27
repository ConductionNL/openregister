<?php

/**
 * Guards the controller docblocks against a PHP warning Nextcloud raises on every request.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/apphost-observability/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Nextcloud's ControllerMethodReflector reads every `@Name(...)` line of the action method's
 * docblock as `key=value` pairs and splits each on `=`. A line such as
 * `@SuppressWarnings(PHPMD.CyclomaticComplexity)` has no `=`, so every request to that action
 * logs "Undefined array key 1 at ControllerMethodReflector.php#57". A reason after the closing
 * parenthesis keeps PHPMD reading the suppression and makes Nextcloud skip the line.
 *
 * @spec openspec/specs/apphost-observability/spec.md
 */
class ControllerAnnotationsParseTest extends TestCase {

	/**
	 * Nextcloud's own pattern (lib/private/AppFramework/Utility/ControllerMethodReflector.php).
	 */
	private const NEXTCLOUD_ANNOTATION = '/^\h+\*\h+@(?P<annotation>[A-Z]\w+)((?P<parameter>.*))?$/m';

	/**
	 * No public controller method carries a parenthesised annotation Nextcloud cannot split.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-observability/spec.md
	 */
	public function testNoActionDocblockMakesNextcloudWarn(): void {
		$offending = [];
		foreach (glob(__DIR__ . '/../../../lib/Controller/{,*/}*.php', GLOB_BRACE) as $file) {
			foreach (self::publicMethodDocblocks(source: (string) file_get_contents($file)) as $method => $docblock) {
				foreach (self::unsplittable(docblock: $docblock) as $line) {
					$offending[] = basename($file) . '::' . $method . ' ' . $line;
				}
			}
		}

		$this->assertSame([], $offending, 'Add a reason after the closing parenthesis of these annotations.');
	}//end testNoActionDocblockMakesNextcloudWarn()

	/**
	 * The check itself finds the shape that warns and passes the two shapes that do not.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-observability/spec.md
	 */
	public function testTheCheckFindsTheWarningShape(): void {
		$source = "<?php\nclass A {\n"
			. "\t/**\n\t * @SuppressWarnings(PHPMD.CyclomaticComplexity)\n\t */\n\t#[Attr]\n\tpublic function bad(): void {}\n"
			. "\t/**\n\t * @SuppressWarnings(PHPMD.CyclomaticComplexity) One branch per filter.\n\t */\n\tpublic function reasoned(): void {}\n"
			. "\t/**\n\t * @UserRateThrottle(limit=5, period=100)\n\t */\n\tpublic function pairs(): void {}\n"
			. "}\n";

		$found = [];
		foreach (self::publicMethodDocblocks(source: $source) as $method => $docblock) {
			if (self::unsplittable(docblock: $docblock) !== []) {
				$found[] = $method;
			}
		}

		$this->assertSame(['bad'], $found);
	}//end testTheCheckFindsTheWarningShape()

	/**
	 * The docblock of each public non-magic method, keyed by method name.
	 *
	 * @param string $source PHP source.
	 *
	 * @return array<string, string>
	 */
	private static function publicMethodDocblocks(string $source): array {
		$docblocks = [];
		$pattern   = '/(\/\*\*(?:(?!\*\/).)*\*\/)\s*(?:#\[[^\n]*\]\s*)*public\s+(?:static\s+)?function\s+(\w+)/s';
		if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER) > 0) {
			foreach ($matches as $match) {
				// A magic method such as the constructor is never routed, so Nextcloud never reflects it.
				if (str_starts_with($match[2], '__') === true) {
					continue;
				}

				$docblocks[$match[2]] = $match[1];
			}
		}

		return $docblocks;
	}//end publicMethodDocblocks()

	/**
	 * The annotation lines Nextcloud would split into a pair without a value.
	 *
	 * @param string $docblock The docblock.
	 *
	 * @return array<int, string>
	 */
	private static function unsplittable(string $docblock): array {
		$lines = [];
		preg_match_all(self::NEXTCLOUD_ANNOTATION, $docblock, $matches);
		foreach ($matches['parameter'] as $key => $parameter) {
			if (str_starts_with($parameter, '(') === false || str_ends_with($parameter, ')') === false) {
				continue;
			}

			foreach (explode(',', str_replace(' ', '', substr($parameter, 1, -1))) as $pair) {
				if (str_contains($pair, '=') === false) {
					$lines[] = '@' . $matches['annotation'][$key] . $parameter;
					break;
				}
			}
		}

		return $lines;
	}//end unsplittable()
}//end class
