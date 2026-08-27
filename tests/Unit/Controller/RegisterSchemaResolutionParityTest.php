<?php

declare(strict_types=1);

/**
 * Register/schema resolution must behave identically in every controller.
 *
 * openregister#2820 was fixed in `ObjectsController` (#2858, #2860) and stayed
 * broken in `BulkController`, because each held a private copy of the same
 * helper and only one was edited. The symptom: `GET /api/objects/19/9476`
 * returned 200 while `POST /api/bulk/19/9475/save` answered
 * `404 Register not found: '19'` for that same register — the bug surviving its
 * own fix in the copy nobody touched.
 *
 * These tests assert the shared trait exists and that BOTH controllers route
 * through it. A future third controller that pastes its own copy fails here,
 * which is the only durable defence: fixing the second copy would have left the
 * same trap set for the third.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\BulkController;
use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Controller\Trait\ResolvesRegisterAndSchemaTrait;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Parity of the `{register}/{schema}` resolution across controllers.
 */
class RegisterSchemaResolutionParityTest extends TestCase {
	/**
	 * Controllers that resolve a `{register}/{schema}` path pair.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function controllerProvider(): array {
		return [
			'ObjectsController' => [ObjectsController::class],
			'BulkController' => [BulkController::class],
		];
	}

	/**
	 * Every such controller uses the shared trait.
	 *
	 * @param string $controller the controller class
	 *
	 * @dataProvider controllerProvider
	 *
	 * @return void
	 */
	public function testEveryResolvingControllerUsesTheSharedTrait(string $controller): void {
		$traits = [];
		for ($class = new ReflectionClass($controller); $class !== false; $class = $class->getParentClass()) {
			$traits = array_merge($traits, $class->getTraitNames());
		}

		$this->assertContains(
			ResolvesRegisterAndSchemaTrait::class,
			$traits,
			$controller . ' resolves register/schema without the shared trait — that is how '
			. 'openregister#2820 survived its own fix in BulkController.'
		);
	}

	/**
	 * No controller keeps a private copy of the resolution logic.
	 *
	 * The tell is `clearCurrents()` appearing in a controller body: that call is
	 * the leak fix, and it belongs to exactly one implementation. If it shows up
	 * in a controller file, someone has pasted the helper again — and the next
	 * fix will once more reach only one of the copies.
	 *
	 * @return void
	 */
	public function testNoControllerHoldsItsOwnCopyOfTheResolution(): void {
		$dir = dirname(__DIR__, 3) . '/lib/Controller';
		$offenders = [];

		foreach (glob($dir . '/*.php') ?: [] as $file) {
			$source = file_get_contents($file);
			if ($source !== false && str_contains($source, 'clearCurrents(') === true) {
				$offenders[] = basename($file);
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'These controllers carry their own resolution copy instead of the trait: '
			. implode(', ', $offenders)
		);
	}

	/**
	 * The trait actually implements the two load-bearing behaviours.
	 *
	 * Asserted on the trait's source rather than by driving ObjectService,
	 * which needs the full container. Crude, but it fails if either behaviour is
	 * deleted, and both are the reason #2820 took a day to find:
	 * `clearCurrents()` stops the leak, and the entity comparison stops a schema
	 * failure being reported as a missing register.
	 *
	 * @return void
	 */
	public function testTheTraitKeepsBothLoadBearingBehaviours(): void {
		$file = (new ReflectionClass(ResolvesRegisterAndSchemaTrait::class))->getFileName();
		$this->assertIsString($file);
		$source = file_get_contents((string)$file);
		$this->assertIsString($source);

		$this->assertStringContainsString(
			'clearCurrents()',
			$source,
			'the leaked-context clear is gone — an earlier caller\'s pending schema ref '
			. 'will be re-resolved inside this call\'s register again'
		);
		$this->assertStringContainsString(
			'SchemaNotFoundException',
			$source,
			'the discriminator is gone — a schema failure will be reported as a missing '
			. 'register, which is what made #2820 cost hours'
		);
	}
}
