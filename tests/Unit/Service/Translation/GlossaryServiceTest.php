<?php

/**
 * GlossaryService: the glossary terms that apply to a text, and the style guide.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Translation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Translation;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Translation\GlossaryService;
use OCA\OpenRegister\Service\Translation\LanguagePair;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Translation\GlossaryService
 * @uses \OCA\OpenRegister\Service\Translation\LanguagePair
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 */
class GlossaryServiceTest extends TestCase {

	/**
	 * The searchObjectsBySlug() calls seen.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $reads = [];

	/**
	 * The stored rows per schema.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $rows = [];

	private ObjectOperationsInterface&MockObject $objects;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->objects->method('searchObjectsBySlug')->willReturnCallback(
			function (string $registerSlug, string $schemaSlug, array $filters=[]): array {
				$this->reads[] = [$registerSlug, $schemaSlug, $filters];
				return array_map(
					static function (array $data): ObjectEntity {
						$entity = new ObjectEntity();
						$entity->setObject($data);
						return $entity;
					},
					($this->rows[$schemaSlug] ?? [])
				);
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function service(): GlossaryService {
		return new GlossaryService(objects: $this->objects, logger: $this->logger);
	}

	private static function term(string $term, string $translation='', array $extra=[]): array {
		return array_merge(
			['term' => $term, 'translation' => $translation, 'sourceLanguage' => 'nl', 'targetLanguage' => 'en'],
			$extra
		);
	}

	private static function nlEn(): LanguagePair {
		return new LanguagePair('nl', 'en');
	}

	public function testATermIsMatchedOnWordBoundariesWithCaseFolding(): void {
		$this->rows['translation-term'] = [
			self::term('gemeente', 'municipality'),
			self::term('vergunning', 'permit'),
		];

		$entries = $this->service()->entriesFor('De Gemeente beslist; de omgevingsvergunningen volgen.', self::nlEn());

		$this->assertSame(['gemeente'], array_column($entries, 'term'));
		$this->assertSame(
			['translation-glossary', 'translation-term', ['sourceLanguage' => 'nl', 'targetLanguage' => 'en']],
			$this->reads[0]
		);
	}

	public function testUnicodeLettersAreFoldedAndBoundAWord(): void {
		$this->rows['translation-term'] = [
			self::term('één loket', 'one counter'),
			self::term('loket', 'counter'),
		];

		$this->assertSame(['één loket', 'loket'], array_column($this->service()->entriesFor('Het ÉÉN LOKET is open.', self::nlEn()), 'term'));
		$this->assertSame([], $this->service()->entriesFor('De loketé is dicht.', self::nlEn()));
	}

	public function testACaseSensitiveTermMatchesOnlyItsOwnCase(): void {
		$this->rows['translation-term'] = [self::term('DigiD', '', ['doNotTranslate' => true, 'caseSensitive' => true])];

		$this->assertSame([], $this->service()->entriesFor('log in met digid', self::nlEn()));
		$entries = $this->service()->entriesFor('Log in met DigiD.', self::nlEn());
		$this->assertSame(
			[['term' => 'DigiD', 'translation' => '', 'doNotTranslate' => true, 'caseSensitive' => true, 'register' => '']],
			$entries
		);
	}

	public function testARegisterScopedTermAppliesToItsOwnRegisterOnly(): void {
		$this->rows['translation-term'] = [
			self::term('melding', 'report', ['register' => 'meldingen']),
			self::term('melding', 'notification'),
		];

		$own   = $this->service()->entriesFor('Een melding.', self::nlEn(), 'meldingen');
		$other = $this->service()->entriesFor('Een melding.', self::nlEn(), 'zaken');

		$this->assertSame(['report', 'notification'], array_column($own, 'translation'));
		$this->assertSame(['notification'], array_column($other, 'translation'));
	}

	public function testTheLongestOverlappingTermComesFirst(): void {
		$this->rows['translation-term'] = [
			self::term('omgevingsvergunning', 'environmental permit'),
			self::term('omgevingsvergunning beperkte milieutoets', 'environmental permit with a limited environmental test'),
			self::term('Wet open overheid', 'Open Government Act'),
		];

		$entries = $this->service()->entriesFor('Een omgevingsvergunning beperkte milieutoets onder de Wet open overheid.', self::nlEn());

		$this->assertSame(
			['omgevingsvergunning beperkte milieutoets', 'omgevingsvergunning', 'Wet open overheid'],
			array_column($entries, 'term')
		);
	}

	public function testAtMostFiftyEntriesAreGiven(): void {
		$terms = [];
		for ($i = 0; $i < 60; $i++) {
			$terms[] = self::term('w'.$i, 't'.$i);
		}

		$this->rows['translation-term'] = $terms;
		$text = implode(' ', array_column($terms, 'term'));

		$this->assertCount(GlossaryService::MAX_ENTRIES, $this->service()->entriesFor($text, self::nlEn()));
	}

	public function testUnusableAndOtherPairRowsAreIgnored(): void {
		$this->rows['translation-term'] = [
			self::term('gemeente'),
			self::term('', 'nothing'),
			self::term('gemeente', 'Gemeinde', ['targetLanguage' => 'de']),
		];

		$this->assertSame([], $this->service()->entriesFor('De gemeente.', self::nlEn()));
	}

	public function testThePairIsReadOncePerService(): void {
		$this->rows['translation-term'] = [self::term('gemeente', 'municipality')];
		$service = $this->service();

		$service->entriesFor('De gemeente.', self::nlEn());
		$service->entriesFor('Nog een gemeente.', self::nlEn());
		$service->entriesFor('Die Gemeinde.', new LanguagePair('de', 'en'));

		$this->assertCount(2, $this->reads);
	}

	public function testAnEmptyTextReadsNothing(): void {
		$this->assertSame([], $this->service()->entriesFor('  ', self::nlEn()));
		$this->assertSame([], $this->reads);
	}

	public function testAnUnreadableGlossaryIsLoggedAndGivesNoEntries(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('searchObjectsBySlug')->willThrowException(new RuntimeException('no register'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[GlossaryService] The translation glossary could not be read',
			['schema' => 'translation-term', 'exception' => 'no register']
		);

		$this->assertSame([], (new GlossaryService($objects, $this->logger))->entriesFor('De gemeente.', self::nlEn()));
	}

	public function testTheStyleGuideOfALanguageIsReadOnce(): void {
		$this->rows['translation-style-guide'] = [['language' => 'de', 'formality' => 'formal', 'instructions' => 'Use "Sie".']];
		$service = $this->service();

		$this->assertSame(['formality' => 'formal', 'instructions' => 'Use "Sie".'], $service->styleGuideFor('de'));
		$this->assertSame(['formality' => 'formal', 'instructions' => 'Use "Sie".'], $service->styleGuideFor('de'));
		$this->assertSame(['translation-glossary', 'translation-style-guide', ['language' => 'de']], $this->reads[0]);
		$this->assertCount(1, $this->reads);
	}

	public function testALanguageWithoutAStyleGuideHasNone(): void {
		$this->rows['translation-style-guide'] = [['language' => 'en', 'formality' => 'formal']];

		$this->assertNull($this->service()->styleGuideFor('fr'));
	}

	public function testContainsFoldsCase(): void {
		$this->assertTrue(GlossaryService::contains('Environmental Permit', 'an environmental permit was issued'));
		$this->assertTrue(GlossaryService::contains('STRASSE', 'die Straße'));
		$this->assertFalse(GlossaryService::contains('permit', 'a licence'));
	}
}
