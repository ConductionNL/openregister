<?php

/**
 * ScriptManifestLoader Unit Tests
 *
 * Locks down the manifest-resolution contract that drives webpack chunk
 * loading: the happy path (manifest present → ordered chunk names), and the
 * fallback branches (missing file, malformed JSON, unknown entry → null, which
 * makes the caller enqueue the legacy single script).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ScriptManifestLoader;
use PHPUnit\Framework\TestCase;

class ScriptManifestLoaderTest extends TestCase {
	/**
	 * Temporary directory acting as the build js/ output folder.
	 *
	 * @var string
	 */
	private string $jsDir;

	/**
	 * Create a unique temp directory for each test's manifest.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->jsDir = sys_get_temp_dir() . '/or-manifest-' . uniqid();
		mkdir($this->jsDir);
	}//end setUp()

	/**
	 * Remove the temp directory and its contents.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$manifest = $this->jsDir . '/openregister-entrypoints.json';
		if (is_file($manifest) === true) {
			unlink($manifest);
		}

		if (is_dir($this->jsDir) === true) {
			rmdir($this->jsDir);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * Write a manifest file into the temp js/ directory.
	 *
	 * @param string $contents Raw file contents to write.
	 *
	 * @return void
	 */
	private function writeManifest(string $contents): void {
		file_put_contents($this->jsDir . '/openregister-entrypoints.json', $contents);
	}//end writeManifest()

	/**
	 * Happy path: a present manifest yields the entry's chunks in order with
	 * the '.js' extension stripped (so they are ready for Util::addScript).
	 *
	 * @return void
	 */
	public function testResolvesOrderedChunksFromManifest(): void {
		$this->writeManifest(
			json_encode(
				[
					'main' => [
						'openregister-openregister-vendor.js',
						'openregister-shared.js',
						'openregister-main.js',
					],
				]
			)
		);

		$result = ScriptManifestLoader::resolveEntryScripts(
			appId: 'openregister',
			entry: 'main',
			jsDirectory: $this->jsDir
		);

		$this->assertSame(
			[
				'openregister-openregister-vendor',
				'openregister-shared',
				'openregister-main',
			],
			$result
		);
	}//end testResolvesOrderedChunksFromManifest()

	/**
	 * Missing manifest: resolution returns null so the caller falls back to the
	 * legacy single script.
	 *
	 * @return void
	 */
	public function testReturnsNullWhenManifestMissing(): void {
		// No manifest written into the temp directory.
		$result = ScriptManifestLoader::resolveEntryScripts(
			appId: 'openregister',
			entry: 'main',
			jsDirectory: $this->jsDir
		);

		$this->assertNull($result);
	}//end testReturnsNullWhenManifestMissing()

	/**
	 * Malformed manifest: invalid JSON decodes to a non-array, so resolution
	 * returns null rather than throwing.
	 *
	 * @return void
	 */
	public function testReturnsNullWhenManifestMalformed(): void {
		$this->writeManifest('this is not valid json');

		$result = ScriptManifestLoader::resolveEntryScripts(
			appId: 'openregister',
			entry: 'main',
			jsDirectory: $this->jsDir
		);

		$this->assertNull($result);
	}//end testReturnsNullWhenManifestMalformed()

	/**
	 * Unknown entry: a valid manifest that does not list the requested entry
	 * resolves to null.
	 *
	 * @return void
	 */
	public function testReturnsNullWhenEntryNotInManifest(): void {
		$this->writeManifest(json_encode(['main' => ['openregister-main.js']]));

		$result = ScriptManifestLoader::resolveEntryScripts(
			appId: 'openregister',
			entry: 'doesNotExist',
			jsDirectory: $this->jsDir
		);

		$this->assertNull($result);
	}//end testReturnsNullWhenEntryNotInManifest()

}//end class
