<?php

/**
 * A translatable name field must not be stored as raw JSON.
 *
 * A property marked `translatable: true` holds `{"nl": "…", "en": "…"}`. When
 * such a property is a schema's `objectNameField`, the hydration handler used
 * to `json_encode` the whole map to satisfy its `?string` return type, so
 * `@self.name` became the literal `{"nl":"…"}` — and every detail page in
 * every consuming app renders `@self.name` as its headline. Observed on
 * pipelinq's lead page.
 *
 * The tests below are written so that removing the resolution makes them
 * FAIL, and the non-language cases prove the change did not simply delete the
 * json_encode fallback that other callers rely on.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectHandlers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectHandlers;

use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for language-map resolution in MetadataHydrationHandler.
 */
class MetadataHydrationTranslatableNameTest extends TestCase {
	/**
	 * The handler under test.
	 *
	 * @var MetadataHydrationHandler
	 */
	private MetadataHydrationHandler $handler;

	/**
	 * Set up the handler with stubbed collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->handler = new MetadataHydrationHandler(
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			cacheHandler: $this->createMock(originalClassName: CacheHandler::class),
		);
	}//end setUp()

	/**
	 * The single-language map that shipped the bug.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function testASingleLanguageMapResolvesToItsValue(): void {
		$name = 'Rijkswaterstaat - Onderhoudscontract software 2026';

		$result = $this->handler->extractMetadataValue(
			data: ['title' => ['nl' => $name]],
			fieldPath: 'title'
		);

		$this->assertSame(expected: $name, actual: $result);
		// The exact shape a user was shown on the page.
		$this->assertStringNotContainsString(needle: '{"nl"', haystack: (string)$result);
	}//end testASingleLanguageMapResolvesToItsValue()

	/**
	 * A multi-language map takes the first declared language.
	 *
	 * No locale is baked in: `@self.name` is written at save time, when there
	 * is no reader whose language could be honoured. Per-request resolution is
	 * TranslationHandler's job.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function testAMultiLanguageMapTakesTheFirstDeclared(): void {
		$result = $this->handler->extractMetadataValue(
			data: ['title' => ['en' => 'Maintenance contract', 'nl' => 'Onderhoudscontract']],
			fieldPath: 'title'
		);

		$this->assertSame(expected: 'Maintenance contract', actual: $result);
	}//end testAMultiLanguageMapTakesTheFirstDeclared()

	/**
	 * An empty leading language falls through to the next one.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function testAnEmptyLeadingLanguageIsSkipped(): void {
		$result = $this->handler->extractMetadataValue(
			data: ['title' => ['en' => '   ', 'nl' => 'Onderhoudscontract']],
			fieldPath: 'title'
		);

		$this->assertSame(expected: 'Onderhoudscontract', actual: $result);
	}//end testAnEmptyLeadingLanguageIsSkipped()

	/**
	 * A regional tag is still a language tag.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function testARegionalSubtagIsRecognised(): void {
		$result = $this->handler->extractMetadataValue(
			data: ['title' => ['nl-BE' => 'Onderhoudscontract']],
			fieldPath: 'title'
		);

		$this->assertSame(expected: 'Onderhoudscontract', actual: $result);
	}//end testARegionalSubtagIsRecognised()

	/**
	 * A structured value is NOT a language map and still encodes.
	 *
	 * The positive control for the fallback: this is what the json_encode
	 * branch exists for, and the fix must not have removed it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function testANonLanguageArrayStillEncodes(): void {
		$result = $this->handler->extractMetadataValue(
			data: ['title' => ['street' => 'Industrieweg', 'number' => 12]],
			fieldPath: 'title'
		);

		$this->assertSame(expected: '{"street":"Industrieweg","number":12}', actual: $result);
	}//end testANonLanguageArrayStillEncodes()

	/**
	 * A list is not a language map either.
	 *
	 * Its keys are integers, so the predicate rejects it and the encode
	 * fallback keeps its behaviour.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function testAListStillEncodes(): void {
		$result = $this->handler->extractMetadataValue(
			data: ['title' => ['one', 'two']],
			fieldPath: 'title'
		);

		$this->assertSame(expected: '["one","two"]', actual: $result);
	}//end testAListStillEncodes()

	/**
	 * A nested map under a language key is left alone.
	 *
	 * Reaching further in would be guessing at a shape nobody declared, so it
	 * falls through to the encode branch rather than inventing a rule.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function testANestedValueUnderALanguageKeyStillEncodes(): void {
		$result = $this->handler->extractMetadataValue(
			data: ['title' => ['nl' => ['first' => 'a']]],
			fieldPath: 'title'
		);

		$this->assertSame(expected: '{"nl":{"first":"a"}}', actual: $result);
	}//end testANestedValueUnderALanguageKeyStillEncodes()

	/**
	 * A plain string is untouched.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function testAPlainStringIsUnchanged(): void {
		$result = $this->handler->extractMetadataValue(
			data: ['title' => 'Zonnepanelen op monument Grote Kerk'],
			fieldPath: 'title'
		);

		$this->assertSame(expected: 'Zonnepanelen op monument Grote Kerk', actual: $result);
	}//end testAPlainStringIsUnchanged()
}//end class
