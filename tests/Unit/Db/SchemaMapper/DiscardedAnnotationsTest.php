<?php

/**
 * OpenRegister DiscardedAnnotationsTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\SchemaMapper;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\DiscardedAnnotations;
use PHPUnit\Framework\TestCase;

/**
 * One entry per discarded family, named without the annotation prefix.
 *
 * @covers \OCA\OpenRegister\Db\SchemaMapper\DiscardedAnnotations
 * @uses   \OCA\OpenRegister\Db\Schema
 *
 * @spec openspec/changes/aggregation-dialect-repair/specs/annotation-validation-surfacing/spec.md
 */
final class DiscardedAnnotationsTest extends TestCase {

	/**
	 * Two families on one schema are two entries, in order, with their own messages.
	 *
	 * @return void
	 */
	public function testEachFamilyIsItsOwnEntry(): void {
		$schema = new Schema();
		$schema->setSlug('meeting');
		$discarded = new DiscardedAnnotations();

		$discarded->record(family: 'x-openregister-calculations', schema: $schema, errors: [['code' => 'a', 'message' => 'Unknown op "pow".']]);
		$discarded->record(family: 'x-openregister-quality', schema: $schema, errors: [['message' => 'Weights must sum to 1.'], ['message' => 'Unknown rule.']]);
		$discarded->record(family: 'hinge', schema: $schema, errors: []);

		self::assertSame(
			[
				['family' => 'calculations', 'schema' => 'meeting', 'messages' => ['Unknown op "pow".']],
				['family' => 'quality', 'schema' => 'meeting', 'messages' => ['Weights must sum to 1.', 'Unknown rule.']],
			],
			$discarded->all()
		);
	}//end testEachFamilyIsItsOwnEntry()

	/**
	 * Nothing recorded is an empty list, and a schema without a slug is named by an empty string.
	 *
	 * @return void
	 */
	public function testAnEmptyListAndAnUnsluggedSchema(): void {
		$discarded = new DiscardedAnnotations();
		self::assertSame([], $discarded->all());

		$discarded->record(family: 'hinge', schema: new Schema(), errors: [['message' => 'Bad lens.']]);

		self::assertSame([['family' => 'hinge', 'schema' => '', 'messages' => ['Bad lens.']]], $discarded->all());
	}//end testAnEmptyListAndAnUnsluggedSchema()
}//end class
