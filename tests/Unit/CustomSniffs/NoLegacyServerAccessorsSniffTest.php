<?php

/**
 * Test for NoLegacyServerAccessorsSniff.
 *
 * Exercises the sniff directly via PHPCS's DummyFile so the tests run without
 * the Nextcloud bootstrap (WSL-friendly).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\CustomSniffs;

use PHP_CodeSniffer\Config;
use PHP_CodeSniffer\Files\DummyFile;
use PHP_CodeSniffer\Ruleset;
use PHPUnit\Framework\TestCase;

// PHPCS has its own autoloader (not exposed via composer's classmap).
require_once __DIR__ . '/../../../vendor/squizlabs/php_codesniffer/autoload.php';

// PHPCS's T_* token constants, T_ANON_CLASS among them, are defined by
// top-level define() calls at the bottom of Util/Tokens.php. Nothing here
// referenced the Tokens class, so on a run where no earlier code happened to
// autoload it those constants did not exist, and constructing a Ruleset with
// the Generic standard fataled in ConstructorNameSniff with
// `Undefined constant "PHP_CodeSniffer\Standards\Generic\Sniffs\NamingConventions\T_ANON_CLASS"`
// (PHP falling back to a namespace-relative lookup for a missing global).
//
// That is environment-dependent, not version-dependent: the failure appeared on
// CI at exactly the PHPCS version this passes on locally, 3.13.6. Requiring the
// file makes the constants unconditional instead of incidental.
require_once __DIR__ . '/../../../vendor/squizlabs/php_codesniffer/src/Util/Tokens.php';

// PHPCS runtime expects these constants to be defined (normally set by its CLI entry point).
if (defined('PHP_CODESNIFFER_VERBOSITY') === false) {
	define('PHP_CODESNIFFER_VERBOSITY', 0);
}

if (defined('PHP_CODESNIFFER_CBF') === false) {
	define('PHP_CODESNIFFER_CBF', false);
}

/**
 * NoLegacyServerAccessorsSniffTest covers positive and negative cases.
 *
 * The sniff itself lives in the shared conduction/hydra-gates package since
 * b22f86d0c; this app no longer carries a copy. The test was skipped for a
 * PHP_CodeSniffer 3.9 tokenizer bug and pointed at the removed local copy, so
 * for months it guarded nothing. PHPCS is at 3.13 now and the skip is gone.
 */
final class NoLegacyServerAccessorsSniffTest extends TestCase {
	/**
	 * Run the sniff against a PHP source snippet and return the error messages.
	 *
	 * @param string $source Full PHP source including the opening tag.
	 *
	 * @return array<string> Flat list of error messages from the sniff.
	 */
	private function runSniff(string $source): array {
		// Use a Ruleset seeded with the built-in Generic standard (which ships with PHPCS)
		// so construction succeeds, then swap in *only* our sniff before tokenization.
		$config = new Config(cliArgs: [], dieOnUnknownArg: false);
		$config->standards = ['Generic'];
		$config->tabWidth = 4;

		$sniffFile = realpath(
			__DIR__ . '/../../../vendor/conduction/hydra-gates/quality-config/phpcs-custom-sniffs/'
			. 'CustomSniffs/Sniffs/Nextcloud/NoLegacyServerAccessorsSniff.php'
		);
		$this->assertIsString($sniffFile, 'The hydra-gates sniff must be installed under vendor/');
		include_once $sniffFile;

		$ruleset = new Ruleset(config: $config);

		$sniffClass = \CustomSniffs\Sniffs\Nextcloud\NoLegacyServerAccessorsSniff::class;
		$ruleset->sniffs = [$sniffClass => $sniffClass];
		$ruleset->ruleset = [];
		$ruleset->populateTokenListeners();

		$file = new DummyFile(content: $source, ruleset: $ruleset, config: $config);
		$file->process();

		$messages = [];
		foreach ($file->getErrors() as $line => $columns) {
			foreach ($columns as $column => $entries) {
				foreach ($entries as $entry) {
					$messages[] = $entry['message'];
				}
			}
		}

		return $messages;
	}//end runSniff()

	/**
	 * \OC::$server->getSystemConfig() must report an error.
	 *
	 * @return void
	 */
	public function testFlagsGetSystemConfig(): void {
		$messages = $this->runSniff(source: '<?php $x = \OC::$server->getSystemConfig()->getValue("foo");');

		$this->assertNotEmpty(actual: $messages, message: 'Expected an error for getSystemConfig');
		$this->assertStringContainsString(
			needle: 'Named accessor \OC::$server->getSystemConfig()',
			haystack: $messages[0]
		);

	}//end testFlagsGetSystemConfig()

	/**
	 * \OC::$server->getDatabaseConnection() must report an error.
	 *
	 * @return void
	 */
	public function testFlagsGetDatabaseConnection(): void {
		$messages = $this->runSniff(source: '<?php $db = \OC::$server->getDatabaseConnection();');

		$this->assertNotEmpty(actual: $messages);
		$this->assertStringContainsString(needle: 'getDatabaseConnection', haystack: $messages[0]);
		$this->assertStringContainsString(needle: 'OCP\IDBConnection', haystack: $messages[0]);

	}//end testFlagsGetDatabaseConnection()

	/**
	 * \OC::$server->getLogger() must report an error.
	 *
	 * @return void
	 */
	public function testFlagsGetLogger(): void {
		$messages = $this->runSniff(source: '<?php \OC::$server->getLogger()->info("x");');

		$this->assertNotEmpty(actual: $messages);
		$this->assertStringContainsString(needle: 'getLogger', haystack: $messages[0]);

	}//end testFlagsGetLogger()

	/**
	 * PSR-11 get(X::class) is deferred (D4) and must NOT be flagged.
	 *
	 * @return void
	 */
	public function testIgnoresPsr11Get(): void {
		$messages = $this->runSniff(
			source: '<?php $tool = \OC::$server->get(\OCP\IConfig::class);'
		);

		$this->assertEmpty(actual: $messages, message: 'PSR-11 get() should not be flagged in this change');

	}//end testIgnoresPsr11Get()

	/**
	 * Normal DI-style property access on $this must NOT be flagged.
	 *
	 * @return void
	 */
	public function testIgnoresThisPropertyAccess(): void {
		$messages = $this->runSniff(
			source: '<?php class Foo { public function bar() { return $this->config->getSystemValue("x"); } }'
		);

		$this->assertEmpty(actual: $messages);

	}//end testIgnoresThisPropertyAccess()

	/**
	 * A docblock example that mentions the pattern in a string/comment must NOT be flagged —
	 * PHPCS doesn't tokenize inside T_DOC_COMMENT so the sniff is naturally safe here.
	 *
	 * @return void
	 */
	public function testIgnoresDocblockExample(): void {
		$source = <<<'PHP'
<?php
/**
 * Legacy pattern for reference only:
 *
 * $foo = \OC::$server->getSystemConfig();
 */
class Foo {}
PHP;

		$messages = $this->runSniff(source: $source);

		$this->assertEmpty(actual: $messages, message: 'Docblock examples must not trip the sniff');

	}//end testIgnoresDocblockExample()

	/**
	 * The same pattern inside a string literal must NOT be flagged.
	 *
	 * @return void
	 */
	public function testIgnoresStringLiteral(): void {
		$messages = $this->runSniff(
			source: '<?php $x = "Avoid \\OC::\$server->getSystemConfig() — use DI instead";'
		);

		$this->assertEmpty(actual: $messages, message: 'String literals must not trip the sniff');

	}//end testIgnoresStringLiteral()
}//end class
