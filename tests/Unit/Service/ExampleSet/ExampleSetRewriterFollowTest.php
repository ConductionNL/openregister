<?php

/**
 * Characterisation of ExampleSetRewriter::follow(): which record values move to what a refresh wrote, and when the
 * record is saved.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetRewriter;
use OCA\OpenRegister\Service\ExampleSet\RewrittenObject;
use PHPUnit\Framework\TestCase;

/**
 * The record follows a rewritten object: its reference values and its `#references` change.
 */
class ExampleSetRewriterFollowTest extends TestCase {

	/**
	 * Follow one object and count the saves.
	 *
	 * @param array<string, mixed> $record  The record; updated.
	 * @param array<string, mixed> $written What the run wrote, by record key.
	 *
	 * @return int How often the record was saved.
	 */
	private static function follow(array &$record, array $written): int {
		if (isset($written['app/zaak']) === false) {
			return 0;
		}

		return (int) (new ExampleSetRewriter())->follow($record, new RewrittenObject('app/zaak', $written['app/zaak']));
	}//end follow()

	public function testOnlyTheReferencesChangeMovesAndTheRecordIsSaved(): void {
		$record = [
			'objects' => ['app/zaak' => ['refs' => ['status' => 'open']]],
			'changes' => ['app/zaak#references' => ['set' => [['path' => 'owner', 'old' => 'a', 'new' => 'b']]]],
		];

		$saves = self::follow($record, ['app/zaak' => ['status' => 'open', 'owner' => 'c']]);

		$this->assertSame(1, $saves);
		$this->assertSame('c', $record['changes']['app/zaak#references']['set'][0]['new']);
		$this->assertSame('a', $record['changes']['app/zaak#references']['set'][0]['old']);
		$this->assertSame(['status' => 'open'], $record['objects']['app/zaak']['refs']);
	}

	public function testOnlyTheReferenceValuesMoveAndTheRecordIsSaved(): void {
		$record = ['objects' => ['app/zaak' => ['refs' => ['status' => 'open', 'kept' => 1]]]];

		$saves = self::follow($record, ['app/zaak' => ['status' => 'closed']]);

		$this->assertSame(1, $saves);
		$this->assertSame(['status' => 'closed', 'kept' => 1], $record['objects']['app/zaak']['refs']);
	}

	public function testNothingWrittenOrNothingDifferentSavesNothing(): void {
		$record = ['objects' => ['app/zaak' => ['refs' => ['status' => 'open']]]];

		$this->assertSame(0, self::follow($record, []));
		$this->assertSame(0, self::follow($record, ['app/zaak' => ['status' => 'open']]));
		$this->assertSame(['status' => 'open'], $record['objects']['app/zaak']['refs']);
	}
}
