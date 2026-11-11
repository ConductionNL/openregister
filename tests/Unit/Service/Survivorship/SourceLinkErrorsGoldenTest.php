<?php

/**
 * Golden coverage for the `sourceLink` errors of the merge and survivorship annotations.
 *
 * Both validators check the same reverse-FK `sourceLink` block under their own
 * annotation name; this pins the exact codes and messages each one reports.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Survivorship
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#1.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Survivorship;

use OCA\OpenRegister\Service\Merge\MergeAnnotationValidator;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * Locks the sourceLink errors of both annotations.
 */
final class SourceLinkErrorsGoldenTest extends TestCase {

	/**
	 * The sourceLink errors the merge validator reports.
	 *
	 * @param mixed $sourceLink The sourceLink value.
	 *
	 * @return array<int, array{code: string, message: string}> The errors.
	 */
	private function mergeErrors(mixed $sourceLink): array {
		$errors = (new MergeAnnotationValidator())->validate(schema: ['x-openregister-merge' => ['sourceLink' => $sourceLink]]);

		return array_values(array_filter($errors, static fn (array $e): bool => str_contains($e['code'], 'source-link')));
	}//end mergeErrors()

	/**
	 * The sourceLink errors the survivorship validator reports.
	 *
	 * @param mixed $sourceLink The sourceLink value.
	 *
	 * @return array<int, array{code: string, message: string}> The errors.
	 */
	private function survivorshipErrors(mixed $sourceLink): array {
		$errors = (new SurvivorshipAnnotationValidator())->validate(
			schema: ['properties' => [], 'x-openregister-survivorship' => ['sourceLink' => $sourceLink, 'sourceLinkField' => 'src']]
		);

		return array_values(array_filter($errors, static fn (array $e): bool => str_contains($e['code'], 'source-link')));
	}//end survivorshipErrors()

	/**
	 * Each validator reports its own code and message for every sourceLink shape.
	 *
	 * @return void
	 */
	public function testEverySourceLinkShape(): void {
		foreach (['merge' => [$this, 'mergeErrors'], 'survivorship' => [$this, 'survivorshipErrors']] as $name => $errorsOf) {
			$this->assertSame([], $errorsOf(null), $name);
			$this->assertSame([], $errorsOf([]), $name);
			$this->assertSame([], $errorsOf(['mode' => 'embedded']), $name);
			$this->assertSame([], $errorsOf(['mode' => 'reverseFk', 'sourceSchema' => 's', 'referenceField' => 'f']), $name);
			$this->assertSame(
				[['code' => $name . '.source-link-not-object', 'message' => 'x-openregister-' . $name . ' "sourceLink" must be an object.']],
				$errorsOf('x'),
				$name
			);
			$incomplete = [[
				'code' => $name . '.source-link-reverse-fk-incomplete',
				'message' => 'x-openregister-' . $name . ' reverseFk "sourceLink" requires "sourceSchema" and "referenceField".',
			]];
			$this->assertSame($incomplete, $errorsOf(['mode' => 'reverseFk', 'sourceSchema' => 's']), $name);
			$this->assertSame($incomplete, $errorsOf(['mode' => 'reverseFk', 'referenceField' => 'f']), $name);
			$this->assertSame($incomplete, $errorsOf(['mode' => 'reverseFk', 'sourceSchema' => '', 'referenceField' => '']), $name);
		}
	}//end testEverySourceLinkShape()
}//end class
