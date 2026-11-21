<?php

/**
 * The credential broker register carries outside custody (credential-outside-vault-reference task 1.1).
 *
 * Reads the shipped register and provider catalogue files, as the import does.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Settings;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
class CredentialBrokerRegisterTest extends TestCase {

	/**
	 * A shipped settings file, decoded.
	 *
	 * @param string $file The file under lib/Settings.
	 *
	 * @return array<string, mixed>
	 */
	private function settings(string $file): array {
		$decoded = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/' . $file), true);
		$this->assertIsArray($decoded, $file . ' must parse');
		return $decoded;
	}

	/**
	 * The brokeredcredential schema.
	 *
	 * @return array<string, mixed>
	 */
	private function credentialSchema(): array {
		return $this->settings('credential_broker_register.json')['components']['schemas']['brokeredcredential'];
	}

	public function testCustodyIsHeldOrOutsideAndDefaultsToHeld(): void {
		$custody = $this->credentialSchema()['properties']['custody'];

		$this->assertSame('string', $custody['type']);
		$this->assertSame(['held', 'outside'], $custody['enum']);
		$this->assertSame('held', $custody['default']);
	}

	public function testVaultRefNamesAConnectionAPathAndAKey(): void {
		$vaultRef = $this->credentialSchema()['properties']['vaultRef'];

		$this->assertSame('object', $vaultRef['type']);
		$this->assertSame(['connection', 'path', 'key'], array_keys($vaultRef['properties']));
		$this->assertSame('uuid', $vaultRef['properties']['connection']['format']);
	}

	public function testAConnectionDeclaresHowToLogIn(): void {
		$properties = $this->credentialSchema()['properties'];

		$this->assertSame(['token', 'approle'], $properties['vaultAuthMethod']['enum']);
		$this->assertSame('string', $properties['vaultRoleId']['type']);
		$this->assertSame('secret', $properties['vaultMount']['default']);
		$this->assertContains('outside-vault', $properties['kind']['enum']);
	}

	public function testTheSchemaVersionMovedOnSoTheImportAppliesIt(): void {
		$this->assertSame('1.5.0', $this->credentialSchema()['version']);
	}

	public function testTheCatalogueOffersAnOutsideVaultConnectionThatIsNeverProxied(): void {
		$entry = $this->settings('credential-providers.json')['providers']['outside-vault'];

		$this->assertSame('outside-vault', $entry['identifier']);
		$this->assertSame('outside-vault', $entry['kind']);
		$this->assertTrue($entry['inject_only']);
		$this->assertArrayNotHasKey('baseUrl', $entry);
		$this->assertArrayNotHasKey('allowRules', $entry);
	}
}
