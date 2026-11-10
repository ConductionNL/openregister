<?php

/**
 * Golden: the refusals PurposeRegistry::requirePurpose() throws, field for field.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Audit;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ProcessingPurpose;
use OCA\OpenRegister\Db\ProcessingPurposeMapper;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
use OCA\OpenRegister\Service\Audit\PurposeRefusedException;
use OCA\OpenRegister\Service\Audit\PurposeRegistry;
use PHPUnit\Framework\TestCase;

final class PurposeRegistryRefusalGoldenTest extends TestCase {
	private function registry(string $status): PurposeRegistry {
		$purpose = new ProcessingPurpose();
		$purpose->setUuid('purpose-uuid');
		$purpose->setCode('brp-adresonderzoek');
		$purpose->setName('Adresonderzoek');
		$purpose->setActivity('VA-01');
		$purpose->setActivityUuid('activity-uuid');
		$purpose->setStatus($status);

		$activity = new Verwerkingsactiviteit();
		$activity->setUuid('activity-uuid');
		$activity->setCode('VA-01');
		$activity->setName('Adresonderzoek BRP');
		$activity->setStatus('actief');

		$purposeMapper = $this->createMock(ProcessingPurposeMapper::class);
		$purposeMapper->method('findAll')->willReturn([$purpose]);
		$purposeMapper->method('resolveReference')->willReturnCallback(
			static fn (string $reference): ?ProcessingPurpose => ($reference === 'brp-adresonderzoek' ? $purpose : null)
		);
		$activityMapper = $this->createMock(VerwerkingsactiviteitMapper::class);
		$activityMapper->method('resolveReference')->willReturn($activity);

		return new PurposeRegistry($purposeMapper, $activityMapper);
	}//end registry()

	/**
	 * @return array<string, mixed>
	 */
	private static function refusal(string $rule, ?string $purpose, string $message): array {
		return [
			'rule' => $rule,
			'purpose' => $purpose,
			'message' => $message,
			'body' => [
				'error' => 'PURPOSE_REFUSED',
				'rule' => $rule,
				'message' => $message,
				'purpose' => $purpose,
				'available' => ['brp-adresonderzoek'],
			],
		];
	}//end refusal()

	/**
	 * @return array<string, array{0: string, 1: ?string, 2: array<string, mixed>}>
	 */
	public static function refusals(): array {
		return [
			'no purpose named' => [
				ProcessingPurpose::STATUS_ACTIVE,
				null,
				self::refusal(
					rule: PurposeRefusedException::RULE_MISSING,
					purpose: null,
					message: 'This read needs a declared purpose. Name one in the X-Processing-Purpose header or the _purpose parameter.'
				),
			],
			'unknown purpose' => [
				ProcessingPurpose::STATUS_ACTIVE,
				'brp-onbekend',
				self::refusal(
					rule: PurposeRefusedException::RULE_UNKNOWN,
					purpose: 'brp-onbekend',
					message: 'Purpose "brp-onbekend" is not in the administered list.'
				),
			],
			'withdrawn purpose' => [
				ProcessingPurpose::STATUS_RETIRED,
				'brp-adresonderzoek',
				self::refusal(
					rule: PurposeRefusedException::RULE_RETIRED,
					purpose: 'brp-adresonderzoek',
					message: 'Purpose "brp-adresonderzoek" has been withdrawn and cannot carry a query.'
				),
			],
		];
	}//end refusals()

	/**
	 * @dataProvider refusals
	 */
	public function testEachRefusalIsPinned(string $status, ?string $named, array $expected): void {
		try {
			$this->registry($status)->requirePurpose($named);
			self::fail('the read must be refused');
		} catch (PurposeRefusedException $refusal) {
			$actual = [
				'rule' => $refusal->getRule(),
				'purpose' => $refusal->getPurpose(),
				'message' => $refusal->getMessage(),
				'body' => $refusal->toResponseBody(),
			];
			self::assertSame($expected, $actual);
		}
	}//end testEachRefusalIsPinned()

	public function testAnActivePurposeIsAllowed(): void {
		$resolved = $this->registry(ProcessingPurpose::STATUS_ACTIVE)->requirePurpose('brp-adresonderzoek');

		self::assertSame('brp-adresonderzoek', $resolved['purpose']->getCode());
		self::assertSame('activity-uuid', $resolved['activity']->getUuid());
	}//end testAnActivePurposeIsAllowed()
}//end class
