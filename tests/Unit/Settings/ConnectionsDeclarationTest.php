<?php

/**
 * The connection declaration integriq reads.
 *
 * `lib/Settings/connections.json` is static JSON that integriq turns into the
 * rows of the Connections page. Nothing in OpenRegister reads it at runtime, so
 * a broken file fails nowhere in this repo: integriq skips it whole and the page
 * goes empty on some other instance. Every assertion here is a way that file
 * could go wrong without a sound.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Settings;

use OCA\OpenRegister\Service\Connection\ConnectionReporter;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Guards lib/Settings/connections.json against hydra connection-registry D2 and D12.
 *
 * @coversNothing
 */
class ConnectionsDeclarationTest extends TestCase {

	/**
	 * Integriq's schema, vendored from branch feat/connection-registry-amendments
	 * at efe726dcfb2ed583c7ce3e2dfbc05310c8756323 (the D12 amendments). The copy
	 * on integriq development (b2ed68f) predates `reportedOnly`, `jsonPath` and
	 * `simulatedValues`.
	 *
	 * @var string
	 */
	private const SCHEMA = '/tests/Fixtures/Integriq/connections.schema.json';

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The raw declaration file.
	 *
	 * @return string
	 */
	private function raw(): string {
		$raw = file_get_contents($this->root() . '/lib/Settings/connections.json');
		$this->assertIsString(actual: $raw, message: 'lib/Settings/connections.json must exist');

		return $raw;
	}//end raw()

	/**
	 * The decoded declaration.
	 *
	 * @return array<string, mixed>
	 */
	private function declaration(): array {
		$decoded = json_decode($this->raw(), true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray(actual: $decoded);

		return $decoded;
	}//end declaration()

	/**
	 * The declared connections, keyed by connection key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function connectionsByKey(): array {
		$byKey = [];
		foreach ($this->declaration()['connections'] as $connection) {
			$byKey[$connection['key']] = $connection;
		}

		return $byKey;
	}//end connectionsByKey()

	/**
	 * The file validates against integriq's JSON Schema.
	 *
	 * @return void
	 */
	public function testTheFileValidatesAgainstIntegriqsSchema(): void {
		$schema = file_get_contents($this->root() . self::SCHEMA);
		$this->assertIsString(actual: $schema);

		$validator = new Validator();
		$result = $validator->validate(json_decode($this->raw()), $schema);

		$errors = [];
		if ($result->hasError() === true) {
			$errors = (new ErrorFormatter())->format($result->error());
		}

		$this->assertTrue(condition: $result->isValid(), message: json_encode($errors, JSON_PRETTY_PRINT));
	}//end testTheFileValidatesAgainstIntegriqsSchema()

	/**
	 * The schema check can fail: a misspelled field is refused.
	 *
	 * Without this control a validator that accepts everything would pass the
	 * test above as well.
	 *
	 * @return void
	 */
	public function testTheSchemaRefusesAnUnknownField(): void {
		$schema = file_get_contents($this->root() . self::SCHEMA);
		$this->assertIsString(actual: $schema);

		$declaration = json_decode($this->raw());
		$declaration->connections[0]->setingsUrl = '/settings/admin/openregister#section-llm';

		$this->assertFalse(condition: (new Validator())->validate($declaration, $schema)->isValid());
	}//end testTheSchemaRefusesAnUnknownField()

	/**
	 * The file names the app it ships in.
	 *
	 * @return void
	 */
	public function testTheFileNamesThisApp(): void {
		// Read and parse a string, not simplexml_load_file(): under Nextcloud a
		// locked-down libxml external-entity resolver makes the path form return
		// false on a well-formed file (same reason the production code reads first).
		$infoXml = simplexml_load_string((string) file_get_contents($this->root() . '/appinfo/info.xml'));

		$this->assertNotFalse(condition: $infoXml);
		$this->assertSame(expected: (string)$infoXml->id, actual: $this->declaration()['app']);
		$this->assertSame(expected: ConnectionReporter::APP_ID, actual: $this->declaration()['app']);
	}//end testTheFileNamesThisApp()

	/**
	 * The keys are unique, in rising order, and equal to the ones the reporter accepts.
	 *
	 * @return void
	 */
	public function testTheKeysAreUniqueOrderedAndKnownToTheReporter(): void {
		$connections = $this->declaration()['connections'];
		$keys = array_column($connections, 'key');

		$this->assertSame(expected: array_values(array_unique($keys)), actual: $keys, message: 'a key is declared twice');
		$this->assertSame(expected: ConnectionReporter::KEYS, actual: $keys);

		$orders = array_column($connections, 'order');
		$sorted = $orders;
		sort($sorted);
		$this->assertSame(expected: $sorted, actual: $orders);
		$this->assertCount(expectedCount: count($keys), haystack: array_unique($orders));
	}//end testTheKeysAreUniqueOrderedAndKnownToTheReporter()

	/**
	 * No text a reader sees carries an em-dash (voice rule 8).
	 *
	 * @return void
	 */
	public function testNoTextCarriesAnEmDash(): void {
		$this->assertStringNotContainsString(needle: "\u{2014}", haystack: $this->raw());
		$this->assertStringNotContainsString(needle: ' -- ', haystack: $this->raw());
	}//end testNoTextCarriesAnEmDash()

	/**
	 * Every settings link lands on an element id that exists under src/ or templates/.
	 *
	 * A link into a section that does not exist scrolls nowhere and logs
	 * nothing, which is the untruth this page exists to stop. A copy of the link
	 * itself does not count as the element.
	 *
	 * @return void
	 */
	public function testEverySettingsLinkPointsAtAnExistingElement(): void {
		$sources = $this->sourcesUnder(dirs: ['src', 'templates']);
		$linked = 0;

		foreach ($this->declaration()['connections'] as $connection) {
			if (array_key_exists('settingsUrl', $connection) === false) {
				continue;
			}

			$url = (string)$connection['settingsUrl'];
			$this->assertStringStartsWith(prefix: '/settings/admin/openregister#', string: $url, message: $connection['key']);
			$anchor = substr($url, (int)strpos($url, '#') + 1);
			$this->assertMatchesRegularExpression(
				pattern: '/\bid="' . preg_quote($anchor, '/') . '"/',
				string: $sources,
				message: $connection['key'] . ' links to a missing element #' . $anchor
			);
			$linked++;
		}

		$this->assertSame(expected: 4, actual: $linked);
	}//end testEverySettingsLinkPointsAtAnExistingElement()

	/**
	 * The keys a save refreshes are exactly the declared config keys.
	 *
	 * Integriq decides a status from `requiredConfig` and `adapter.configKey`.
	 * If the reporter refreshed on different keys, a save would change a value
	 * integriq reads and nobody would tell it.
	 *
	 * @return void
	 */
	public function testTheRefreshMapMatchesTheDeclaredConfigKeys(): void {
		$declared = [];
		foreach ($this->declaration()['connections'] as $connection) {
			$keys = ($connection['requiredConfig'] ?? []);
			if (isset($connection['adapter']['configKey']) === true) {
				$keys[] = $connection['adapter']['configKey'];
			}

			$keys = array_values(array_unique($keys));
			if ($keys !== []) {
				$declared[$connection['key']] = $keys;
			}
		}

		$this->assertSame(expected: ConnectionReporter::REFRESH_KEYS, actual: $declared);
	}//end testTheRefreshMapMatchesTheDeclaredConfigKeys()

	/**
	 * The LLM row carries no adapter block, so it can never read Simulated.
	 *
	 * With no provider chosen nothing answers: chat throws a 503. A mock is not
	 * running, so rule 3 must not apply (design D2).
	 *
	 * @return void
	 */
	public function testAnUnsetLlmCannotReadSimulated(): void {
		$llm = $this->connectionsByKey()['llm'];

		$this->assertArrayNotHasKey(key: 'adapter', array: $llm);
		$this->assertStringContainsString(needle: '503', haystack: (string)$llm['unconfiguredMessage']);
	}//end testAnUnsetLlmCannotReadSimulated()

	/**
	 * The DI seams are reported-only, and only the office converter is unavailable.
	 *
	 * @return void
	 */
	public function testSeamsAreReportedOnlyAndTheConverterIsUnavailable(): void {
		$byKey = $this->connectionsByKey();

		$reportedOnly = array_keys(array_filter($byKey, static fn (array $c): bool => ($c['reportedOnly'] ?? false) === true));
		$this->assertSame(expected: ['translation', 'dsar-identity', 'dsar-regulator'], actual: $reportedOnly);

		$unavailable = array_keys(array_filter($byKey, static fn (array $c): bool => ($c['available'] ?? true) === false));
		$this->assertSame(expected: ['office-converter'], actual: $unavailable);
		$this->assertMatchesRegularExpression(pattern: '/nothing calls/i', string: (string)$byKey['office-converter']['unavailableMessage']);
	}//end testSeamsAreReportedOnlyAndTheConverterIsUnavailable()

	/**
	 * A source template names the source the provider really calls.
	 *
	 * The template is offered first when an admin links a source, so a
	 * template for another source would link the wrong one.
	 *
	 * @return void
	 */
	public function testSourceTemplatesNameTheSourceTheProviderCalls(): void {
		$byKey = $this->connectionsByKey();

		$this->assertSame(
			expected: \OCA\OpenRegister\Service\Integration\Providers\BrpPersonProvider::SOURCE_ID,
			actual: $byKey['brp']['sourceTemplate']
		);
		$this->assertSame(expected: \OCA\OpenRegister\Service\Integration\Providers\KvkProvider::SOURCE_ID, actual: $byKey['kvk']['sourceTemplate']);
		$this->assertSame(
			expected: \OCA\OpenRegister\Service\Integration\Providers\OpenCorporatesProvider::SOURCE_ID,
			actual: $byKey['opencorporates']['sourceTemplate']
		);
		$this->assertSame(
			expected: \OCA\OpenRegister\Service\Integration\Providers\MessageDispatchProvider::SOURCE_ID,
			actual: $byKey['message-dispatch']['sourceTemplate']
		);
		$this->assertContains(
			needle: $byKey['message-dispatch']['sourceTemplate'],
			haystack: \OCA\OpenRegister\Service\Integration\Providers\MessageDispatchProvider::ALLOWED_SOURCES
		);
		$this->assertSame(expected: 'xwiki', actual: $byKey['xwiki']['sourceTemplate']);
		$this->assertArrayNotHasKey(key: 'sourceTemplate', array: $byKey['openproject'], message: 'integriq ships no openproject source template');
	}//end testSourceTemplatesNameTheSourceTheProviderCalls()

	/**
	 * Per-record connections stay out of the file (design D12).
	 *
	 * @return void
	 */
	public function testPerRecordConnectionsAreNotDeclared(): void {
		foreach (array_keys($this->connectionsByKey()) as $key) {
			$this->assertDoesNotMatchRegularExpression(pattern: '/webhook|oauth|federat/i', string: $key);
		}
	}//end testPerRecordConnectionsAreNotDeclared()

	/**
	 * The contents of every .vue, .js and .php file under the given directories.
	 *
	 * @param array<int, string> $dirs Directories relative to the repository root.
	 *
	 * @return string
	 */
	private function sourcesUnder(array $dirs): string {
		$contents = '';
		foreach ($dirs as $dir) {
			$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root() . '/' . $dir));
			foreach ($iterator as $file) {
				if ($file->isFile() === false || preg_match('/\.(vue|js|php)$/', $file->getFilename()) !== 1) {
					continue;
				}

				$contents .= (string)file_get_contents($file->getPathname()) . "\n";
			}
		}

		return $contents;
	}//end sourcesUnder()
}//end class
