<?php

/**
 * A Dutch BSN in a text is detected as a citizen service number (or#4104).
 *
 * The regex detector had e-mail, phone and IBAN only. A BSN was at best
 * labelled PHONE (the phone pattern matches any run of digits), which the
 * risk service rates medium, so a file full of BSNs came out too low and the
 * BSNs were never offered for anonymisation as such.
 *
 * The regex method now runs the Dutch pattern set, which confirms each
 * nine-digit candidate with the elfproef in `BsnFormat` and reports it as
 * `SSN`, the type `RiskLevelService` rates very high. A phone match on the
 * same span is dropped.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\TextExtraction
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
 * @spec openspec/changes/detection-dutch-licence-plates/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionHandler;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Service\TextExtraction\EntityRecognitionHandler
 */
class BsnDetectionTest extends TestCase {

	/**
	 * Run the handler's regex method over a text.
	 *
	 * @param string $text The text.
	 * @param array|null $entityTypes The type filter.
	 *
	 * @return array The detected entities.
	 */
	private function detect(string $text, ?array $entityTypes=null): array {
		$handler = new EntityRecognitionHandler(
			$this->createMock(ChunkMapper::class),
			$this->createMock(GdprEntityMapper::class),
			$this->createMock(EntityRelationMapper::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(SettingsService::class),
			$this->createMock(AnonymisationBackendService::class)
		);

		$method = new ReflectionMethod(EntityRecognitionHandler::class, 'detectWithRegex');

		return array_values($method->invoke($handler, $text, $entityTypes, 0.5));

	}//end detect()

	/**
	 * The entities of one type.
	 *
	 * @param array $entities The entities.
	 * @param string $type The type.
	 *
	 * @return array
	 */
	private function ofType(array $entities, string $type): array {
		return array_values(array_filter($entities, static fn (array $e): bool => $e['type'] === $type));

	}//end ofType()

	/**
	 * THE DEFECT: a valid BSN is found as SSN, an invalid nine-digit number is not.
	 *
	 * @return void
	 */
	public function testAValidBsnIsFoundAndAnInvalidOneIsNot(): void {
		$entities = $this->detect('BSN 111222333 en nummer 123456789');

		$ssn = $this->ofType($entities, EntityRecognitionHandler::ENTITY_TYPE_SSN);
		$this->assertCount(1, $ssn, 'exactly the elfproef-valid number is a BSN');
		$this->assertSame('111222333', $ssn[0]['value']);
		$this->assertSame(EntityRecognitionHandler::CATEGORY_SENSITIVE_PII, $ssn[0]['category']);
		$this->assertSame(4, $ssn[0]['position_start']);
		$this->assertSame(13, $ssn[0]['position_end']);

	}//end testAValidBsnIsFoundAndAnInvalidOneIsNot()

	/**
	 * A valid BSN is not also labelled PHONE.
	 *
	 * @return void
	 */
	public function testAValidBsnIsNotLabelledPhone(): void {
		$entities = $this->detect('BSN 111222333');

		foreach ($this->ofType($entities, EntityRecognitionHandler::ENTITY_TYPE_PHONE) as $phone) {
			$this->assertFalse(
				$phone['position_start'] < 13 && $phone['position_end'] > 4,
				'a PHONE entity overlaps the BSN: ' . $phone['value']
			);
		}

	}//end testAValidBsnIsNotLabelledPhone()

	/**
	 * A grouped BSN (4-2-3 with dots) is found too.
	 *
	 * @return void
	 */
	public function testAGroupedBsnIsFound(): void {
		$ssn = $this->ofType($this->detect('burgerservicenummer 1112.22.333.'), EntityRecognitionHandler::ENTITY_TYPE_SSN);

		$this->assertCount(1, $ssn);
		$this->assertSame('1112.22.333', $ssn[0]['value']);

	}//end testAGroupedBsnIsFound()

	/**
	 * Digits inside an IBAN or a longer number are not a BSN candidate.
	 *
	 * @return void
	 */
	public function testDigitsInsideALongerTokenAreNotABsn(): void {
		$entities = $this->detect('IBAN NL91ABNA0417164300, dossier 11122233344, bedrag 111222333,50');

		$this->assertSame([], $this->ofType($entities, EntityRecognitionHandler::ENTITY_TYPE_SSN));

	}//end testDigitsInsideALongerTokenAreNotABsn()

	/**
	 * A filter without SSN leaves BSNs out, and keeps the old phone behaviour.
	 *
	 * @return void
	 */
	public function testAFilterWithoutSsnFindsNoBsn(): void {
		$entities = $this->detect('BSN 111222333, mail a@b.nl', ['EMAIL']);

		$this->assertSame([], $this->ofType($entities, EntityRecognitionHandler::ENTITY_TYPE_SSN));
		$this->assertCount(1, $this->ofType($entities, EntityRecognitionHandler::ENTITY_TYPE_EMAIL));

	}//end testAFilterWithoutSsnFindsNoBsn()

	/**
	 * Other phone numbers are still found.
	 *
	 * @return void
	 */
	public function testOtherPhoneNumbersAreStillFound(): void {
		$entities = $this->detect('BSN 111222333, bel +31612345678');

		$this->assertCount(1, $this->ofType($entities, EntityRecognitionHandler::ENTITY_TYPE_SSN));
		$phones = array_column($this->ofType($entities, EntityRecognitionHandler::ENTITY_TYPE_PHONE), 'value');
		$this->assertContains('+31612345678', $phones);

	}//end testOtherPhoneNumbersAreStillFound()

}//end class
