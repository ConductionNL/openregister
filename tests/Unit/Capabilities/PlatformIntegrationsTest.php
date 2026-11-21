<?php

/**
 * PlatformIntegrations: the ten platform integrations and which are on.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Capabilities
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-capability/specs/deep-link-registry/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Capabilities;

use Composer\Autoload\ClassLoader;
use OCA\OpenRegister\Capabilities\PlatformIntegrations;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Capabilities\PlatformIntegrations
 */
class PlatformIntegrationsTest extends TestCase {

	/**
	 * The catalogue names the ten integrations of the programme, each with a switch.
	 *
	 * @return void
	 */
	public function testTheTenIntegrationsEachHaveASwitch(): void {
		$switches = PlatformIntegrations::switches();

		self::assertSame(
			[
				'caldav-backend', 'capability', 'cloud-federation-provider', 'collaboration-resources', 'comments-entity',
				'reference-provider', 'search-provider', 'share-provider', 'team-resource-provider', 'user-migrator',
			],
			array_keys($switches)
		);
		self::assertContainsOnly('bool', $switches);
		self::assertTrue($switches['capability']);
		self::assertFalse($switches['user-migrator']);
	}//end testTheTenIntegrationsEachHaveASwitch()

	/**
	 * The enabled list is exactly the integrations with an implementation.
	 *
	 * @return void
	 */
	public function testEnabledIsTheIntegrationsWithAnImplementation(): void {
		$expected = array_keys(array_filter(PlatformIntegrations::IMPLEMENTATIONS, static fn (?string $class): bool => $class !== null));

		self::assertSame($expected, PlatformIntegrations::enabled());
		self::assertContains('search-provider', PlatformIntegrations::enabled());
		self::assertNotContains('share-provider', PlatformIntegrations::enabled());
	}//end testEnabledIsTheIntegrationsWithAnImplementation()

	/**
	 * Every implementation named is a class in OpenRegister's own source.
	 *
	 * Checked through the autoloader's file map rather than by loading the
	 * class, which would need the Nextcloud interface it implements.
	 *
	 * @return void
	 */
	public function testEveryNamedImplementationExists(): void {
		$lib = (string)realpath(__DIR__.'/../../../lib');

		foreach (array_filter(PlatformIntegrations::IMPLEMENTATIONS) as $name => $class) {
			$file = null;
			foreach (ClassLoader::getRegisteredLoaders() as $loader) {
				$file = $loader->findFile($class);
				if ($file !== false && $file !== null) {
					break;
				}
			}

			self::assertIsString($file, $name.' names '.$class.', which has no file.');
			self::assertStringStartsWith($lib.'/', (string)realpath($file), $name.' must be implemented in OpenRegister.');
		}
	}//end testEveryNamedImplementationExists()
}//end class
