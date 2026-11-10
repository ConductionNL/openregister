<?php

/**
 * Every unit test method can fail.
 *
 * A test method that calls no assertion, sets no mock expectation and expects
 * no exception passes whatever the code under test does, and so does one whose
 * only assertion is assertTrue(true). Both count as coverage while catching
 * nothing. This guard scans tests/Unit and fails on any such method that is not
 * on the allowlist. A test whose contract is "does not throw" says so with
 * expectNotToPerformAssertions().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class TestMethodsCanFailTest extends TestCase {

	/**
	 * The unit test directory.
	 *
	 * @var string
	 */
	private const TESTS = __DIR__ . '/..';

	/**
	 * Calls that make a test able to fail, besides any assert*() call.
	 *
	 * @var string[]
	 */
	private const VERDICTS = [
		'fail',
		'expects',
		'expectexception',
		'expectexceptionmessage',
		'expectexceptioncode',
		'expectexceptionmessagematches',
		'expectexceptionobject',
		'expectoutputstring',
		'expectoutputregex',
		'expectnottoperformassertions',
		'addtoassertioncount',
		'marktestskipped',
		'marktestincomplete',
	];

	/**
	 * Methods per file, by lowercased name.
	 *
	 * @var array<string, array<string, array{name: string, test: bool, calls: string[], verdict: bool}>>
	 */
	private array $methods = [];

	/**
	 * The files that define a method, by lowercased method name.
	 *
	 * @var array<string, string[]>
	 */
	private array $helpers = [];

	/**
	 * No test method outside the allowlist is unable to fail.
	 *
	 * @return void
	 */
	public function testEveryTestMethodCanFail(): void {
		$allowed = $this->allowlist();
		$found   = [];
		foreach ($this->testMethods() as $key) {
			if (isset($allowed[$key]) === false) {
				$found[] = $key;
			}
		}

		$this->assertSame(
			expected: [],
			actual: $found,
			message: "These test methods cannot fail: give each an assertion on the behaviour its name describes,\n"
			. 'or, when the contract is that it does not throw, call expectNotToPerformAssertions().'
		);
	}

	/**
	 * The scanner sees a method that asserts nothing, and one that only asserts true.
	 *
	 * @return void
	 */
	public function testTheScannerFindsBothKinds(): void {
		$source = '<?php class FixtureTest { '
			. 'public function testNothing(): void { $x = 1; } '
			. 'public function testOnlyTrue(): void { $this->assertTrue(true); } '
			. 'public function testReal(): void { $this->assertSame(1, 1); } '
			. 'public function testViaHelper(): void { $this->check(); } '
			. 'private function check(): void { $this->assertTrue(false === false); } }';

		$this->methods = [];
		$this->helpers = [];
		$this->index(path: 'fixture.php', source: $source);

		$this->assertSame(expected: ['fixture.php::testNothing', 'fixture.php::testOnlyTrue'], actual: $this->weakIn(path: 'fixture.php'));
	}

	/**
	 * The allowlisted methods, as "relative/path.php::method".
	 *
	 * @return array<string, true>
	 */
	private function allowlist(): array {
		$lines = file(__DIR__ . '/TestMethodsCanFailAllowlist.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$allowed = [];
		foreach ($lines as $line) {
			if (str_starts_with(trim($line), '#') === false) {
				$allowed[trim($line)] = true;
			}
		}

		return $allowed;
	}

	/**
	 * Every test method in tests/Unit that cannot fail.
	 *
	 * @return string[]
	 */
	private function testMethods(): array {
		$root  = realpath(self::TESTS);
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
		foreach ($files as $file) {
			if (str_ends_with($file->getFilename(), '.php') === true) {
				$path = substr($file->getPathname(), strlen($root) + 1);
				$this->index(path: $path, source: (string)file_get_contents($file->getPathname()));
			}
		}

		$weak = [];
		foreach (array_keys($this->methods) as $path) {
			array_push($weak, ...$this->weakIn(path: $path));
		}

		sort($weak);

		return $weak;
	}

	/**
	 * The test methods of one indexed file that cannot fail.
	 *
	 * @param string $path The file's path relative to tests/Unit.
	 *
	 * @return string[]
	 */
	private function weakIn(string $path): array {
		$weak = [];
		foreach ($this->methods[$path] as $method) {
			if ($method['test'] === false || $this->reachesVerdict(path: $path, method: $method) === true) {
				continue;
			}

			$weak[] = $path . '::' . $method['name'];
		}

		return $weak;
	}

	/**
	 * Whether a method, or a helper it calls at any depth, can fail.
	 *
	 * A call resolves to the method of that name in the same file, or else to
	 * any method of that name in tests/Unit (traits and base classes).
	 *
	 * @param string $path    The file's path.
	 * @param array  $method  The indexed method.
	 * @param array  $visited The methods already on this path, as path::name.
	 *
	 * @return bool
	 */
	private function reachesVerdict(string $path, array $method, array $visited = []): bool {
		if ($method['verdict'] === true) {
			return true;
		}

		$visited[$path . '::' . strtolower($method['name'])] = true;
		foreach ($method['calls'] as $call) {
			$targets = [$path];
			if (isset($this->methods[$path][$call]) === false) {
				$targets = ($this->helpers[$call] ?? []);
			}

			foreach ($targets as $target) {
				$helper = $this->methods[$target][$call];
				if ($helper['test'] === false && isset($visited[$target . '::' . $call]) === false
					&& $this->reachesVerdict(path: $target, method: $helper, visited: $visited) === true
				) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Index the methods of one PHP file.
	 *
	 * @param string $path   The file's path.
	 * @param string $source The file's source.
	 *
	 * @return void
	 */
	private function index(string $path, string $source): void {
		$tokens = token_get_all($source);
		$count  = count($tokens);
		$this->methods[$path] = [];
		$doc = '';
		$attr = '';
		$public = true;
		$inTestClass = false;
		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];
			if (is_array($token) === true && in_array($token[0], [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true) === true) {
				$name = $tokens[$this->next(tokens: $tokens, from: $i)];
				$inTestClass = $token[0] === T_CLASS && is_array($name) === true && str_ends_with($name[1], 'Test') === true;
				continue;
			}
			if (is_array($token) === true && in_array($token[0], [T_PRIVATE, T_PROTECTED], true) === true) {
				$public = false;
				continue;
			}

			if (is_array($token) === true && $token[0] === T_DOC_COMMENT) {
				$doc = $token[1];
				continue;
			}

			if (is_array($token) === true && $token[0] === T_ATTRIBUTE) {
				for (; $i < $count && $tokens[$i] !== ']'; $i++) {
					$attr .= self::text(token: $tokens[$i]);
				}

				continue;
			}

			if (is_array($token) === false || $token[0] !== T_FUNCTION) {
				if ($token === ';' || $token === '{' || $token === '}') {
					$doc = '';
					$attr = '';
					$public = true;
				}

				continue;
			}

			$marks = $doc . $attr;
			if ($public === true && $inTestClass === true) {
				$marks = 'public ' . $marks;
			}

			$i = $this->indexMethod(path: $path, tokens: $tokens, start: $i, marks: $marks);
			$doc = '';
			$attr = '';
			$public = true;
		}//end for
	}

	/**
	 * Index one function and return the index of its last token.
	 *
	 * @param string $path   The file's path.
	 * @param array  $tokens The file's tokens.
	 * @param int    $start  The index of the function keyword.
	 * @param string $marks  The docblock and attributes before it.
	 *
	 * @return int
	 */
	private function indexMethod(string $path, array $tokens, int $start, string $marks): int {
		$count = count($tokens);
		$j = $this->next(tokens: $tokens, from: $start);
		if (is_array($tokens[$j]) === false || $tokens[$j][0] !== T_STRING) {
			return $start;
		}

		$name = $tokens[$j][1];
		for (; $j < $count && $tokens[$j] !== '{' && $tokens[$j] !== ';'; $j++) {
		}

		if ($j >= $count || $tokens[$j] === ';') {
			return $j;
		}

		$depth = 0;
		$body  = [];
		for (; $j < $count; $j++) {
			$text = self::text(token: $tokens[$j]);
			if ($text === '{' || (is_array($tokens[$j]) === true && in_array($tokens[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true) === true)) {
				$depth++;
			}

			if ($text === '}') {
				$depth--;
				if ($depth === 0) {
					break;
				}
			}

			$body[] = $tokens[$j];
		}

		$method = $this->analyse(body: $body);
		$method['name'] = $name;
		$method['test'] = str_starts_with($marks, 'public ') === true
			&& (preg_match('/^test[A-Z0-9_]/', $name) === 1
			|| str_contains($marks, '@test') === true
			|| preg_match('/#\[\s*(\\\\?PHPUnit\\\\Framework\\\\Attributes\\\\)?Test\b/', $marks) === 1);
		$this->methods[$path][strtolower($name)] = $method;
		$this->helpers[strtolower($name)][] = $path;

		return $j;
	}

	/**
	 * The calls in a method body and whether one of them can fail the test.
	 *
	 * @param array $body The body's tokens.
	 *
	 * @return array{calls: string[], verdict: bool}
	 */
	private function analyse(array $body): array {
		$calls   = [];
		$verdict = false;
		$count   = count($body);
		for ($k = 0; $k < $count; $k++) {
			$next = $this->next(tokens: $body, from: $k);
			if (is_array($body[$k]) === false || $body[$k][0] !== T_STRING || ($body[$next] ?? null) !== '(') {
				continue;
			}

			$call = strtolower($body[$k][1]);
			$calls[] = $call;
			if (str_starts_with($call, 'assert') === true) {
				$args = strtolower((string)preg_replace('/\s+/', '', $this->arguments(tokens: $body, open: $next)));
				$trivial = ($call === 'asserttrue' && $args === 'true') || ($call === 'assertfalse' && $args === 'false');
				$verdict = $verdict || $trivial === false;
				continue;
			}

			if ($call === 'expects') {
				$verdict = $verdict || preg_match('/\bany\s*\(/i', $this->arguments(tokens: $body, open: $next)) !== 1;
				continue;
			}

			$verdict = $verdict || in_array($call, self::VERDICTS, true) === true;
		}//end for

		return ['calls' => array_values(array_unique($calls)), 'verdict' => $verdict];
	}

	/**
	 * The source text of a token.
	 *
	 * @param array|string $token The token.
	 *
	 * @return string
	 */
	private static function text(array|string $token): string {
		if (is_array($token) === true) {
			return $token[1];
		}

		return $token;
	}

	/**
	 * The index of the next token that is not whitespace or a comment.
	 *
	 * @param array $tokens The tokens.
	 * @param int   $from   The index to start after.
	 *
	 * @return int
	 */
	private function next(array $tokens, int $from): int {
		$count = count($tokens);
		for ($k = $from + 1; $k < $count; $k++) {
			if (is_array($tokens[$k]) === false || in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === false) {
				return $k;
			}
		}

		return $count;
	}

	/**
	 * The source text between a call's parentheses.
	 *
	 * @param array $tokens The tokens.
	 * @param int   $open   The index of the opening parenthesis.
	 *
	 * @return string
	 */
	private function arguments(array $tokens, int $open): string {
		$depth = 0;
		$text  = '';
		$count = count($tokens);
		for ($k = $open; $k < $count; $k++) {
			$part = self::text(token: $tokens[$k]);
			if ($part === '(') {
				$depth++;
				if ($depth === 1) {
					continue;
				}
			}

			if ($part === ')') {
				$depth--;
				if ($depth === 0) {
					break;
				}
			}

			$text .= $part;
		}

		return $text;
	}
}
