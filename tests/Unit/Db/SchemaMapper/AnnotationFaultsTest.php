<?php

/**
 * OpenRegister AnnotationFaultsTest
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

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\AnnotationFaults;
use PHPUnit\Framework\TestCase;

/**
 * How a schema annotation's errors refuse a save, and its unknown-key notice.
 *
 * @covers \OCA\OpenRegister\Db\SchemaMapper\AnnotationFaults
 * @uses   \OCA\OpenRegister\Db\Schema
 */
final class AnnotationFaultsTest extends TestCase {
	/**
	 * Faults for the x-openregister-demo key of a schema slugged `zaak`.
	 *
	 * @return AnnotationFaults
	 */
	private function faults(): AnnotationFaults {
		$schema = new Schema();
		$schema->setSlug('zaak');

		return new AnnotationFaults(schema: $schema, key: 'x-openregister-demo');
	}//end faults()

	/**
	 * The message the call refuses with.
	 *
	 * @param callable $call The call.
	 *
	 * @return string
	 */
	private static function refusal(callable $call): string {
		try {
			$call();
		} catch (Exception $exception) {
			return $exception->getMessage();
		}

		return 'not refused';
	}//end refusal()

	/**
	 * Each refusal joins the messages behind the key, plain, coded or bracketed.
	 *
	 * @return void
	 */
	public function testErrorsRefuseWithTheirMessages(): void {
		$errors = [['code' => 'a-bad', 'message' => 'A is bad.'], ['code' => 'b-bad', 'message' => 'B is bad.']];
		$faults = $this->faults();

		self::assertSame('x-openregister-demo: A is bad. B is bad.', self::refusal(fn () => $faults->refuse($errors)));
		self::assertSame('x-openregister-demo: a-bad: A is bad. b-bad: B is bad.', self::refusal(fn () => $faults->refuseCoded($errors)));
		self::assertSame(
			'Invalid x-openregister-demo declaration: [a-bad] A is bad. [b-bad] B is bad.',
			self::refusal(fn () => $faults->refuseInvalid($errors))
		);
		self::assertSame('x-openregister-demo: plain', self::refusal(fn () => $faults->refuseWith(['plain'])));
	}//end testErrorsRefuseWithTheirMessages()

	/**
	 * No errors refuse nothing.
	 *
	 * @return void
	 */
	public function testNoErrorsRefuseNothing(): void {
		$faults = $this->faults();

		self::assertSame('not refused', self::refusal(fn () => $faults->refuse([])));
		self::assertSame('not refused', self::refusal(fn () => $faults->refuseCoded([])));
		self::assertSame('not refused', self::refusal(fn () => $faults->refuseInvalid([])));
		self::assertSame('not refused', self::refusal(fn () => $faults->refuseWith([])));
	}//end testNoErrorsRefuseNothing()

	/**
	 * The unknown-keys notice counts the warnings and names the schema slug.
	 *
	 * @return void
	 */
	public function testTheUnknownKeysNotice(): void {
		self::assertSame(
			'[OpenRegister.SchemaMapper] Ignored 2 unknown x-openregister-demo key(s) on schema "zaak": Key foo is unknown. Key bar is unknown.',
			$this->faults()->unknownKeysNotice([['message' => 'Key foo is unknown.'], ['message' => 'Key bar is unknown.']])
		);
		self::assertSame(
			'[OpenRegister.SchemaMapper] Ignored 0 unknown x-openregister-demo key(s) on schema "": ',
			(new AnnotationFaults(schema: new Schema(), key: 'x-openregister-demo'))->unknownKeysNotice([])
		);
	}//end testTheUnknownKeysNotice()
}//end class
