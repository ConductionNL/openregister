<?php

/**
 * BulkTranslationService with a glossary-checking provider: a missed term is a
 * draft, an encrypted property is never sent, and the identity binding answers
 * when nothing is enabled.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/ai-translation-with-a-glossary/specs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Translation;
use OCA\OpenRegister\Db\TranslationEdit;
use OCA\OpenRegister\Db\TranslationMapper;
use OCA\OpenRegister\Db\TranslationSlot;
use OCA\OpenRegister\Service\BulkTranslationService;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\ObjectTranslationRequest;
use OCA\OpenRegister\Service\Translation\DetailedTranslationProviderInterface;
use OCA\OpenRegister\Service\Translation\IdentityTranslationProvider;
use OCA\OpenRegister\Service\Translation\LanguagePair;
use OCA\OpenRegister\Service\Translation\TranslationOutcome;
use OCA\OpenRegister\Service\Translation\TranslationProviderInterface;
use OCA\OpenRegister\Service\TranslationStores;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\BulkTranslationService
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\TranslationEdit
 * @uses \OCA\OpenRegister\Db\TranslationSlot
 * @uses \OCA\OpenRegister\Service\ObjectTranslationRequest
 * @uses \OCA\OpenRegister\Service\PropertyTranslation
 * @uses \OCA\OpenRegister\Service\TranslationStores
 * @uses \OCA\OpenRegister\Service\Translation\IdentityTranslationProvider
 * @uses \OCA\OpenRegister\Service\Translation\LanguagePair
 * @uses \OCA\OpenRegister\Service\Translation\TranslationOutcome
 */
class BulkTranslationServiceGlossaryTest extends TestCase {

	/**
	 * Every sidecar write, as [property, language, value, status, translator].
	 *
	 * @var list<list<string|null>>
	 */
	private array $upserts = [];

	/**
	 * Every text the provider was asked to translate, with the register it was given.
	 *
	 * @var list<array{0: string, 1: string|null}>
	 */
	private array $asked = [];

	/**
	 * The service over a schema whose `toelichting` is encrypted at rest.
	 */
	private function service(TranslationProviderInterface $provider): BulkTranslationService {
		$mapper = $this->createMock(TranslationMapper::class);
		$mapper->method('upsert')->willReturnCallback(
			function (TranslationSlot $slot, TranslationEdit $edit): Translation {
				$this->upserts[] = [$slot->property, $slot->language, $edit->value, $edit->status, $edit->translator];
				return new Translation();
			}
		);

		$handler = $this->createMock(TranslationHandler::class);
		$handler->method('getTranslatableProperties')->willReturn(['omschrijving', 'toelichting', 'naam']);

		$schema = new Schema();
		$schema->setProperties(
			[
				'omschrijving' => ['type' => 'string'],
				'toelichting' => ['type' => 'string', 'x-openregister-encrypted' => true],
				'naam' => ['type' => 'string'],
			]
		);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		return new BulkTranslationService($provider, new TranslationStores($mapper, $handler), $schemaMapper, $this->createMock(LoggerInterface::class));
	}

	private function request(): ObjectTranslationRequest {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setSchema('persoon');
		$object->setRegister('zaken');
		$object->setObject(
			[
				'omschrijving' => ['nl' => 'Aanvraag omgevingsvergunning voor een dakkapel'],
				'toelichting' => ['nl' => 'Vertrouwelijke toelichting'],
				'naam' => ['nl' => 'Dakkapel'],
			]
		);

		return new ObjectTranslationRequest($object, 'nl', 'en');
	}

	/**
	 * A detailed provider that answers per source text.
	 *
	 * @param array<string, TranslationOutcome|null> $answers The outcome per source text.
	 */
	private function detailedProvider(array $answers): DetailedTranslationProviderInterface {
		$provider = $this->createMock(DetailedTranslationProviderInterface::class);
		$provider->method('getIdentifier')->willReturn('taskprocessing');
		$provider->expects($this->never())->method('translate');
		$provider->method('translateDetailed')->willReturnCallback(
			function (string $text, LanguagePair $pair, ?string $register=null) use ($answers): ?TranslationOutcome {
				$this->assertSame(['nl', 'en'], [$pair->fromLong, $pair->toLong]);
				$this->asked[] = [$text, $register];
				return $answers[$text] ?? null;
			}
		);

		return $provider;
	}

	public function testAMissedGlossaryTermIsStoredAsADraftAndNamed(): void {
		$provider = $this->detailedProvider(
			[
				'Aanvraag omgevingsvergunning voor een dakkapel' => new TranslationOutcome('Application for a building permit for a dormer', ['omgevingsvergunning']),
				'Dakkapel' => new TranslationOutcome('Dormer'),
			]
		);

		$result = $this->service($provider)->translate($this->request());

		$this->assertSame(
			[
				'translated' => ['omschrijving' => 'Application for a building permit for a dormer', 'naam' => 'Dormer'],
				'skipped' => ['omschrijving' => 'glossary-terms-missing: omgevingsvergunning', 'toelichting' => 'encrypted-at-rest'],
			],
			$result
		);
		$this->assertSame(
			[
				['omschrijving', 'en', 'Application for a building permit for a dormer', 'draft', 'provider:taskprocessing'],
				['naam', 'en', 'Dormer', 'machine_translated', 'provider:taskprocessing'],
			],
			$this->upserts
		);
	}

	public function testAnEncryptedPropertyIsNeverSentAndTheRegisterIsPassedOn(): void {
		$provider = $this->detailedProvider(
			[
				'Aanvraag omgevingsvergunning voor een dakkapel' => new TranslationOutcome('Application for an environmental permit for a dormer'),
				'Dakkapel' => new TranslationOutcome('Dormer'),
			]
		);

		$result = $this->service($provider)->translate($this->request());

		$this->assertSame(
			[['Aanvraag omgevingsvergunning voor een dakkapel', 'zaken'], ['Dakkapel', 'zaken']],
			$this->asked
		);
		$this->assertSame(['toelichting' => 'encrypted-at-rest'], $result['skipped']);
		$this->assertSame(['machine_translated', 'machine_translated'], array_column($this->upserts, 3));
	}

	public function testAnEncryptedPropertyIsSkippedEvenWhenAskedForByName(): void {
		$provider = $this->detailedProvider([]);

		$result = $this->service($provider)->translate($this->request(), ['toelichting']);

		$this->assertSame(['translated' => [], 'skipped' => ['toelichting' => 'encrypted-at-rest']], $result);
		$this->assertSame([], $this->asked);
		$this->assertSame([], $this->upserts);
	}

	public function testNoOutcomeIsAnEmptyAnswer(): void {
		$result = $this->service($this->detailedProvider([]))->translate($this->request(), ['naam']);

		$this->assertSame(['translated' => [], 'skipped' => ['naam' => 'provider-returned-empty']], $result);
		$this->assertSame([], $this->upserts);
	}

	public function testThePlainIdentityProviderStillFillsSlotsAsMachineTranslated(): void {
		$result = $this->service(new IdentityTranslationProvider())->translate($this->request());

		$this->assertSame(['omschrijving' => 'Aanvraag omgevingsvergunning voor een dakkapel', 'naam' => 'Dakkapel'], $result['translated']);
		$this->assertSame(['toelichting' => 'encrypted-at-rest'], $result['skipped']);
		$this->assertSame(['machine_translated', 'machine_translated'], array_column($this->upserts, 3));
		$this->assertSame(['provider:identity', 'provider:identity'], array_column($this->upserts, 4));
	}

	public function testAPlainProviderAnsweringNullIsAnEmptyAnswer(): void {
		$provider = $this->createMock(TranslationProviderInterface::class);
		$provider->method('getIdentifier')->willReturn('plain');
		$provider->method('translate')->willReturn(null);

		$result = $this->service($provider)->translate($this->request(), ['naam']);

		$this->assertSame(['translated' => [], 'skipped' => ['naam' => 'provider-returned-empty']], $result);
	}
}
