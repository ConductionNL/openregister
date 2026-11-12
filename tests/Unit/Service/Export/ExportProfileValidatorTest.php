<?php

/**
 * Unit test for ExportProfileValidator: what a full and a partial export
 * profile must carry, and each value it refuses with its own message.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Export;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ExportProfile;
use OCA\OpenRegister\Service\Export\ExportProfileValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Export\ExportProfileValidator
 */
class ExportProfileValidatorTest extends TestCase {

	/**
	 * A complete, valid profile.
	 *
	 * @var array<string, mixed>
	 */
	private const VALID = [
		'name'          => 'Zaken',
		'registerId'    => 3,
		'fields'        => ['title', 'status'],
		'valueMode'     => ExportProfile::MODE_RENDERED,
		'format'        => 'csv',
		'filters'       => ['status' => 'open'],
		'retentionDays' => 90,
	];

	/**
	 * The message validate() refuses with, or '' when it accepts.
	 *
	 * @param array<string, mixed> $data    The profile.
	 * @param bool                 $partial Whether it is a partial update.
	 *
	 * @return string
	 */
	private function refusal(array $data, bool $partial=false): string {
		try {
			(new ExportProfileValidator())->validate(data: $data, partial: $partial);
		} catch (InvalidArgumentException $e) {
			return $e->getMessage();
		}

		return '';
	}//end refusal()

	/**
	 * A complete profile, a minimal one and an empty partial update are accepted.
	 *
	 * @return void
	 */
	public function testValidProfilesAreAccepted(): void {
		$this->assertSame('', $this->refusal(data: self::VALID));
		$this->assertSame('', $this->refusal(data: ['name' => 'n', 'registerId' => 1, 'fields' => ['a']]));
		$this->assertSame('', $this->refusal(data: [], partial: true));
		$this->assertSame('', $this->refusal(data: ['retentionDays' => 1], partial: true));
	}//end testValidProfilesAreAccepted()

	/**
	 * Refused profiles and their messages.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool, 2: string}>
	 */
	public static function refused(): array {
		$v = self::VALID;
		return [
			'no name'                 => [array_diff_key($v, ['name' => 1]), false, 'An export profile needs a name.'],
			'no register'             => [array_diff_key($v, ['registerId' => 1]), false, 'An export profile needs a registerId.'],
			'no fields'               => [array_diff_key($v, ['fields' => 1]), false, 'An export profile needs a fields.'],
			'empty fields'            => [['fields' => []], true, 'An export profile needs at least one field.'],
			'fields not a list'       => [['fields' => 'title'], true, 'An export profile needs at least one field.'],
			'a blank field'           => [['fields' => ['title', ' ']], true, 'Every field in an export profile is a non-empty name.'],
			'a field that is no name' => [['fields' => [3]], true, 'Every field in an export profile is a non-empty name.'],
			'an unknown mode'         => [['valueMode' => 'raw'], true, 'An export profile writes stored values or rendered ones, and names which.'],
			'an unknown format'       => [['format' => 'xlsx'], true, 'An export profile writes csv or json.'],
			'a filter that is text'   => [['filters' => 'status=open'], true, 'The filter of an export profile is a map.'],
			'zero days'               => [['retentionDays' => 0], true, 'An export profile keeps its files for 1 to 90 days, or keeps them when no retention is given.'],
			'too many days'           => [['retentionDays' => 91], true, 'An export profile keeps its files for 1 to 90 days, or keeps them when no retention is given.'],
			'days as text'            => [['retentionDays' => '30'], true, 'An export profile keeps its files for 1 to 90 days, or keeps them when no retention is given.'],
		];
	}//end refused()

	/**
	 * Each faulty profile is refused with its own message.
	 *
	 * @param array<string, mixed> $data    The profile.
	 * @param bool                 $partial Whether it is a partial update.
	 * @param string               $message The refusal.
	 *
	 * @return void
	 *
	 * @dataProvider refused
	 */
	public function testAFaultyProfileIsRefused(array $data, bool $partial, string $message): void {
		$this->assertSame($message, $this->refusal(data: $data, partial: $partial));
	}//end testAFaultyProfileIsRefused()

	/**
	 * A partial update does not need the required fields a full profile does.
	 *
	 * @return void
	 */
	public function testAPartialUpdateNeedsNoRequiredFields(): void {
		$this->assertSame('', $this->refusal(data: ['format' => 'json'], partial: true));
		$this->assertSame('An export profile needs a name.', $this->refusal(data: ['format' => 'json'], partial: false));
	}//end testAPartialUpdateNeedsNoRequiredFields()
}//end class
