<?php

/**
 * The interfaces lane K07 added for the Notifications component are bound in NotificationsWiring.
 *
 * A missing alias is a runtime 500 (or, for an optional writer, a silently refused part) that no
 * other unit test sees, so this test pins each binding, that the bound class implements the
 * interface, and that the callers outside lib/Notifications name only the interface.
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

use OCA\OpenRegister\Notifications\NotificationsWiring;
use OCA\OpenRegister\Notifications\Service\ExampleSet\ExampleSetTimelineEntries;
use OCA\OpenRegister\Notifications\Service\ExampleSet\ExampleSetTimelineRowsInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class K07InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Each new interface and the class it is bound to.
	 *
	 * @return array<string, array{0: class-string, 1: class-string}>
	 */
	public static function bindings(): array {
		return [
			'example-set timeline entries' => [ExampleSetTimelineRowsInterface::class, ExampleSetTimelineEntries::class],
		];
	}//end bindings()

	/**
	 * The interface is bound in NotificationsWiring to the class, and the class implements it.
	 *
	 * @param class-string $contract The interface.
	 * @param class-string $service  The class.
	 *
	 * @return void
	 */
	#[DataProvider('bindings')]
	public function testBindingIsPresentAndImplemented(string $contract, string $service): void {
		$this->assertArrayHasKey($contract, NotificationsWiring::BINDINGS);
		$this->assertSame($service, NotificationsWiring::BINDINGS[$contract]);
		$this->assertTrue(is_subclass_of($service, $contract), $service . ' must implement ' . $contract);
	}//end testBindingIsPresentAndImplemented()

	/**
	 * Outside lib/Notifications, the callers name the interface, not the Notifications classes.
	 *
	 * @return array<string, array{0: string, 1: list<string>}>
	 */
	public static function callers(): array {
		return [
			'example-set history row kinds' => ['Service/ExampleSet/ExampleSetHistoryRowKinds.php', ['ExampleSetTimelineEntries']],
		];
	}//end callers()

	/**
	 * The caller does not name a hidden class.
	 *
	 * @param string       $file    The caller, relative to lib/.
	 * @param list<string> $classes The Notifications classes it must not name.
	 *
	 * @return void
	 */
	#[DataProvider('callers')]
	public function testCallerNamesNoHiddenClass(string $file, array $classes): void {
		$source = (string) file_get_contents(self::LIB . '/' . $file);
		foreach ($classes as $class) {
			$this->assertDoesNotMatchRegularExpression('/\b' . $class . '\b/', $source, $file . ' names ' . $class);
		}
	}//end testCallerNamesNoHiddenClass()
}//end class
