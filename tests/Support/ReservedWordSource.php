<?php

/**
 * The words MariaDB and MySQL refuse as an unquoted column name, per server series.
 *
 * Neither project publishes the list as a file. Both keep it in two places in
 * their server source: the lexer's symbol table (`sql/lex.h`, every keyword the
 * lexer knows) and the grammar (`sql/sql_yacc.yy`), whose identifier rule names
 * the keywords that may still be used as an identifier (MariaDB `keyword_ident`,
 * MySQL `ident_keyword`). A reserved word is a lexer keyword that this rule
 * cannot reach. This class downloads both files for a branch of the upstream
 * repository and derives the words that way. The committed snapshot
 * (`fixtures/reserved-words-<engine>-<series>.txt`) is always part of the
 * result: words() is the live branch head merged with the snapshot, and the
 * snapshot alone when the download fails, so a test that uses it stays
 * deterministic without network. The snapshot of a released series is the union
 * over the head and every release tag of the series, which the live head cannot
 * give: it is the only source for the older point releases. A word upstream stops
 * reserving therefore stays in the list until the snapshot is regenerated.
 * `fixtures/update-reserved-words.php` rewrites the snapshots from the same code.
 *
 * Environment: RESERVED_WORDS_BASE_URL replaces https://raw.githubusercontent.com
 * (a mirror, or an unreachable host to exercise the fallback).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Support;

use RuntimeException;

class ReservedWordSource {

	/**
	 * The upstream repository per engine, and the branch per supported series.
	 *
	 * The test reads the branch head of each series, so the words a new point
	 * release adds are picked up without an edit here. A branch head is only the
	 * newest point release though, and upstream also un-reserves words: the 10.11
	 * and 11.4 heads no longer list ROW_NUMBER while every released 10.11.x and
	 * 11.4.x does. So the snapshot of a released series is the union over the
	 * branch head and every release tag of that series (`releaseTags()`, written by
	 * `fixtures/update-reserved-words.php`) and is always merged into what the test
	 * checks; it is the only place the older point releases are covered. A release
	 * tag is `<tagPrefix><series>.<n>`; `firstRelease` names the first <n> whose
	 * sources the parser can read (MySQL 8.0.0 to 8.0.4 are development milestones
	 * with another lex.h layout, 8.0.11 is the first general-availability release).
	 *
	 * `appinfo/info.xml` asks for MySQL 8.0 or newer and docs/installation.md for
	 * MariaDB 10.5 or newer; the other MariaDB series are the long-term releases
	 * Nextcloud 32 to 35 run on, plus 11.3: MariaDB reserves ROW_NUMBER from 10.7 on
	 * (before that it is a function name only, `sql_functions[]` in lex.h), the word
	 * that broke the import preview migration (#4909).
	 *
	 * `development` names the series that are not released: `main` and `trunk`
	 * hold the words a coming series may reserve. A word only they reserve is a
	 * heads-up for the next release, not a defect of a server anyone runs.
	 */
	public const SOURCES = [
		'mariadb' => [
			'repository'   => 'MariaDB/server',
			'tagPrefix'    => 'mariadb-',
			'firstRelease' => [],
			'series'       => ['10.5', '10.6', '10.11', '11.3', '11.4', '11.8', 'main'],
			'development'  => ['main'],
			'identifier'   => 'keyword_ident',
		],
		'mysql'   => [
			'repository'   => 'mysql/mysql-server',
			'tagPrefix'    => 'mysql-',
			'firstRelease' => ['8.0' => 11],
			'series'       => ['8.0', '8.4', 'trunk'],
			'development'  => ['trunk'],
			'identifier'   => 'ident_keyword',
		],
	];

	/**
	 * A list shorter than this is a parser that lost its way, not a server.
	 */
	private const MINIMUM_WORDS = 200;

	/**
	 * A list longer than this is a parser that lost its way too: the lexers know
	 * about 700 keywords, so a result near that means no keyword was found
	 * reachable from the identifier rule and every one counts as reserved.
	 */
	private const MAXIMUM_WORDS = 400;

	/**
	 * Words that neither engine reserves in any series. One of them in a list
	 * means the identifier rule was not followed, not that the server changed.
	 */
	private const NEVER_RESERVED = ['status', 'date'];

	/**
	 * Why downloads stopped, once one failed, so a network that is down costs one timeout, not one per file.
	 */
	private static ?string $offlineReason = null;

	/**
	 * The words per `<engine>-<series>`, resolved once per process.
	 *
	 * @var array<string, string[]>
	 */
	private static array $words = [];

	/**
	 * Where a series' words came from, per `<engine>-<series>`: 'online' or 'snapshot' (downloadFailure() says why).
	 *
	 * @var array<string, string>
	 */
	private static array $origin = [];

	/**
	 * Whether a series is a development branch rather than a released series.
	 *
	 * @param string $engine The engine, a key of SOURCES.
	 * @param string $series The series, an entry of that engine's series.
	 *
	 * @return bool
	 */
	public static function isDevelopment(string $engine, string $series): bool {
		return in_array($series, self::SOURCES[$engine]['development'], true);
	}//end isDevelopment()

	/**
	 * The snapshot file of one series.
	 *
	 * @param string $engine The engine, a key of SOURCES.
	 * @param string $series The series, an entry of that engine's series.
	 *
	 * @return string The absolute path.
	 */
	public static function snapshotPath(string $engine, string $series): string {
		return __DIR__.'/../Unit/Migration/fixtures/reserved-words-'.$engine.'-'.$series.'.txt';
	}//end snapshotPath()

	/**
	 * The URL of one source file on one branch.
	 *
	 * @param string $engine The engine.
	 * @param string $series The branch.
	 * @param string $file   The path inside the repository.
	 *
	 * @return string The URL.
	 */
	public static function url(string $engine, string $series, string $file): string {
		$base = getenv('RESERVED_WORDS_BASE_URL');
		if (is_string($base) === false || $base === '') {
			$base = 'https://raw.githubusercontent.com';
		}

		return rtrim($base, '/').'/'.self::SOURCES[$engine]['repository'].'/'.$series.'/'.$file;
	}//end url()

	/**
	 * The words one series reserves, from upstream when reachable, merged with the snapshot.
	 *
	 * @param string $engine The engine.
	 * @param string $series The series.
	 *
	 * @return string[] Lower-case words, sorted.
	 *
	 * @throws RuntimeException When upstream answered but its files no longer parse.
	 */
	public static function words(string $engine, string $series): array {
		$key = $engine.'-'.$series;
		if (isset(self::$words[$key]) === true) {
			return self::$words[$key];
		}

		$snapshot = self::readSnapshot(engine: $engine, series: $series);
		$online   = self::fetch(engine: $engine, series: $series);
		self::$origin[$key] = ($online === null) ? 'snapshot' : 'online';

		$words = array_values(array_unique(array_merge($snapshot, $online ?? [])));
		sort($words);
		self::$words[$key] = $words;

		return $words;
	}//end words()

	/**
	 * Where each series' words came from in this run.
	 *
	 * @return array<string, string> `<engine>-<series>` => 'online' or 'snapshot'.
	 */
	public static function origins(): array {
		return self::$origin;
	}//end origins()

	/**
	 * Why the last download failed, or null when none did.
	 *
	 * @return string|null
	 */
	public static function downloadFailure(): ?string {
		return self::$offlineReason;
	}//end downloadFailure()

	/**
	 * The words upstream reserves on one branch, or null when it cannot be reached.
	 *
	 * @param string $engine The engine.
	 * @param string $series The branch.
	 *
	 * @return string[]|null
	 *
	 * @throws RuntimeException When both files came back but yield no plausible list.
	 */
	public static function fetch(string $engine, string $series): ?array {
		$lexer   = self::download(url: self::url(engine: $engine, series: $series, file: 'sql/lex.h'));
		$grammar = self::download(url: self::url(engine: $engine, series: $series, file: 'sql/sql_yacc.yy'));
		if ($lexer === null || $grammar === null) {
			return null;
		}

		$words = self::parse(engine: $engine, lexer: $lexer, grammar: $grammar, series: $series);
		self::assertPlausible(
			words: $words,
			label: 'The '.$engine.' '.$series.' sources',
			hint: 'the layout of sql/lex.h or sql/sql_yacc.yy has changed, so ReservedWordSource::parse() needs an update.'
		);

		return $words;
	}//end fetch()

	/**
	 * The release tags of one released series, oldest first: `<tagPrefix><series>.<n>`.
	 *
	 * Used by the refresh script, never by the test. Pre-release and vendor tags
	 * (`mysql-8.0.37-fc40`, `mysql-cluster-*`, `*-labs-*`) do not match.
	 *
	 * @param string $engine The engine.
	 * @param string $series A released series, not a development branch.
	 *
	 * @return string[]
	 *
	 * @throws RuntimeException When the GitHub API refuses the listing or the series has no tags.
	 */
	public static function releaseTags(string $engine, string $series): array {
		$source  = self::SOURCES[$engine];
		$base    = getenv('RESERVED_WORDS_API_URL');
		$base    = (is_string($base) === true && $base !== '') ? $base : 'https://api.github.com';
		$stem    = $source['tagPrefix'].$series;
		$pattern = '/^'.preg_quote($stem, '/').'\.\d+$/';
		$tags    = [];
		for ($page = 1; $page <= 20; $page++) {
			$url      = rtrim($base, '/').'/repos/'.$source['repository'].'/git/matching-refs/tags/'.$stem.'.?per_page=100&page='.$page;
			$response = self::request(url: $url);
			if ($response['status'] !== 200) {
				throw new RuntimeException(
					'Listing the '.$engine.' '.$series.' tags failed: HTTP '.$response['status'].' from '.$url
					.(in_array($response['status'], [403, 429], true) ? '; the GitHub API rate limit is reached, set GITHUB_TOKEN' : '')
					.(($response['error'] !== '') ? ' ('.$response['error'].')' : '')
				);
			}

			$refs = json_decode($response['body'], true);
			foreach (is_array($refs) ? $refs : [] as $ref) {
				$tag = substr((string) ($ref['ref'] ?? ''), strlen('refs/tags/'));
				$point = (int) substr($tag, (strlen($stem) + 1));
				if (preg_match($pattern, $tag) === 1 && $point >= ($source['firstRelease'][$series] ?? 0)) {
					$tags[] = $tag;
				}
			}

			if (is_array($refs) === false || count($refs) < 100) {
				break;
			}
		}

		if ($tags === []) {
			throw new RuntimeException('No release tag '.$stem.'.<n> exists; the tag naming of '.$source['repository'].' changed, so ReservedWordSource::SOURCES needs an update.');
		}

		usort($tags, 'strnatcmp');

		return $tags;
	}//end releaseTags()

	/**
	 * The words one release tag reserves, kept in a cache directory so an interrupted refresh resumes.
	 *
	 * A tag never changes, so its words are cached for good (RESERVED_WORDS_CACHE, default
	 * a directory in the system temp dir). Used by the refresh script, never by the test.
	 *
	 * @param string $engine The engine.
	 * @param string $tag    The release tag.
	 *
	 * @return string[]
	 *
	 * @throws RuntimeException When the sources of the tag cannot be downloaded or no longer parse.
	 */
	public static function releaseWords(string $engine, string $tag): array {
		$dir = getenv('RESERVED_WORDS_CACHE');
		$dir = (is_string($dir) === true && $dir !== '') ? $dir : sys_get_temp_dir().'/reserved-words-cache';
		if (is_dir($dir) === false) {
			mkdir($dir, 0777, true);
		}

		$file = $dir.'/'.$engine.'-'.$tag.'.txt';
		if (is_file($file) === true) {
			$cached = file($file, (FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
			if ($cached !== false) {
				return $cached;
			}
		}

		$words = self::fetch(engine: $engine, series: $tag);
		if ($words === null) {
			throw new RuntimeException('Could not download the '.$engine.' '.$tag.' sources: '.(self::$offlineReason ?? 'unknown reason').'. Run the script again to resume; finished tags are cached in '.$dir.'.');
		}

		file_put_contents($file, implode("\n", $words)."\n");

		return $words;
	}//end releaseWords()

	/**
	 * Refuses a list that no server can have, so a parser that lost its way says so.
	 *
	 * @param string[] $words The words.
	 * @param string   $label What the list came from, for the message.
	 * @param string   $hint  What to do about it, for the message.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the list is too short, too long, lacks `select` or holds a word no engine reserves.
	 */
	private static function assertPlausible(array $words, string $label, string $hint): void {
		$problem = null;
		if (count($words) < self::MINIMUM_WORDS) {
			$problem = 'only '.count($words).' reserved words (at least '.self::MINIMUM_WORDS.' expected)';
		} else if (count($words) > self::MAXIMUM_WORDS) {
			$problem = count($words).' reserved words (at most '.self::MAXIMUM_WORDS.' expected), so almost every keyword counts as reserved';
		} else if (in_array('select', $words, true) === false) {
			$problem = 'no `select` among its reserved words';
		} else if (array_intersect(self::NEVER_RESERVED, $words) !== []) {
			$problem = 'the word(s) '.implode(', ', array_intersect(self::NEVER_RESERVED, $words)).' that no series reserves';
		}

		if ($problem !== null) {
			throw new RuntimeException($label.' yielded '.$problem.'; '.$hint);
		}
	}//end assertPlausible()

	/**
	 * The reserved words in one pair of source files.
	 *
	 * @param string $engine  The engine.
	 * @param string $lexer   The contents of sql/lex.h.
	 * @param string $grammar The contents of sql/sql_yacc.yy.
	 * @param string $series  The series or tag the files belong to, for the error message.
	 *
	 * @return string[] Lower-case words, sorted.
	 *
	 * @throws RuntimeException When the identifier rule, or a rule it uses, is not in the grammar.
	 */
	public static function parse(string $engine, string $lexer, string $grammar, string $series=''): array {
		$allowed = self::identifierTokens(
			rules: self::grammarRules(grammar: $grammar),
			start: self::SOURCES[$engine]['identifier'],
			label: trim($engine.' '.$series)
		);

		$words = [];
		foreach (self::lexerKeywords(engine: $engine, lexer: $lexer) as $word => $token) {
			if (isset($allowed[$token]) === false) {
				$words[] = $word;
			}
		}

		sort($words);

		return array_values(array_unique($words));
	}//end parse()

	/**
	 * The keyword each lexer symbol names, word => grammar token, lower case.
	 *
	 * MariaDB keeps the keywords in `symbols[]` and the names only recognised
	 * before a '(' in `sql_functions[]`, which is not reserved. MySQL marks them
	 * with SYM (keyword) and SYM_HK (keyword usable in an optimizer hint); SYM_FN
	 * (function) and SYM_H (hint) entries are no keywords.
	 *
	 * @param string $engine The engine.
	 * @param string $lexer  The contents of sql/lex.h.
	 *
	 * @return array<string, string>
	 */
	private static function lexerKeywords(string $engine, string $lexer): array {
		if ($engine === 'mariadb') {
			$start = strpos($lexer, 'symbols[]');
			$end   = strpos($lexer, 'sql_functions[]');
			$block = ($start === false || $end === false) ? '' : substr($lexer, $start, ($end - $start));
			$found = preg_match_all('/\{\s*"([A-Za-z_][A-Za-z0-9_]*)"\s*,\s*SYM\(\s*(\w+)\s*\)/', $block, $matches, PREG_SET_ORDER);
		} else {
			$found = preg_match_all('/\{SYM(?:_HK)?\(\s*"([A-Za-z_][A-Za-z0-9_]*)"\s*,\s*(\w+)\s*\)\}/', $lexer, $matches, PREG_SET_ORDER);
		}

		$keywords = [];
		foreach (($found === false) ? [] : $matches as $match) {
			$keywords[strtolower($match[1])] = $match[2];
		}

		return $keywords;
	}//end lexerKeywords()

	/**
	 * The grammar tokens an identifier may be spelled as, from the identifier rule down.
	 *
	 * Rule names are lower case and tokens upper case in both grammars, so a lower
	 * case symbol with no rule of its own means the grammar was renamed under us:
	 * treating it as a token would silently shrink the set of allowed identifiers.
	 *
	 * @param array<string, string[][]> $rules The grammar, rule name => alternatives => symbols.
	 * @param string                    $start The rule that lists the keywords usable as an identifier.
	 * @param string                    $label The engine and series, for the error message.
	 *
	 * @return array<string, true> The token names.
	 *
	 * @throws RuntimeException When $start, or a rule reachable from it, is not defined.
	 */
	private static function identifierTokens(array $rules, string $start, string $label): array {
		if (isset($rules[$start]) === false) {
			throw new RuntimeException(
				'The '.$label.' grammar has no rule `'.$start.'`; sql/sql_yacc.yy was changed, '
				.'so ReservedWordSource::SOURCES and parse() need an update.'
			);
		}

		$tokens = [];
		$seen   = [];
		$queue  = [$start];
		while ($queue !== []) {
			$rule = array_pop($queue);
			if (isset($seen[$rule]) === true) {
				continue;
			}

			$seen[$rule] = true;
			foreach ($rules[$rule] as $alternative) {
				foreach ($alternative as $symbol) {
					if (isset($rules[$symbol]) === true) {
						$queue[] = $symbol;
					} else if (preg_match('/^[a-z]/', $symbol) === 1) {
						throw new RuntimeException(
							'The '.$label.' grammar rule `'.$rule.'` uses `'.$symbol.'`, which has no rule; '
							.'sql/sql_yacc.yy was changed, so ReservedWordSource::parse() needs an update.'
						);
					} else {
						$tokens[$symbol] = true;
					}
				}
			}
		}

		return $tokens;
	}//end identifierTokens()

	/**
	 * The rules of a Bison grammar, without actions, comments or conditional sections.
	 *
	 * @param string $grammar The contents of sql_yacc.yy.
	 *
	 * @return array<string, string[][]> Rule name => alternatives => symbols.
	 */
	private static function grammarRules(string $grammar): array {
		$tokens = self::grammarTokens(text: self::withoutOtherModes(grammar: $grammar));
		$rules  = [];
		$name   = null;
		$count  = count($tokens);
		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];
			if ($token === ';') {
				$name = null;
			} else if (($tokens[($i + 1)] ?? '') === ':' && preg_match('/^[A-Za-z_]/', $token) === 1) {
				$name         = $token;
				$rules[$name] = ($rules[$name] ?? []);
				$rules[$name][] = [];
				$i++;
			} else if ($name !== null && $token === '|') {
				$rules[$name][] = [];
			} else if ($name !== null) {
				$last = (count($rules[$name]) - 1);
				$rules[$name][$last][] = $token;
			}
		}

		return $rules;
	}//end grammarRules()

	/**
	 * The grammar text as the default build sees it: the MariaDB sections, not the Oracle-mode ones.
	 *
	 * MariaDB compiles one grammar twice and marks the differences with
	 * `%ifdef MARIADB|ORACLE`, `%else` and `%endif`. The text before the first
	 * `%%` is the declarations, which hold no rules.
	 *
	 * @param string $grammar The contents of sql_yacc.yy.
	 *
	 * @return string The rules section with the unused sections removed.
	 */
	private static function withoutOtherModes(string $grammar): string {
		$kept   = [];
		$active = [true];
		$inRules = false;
		foreach (preg_split('/\R/', $grammar) ?: [] as $line) {
			if ($inRules === false) {
				$inRules = (trim($line) === '%%');
				continue;
			}

			if (preg_match('/^%(ifdef|ifndef)\s+(\w+)/', $line, $match) === 1) {
				$defined  = ($match[2] === 'MARIADB');
				$active[] = (end($active) === true) && (($match[1] === 'ifdef') ? $defined : ($defined === false));
			} else if (preg_match('/^%else\b/', $line) === 1) {
				$inner = array_pop($active);
				$outer = (end($active) !== false) ? end($active) : false;
				$active[] = $outer && ($inner === false);
			} else if (preg_match('/^%endif\b/', $line) === 1) {
				array_pop($active);
			} else if (end($active) === true) {
				$kept[] = $line;
			}
		}

		return implode("\n", $kept);
	}//end withoutOtherModes()

	/**
	 * The symbols, ':', '|' and ';' of a rules section; comments and `{ action }` blocks dropped.
	 *
	 * @param string $text The rules section.
	 *
	 * @return string[]
	 */
	private static function grammarTokens(string $text): array {
		$tokens = [];
		$length = strlen($text);
		$i      = 0;
		while ($i < $length) {
			$char = $text[$i];
			if (ctype_space($char) === true) {
				$i++;
			} else if ($char === '/' && ($text[($i + 1)] ?? '') === '*') {
				$end = strpos($text, '*/', ($i + 2));
				$i   = ($end === false) ? $length : ($end + 2);
			} else if ($char === '/' && ($text[($i + 1)] ?? '') === '/') {
				$end = strpos($text, "\n", $i);
				$i   = ($end === false) ? $length : $end;
			} else if ($char === '{') {
				$i = self::endOfAction(text: $text, open: $i);
			} else if ($char === '"' || $char === "'") {
				$end      = self::endOfQuote(text: $text, open: $i);
				$tokens[] = substr($text, $i, ($end - $i));
				$i        = $end;
			} else if ($char === ':' || $char === '|' || $char === ';') {
				$tokens[] = $char;
				$i++;
			} else if ($char === '%') {
				// A directive such as `%prec TOKEN`: it names no symbol of the rule.
				preg_match('/\G%\w+(?:\s+\w+)?/', $text, $match, 0, $i);
				$i += max(1, strlen($match[0] ?? ''));
			} else if (preg_match('/\G[A-Za-z_][A-Za-z0-9_.]*/', $text, $match, 0, $i) === 1) {
				$tokens[] = $match[0];
				$i       += strlen($match[0]);
			} else {
				$i++;
			}
		}

		return $tokens;
	}//end grammarTokens()

	/**
	 * The offset just after the `}` that closes the action starting at $open.
	 *
	 * @param string $text The rules section.
	 * @param int    $open The offset of the opening brace.
	 *
	 * @return int
	 */
	private static function endOfAction(string $text, int $open): int {
		$length = strlen($text);
		$depth  = 0;
		for ($i = $open; $i < $length; $i++) {
			$char = $text[$i];
			if ($char === '"' || $char === "'") {
				$i = (self::endOfQuote(text: $text, open: $i) - 1);
			} else if ($char === '/' && ($text[($i + 1)] ?? '') === '*') {
				$end = strpos($text, '*/', ($i + 2));
				$i   = ($end === false) ? $length : ($end + 1);
			} else if ($char === '/' && ($text[($i + 1)] ?? '') === '/') {
				$end = strpos($text, "\n", $i);
				$i   = ($end === false) ? $length : $end;
			} else if ($char === '{') {
				$depth++;
			} else if ($char === '}') {
				$depth--;
				if ($depth === 0) {
					return ($i + 1);
				}
			}
		}

		return $length;
	}//end endOfAction()

	/**
	 * The offset just after the quote that closes the string or character literal starting at $open.
	 *
	 * @param string $text The text.
	 * @param int    $open The offset of the opening quote.
	 *
	 * @return int
	 */
	private static function endOfQuote(string $text, int $open): int {
		$quote  = $text[$open];
		$length = strlen($text);
		for ($i = ($open + 1); $i < $length; $i++) {
			if ($text[$i] === '\\') {
				$i++;
			} else if ($text[$i] === $quote) {
				return ($i + 1);
			} else if ($text[$i] === "\n") {
				// An apostrophe in prose inside an action, not a literal: stop at the line end.
				return $i;
			}
		}

		return $length;
	}//end endOfQuote()

	/**
	 * One file from upstream, or null when the network does not deliver it.
	 *
	 * 200 is the file. A refused connection, a timeout, a rate limit (429) or a
	 * server error (5xx) is a network that is down for our purposes: null, with the
	 * reason kept for the origin report, and no further download in this run.
	 * A 404 or any redirect is different: the branch or file moved or is gone, and
	 * the snapshot cannot repair that, so it throws. Any other status (a 403, say)
	 * falls back like an outage; the origin report names it.
	 *
	 * @param string $url The URL.
	 *
	 * @return string|null
	 *
	 * @throws RuntimeException On a 404 or a 3xx.
	 */
	private static function download(string $url): ?string {
		if (self::$offlineReason !== null) {
			return null;
		}

		if (function_exists('curl_init') === false) {
			self::$offlineReason = 'the curl extension is not loaded';
			return null;
		}

		['status' => $status, 'body' => $body, 'error' => $error] = self::request(url: $url);

		if ($status === 404 || ($status >= 300 && $status < 400)) {
			throw new RuntimeException(
				$url.' answered HTTP '.$status.'; the source file or branch was moved or removed upstream, '
				.'so ReservedWordSource::SOURCES or its URLs need an update.'
			);
		}

		if ($body === '' || $status !== 200) {
			self::$offlineReason = ($status === 0) ? 'no answer from '.$url.' ('.($error !== '' ? $error : 'connection failed').')' : 'HTTP '.$status.' from '.$url;
			return null;
		}

		return $body;
	}//end download()

	/**
	 * One GET request, redirects not followed.
	 *
	 * A GITHUB_TOKEN in the environment is sent to GitHub's own hosts only, to
	 * lift the unauthenticated rate limit; a mirror in RESERVED_WORDS_BASE_URL never sees it.
	 *
	 * @param string $url The URL.
	 *
	 * @return array{status: int, body: string, error: string} Status 0 when nothing answered.
	 */
	private static function request(string $url): array {
		$headers = [];
		$token   = getenv('GITHUB_TOKEN');
		$host    = parse_url($url, PHP_URL_HOST);
		if (is_string($token) === true && $token !== '' && in_array($host, ['api.github.com', 'raw.githubusercontent.com'], true) === true) {
			$headers[] = 'Authorization: Bearer '.$token;
		}

		$handle = curl_init($url);
		curl_setopt_array(
			$handle,
			[
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_TIMEOUT        => 20,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_USERAGENT      => 'openregister-reserved-words',
				CURLOPT_HTTPHEADER     => $headers,
			]
		);
		$body = curl_exec($handle);

		return [
			'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
			'body'   => is_string($body) ? $body : '',
			'error'  => curl_error($handle),
		];
	}//end request()

	/**
	 * The words in one committed snapshot.
	 *
	 * @param string $engine The engine.
	 * @param string $series The series.
	 *
	 * @return string[]
	 *
	 * @throws RuntimeException When the snapshot is missing or empty.
	 */
	private static function readSnapshot(string $engine, string $series): array {
		$path  = self::snapshotPath(engine: $engine, series: $series);
		$lines = is_file($path) ? file($path, (FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : false;
		if ($lines === false) {
			throw new RuntimeException('Snapshot '.$path.' is missing; run tests/Unit/Migration/fixtures/update-reserved-words.php.');
		}

		$words = [];
		foreach ($lines as $line) {
			$word = strtolower(trim($line));
			if ($word !== '' && str_starts_with($word, '#') === false) {
				$words[] = $word;
			}
		}

		self::assertPlausible(
			words: $words,
			label: 'Snapshot '.$path,
			hint: 'run tests/Unit/Migration/fixtures/update-reserved-words.php.'
		);

		return $words;
	}//end readSnapshot()
}//end class
