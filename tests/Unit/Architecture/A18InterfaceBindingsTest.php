<?php

/**
 * The A18 classes are reached through the interfaces next to them.
 *
 * Each class listed here had more than ten dependants. Their callers now type
 * against a narrow role interface, which ContractBindings aliases to the class.
 * A missing alias is a runtime 500 that no other unit test sees, so this test
 * pins the binding as well as the implementation and the files still naming
 * the class.
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

use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Party\PartySchemaCatalogueInterface;
use OCA\OpenRegister\Service\Party\PartyService;
use OCA\OpenRegister\Service\Party\TrustedPartyReaderInterface;
use OCA\OpenRegister\Service\Settings\ArchivalSettingsReaderInterface;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCA\OpenRegister\Service\Settings\RetentionSettingsReaderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class A18InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Interface => the class ContractBindings binds it to.
	 *
	 * @var array<class-string, class-string>
	 */
	private const INTERFACES = [
		ArchivalSettingsReaderInterface::class => ObjectRetentionHandler::class,
		PartySchemaCatalogueInterface::class => PartyService::class,
		RetentionSettingsReaderInterface::class => ObjectRetentionHandler::class,
		TrustedPartyReaderInterface::class => PartyService::class,
	];

	/**
	 * Class => the lib files (under lib/) still allowed to name it.
	 *
	 * The ObjectRetentionHandler callers kept here read or write more than the
	 * archival and retention settings, are changed by open pull requests, or
	 * resolve the handler from a container their tests key by the class. The
	 * example-set installers resolve PartyService from the container the same
	 * way. Either way each count stays at or below ten.
	 *
	 * @var array<class-string, list<string>>
	 */
	private const KEPT_CALLERS = [
		ObjectRetentionHandler::class => [
			'Service/Configuration/ConfigurationServiceRegistrar.php',
			'Service/ContractBindings.php',
			'Service/SettingsService.php',
		],
		PartyService::class => [
			'Notifications/Service/ExampleSet/ExampleSetTimelineEntries.php',
			'Service/ContractBindings.php',
			'Service/ExampleSet/ExampleSetAppointments.php',
			'Service/ExampleSet/ExampleSetAuditEntries.php',
			'Service/ExampleSet/ExampleSetCasePlans.php',
			'Service/ExampleSet/ExampleSetDeckCards.php',
			'Service/ExampleSet/ExampleSetEmailLinks.php',
			'Service/ExampleSet/ExampleSetGrants.php',
			'Service/ExampleSet/ExampleSetParties.php',
			'Service/ExampleSet/ExampleSetTalkConversations.php',
		],
	];

	/**
	 * One case per interface.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function interfaces(): array {
		$cases = [];
		foreach (array_keys(self::INTERFACES) as $contract) {
			$cases[$contract] = [$contract];
		}

		return $cases;
	}//end interfaces()

	/**
	 * One case per class.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function classes(): array {
		$cases = [];
		foreach (array_keys(self::KEPT_CALLERS) as $class) {
			$cases[$class] = [$class];
		}

		return $cases;
	}//end classes()

	/**
	 * The class implements the interface, and ContractBindings binds it.
	 *
	 * @param class-string $contract The interface.
	 *
	 * @return void
	 */
	#[DataProvider('interfaces')]
	public function testTheInterfaceIsImplementedAndBound(string $contract): void {
		$class = self::INTERFACES[$contract];
		$this->assertTrue(interface_exists($contract), $contract . ' exists');
		$this->assertContains($contract, class_implements($class), $class . ' implements ' . $contract);
		$this->assertArrayHasKey($contract, ContractBindings::BINDINGS);
		$this->assertSame($class, ContractBindings::BINDINGS[$contract]);
	}//end testTheInterfaceIsImplementedAndBound()

	/**
	 * Only the kept files name the class, so its fan-in stays at or below ten.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testOnlyTheKeptFilesNameTheClass(string $class): void {
		$naming = [];
		foreach ($this->libFiles() as $relative => $path) {
			if ($path !== $this->fileOf(class: $class)
				&& $this->namesClass(source: (string) file_get_contents($path), class: $class) === true
			) {
				$naming[] = $relative;
			}
		}

		$this->assertSame(self::KEPT_CALLERS[$class], $naming);
		$this->assertLessThanOrEqual(10, count($naming));
	}//end testOnlyTheKeptFilesNameTheClass()

	/**
	 * The file that declares the class.
	 *
	 * @param class-string $class The class.
	 *
	 * @return string
	 */
	private function fileOf(string $class): string {
		return (string) (new \ReflectionClass($class))->getFileName();
	}//end fileOf()

	/**
	 * Every lib PHP file, keyed by its path under lib/.
	 *
	 * @return array<string, string>
	 */
	private function libFiles(): array {
		$lib   = (string) realpath(self::LIB);
		$files = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib)) as $file) {
			if ($file->getExtension() === 'php') {
				$files[substr($file->getPathname(), strlen($lib) + 1)] = $file->getPathname();
			}
		}

		ksort($files);
		return $files;
	}//end libFiles()

	/**
	 * Whether code (not comments) in this source names the class.
	 *
	 * A short name counts when the class is imported or the file shares its namespace.
	 *
	 * @param string       $source The PHP source.
	 * @param class-string $class  The class.
	 *
	 * @return bool
	 */
	private function namesClass(string $source, string $class): bool {
		$short     = substr($class, (int) strrpos($class, '\\') + 1);
		$namespace = substr($class, 0, (int) strrpos($class, '\\'));
		$bare      = str_contains($source, 'use ' . $class . ';')
			|| str_contains($source, 'namespace ' . $namespace . ';');
		foreach (token_get_all($source) as $token) {
			if (is_array($token) === false) {
				continue;
			}

			if ($this->tokenNames(token: $token, class: $class) === true
				|| ($bare === true && $token[0] === T_STRING && $token[1] === $short)
			) {
				return true;
			}
		}

		return false;
	}//end namesClass()

	/**
	 * Whether a qualified-name token is the class.
	 *
	 * @param array{int, string, int} $token The token.
	 * @param class-string            $class The class.
	 *
	 * @return bool
	 */
	private function tokenNames(array $token, string $class): bool {
		if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) === false) {
			return false;
		}

		return ltrim($token[1], '\\') === $class;
	}//end tokenNames()
}//end class
