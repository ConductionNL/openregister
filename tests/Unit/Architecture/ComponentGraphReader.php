<?php

/**
 * ComponentGraphReader: the module dependency graph of lib/ as the SIG estimate tool reads it.
 *
 * A PHP port of check_sig_maintainability.py (gate 122, with the pattern-components
 * patch): php_dependencies(), code_lines(), pattern_components(), the scope
 * excludes and violation_degree(). scripts/component-deps.py runs the Python
 * originals; ComponentDependencyRulesTest proves that both give the same edges,
 * LOC and degree on the sample files and on the whole tree.
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

use RuntimeException;

/**
 * Reads the PHP files under <root>/lib, their references and their components.
 */
final class ComponentGraphReader {

	/**
	 * The component a file matched by no pattern lands in.
	 *
	 * @var string
	 */
	public const REMAINDER = 'Remainder';

	/**
	 * Whitespace as Python's str.strip() removes it (ASCII part; \0 is not whitespace there).
	 *
	 * @var string
	 */
	private const PY_WHITESPACE = " \t\n\r\x0B\x0C\x1C\x1D\x1E\x1F";

	/**
	 * Module => modules it references, both as paths relative to the root.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $deps = [];

	/**
	 * Module => code lines.
	 *
	 * @var array<string, int>
	 */
	private array $loc = [];

	/**
	 * Module => component name.
	 *
	 * @var array<string, string>
	 */
	private array $components = [];

	/**
	 * Module => raw source.
	 *
	 * @var array<string, string>
	 */
	private array $sources = [];

	/**
	 * Read the tree.
	 *
	 * @param string             $root       The app root (holds lib/).
	 * @param string             $components The YAML file with the `components:` block.
	 * @param array<int, string> $excludes   Path regexes (Python syntax, full match on "/<path>") left out of scope.
	 */
	public function __construct(private string $root, string $components, array $excludes) {
		$files = $this->libFiles(excludes: $excludes);
		$this->readDependencies(files: $files);
		$patterns = self::patternComponents(path: $components);
		foreach (array_keys($this->deps) as $rel) {
			$this->components[$rel] = self::componentOf(patterns: $patterns, rel: $rel);
			$this->loc[$rel]        = self::codeLines(text: $this->sources[$rel]);
		}
	}//end __construct()

	/**
	 * The edges, as "source -> target", sorted.
	 *
	 * @return array<int, string>
	 */
	public function edges(): array {
		$out = [];
		foreach ($this->deps as $source => $targets) {
			foreach ($targets as $target) {
				$out[] = $source . ' -> ' . $target;
			}
		}

		sort($out, SORT_STRING);
		return $out;
	}//end edges()

	/**
	 * The edges ADR-013 Rule 1 forbids: between two different non-Kernel components, or from Kernel to non-Kernel.
	 *
	 * @param string $kernel The Kernel component's name.
	 *
	 * @return array<int, string>
	 */
	public function forbiddenEdges(string $kernel): array {
		$out = [];
		foreach ($this->edges() as $edge) {
			[$source, $target] = explode(' -> ', $edge);
			if ($this->components[$source] !== $this->components[$target] && $this->components[$target] !== $kernel) {
				$out[] = $edge;
			}
		}

		return $out;
	}//end forbiddenEdges()

	/**
	 * Component of a module.
	 *
	 * @param string $rel Path relative to the root.
	 *
	 * @return string
	 */
	public function component(string $rel): string {
		return $this->components[$rel];
	}//end component()

	/**
	 * Modules referenced from another component, sorted.
	 *
	 * @return array<int, string>
	 */
	public function exposed(): array {
		$out = [];
		foreach ($this->deps as $source => $targets) {
			foreach ($targets as $target) {
				if ($this->components[$source] !== $this->components[$target]) {
					$out[$target] = true;
				}
			}
		}

		$out = array_keys($out);
		sort($out, SORT_STRING);
		return $out;
	}//end exposed()

	/**
	 * Exposed LOC.
	 *
	 * @return int
	 */
	public function exposedLoc(): int {
		return array_sum(array_map(fn (string $rel): int => $this->loc[$rel], $this->exposed()));
	}//end exposedLoc()

	/**
	 * Total LOC of the measured modules.
	 *
	 * @return int
	 */
	public function totalLoc(): int {
		return array_sum($this->loc);
	}//end totalLoc()

	/**
	 * Communication lines: "source -> target" component pair => number of module edges, sorted by pair.
	 *
	 * @return array<string, int>
	 */
	public function lines(): array {
		$out = [];
		foreach ($this->deps as $source => $targets) {
			foreach ($targets as $target) {
				$from = $this->components[$source];
				$to   = $this->components[$target];
				if ($from !== $to) {
					$out[$from . ' -> ' . $to] = ($out[$from . ' -> ' . $to] ?? 0) + 1;
				}
			}
		}

		ksort($out, SORT_STRING);
		return $out;
	}//end lines()

	/**
	 * Files outside lib/AppInfo whose code (comments stripped) names OCA\OpenRegister\AppInfo\, sorted.
	 *
	 * @return array<int, string>
	 */
	public function appInfoNaming(): array {
		$out = [];
		foreach ($this->sources as $rel => $source) {
			if (str_starts_with($rel, 'lib/AppInfo/') === true) {
				continue;
			}

			if (preg_match('~OpenRegister\\\\+AppInfo\\\\+~', self::stripComments(source: $source)) === 1) {
				$out[] = $rel;
			}
		}

		sort($out, SORT_STRING);
		return $out;
	}//end appInfoNaming()

	/**
	 * The violation degree of the communication lines, rounded to four decimals.
	 *
	 * @return float
	 */
	public function violationDegree(): float {
		$lines = [];
		foreach ($this->lines() as $pair => $weight) {
			$lines[$pair] = $weight;
		}

		return self::degreeOf(lines: $lines);
	}//end violationDegree()

	/**
	 * check_sig_maintainability.py::violation_degree: direct cycles, then indirect cycles, then transitive lines.
	 *
	 * @param array<string, int> $lines "a -> b" => weight.
	 *
	 * @return float
	 */
	public static function degreeOf(array $lines): float {
		$total    = array_sum($lines);
		$total    = $total === 0 ? 1 : $total;
		$violated = [];
		foreach ($lines as $pair => $weight) {
			[$a, $b] = explode(' -> ', $pair);
			$back    = $b . ' -> ' . $a;
			if (isset($lines[$back]) === true && strcmp($a, $b) < 0) {
				$low = min($weight, $lines[$back]);
				if ($weight <= $lines[$back]) {
					$violated[$pair] = $low;
				}

				if ($lines[$back] <= $weight) {
					$violated[$back] = $low;
				}
			}
		}

		$remaining = array_diff_key($lines, $violated);
		while (($cycle = self::findCycle(graph: $remaining)) !== null) {
			$weakest = null;
			for ($i = 0; $i < count($cycle) - 1; $i++) {
				$edge = $cycle[$i] . ' -> ' . $cycle[$i + 1];
				if ($weakest === null
					|| $remaining[$edge] < $remaining[$weakest]
					|| ($remaining[$edge] === $remaining[$weakest] && self::pairLess(left: $edge, right: $weakest) === true)
				) {
					$weakest = $edge;
				}
			}

			$violated[$weakest] = $remaining[$weakest];
			unset($remaining[$weakest]);
		}

		$adj      = [];
		$rev      = [];
		$outgoing = [];
		foreach (array_keys($remaining) as $pair) {
			[$a, $b]    = explode(' -> ', $pair);
			$adj[$a][]  = $b;
			$rev[$b][]  = $a;
			$outgoing[$a] = ($outgoing[$a] ?? 0) + 1;
		}

		$sorted = array_keys($remaining);
		usort($sorted, fn (string $l, string $r): int => self::pairLess(left: $l, right: $r) === true ? -1 : ($l === $r ? 0 : 1));
		foreach ($sorted as $pair) {
			[$a, $b] = explode(' -> ', $pair);
			if (($outgoing[$b] ?? 0) === 0) {
				continue;
			}

			$fwd = self::reach(adj: $adj, start: $a, skip: [$a, $b], reverse: false);
			if (isset($fwd[$b]) === false) {
				continue;
			}

			$back    = self::reach(adj: $rev, start: $b, skip: [$a, $b], reverse: true);
			$onPaths = [];
			foreach ($remaining as $other => $weight) {
				[$u, $v] = explode(' -> ', $other);
				if ($other !== $pair && isset($fwd[$u]) === true && isset($back[$v]) === true) {
					$onPaths[] = $weight;
				}
			}

			if ($onPaths !== [] && $remaining[$pair] <= min($onPaths)) {
				$violated[$pair] = $remaining[$pair];
			}
		}

		return round(array_sum($violated) / $total, 4);
	}//end degreeOf()

	/**
	 * Python tuple order of two "a -> b" pairs.
	 *
	 * @param string $left  Pair.
	 * @param string $right Pair.
	 *
	 * @return bool
	 */
	private static function pairLess(string $left, string $right): bool {
		[$la, $lb] = explode(' -> ', $left);
		[$ra, $rb] = explode(' -> ', $right);
		$first     = strcmp($la, $ra);
		return $first < 0 || ($first === 0 && strcmp($lb, $rb) < 0);
	}//end pairLess()

	/**
	 * The first cycle a sorted depth-first search finds, as a node list that repeats its first node at the end.
	 *
	 * @param array<string, int> $graph "a -> b" => weight.
	 *
	 * @return array<int, string>|null
	 */
	private static function findCycle(array $graph): ?array {
		$adj = [];
		foreach (array_keys($graph) as $pair) {
			[$a, $b]   = explode(' -> ', $pair);
			$adj[$a][] = $b;
		}

		$state = [];
		$stack = [];
		$nodes = array_map('strval', array_keys($adj));
		sort($nodes, SORT_STRING);
		foreach ($nodes as $node) {
			if (($state[$node] ?? 0) === 0) {
				$cycle = self::dfs(node: $node, adj: $adj, state: $state, stack: $stack);
				if ($cycle !== null) {
					return $cycle;
				}
			}
		}

		return null;
	}//end findCycle()

	/**
	 * One step of findCycle().
	 *
	 * @param string                            $node  Node.
	 * @param array<string, array<int, string>> $adj   Adjacency.
	 * @param array<string, int>                $state 1 on the stack, 2 done.
	 * @param array<int, string>                $stack Current path.
	 *
	 * @return array<int, string>|null
	 */
	private static function dfs(string $node, array $adj, array &$state, array &$stack): ?array {
		$state[$node] = 1;
		$stack[]      = $node;
		$next         = $adj[$node] ?? [];
		sort($next, SORT_STRING);
		foreach ($next as $m) {
			if (($state[$m] ?? 0) === 1) {
				$cycle   = array_slice($stack, (int)array_search($m, $stack, true));
				$cycle[] = $m;
				return $cycle;
			}

			if (($state[$m] ?? 0) === 0) {
				$cycle = self::dfs(node: $m, adj: $adj, state: $state, stack: $stack);
				if ($cycle !== null) {
					return $cycle;
				}
			}
		}

		$state[$node] = 2;
		array_pop($stack);
		return null;
	}//end dfs()

	/**
	 * Nodes reachable from a start node without the skipped edge.
	 *
	 * @param array<string, array<int, string>> $adj     Adjacency (reversed when walking back).
	 * @param string                            $start   Start node.
	 * @param array<int, string>                $skip    The edge [a, b] not to use.
	 * @param bool                              $reverse Whether $adj is the reversed graph.
	 *
	 * @return array<string, true>
	 */
	private static function reach(array $adj, string $start, array $skip, bool $reverse): array {
		$seen = [$start => true];
		$todo = [$start];
		while ($todo !== []) {
			$n = array_pop($todo);
			foreach ($adj[$n] ?? [] as $m) {
				$edge = $reverse === true ? [$m, $n] : [$n, $m];
				if ($edge !== $skip && isset($seen[$m]) === false) {
					$seen[$m] = true;
					$todo[]   = $m;
				}
			}
		}

		return $seen;
	}//end reach()

	/**
	 * The `components:` block: list of [name, include regexes, exclude regexes] (PCRE, full match).
	 *
	 * @param string $path YAML file.
	 *
	 * @return array<int, array{0: string, 1: array<int, string>, 2: array<int, string>}>
	 */
	public static function patternComponents(string $path): array {
		$comps   = [];
		$section = false;
		$key     = null;
		$text    = file_get_contents($path);
		if ($text === false) {
			throw new RuntimeException('cannot read ' . $path);
		}

		foreach (preg_split('/(?<=\n)/', $text) as $line) {
			$stripped = trim($line, self::PY_WHITESPACE);
			if ($stripped === '' || str_starts_with($stripped, '#') === true) {
				continue;
			}

			if (str_starts_with($line, ' ') === false && str_starts_with($stripped, 'components:') === true) {
				$section = true;
				$key     = null;
				continue;
			}

			if ($section === false) {
				continue;
			}

			if (str_starts_with($line, ' ') === false) {
				break;
			}

			if (preg_match('/^\s*-\s*name:\s*(.+?)\s*$/', $line, $m) === 1) {
				$comps[] = [trim($m[1], '"\''), [], []];
				$key     = null;
				continue;
			}

			if (preg_match('/^\s*(include|exclude):\s*$/', $line, $m) === 1) {
				$key = $m[1];
				continue;
			}

			if (preg_match('/^\s*-\s*(.+?)\s*$/', $line, $m) === 1 && $comps !== [] && $key !== null) {
				$pattern = trim(trim($m[1], self::PY_WHITESPACE), '"\'');
				$comps[count($comps) - 1][$key === 'include' ? 1 : 2][] = self::fullMatch(pattern: $pattern);
			}
		}//end foreach

		return $comps;
	}//end patternComponents()

	/**
	 * The top-level `exclude:` regexes of <root>/sigrid.yaml, as check_sig_maintainability.py reads them.
	 *
	 * @param string $root App root.
	 *
	 * @return array<int, string>
	 */
	public static function sigridYamlExcludes(string $root): array {
		foreach (['sigrid.yaml', 'sigrid.yml'] as $name) {
			$path = $root . '/' . $name;
			if (is_file($path) === false) {
				continue;
			}

			$out    = [];
			$inside = false;
			foreach (preg_split('/(?<=\n)/', (string)file_get_contents($path)) as $line) {
				if (preg_match('/^exclude:\s*$/', $line) === 1) {
					$inside = true;
					continue;
				}

				if ($inside === true) {
					if (preg_match('/^\s+-\s+["\']?(.+?)["\']?\s*$/', $line, $m) === 1) {
						$out[] = $m[1];
					} else if (trim($line, self::PY_WHITESPACE) !== '' && preg_match('/^[ \t#]/', $line) !== 1) {
						$inside = false;
					}
				}
			}

			return $out;
		}//end foreach

		return [];
	}//end sigridYamlExcludes()

	/**
	 * The first component whose include patterns match the path and whose exclude patterns do not.
	 *
	 * @param array<int, array{0: string, 1: array<int, string>, 2: array<int, string>}> $patterns Components block.
	 * @param string                                                                      $rel      Path relative to the root.
	 *
	 * @return string
	 */
	private static function componentOf(array $patterns, string $rel): string {
		$probe = '/' . $rel;
		foreach ($patterns as [$name, $include, $exclude]) {
			if (self::anyMatch(patterns: $include, subject: $probe) === true
				&& self::anyMatch(patterns: $exclude, subject: $probe) === false
			) {
				return $name;
			}
		}

		return self::REMAINDER;
	}//end componentOf()

	/**
	 * Whether any pattern matches.
	 *
	 * @param array<int, string> $patterns PCRE patterns.
	 * @param string             $subject  Subject.
	 *
	 * @return bool
	 */
	private static function anyMatch(array $patterns, string $subject): bool {
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $subject) === 1) {
				return true;
			}
		}

		return false;
	}//end anyMatch()

	/**
	 * A Python regex as an anchored PCRE pattern (fullmatch).
	 *
	 * @param string $pattern Python regex.
	 *
	 * @return string
	 */
	public static function fullMatch(string $pattern): string {
		return '~^(?:' . str_replace('~', '\\~', $pattern) . ')$~D';
	}//end fullMatch()

	/**
	 * Code lines of a PHP source: not blank, not a comment-only line (check_sig_maintainability.py::code_lines).
	 *
	 * @param string $text Source.
	 *
	 * @return int
	 */
	public static function codeLines(string $text): int {
		$count   = 0;
		$inBlock = false;
		$lines   = preg_split('/\r\n|[\n\r\x0B\x0C\x1C\x1D\x1E]|\xC2\x85|\xE2\x80[\xA8\xA9]/', $text);
		foreach ($lines as $raw) {
			$s = trim($raw, self::PY_WHITESPACE);
			if ($inBlock === true) {
				if (str_contains($s, '*/') === false) {
					continue;
				}

				$inBlock = false;
				$s       = trim(explode('*/', $s, 2)[1], self::PY_WHITESPACE);
			}

			if ($s === '') {
				continue;
			}

			if (str_starts_with($s, '/*') === true) {
				$close = strpos($s, '*/', 2);
				if ($close === false) {
					$inBlock = true;
					continue;
				}

				$s = trim(substr($s, $close + 2), self::PY_WHITESPACE);
				if ($s === '') {
					continue;
				}
			}

			if (str_starts_with($s, '//') === true || str_starts_with($s, '#') === true || str_starts_with($s, '*') === true) {
				continue;
			}

			$count++;
		}//end foreach

		return $count;
	}//end codeLines()

	/**
	 * A source with block and line comments removed, as the Python reader removes them.
	 *
	 * @param string $source Source.
	 *
	 * @return string
	 */
	public static function stripComments(string $source): string {
		$out = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $source);
		if ($out === null) {
			throw new RuntimeException('comment strip failed: ' . preg_last_error_msg());
		}

		return $out;
	}//end stripComments()

	/**
	 * Tracked-like PHP files under lib/, minus the excluded paths, sorted bytewise as git lists them.
	 *
	 * @param array<int, string> $excludes Python regexes.
	 *
	 * @return array<int, string>
	 */
	private function libFiles(array $excludes): array {
		$patterns = array_map(fn (string $p): string => self::fullMatch(pattern: $p), $excludes);
		$out      = [];
		$lib      = $this->root . '/lib';
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib, \FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			if ($file->isFile() === false || strtolower($file->getExtension()) !== 'php') {
				continue;
			}

			$rel   = 'lib/' . substr($file->getPathname(), strlen($lib) + 1);
			$probe = '/' . $rel;
			foreach ($patterns as $pattern) {
				if (preg_match($pattern, $probe) === 1) {
					continue 2;
				}
			}

			$out[] = $rel;
		}

		sort($out, SORT_STRING);
		return $out;
	}//end libFiles()

	/**
	 * check_sig_maintainability.py::php_dependencies over the given files.
	 *
	 * @param array<int, string> $files Paths relative to the root.
	 *
	 * @return void
	 */
	private function readDependencies(array $files): void {
		$fqcn   = [];
		$nsOf   = [];
		$bodies = [];
		foreach ($files as $rel) {
			$src = (string)file_get_contents($this->root . '/' . $rel);
			if (preg_match('/^namespace\s+([^;]+);/m', $src, $m) !== 1) {
				continue;
			}

			$ns                 = trim($m[1], self::PY_WHITESPACE);
			$nsOf[$rel]         = $ns;
			$this->sources[$rel] = $src;
			$bodies[$rel]       = (string)preg_replace('/^namespace\s+[^;]+;/m', '', self::stripComments(source: $src), 1);
			$fqcn[$ns . '\\' . substr(basename($rel), 0, -4)] = $rel;
		}

		foreach ($bodies as $rel => $body) {
			$targets = [];
			preg_match_all('/^use\s+([^;]+);/m', $body, $uses);
			foreach ($uses[1] as $imp) {
				$prefix = '';
				$parts  = explode(',', $imp);
				if (preg_match('/^(.*)\\\\\{(.*)\}$/', trim($imp, self::PY_WHITESPACE), $group) === 1) {
					$prefix = ltrim(trim($group[1], self::PY_WHITESPACE), '\\') . '\\';
					$parts  = explode(',', $group[2]);
				}

				foreach ($parts as $part) {
					$name = $prefix . ltrim(trim(explode(' as ', trim($part, self::PY_WHITESPACE))[0], self::PY_WHITESPACE), '\\');
					if (isset($fqcn[$name]) === true) {
						$targets[$fqcn[$name]] = true;
					}
				}
			}

			preg_match_all('/\b([A-Z][A-Za-z0-9_]*)\b/', $body, $idents);
			foreach (array_unique($idents[1]) as $ident) {
				$key = $nsOf[$rel] . '\\' . $ident;
				if (isset($fqcn[$key]) === true) {
					$targets[$fqcn[$key]] = true;
				}
			}

			preg_match_all('/\\\\?([A-Z][A-Za-z0-9_]*(?:\\\\[A-Za-z0-9_]+)+)/', $body, $qualified);
			foreach ($qualified[1] as $fq) {
				if (isset($fqcn[$fq]) === true) {
					$targets[$fqcn[$fq]] = true;
				}
			}

			unset($targets[$rel]);
			$list = array_map('strval', array_keys($targets));
			sort($list, SORT_STRING);
			$this->deps[$rel] = $list;
		}//end foreach
	}//end readDependencies()
}//end class
