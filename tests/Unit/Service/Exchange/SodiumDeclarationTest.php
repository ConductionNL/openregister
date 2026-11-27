<?php

/**
 * The encrypted set's cipher is a declared platform requirement.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Exchange
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-an-instance-set-can-be-encrypted-with-a-passphrase-or-an-export-key
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Exchange;

use PHPUnit\Framework\TestCase;

/**
 * The secretstream cipher (design D-1) comes from PHP's bundled ext-sodium; an
 * install without it must be refused by Composer, not fail on the first export.
 *
 * @coversNothing
 */
class SodiumDeclarationTest extends TestCase {

	/**
	 * Read a JSON file at the repository root.
	 *
	 * @param string $name The file name.
	 *
	 * @return array<string, mixed> The decoded file.
	 */
	private function rootJson(string $name): array {
		$path = dirname(__DIR__, 4) . '/' . $name;
		$this->assertFileExists($path);

		return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	}//end rootJson()

	/**
	 * composer.json requires ext-sodium.
	 *
	 * @return void
	 */
	public function testComposerRequiresTheSodiumExtension(): void {
		$composer = $this->rootJson('composer.json');

		$this->assertSame('*', ($composer['require']['ext-sodium'] ?? null));
	}//end testComposerRequiresTheSodiumExtension()

	/**
	 * The lock file carries the same platform requirement, so it is not stale.
	 *
	 * @return void
	 */
	public function testTheLockFileCarriesTheSamePlatformRequirement(): void {
		$lock = $this->rootJson('composer.lock');

		$this->assertSame('*', ($lock['platform']['ext-sodium'] ?? null));
	}//end testTheLockFileCarriesTheSamePlatformRequirement()

	/**
	 * The functions the writer and reader call exist where the extension is loaded.
	 *
	 * @return void
	 */
	public function testTheSecretstreamFunctionsTheWriterCallsExist(): void {
		$this->assertTrue(extension_loaded('sodium'));
		$this->assertTrue(function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push'));
		$this->assertTrue(function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_pull'));
		$this->assertTrue(function_exists('sodium_crypto_pwhash'));
	}//end testTheSecretstreamFunctionsTheWriterCallsExist()
}//end class
