<?php

/**
 * Unit tests for ConfigurationsController's import-result id mapping.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ConfigurationsController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Locks the mixed shape `ImportHandler` actually returns.
 *
 * `$result['objects']` is NOT uniformly entities: the handler appends an
 * ObjectEntity in two places and a bare id in two others. The controller mapped
 * it with `$obj->getId()`, so any descriptor carrying seed objects reached that
 * call on an int and died with a TypeError — an `Error`, which the endpoint's
 * `catch (Exception)` did not see, so it answered 500 with Nextcloud's HTML
 * error page instead of the JSON it documents.
 *
 * Measured across eighteen apps' registers: stackiq's was the only one that
 * failed, and it failed this way.
 */
class ConfigurationsControllerIdsOfTest extends TestCase {

	/**
	 * Invoke the private static `idsOf()` through reflection.
	 *
	 * @param mixed $items The import-result list.
	 *
	 * @return array<int, int|string> The mapped ids.
	 */
	private function idsOf(mixed $items): array {
		$method = new ReflectionMethod(ConfigurationsController::class, 'idsOf');
		$method->setAccessible(true);

		return $method->invoke(null, $items);
	}//end idsOf()

	/**
	 * A list of entities maps through `getId()`, which is the shape the
	 * registers and schemas legs always had.
	 *
	 * @return void
	 */
	public function testEntitiesMapThroughGetId(): void {
		$entity = new class {

			/**
			 * @return int The id.
			 */
			public function getId(): int {
				return 42;
			}
		};

		$this->assertSame([42], $this->idsOf([$entity]));

	}//end testEntitiesMapThroughGetId()

	/**
	 * A list of bare ids is taken as given. This is the case that crashed.
	 *
	 * @return void
	 */
	public function testBareIdsArePassedThrough(): void {
		$this->assertSame([7, 9], $this->idsOf([7, 9]));

	}//end testBareIdsArePassedThrough()

	/**
	 * A MIXED list is what the handler really produces, and it must not throw.
	 *
	 * Asserting on entities-only and ids-only separately would both have passed
	 * before the fix as well; the mix is the shape that distinguishes them.
	 *
	 * @return void
	 */
	public function testAMixedListMapsWithoutThrowing(): void {
		$entity = new class {

			/**
			 * @return string The uuid.
			 */
			public function getId(): string {
				return 'uuid-1';
			}
		};

		$this->assertSame(['uuid-1', 5], $this->idsOf([$entity, 5]));

	}//end testAMixedListMapsWithoutThrowing()

	/**
	 * Anything that is neither an entity nor an id is dropped rather than
	 * mapped to null, so the configuration's id list never holds a hole.
	 *
	 * @return void
	 */
	public function testUnusableEntriesAreDropped(): void {
		$this->assertSame([3], $this->idsOf([null, ['nested'], 3, new \stdClass()]));

	}//end testUnusableEntriesAreDropped()

	/**
	 * A non-array result is empty rather than fatal.
	 *
	 * @return void
	 */
	public function testANonArrayIsEmpty(): void {
		$this->assertSame([], $this->idsOf(null));
		$this->assertSame([], $this->idsOf('nope'));

	}//end testANonArrayIsEmpty()

	/**
	 * The endpoint catches `Throwable`, not `Exception`.
	 *
	 * A TypeError inside `import()` is a bug in OpenRegister rather than bad
	 * input, and answering it with a 500 HTML page hides which of the two it
	 * was: the caller cannot tell a malformed descriptor from a crash, and
	 * neither can the operator reading the response.
	 *
	 * @return void
	 */
	public function testTheImportCatchIsThrowable(): void {
		$source = (string)file_get_contents(
			(string)(new \ReflectionClass(ConfigurationsController::class))->getFileName()
		);

		$this->assertStringContainsString(
			'} catch (\Throwable $e) {',
			$source,
			'import() must catch Throwable so a bug answers in JSON, not an HTML 500'
		);

	}//end testTheImportCatchIsThrowable()

}//end class
