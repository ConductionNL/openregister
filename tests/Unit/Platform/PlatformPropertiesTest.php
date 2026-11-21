<?php

/**
 * What OpenRegister leaves to the platform, asserted from its own files.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * No own identity client, session table or storage client, and nothing to install beside Nextcloud.
 *
 * Reads files only; executes no app code.
 *
 * @coversNothing
 */
final class PlatformPropertiesTest extends TestCase {

	/**
	 * Clients that would mean the app signs people in itself (REQ-PPC-001).
	 *
	 * @var string
	 */
	private const IDENTITY_CLIENT = '/Jumbojett|OpenIDConnectClient|OneLogin\\\\Saml2|\bldap_connect\(|\bldap_bind\(/';

	/**
	 * Clients that would mean the app stores files outside Nextcloud's storage layer (REQ-PPC-006).
	 *
	 * @var string
	 */
	private const STORAGE_CLIENT = '/Aws\\\\S3|\bS3Client\b|OpenStack\\\\ObjectStore|Icewind\\\\SMB|\bsmbclient_/';

	/**
	 * A migration creating its own session or device table (REQ-PPC-003).
	 *
	 * @var string
	 */
	private const SESSION_TABLE = "/createTable\\(\\s*'[a-z_]*(session|device)[a-z_]*'/";

	/**
	 * The app root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The PHP files under a directory whose content matches a pattern.
	 *
	 * @param string $dir     The directory, relative to the app root.
	 * @param string $pattern The pattern.
	 *
	 * @return array<int, string> The matching files, relative to the app root.
	 */
	private function filesMatching(string $dir, string $pattern): array {
		$found = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root() . '/' . $dir));
		foreach ($files as $file) {
			if ($file->isFile() === true && $file->getExtension() === 'php'
				&& preg_match($pattern, (string)file_get_contents($file->getPathname())) === 1
			) {
				$found[] = substr($file->getPathname(), strlen($this->root()) + 1);
			}
		}

		sort($found);

		return $found;
	}//end filesMatching()

	/**
	 * No OpenID Connect, SAML or LDAP client in lib/: sign-in is Nextcloud's.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-001
	 */
	public function testNoOwnIdentityClient(): void {
		$this->assertSame([], $this->filesMatching(dir: 'lib', pattern: self::IDENTITY_CLIENT));
	}//end testNoOwnIdentityClient()

	/**
	 * No migration creates a session or device table: sessions are Nextcloud's.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-003
	 */
	public function testNoOwnSessionTable(): void {
		$this->assertSame([], $this->filesMatching(dir: 'lib/Migration', pattern: self::SESSION_TABLE));
	}//end testNoOwnSessionTable()

	/**
	 * No S3, Swift or SMB client in lib/: files go through Nextcloud's storage layer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-006
	 */
	public function testNoOwnStorageClient(): void {
		$this->assertSame([], $this->filesMatching(dir: 'lib', pattern: self::STORAGE_CLIENT));
	}//end testNoOwnStorageClient()

	/**
	 * info.xml asks for PHP, a database and Nextcloud, and nothing else to run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-007
	 */
	public function testTheAppDependsOnlyOnPhpADatabaseAndNextcloud(): void {
		$info = simplexml_load_string((string)file_get_contents($this->root() . '/appinfo/info.xml'));

		$kinds = [];
		foreach ($info->dependencies->children() as $dependency) {
			$kinds[] = $dependency->getName();
		}

		$kinds = array_values(array_unique($kinds));
		sort($kinds);

		$this->assertSame(['database', 'nextcloud', 'php'], $kinds);
	}//end testTheAppDependsOnlyOnPhpADatabaseAndNextcloud()
}//end class
