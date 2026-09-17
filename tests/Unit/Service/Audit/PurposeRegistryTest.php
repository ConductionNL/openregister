<?php

/**
 * Unit tests for the administered purpose list and its refusals.
 *
 * Covers the spec delta's "a person search names its grondslag" and "an
 * unbound purpose is refused": the four refusals named apart, and the binding
 * read live against the processing register so an archived activity unbinds
 * the purposes that name it instead of leaving a stale uuid looking bound.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
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

final class PurposeRegistryTest extends TestCase {
	private function purpose(
		string $code = 'brp-adresonderzoek',
		string $activity = 'VA-01',
		string $status = ProcessingPurpose::STATUS_ACTIVE,
	): ProcessingPurpose {
		$purpose = new ProcessingPurpose();
		$purpose->setUuid('purpose-uuid');
		$purpose->setCode($code);
		$purpose->setName('Adresonderzoek');
		$purpose->setActivity($activity);
		$purpose->setActivityUuid('activity-uuid');
		$purpose->setStatus($status);

		return $purpose;
	}//end purpose()

	private function activity(string $status = 'actief'): Verwerkingsactiviteit {
		$activity = new Verwerkingsactiviteit();
		$activity->setUuid('activity-uuid');
		$activity->setCode('VA-01');
		$activity->setName('Adresonderzoek BRP');
		$activity->setStatus($status);

		return $activity;
	}//end activity()

	private function registry(array $purposes, ?Verwerkingsactiviteit $activity): PurposeRegistry {
		$purposeMapper = $this->createMock(ProcessingPurposeMapper::class);
		$purposeMapper->method('findAll')->willReturn($purposes);
		$purposeMapper->method('resolveReference')->willReturnCallback(
			static function (string $reference) use ($purposes): ?ProcessingPurpose {
				foreach ($purposes as $purpose) {
					if ($purpose->getCode() === $reference || $purpose->getUuid() === $reference) {
						return $purpose;
					}
				}

				return null;
			}
		);

		$activityMapper = $this->createMock(VerwerkingsactiviteitMapper::class);
		$activityMapper->method('resolveReference')->willReturn($activity);

		return new PurposeRegistry($purposeMapper, $activityMapper);
	}//end registry()

	public function testAQueryUnderABoundPurposeResolvesToItsActivity(): void {
		$registry = $this->registry([$this->purpose()], $this->activity());

		$resolved = $registry->requirePurpose('brp-adresonderzoek');

		self::assertSame('brp-adresonderzoek', $resolved['purpose']->getCode());
		self::assertSame('activity-uuid', $resolved['activity']->getUuid());
	}//end testAQueryUnderABoundPurposeResolvesToItsActivity()

	public function testAQueryThatNamesNoPurposeIsRefusedAndOffersTheOnesItCould(): void {
		$registry = $this->registry([$this->purpose()], $this->activity());

		try {
			$registry->requirePurpose(null);
			self::fail('a read with no declared purpose must be refused');
		} catch (PurposeRefusedException $refusal) {
			self::assertSame(PurposeRefusedException::RULE_MISSING, $refusal->getRule());
			self::assertNull($refusal->getPurpose());
			self::assertSame(403, $refusal->getStatusCode());
			self::assertSame(['brp-adresonderzoek'], $refusal->toResponseBody()['available']);
		}
	}//end testAQueryThatNamesNoPurposeIsRefusedAndOffersTheOnesItCould()

	public function testAnUnknownPurposeIsQuotedBackSoTheCallerCanSeeItsOwnTypo(): void {
		$registry = $this->registry([$this->purpose()], $this->activity());

		try {
			$registry->requirePurpose('brp-adresonderzeok');
			self::fail('an unadministered purpose must be refused');
		} catch (PurposeRefusedException $refusal) {
			self::assertSame(PurposeRefusedException::RULE_UNKNOWN, $refusal->getRule());
			self::assertSame('brp-adresonderzeok', $refusal->getPurpose());
			self::assertStringContainsString('brp-adresonderzeok', $refusal->getMessage());
		}
	}//end testAnUnknownPurposeIsQuotedBackSoTheCallerCanSeeItsOwnTypo()

	public function testAPurposeNamingNoProcessingActivityIsRefusedAsUnbound(): void {
		// The purpose exists and is active. Nothing answers to its activity
		// reference, which is a different fault, needing a different person.
		$registry = $this->registry([$this->purpose()], null);

		try {
			$registry->requirePurpose('brp-adresonderzoek');
			self::fail('an unbound purpose must be refused');
		} catch (PurposeRefusedException $refusal) {
			self::assertSame(PurposeRefusedException::RULE_UNBOUND, $refusal->getRule());
			self::assertSame('brp-adresonderzoek', $refusal->getPurpose());
		}
	}//end testAPurposeNamingNoProcessingActivityIsRefusedAsUnbound()

	public function testAnArchivedActivityUnbindsThePurposesThatNameIt(): void {
		// The stored activityUuid still points at a row. Reading the binding
		// off that column would report this purpose usable forever.
		$registry = $this->registry([$this->purpose()], $this->activity('archived'));

		self::assertNull($registry->boundActivity($this->purpose()));

		$this->expectException(PurposeRefusedException::class);
		$registry->requirePurpose('brp-adresonderzoek');
	}//end testAnArchivedActivityUnbindsThePurposesThatNameIt()

	public function testAWithdrawnPurposeIsRefusedAsRetiredNotAsUnknown(): void {
		$retired = $this->purpose(status: ProcessingPurpose::STATUS_RETIRED);
		$registry = $this->registry([$retired], $this->activity());

		try {
			$registry->requirePurpose('brp-adresonderzoek');
			self::fail('a withdrawn purpose must be refused');
		} catch (PurposeRefusedException $refusal) {
			self::assertSame(PurposeRefusedException::RULE_RETIRED, $refusal->getRule());
		}
	}//end testAWithdrawnPurposeIsRefusedAsRetiredNotAsUnknown()

	public function testAnInstanceAdministeringNoPurposesSaysSo(): void {
		$registry = $this->registry([], null);

		self::assertFalse($registry->hasAdministeredPurposes());
		self::assertSame([], $registry->usablePurposes());
	}//end testAnInstanceAdministeringNoPurposesSaysSo()

	public function testUsablePurposesLeavesOutTheUnboundOnes(): void {
		$registry = $this->registry([$this->purpose()], null);

		self::assertTrue($registry->hasAdministeredPurposes());
		self::assertSame([], $registry->usablePurposes());
	}//end testUsablePurposesLeavesOutTheUnboundOnes()
}//end class
