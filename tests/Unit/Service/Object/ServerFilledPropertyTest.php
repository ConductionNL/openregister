<?php

/**
 * Unit tests for ServerFilledProperty.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Form\FormDestinationValidator;
use OCA\OpenRegister\Service\Object\ServerFilledProperty;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one definition of a property the server fills, and the form's answer is the same one.
 */
final class ServerFilledPropertyTest extends TestCase {

	/**
	 * Properties and whether the server fills them.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public static function properties(): array {
		return [
			'plain'                => [['type' => 'string'], false],
			'default'              => [['type' => 'string', 'default' => 'x'], true],
			'null default'         => [['type' => 'string', 'default' => null], true],
			'computed'             => [['type' => 'string', 'computed' => ['expression' => 1]], true],
			'generated identifier' => [['type' => 'string', 'x-openregister-generated' => ['pattern' => '{n}']], true],
			'serverSet marker'     => [['type' => 'string', 'x-openregister' => ['serverSet' => true]], true],
			'serverSet not true'   => [['type' => 'string', 'x-openregister' => ['serverSet' => 'yes']], false],
			'serverSet false'      => [['type' => 'string', 'x-openregister' => ['serverSet' => false]], false],
			'dialect not a block'  => [['type' => 'string', 'x-openregister' => true], false],
			'other marker only'    => [['type' => 'string', 'x-openregister' => ['confirmation' => true]], false],
		];
	}//end properties()

	/**
	 * The save path and the form give the same answer for every property.
	 *
	 * @param array<string, mixed> $property The schema property.
	 * @param bool                 $expected Whether the server fills it.
	 *
	 * @return void
	 */
	#[DataProvider('properties')]
	public function testTheServerFilledPropertiesAreAnsweredOnce(array $property, bool $expected): void {
		self::assertSame($expected, ServerFilledProperty::isServerFilled(property: $property));
		self::assertSame($expected, FormDestinationValidator::isServerFilled(property: $property));
	}//end testTheServerFilledPropertiesAreAnsweredOnce()

	/**
	 * The form's dialect and marker names are the save path's.
	 *
	 * @return void
	 */
	public function testTheFormMarkerNamesAreTheSavePathOnes(): void {
		self::assertSame('x-openregister', ServerFilledProperty::DIALECT);
		self::assertSame('serverSet', ServerFilledProperty::MARKER_SERVER_SET);
		self::assertSame(ServerFilledProperty::DIALECT, FormDestinationValidator::DIALECT);
		self::assertSame(ServerFilledProperty::MARKER_SERVER_SET, FormDestinationValidator::MARKER_SERVER_SET);
	}//end testTheFormMarkerNamesAreTheSavePathOnes()
}//end class
