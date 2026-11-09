<?php

/**
 * Controllers are built and listed inside the Controller component.
 *
 * IntegrationRegistrar (lib/Service/Integration) used to construct the
 * IntegrationsController, the ObjectIntegrationsController and their four
 * Support bundles itself. It now hands that to IntegrationControllersRegistrar,
 * so no file outside lib/Controller names those classes. This test pins that
 * the integration registrar still registers both controllers, in the same
 * place, and that no outside file names them again.
 *
 * MaintenanceModeMiddleware (lib/Middleware) named OperationsConsoleController
 * in its always-reachable list; that list now lives in
 * MaintenanceReachableControllersInterface, next to the controller.
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

use OCA\OpenRegister\Controller\IntegrationControllersRegistrar;
use OCA\OpenRegister\Controller\IntegrationsController;
use OCA\OpenRegister\Controller\ObjectIntegrationsController;
use OCA\OpenRegister\Controller\OperationsConsoleController;
use OCA\OpenRegister\Controller\Support\IntegrationsControllerServices;
use OCA\OpenRegister\Controller\Support\MaintenanceReachableControllersInterface;
use OCA\OpenRegister\Controller\Support\ObjectIntegrationsAccess;
use OCA\OpenRegister\Controller\Support\ObjectIntegrationsControllerServices;
use OCA\OpenRegister\Controller\Support\ObjectIntegrationsDomain;
use OCA\OpenRegister\Service\Integration\IntegrationRegistrar;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class F09ControllerBoundaryTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * The classes no file outside lib/Controller may name.
	 *
	 * @var list<class-string>
	 */
	private const CLASSES = [
		IntegrationsController::class,
		ObjectIntegrationsController::class,
		IntegrationsControllerServices::class,
		ObjectIntegrationsAccess::class,
		ObjectIntegrationsControllerServices::class,
		ObjectIntegrationsDomain::class,
		OperationsConsoleController::class,
	];

	/**
	 * One case per class.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function classes(): array {
		$cases = [];
		foreach (self::CLASSES as $class) {
			$cases[$class] = [$class];
		}

		return $cases;
	}//end classes()

	/**
	 * The integration registrar still registers both controllers, through the Controller registrar.
	 *
	 * @return void
	 */
	public function testTheIntegrationRegistrarStillRegistersBothControllers(): void {
		$direct = new RecordingRegistrationContext();
		IntegrationControllersRegistrar::register(context: $direct);
		$this->assertSame(expected: [IntegrationsController::class, ObjectIntegrationsController::class], actual: $direct->services);

		$context = new RecordingRegistrationContext();
		IntegrationRegistrar::register(context: $context);
		$this->assertSame(
			expected: [IntegrationsController::class, ObjectIntegrationsController::class],
			actual: array_values(array_intersect($context->services, [IntegrationsController::class, ObjectIntegrationsController::class]))
		);
	}//end testTheIntegrationRegistrarStillRegistersBothControllers()

	/**
	 * The operations console is still the one controller maintenance mode never closes.
	 *
	 * @return void
	 */
	public function testTheOperationsConsoleStaysAlwaysReachable(): void {
		$this->assertSame(expected: [OperationsConsoleController::class], actual: MaintenanceReachableControllersInterface::ALWAYS_REACHABLE);
	}//end testTheOperationsConsoleStaysAlwaysReachable()

	/**
	 * No lib file outside the class's component names the class.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testNoOutsideFileNamesTheClass(string $class): void {
		$naming = [];
		foreach ($this->outsideFiles(class: $class) as $relative => $path) {
			if ($this->namesClass(source: (string) file_get_contents($path), class: $class) === true) {
				$naming[] = $relative;
			}
		}

		$this->assertSame(expected: [], actual: $naming, message: 'Build or list it inside lib/Controller instead.');
	}//end testNoOutsideFileNamesTheClass()

	/**
	 * Every lib PHP file outside the component of the class, keyed by its path under lib/.
	 *
	 * @param class-string $class The class.
	 *
	 * @return array<string, string>
	 */
	private function outsideFiles(string $class): array {
		$component = explode('\\', $class)[2];
		$lib       = (string) realpath(self::LIB);
		$files     = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib)) as $file) {
			$relative = substr($file->getPathname(), strlen($lib) + 1);
			if ($file->getExtension() === 'php' && str_starts_with($relative, $component . '/') === false) {
				$files[$relative] = $file->getPathname();
			}
		}

		ksort($files);
		return $files;
	}//end outsideFiles()

	/**
	 * Whether code (not comments) in this source names the class.
	 *
	 * A bare short name counts when the class is imported, or when the file
	 * sits in the class's own namespace.
	 *
	 * @param string       $source The PHP source.
	 * @param class-string $class  The class.
	 *
	 * @return bool
	 */
	private function namesClass(string $source, string $class): bool {
		$short     = substr($class, (int) strrpos($class, '\\') + 1);
		$namespace = substr($class, 0, (int) strrpos($class, '\\'));
		$bare      = str_contains($source, 'use ' . $class . ';') || str_contains($source, 'namespace ' . $namespace . ';');
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
