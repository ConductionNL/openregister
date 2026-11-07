<?php

/**
 * OpenRegister - golden test for the not-supplied refusal rules.
 *
 * Pins which code each record entry is refused with, and that the first rule
 * to fire wins, so the refusal rules can be reorganised without changing what
 * a caller is told.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\NotSuppliedHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Object\NotSuppliedHandler
 * @uses \OCA\OpenRegister\Db\Schema
 */
final class NotSuppliedHandlerRefusalGoldenTest extends TestCase {

	/**
	 * A schema declaring bsn and naam, administering the given reasons.
	 *
	 * @param array<string, string> $reasons The administered reasons.
	 *
	 * @return Schema The schema.
	 */
	private static function schema(array $reasons): Schema {
		$schema = new Schema();
		$schema->setProperties(['bsn' => ['type' => 'string'], 'naam' => ['type' => 'string']]);
		if ($reasons !== []) {
			$schema->setConfiguration([Schema::NOT_SUPPLIED_REASONS_ANNOTATION => $reasons]);
		}

		return $schema;
	}//end schema()

	/**
	 * One case per rule, plus the precedence between them.
	 *
	 * @return array<string, array{0: array, 1: array<string, string>, 2: list<string>}>
	 */
	public static function cases(): array {
		$known = ['onbekend' => 'Unknown'];

		return [
			'accepted' => [['bsn' => 'onbekend'], $known, []],
			'no reason' => [['bsn' => ''], $known, ['bsn:not-supplied-without-a-reason']],
			'reason not a string' => [['bsn' => 7], $known, ['bsn:not-supplied-without-a-reason']],
			'unknown property' => [['postcode' => 'onbekend'], $known, ['postcode:not-supplied-unknown-property']],
			'answered' => [['naam' => 'onbekend'], $known, ['naam:not-supplied-and-answered']],
			'no reasons administered' => [['bsn' => 'onbekend'], [], ['bsn:not-supplied-no-reasons-administered']],
			'unknown reason' => [['bsn' => 'geheim'], $known, ['bsn:not-supplied-unknown-reason']],
			'no reason beats unknown property' => [['postcode' => ''], [], ['postcode:not-supplied-without-a-reason']],
			'unknown property beats no reasons' => [['postcode' => 'geheim'], [], ['postcode:not-supplied-unknown-property']],
			'answered beats unknown reason' => [['naam' => 'geheim'], $known, ['naam:not-supplied-and-answered']],
			'answered beats no reasons' => [['naam' => 'geheim'], [], ['naam:not-supplied-and-answered']],
			'one refusal per entry, in record order' => [
				['bsn' => 'geheim', 'naam' => 'onbekend', 'postcode' => ''],
				$known,
				['bsn:not-supplied-unknown-reason', 'naam:not-supplied-and-answered', 'postcode:not-supplied-without-a-reason'],
			],
		];
	}//end cases()

	/**
	 * Each record entry is refused with the first rule that fires, or accepted.
	 *
	 * @param array                 $record   The not-supplied record.
	 * @param array<string, string> $reasons  The administered reasons.
	 * @param list<string>          $expected property:code per refusal.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testTheRefusalCodes(array $record, array $reasons, array $expected): void {
		$violations = (new NotSuppliedHandler())->validate(
			object: ['naam' => 'Jansen', Schema::NOT_SUPPLIED_KEY => $record],
			schema: self::schema(reasons: $reasons)
		);

		$actual = array_map(static fn (array $v): string => $v['property'] . ':' . $v['code'], $violations);
		$this->assertSame($expected, $actual);
	}//end testTheRefusalCodes()

	/**
	 * The unknown-reason refusal names the reasons the schema accepts.
	 *
	 * @return void
	 */
	public function testTheUnknownReasonMessageListsTheAcceptedReasons(): void {
		$violations = (new NotSuppliedHandler())->validate(
			object: [Schema::NOT_SUPPLIED_KEY => ['bsn' => 'geheim']],
			schema: self::schema(reasons: ['onbekend' => 'Unknown', 'nvt' => 'Not applicable'])
		);

		$this->assertSame("'geheim' is not a reason this schema administers. It accepts: onbekend, nvt.", $violations[0]['message']);
	}//end testTheUnknownReasonMessageListsTheAcceptedReasons()
}//end class
