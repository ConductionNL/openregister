<?php

/**
 * The H12 Notifications classes are reached through an interface, or registered by their own component.
 *
 * TaskObjectAdapters was named by a listener and by the write-back refusal notice, which
 * each built the adapter and called the dispatcher themselves. Both now hand the transition
 * to TaskTransitionNotifierInterface, bound in NotificationsWiring. TimelineEntriesProvider
 * was registered by the Search component's registrar; NotificationsWiring now registers it.
 * A missing alias or registration is a runtime failure no other unit test sees, so this test
 * pins the binding, the registration and the absence of outside callers.
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
use OCA\OpenRegister\Notifications\Search\TimelineEntriesProvider;
use OCA\OpenRegister\Notifications\Service\Notification\TaskObjectAdapters;
use OCA\OpenRegister\Notifications\Service\Notification\TaskTransitionNotifier;
use OCA\OpenRegister\Notifications\Service\Notification\TaskTransitionNotifierInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class H12InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Interface => the class NotificationsWiring binds it to.
	 *
	 * @var array<class-string, class-string>
	 */
	private const BINDINGS = [
		TaskTransitionNotifierInterface::class => TaskTransitionNotifier::class,
	];

	/**
	 * Notifications classes no lib file outside lib/Notifications names.
	 *
	 * @var list<class-string>
	 */
	private const HIDDEN = [
		TaskObjectAdapters::class,
		TaskTransitionNotifier::class,
		TimelineEntriesProvider::class,
	];

	/**
	 * One case per binding.
	 *
	 * @return array<string, array{class-string, class-string}>
	 */
	public static function bindings(): array {
		$cases = [];
		foreach (self::BINDINGS as $contract => $class) {
			$cases[$contract] = [$contract, $class];
		}

		return $cases;
	}//end bindings()

	/**
	 * One case per hidden class.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function hidden(): array {
		$cases = [];
		foreach (self::HIDDEN as $class) {
			$cases[$class] = [$class];
		}

		return $cases;
	}//end hidden()

	/**
	 * The class implements its interface and NotificationsWiring binds the two.
	 *
	 * @param class-string $contract The interface.
	 * @param class-string $class    The class.
	 *
	 * @return void
	 */
	#[DataProvider('bindings')]
	public function testTheInterfaceIsBoundToTheClass(string $contract, string $class): void {
		$this->assertTrue(interface_exists($contract), $contract . ' exists');
		$this->assertTrue(is_a($class, $contract, true), $class . ' implements ' . $contract);
		$this->assertArrayHasKey($contract, NotificationsWiring::BINDINGS);
		$this->assertSame($class, NotificationsWiring::BINDINGS[$contract]);
	}//end testTheInterfaceIsBoundToTheClass()

	/**
	 * NotificationsWiring registers the timeline entries search provider.
	 *
	 * @return void
	 */
	public function testTheTimelineEntriesProviderIsRegisteredByItsComponent(): void {
		$registered = [];
		$context    = $this->createMock(IRegistrationContext::class);
		$context->method('registerSearchProvider')->willReturnCallback(
			static function (string $class) use (&$registered): void {
				$registered[] = $class;
			}
		);

		NotificationsWiring::register($context);

		$this->assertSame([TimelineEntriesProvider::class], $registered);
	}//end testTheTimelineEntriesProviderIsRegisteredByItsComponent()

	/**
	 * No lib file outside lib/Notifications names the class.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('hidden')]
	public function testNoOutsideFileNamesTheClass(string $class): void {
		$short  = substr($class, (int) strrpos($class, '\\') + 1);
		$lib    = (string) realpath(self::LIB);
		$naming = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib)) as $file) {
			$relative = substr($file->getPathname(), strlen($lib) + 1);
			if ($file->getExtension() !== 'php' || str_starts_with($relative, 'Notifications/') === true) {
				continue;
			}

			$code = $this->code(source: (string) file_get_contents($file->getPathname()));
			if (str_contains($code, $class) === true || preg_match('/\b' . $short . '\b/', $code) === 1) {
				$naming[] = $relative;
			}
		}

		sort($naming);
		$this->assertSame([], $naming, 'Type against the interface instead of ' . $class);
	}//end testNoOutsideFileNamesTheClass()

	/**
	 * The source without its comments.
	 *
	 * @param string $source The PHP source.
	 *
	 * @return string
	 */
	private function code(string $source): string {
		$code = '';
		foreach (token_get_all($source) as $token) {
			if (is_array($token) === true && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) === true) {
				continue;
			}

			$code .= (is_array($token) === true ? $token[1] : $token);
		}

		return $code;
	}//end code()
}//end class
