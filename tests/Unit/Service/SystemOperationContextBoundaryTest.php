<?php

/**
 * The reachability boundary on trusted userless elevation.
 *
 * ADR-099 rule 9: `runAsSystem()` / `SystemOperationContext::run()` is
 * code-initiated only, and MUST NOT be reachable from a flow node, an agent
 * tool, or the handling of an inbound request.
 *
 * This is a structural test rather than a behavioural one because the property
 * being protected is "who is allowed to call this", which no runtime assertion
 * inside the method can answer — by the time the callable runs, the caller is
 * gone. Pinning the call-site set makes adding one a deliberate act with a
 * visible diff, which is the actual failure mode: not somebody arguing for an
 * escalation, but somebody reaching for the nearest thing that makes a refusal
 * go away.
 *
 * It is a guard against drift, not a proof. A dynamically dispatched call is
 * invisible to it. The structural control that actually holds the line is that
 * ObjectService is not injected into node, tool or endpoint classes for this
 * purpose.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/delegated-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Pins which files may elevate to a trusted userless principal.
 */
class SystemOperationContextBoundaryTest extends TestCase {

	/**
	 * Files permitted to elevate, relative to the app root.
	 *
	 * Each entry is here because the work genuinely has no user to act for:
	 * a repair step seeding a shipped register, an app's own config import at
	 * boot or under webcron, and seeding of shipped seed-data objects. Adding
	 * an entry means asserting the same of the new caller.
	 *
	 * @var string[]
	 */
	private const ALLOWED = [
		'lib/Service/ObjectService.php',
		'lib/Service/ConfigurationService.php',
		'lib/Service/Configuration/ImportHandler.php',
		'lib/Repair/SeedVocabularyRegister.php',
	];

	/**
	 * Directories from which elevation is forbidden outright.
	 *
	 * A match here fails even if somebody also adds the file to ALLOWED — these
	 * are the three caller classes ADR-099 names, and a reviewer skimming an
	 * ALLOWED diff could plausibly wave one through.
	 *
	 * @var string[]
	 */
	private const FORBIDDEN_PREFIXES = [
		'lib/Service/Flow/Nodes/',
		'lib/Controller/',
		'lib/Service/Mcp/',
	];

	/**
	 * The app root.
	 *
	 * @return string The absolute path to the app root.
	 */
	private function appRoot(): string {
		return dirname(__DIR__, 3);
	}

	/**
	 * Every file that calls the elevation API, relative to the app root.
	 *
	 * Matches real invocations only: a docblock naming `runAsSystem()` is
	 * documentation, and several files legitimately mention it while explaining
	 * that they refuse to use it. Requires an opening parenthesis and excludes
	 * comment lines.
	 *
	 * @return string[] Sorted, unique relative paths.
	 */
	private function callSites(): array {
		$root = $this->appRoot();
		$command = sprintf(
			'grep -rnE "(->runAsSystem\(|SystemOperationContext::run\()" --include=*.php %s/lib 2>/dev/null',
			escapeshellarg($root)
		);

		$output = [];
		exec($command, $output);

		$files = [];
		foreach ($output as $line) {
			[$path, , $code] = array_pad(explode(':', $line, 3), 3, '');
			$trimmed = ltrim($code);

			// Skip docblock and comment lines — they are prose about the rule,
			// not uses of it.
			if (str_starts_with($trimmed, '*') === true
				|| str_starts_with($trimmed, '//') === true
				|| str_starts_with($trimmed, '/*') === true
			) {
				continue;
			}

			$files[] = ltrim(str_replace($root, '', $path), '/');
		}

		$files = array_values(array_unique($files));
		sort($files);

		return $files;
	}

	/**
	 * No flow node, controller or MCP surface elevates.
	 *
	 * @return void
	 */
	public function testForbiddenSurfacesDoNotElevate(): void {
		$offenders = [];
		foreach ($this->callSites() as $file) {
			foreach (self::FORBIDDEN_PREFIXES as $prefix) {
				if (str_starts_with($file, $prefix) === true) {
					$offenders[] = $file;
				}
			}
		}

		$this->assertSame(
			[],
			$offenders,
			"A flow node, controller or MCP surface elevated to a trusted userless principal.\n"
			. "ADR-099 rule 9: where an identity cannot be resolved, refuse with a reason — "
			. "do not escalate. Offending files:\n  " . implode("\n  ", $offenders)
		);
	}

	/**
	 * The elevation call-site set has not grown unnoticed.
	 *
	 * @return void
	 */
	public function testTheCallSiteSetIsPinned(): void {
		$actual = $this->callSites();

		// A scanner that finds NOTHING passes every assertion below while
		// checking nothing at all — and looks exactly like a clean result. The
		// definition itself is a known call site, so an empty set means the
		// scan broke (moved path, changed API name), not that the boundary is
		// clean.
		$this->assertNotEmpty(
			$actual,
			'The elevation scan found no call sites at all, including the definition. '
			. 'The scan is broken, not the codebase clean.'
		);

		$unexpected = array_values(array_diff($actual, self::ALLOWED));

		$this->assertSame(
			[],
			$unexpected,
			"A new caller elevates to a trusted userless principal.\n"
			. "If the new caller genuinely has no user to act for — installation, migration, "
			. "repair, or seeding the app's own shipped data — add it to ALLOWED with that "
			. "reason. If it is standing in for a missing identity, refuse instead.\n"
			. "New callers:\n  " . implode("\n  ", $unexpected)
		);
	}
}
