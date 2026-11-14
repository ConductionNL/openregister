<?php

/**
 * SnippetBuilder safety test.
 *
 * Pins REQ-SDL-002: a passage is quoted only from what the reader may see. A
 * property hit never quotes a write-only, encrypted or unreadable property; a
 * content hit is quoted only from a published, readable anonymised copy with a
 * clean verdict; the marks are the only markup and the quote stays within 300
 * characters (search-dutch-language-quality, tasks 2.1 and 2.4).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md#requirement-a-result-shows-the-passage-that-matched-only-from-what-the-reader-may-see-req-sdl-002
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Search;

use OCA\OpenRegister\Service\Search\ContentSnippetSource;
use OCA\OpenRegister\Service\Search\SnippetBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SnippetBuilder::class)]
#[UsesClass(ContentSnippetSource::class)]
class SnippetSafetyTest extends TestCase {

	private const TEXT = 'de aanvraag voor de omgevingsvergunning is afgewezen';

	private function copy(bool $anonymised = true, ?string $verdict = 'clean', bool $published = true, bool $readable = true): ContentSnippetSource {
		return new ContentSnippetSource(fileId: 7, text: self::TEXT, anonymisedCopy: $anonymised, verdict: $verdict, published: $published, readableByCaller: $readable);
	}

	public function testAPublishedCleanCopyIsQuotedWithTheTermMarked(): void {
		$this->assertSame(
			['source' => 'content', 'fileId' => 7, 'text' => 'de aanvraag voor de omgevings<mark>vergunning</mark> is afgewezen'],
			(new SnippetBuilder())->forContent(search: 'vergunning', source: $this->copy())
		);
	}

	public function testAContentHitOnAnOriginalHasNoSnippet(): void {
		$this->assertNull((new SnippetBuilder())->forContent(search: 'vergunning', source: $this->copy(anonymised: false)));
	}

	public function testAnUnpublishedCopyHasNoSnippet(): void {
		$this->assertNull((new SnippetBuilder())->forContent(search: 'vergunning', source: $this->copy(published: false)));
		$this->assertNull((new SnippetBuilder())->forContent(search: 'vergunning', source: $this->copy(readable: false)));
	}

	public function testALeakingVerdictHasNoSnippet(): void {
		$this->assertNull((new SnippetBuilder())->forContent(search: 'vergunning', source: $this->copy(verdict: 'leaking')));
		$this->assertNull((new SnippetBuilder())->forContent(search: 'vergunning', source: $this->copy(verdict: 'unverifiable')));
	}

	public function testWithoutAVerdictNoContentSnippet(): void {
		$this->assertNull((new SnippetBuilder())->forContent(search: 'vergunning', source: $this->copy(verdict: null)));
	}

	public function testAPropertyHitIsQuoted(): void {
		$snippet = (new SnippetBuilder())->forProperty(
			search: 'Omgevingsvergunning',
			object: ['nummer' => 'Z-1', 'titel' => 'Omgevingsvergunning bouwen'],
			properties: ['nummer' => ['type' => 'string'], 'titel' => ['type' => 'string']]
		);

		$this->assertSame(['source' => 'property', 'property' => 'titel', 'text' => '<mark>Omgevingsvergunning</mark> bouwen'], $snippet);
	}

	public function testAWriteOnlyPropertyIsNeverQuoted(): void {
		$builder = new SnippetBuilder();
		$object = ['bsn' => '999990019', 'geheim' => '999990019', 'naam' => 'Jan', 'notitie' => 'bsn 999990019'];
		$properties = [
			'bsn' => ['type' => 'string', 'writeOnly' => true],
			'geheim' => ['type' => 'string', 'x-openregister-encrypted' => true],
			'naam' => ['type' => 'string'],
			'notitie' => ['type' => 'string'],
		];

		$snippet = $builder->forProperty(search: '999990019', object: $object, properties: $properties, mayRead: static fn (string $name): bool => $name !== 'notitie');

		$this->assertNull($snippet, 'write-only, encrypted and unreadable properties are skipped, so nothing quotes the value');
		$this->assertSame('notitie', $builder->forProperty(search: '999990019', object: $object, properties: $properties)['property']);
	}

	public function testMarksAreTheOnlyHtml(): void {
		$snippet = (new SnippetBuilder())->forProperty(
			search: 'script mark',
			object: ['titel' => '<script>alert("x")</script> & <b>mark</b>'],
			properties: ['titel' => ['type' => 'string']]
		);

		$this->assertNotNull($snippet);
		$this->assertSame(
			'&lt;<mark>script</mark>&gt;alert(&quot;x&quot;)&lt;/<mark>script</mark>&gt; &amp; &lt;b&gt;<mark>mark</mark>&lt;/b&gt;',
			$snippet['text']
		);
		$this->assertSame(['<mark>', '</mark>'], array_values(array_unique(preg_match_all('/<\/?[a-z]+>/', $snippet['text'], $tags) > 0 ? $tags[0] : [])));
	}

	public function testALongTextIsQuotedWithinTheBoundAroundTheMatch(): void {
		$text = str_repeat('aaaa ', 100) . 'besluit ' . str_repeat('bbbb ', 100);

		$snippet = (new SnippetBuilder())->forContent(search: 'besluit', source: new ContentSnippetSource(fileId: 1, text: $text, anonymisedCopy: true, verdict: 'clean', published: true, readableByCaller: true));

		$this->assertNotNull($snippet);
		$plain = strip_tags($snippet['text']);
		$this->assertLessThanOrEqual(SnippetBuilder::MAX_LENGTH, mb_strlen($plain));
		$this->assertStringStartsWith('…', $plain);
		$this->assertStringEndsWith('…', $plain);
		$this->assertStringContainsString('<mark>besluit</mark>', $snippet['text']);
	}

	public function testNoMatchOrNoUsableTermGivesNoSnippet(): void {
		$builder = new SnippetBuilder();

		$this->assertNull($builder->forProperty(search: 'fiets', object: ['titel' => 'auto'], properties: ['titel' => []]));
		$this->assertNull($builder->forProperty(search: ' a ', object: ['titel' => 'a b'], properties: ['titel' => []]));
		$this->assertNull($builder->forContent(search: 'fiets', source: $this->copy()));
	}
}//end class
