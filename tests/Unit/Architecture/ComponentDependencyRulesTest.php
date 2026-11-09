<?php

/**
 * The ADR-013 gate: dependency rules between the ADR-012 functional components.
 *
 * The test builds the module dependency graph of lib/ the way the SIG
 * estimate tool does (check_sig_maintainability.py::php_dependencies: use
 * imports including group use, fully qualified names, bare names resolved in
 * the file's namespace; comments stripped; the namespace line is no
 * reference), assigns each file its component from sigrid.components.yaml,
 * or from fixtures/components.yaml (the block the r12c lanes are measured
 * with) while the tree has no sigrid.components.yaml, and checks ADR-013
 * Rules 1 to 3.
 *
 * MODE: RATCHET plus REPORT-ONLY. Main still violates the rules (functional
 * view: violation degree 0.1967, 142,338 exposed LOC on 44dccfee2dcd), so:
 *
 * (a) HARD, ratchet: no module edge between two different non-Kernel
 *     components, and none from a Kernel file to a non-Kernel component,
 *     beyond component-dependency-baseline.json. The baseline is an upper
 *     bound: an entry that no longer occurs is allowed, so a lane that removes
 *     edges never edits it; a cleanup PR regenerates it with
 *     `python3 scripts/component-deps.py --write-baseline` to shrink it.
 * (b) HARD, ratchet: no file outside lib/AppInfo names OCA\OpenRegister\AppInfo\
 *     beyond the files listed in the same baseline (ADR-013 Rule 5.3; the app
 *     id is Kernel\Contracts\AppIdentity::APP_ID).
 * (c) REPORT ONLY: the exposed LOC and violation degree of the functional
 *     view are printed (to STDERR) next to surface-budget.json, and the test
 *     passes. When the budget holds a later PR sets `"mode": "enforce"`, and
 *     the test then fails above the recorded values.
 *
 * scripts/component-deps.py prints the same graph for a developer; the
 * sample-file pin and the whole-tree comparison below keep the two readers
 * identical.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

class ComponentDependencyRulesTest extends TestCase {

	/**
	 * The app root.
	 *
	 * @var string
	 */
	private const ROOT = __DIR__ . '/../../..';

	/**
	 * The Kernel component's name in the components block.
	 *
	 * @var string
	 */
	private const KERNEL = 'Kernel';

	/**
	 * The graph of the tree, read once per run.
	 *
	 * @var ComponentGraphReader|null
	 */
	private static ?ComponentGraphReader $tree = null;

	/**
	 * The reader gives the pinned graph on three sample files: comments, group use, aliases, bare names.
	 *
	 * @return void
	 */
	public function testReaderGivesThePinnedGraphOnTheSampleFiles(): void {
		$root     = __DIR__ . '/fixtures/component-deps';
		$expected = $this->json(path: $root . '/expected.json');
		$reader   = new ComponentGraphReader($root, $root . '/components.yaml', $this->scopeExcludes(root: $root));

		$this->assertSame($expected['edges'], $reader->edges());
		$this->assertSame($expected['forbidden_edges'], $reader->forbiddenEdges(self::KERNEL));
		$this->assertSame($expected['exposed'], $reader->exposed());
		$this->assertSame($expected['exposed_loc'], $reader->exposedLoc());
		$this->assertSame($expected['lines'], $reader->lines());
		$this->assertSame((float)$expected['violation_degree'], $reader->violationDegree());
	}//end testReaderGivesThePinnedGraphOnTheSampleFiles()

	/**
	 * Rule 1: no module edge to another non-Kernel component, nor from Kernel outward, beyond the baseline.
	 *
	 * @return void
	 */
	public function testNoComponentEdgeBeyondTheBaseline(): void {
		$known = array_flip($this->baseline()['forbidden_edges']);
		$new   = [];
		foreach ($this->tree()->forbiddenEdges(self::KERNEL) as $edge) {
			if (isset($known[$edge]) === false) {
				[$source, $target] = explode(' -> ', $edge);
				$new[] = $edge . ' (' . $this->tree()->component($source) . ' -> ' . $this->tree()->component($target) . ')';
			}
		}

		$this->assertSame(
			[],
			$new,
			"ADR-013 Rule 1: reference the other component through a contract in lib/Kernel/Contracts.\n"
			. 'python3 scripts/component-deps.py lists these too; never add them to the baseline.'
		);
	}//end testNoComponentEdgeBeyondTheBaseline()

	/**
	 * Rule 1 (lib/AppInfo): no file outside lib/AppInfo names OCA\OpenRegister\AppInfo\ beyond the baseline.
	 *
	 * @return void
	 */
	public function testNoNewFileNamesTheBootstrapNamespace(): void {
		$new = array_values(array_diff($this->tree()->appInfoNaming(), $this->baseline()['appinfo_naming']));

		$this->assertSame([], $new, 'read the app id from Kernel\\Contracts\\AppIdentity::APP_ID instead');
	}//end testNoNewFileNamesTheBootstrapNamespace()

	/**
	 * Rule 5.4: the exposed LOC and the violation degree, against surface-budget.json (report mode passes).
	 *
	 * @return void
	 */
	public function testSurfaceBudgetIsReported(): void {
		$budget  = $this->json(path: __DIR__ . '/surface-budget.json');
		$exposed = $this->tree()->exposedLoc();
		$degree  = $this->tree()->violationDegree();

		fwrite(
			STDERR,
			sprintf(
				"\n[ADR-013] functional view: exposed %d of %d LOC, violation degree %.4f; budget %d LOC / %.4f; "
				. "recorded %d LOC / %.4f; mode %s\n",
				$exposed,
				$this->tree()->totalLoc(),
				$degree,
				$budget['exposed_loc'],
				$budget['violation_degree'],
				$budget['recorded_exposed_loc'],
				$budget['recorded_violation_degree'],
				$budget['mode']
			)
		);

		$this->assertContains($budget['mode'], ['report', 'enforce']);
		if ($budget['mode'] === 'report') {
			return;
		}

		$this->assertLessThanOrEqual($budget['recorded_exposed_loc'], $exposed, 'exposed LOC above the recorded value');
		$this->assertLessThanOrEqual((float)$budget['recorded_violation_degree'], $degree, 'violation degree above the recorded value');
	}//end testSurfaceBudgetIsReported()

	/**
	 * The PHP reader and scripts/component-deps.py give the same edges, LOC, degree and AppInfo list on this tree.
	 *
	 * @return void
	 */
	public function testPhpReaderAgreesWithTheScript(): void {
		$python = trim((string)shell_exec('command -v python3 2>/dev/null'));
		if ($python === '') {
			$this->markTestSkipped('python3 is not installed; the sample-file pin still covers the reader');
		}

		$out    = (string)shell_exec(escapeshellarg($python) . ' ' . escapeshellarg(self::ROOT . '/scripts/component-deps.py')
			. ' --app ' . escapeshellarg(self::ROOT) . ' --edges-json - 2>/dev/null');
		$script = json_decode($out, true);
		$this->assertIsArray($script, 'scripts/component-deps.py --edges-json gave no JSON');

		$this->assertSame($script['edges'], $this->tree()->edges());
		$this->assertSame($script['exposed_loc'], $this->tree()->exposedLoc());
		$this->assertSame($script['total_loc'], $this->tree()->totalLoc());
		$this->assertSame((float)$script['violation_degree'], $this->tree()->violationDegree());
		$this->assertSame($script['appinfo_naming'], $this->tree()->appInfoNaming());
	}//end testPhpReaderAgreesWithTheScript()

	/**
	 * The graph of this tree.
	 *
	 * @return ComponentGraphReader
	 */
	private function tree(): ComponentGraphReader {
		if (self::$tree === null) {
			$own        = self::ROOT . '/sigrid.components.yaml';
			$components = is_file($own) === true ? $own : __DIR__ . '/fixtures/components.yaml';
			self::$tree = new ComponentGraphReader(self::ROOT, $components, $this->scopeExcludes(root: self::ROOT));
		}

		return self::$tree;
	}//end tree()

	/**
	 * The paths the SIG tool leaves out: its defaults and test patterns (from the baseline) and sigrid.yaml's excludes.
	 *
	 * @param string $root App root whose sigrid.yaml is read.
	 *
	 * @return array<int, string>
	 */
	private function scopeExcludes(string $root): array {
		$baseline = $this->baseline();

		return array_merge(
			$baseline['scope_excludes'],
			ComponentGraphReader::sigridYamlExcludes(root: $root),
			$baseline['test_patterns']
		);
	}//end scopeExcludes()

	/**
	 * The recorded baseline.
	 *
	 * @return array<string, mixed>
	 */
	private function baseline(): array {
		return $this->json(path: __DIR__ . '/component-dependency-baseline.json');
	}//end baseline()

	/**
	 * A JSON file as an array.
	 *
	 * @param string $path File.
	 *
	 * @return array<string, mixed>
	 */
	private function json(string $path): array {
		$data = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($data, 'not JSON: ' . $path);

		return $data;
	}//end json()
}//end class
