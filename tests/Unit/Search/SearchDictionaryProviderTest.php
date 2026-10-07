<?php

/**
 * Unit tests for the dictionary provider.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Search\SearchDictionaryProvider;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SearchDictionaryProviderTest extends TestCase {

	private MagicMapper&MockObject $objects;

	private IAppConfig&MockObject $appConfig;

	private RegisterMapper&MockObject $registers;

	private SchemaMapper&MockObject $schemas;

	private SearchDictionaryProvider $provider;

	protected function setUp(): void {
		parent::setUp();

		$this->objects = $this->createMock(MagicMapper::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->registers = $this->createMock(RegisterMapper::class);
		$this->schemas = $this->createMock(SchemaMapper::class);

		// findIdsBySlugs() answers slug => LIST OF IDS, keyed by the LOWERCASED
		// slug. Both are load-bearing and both are reproduced here.
		$this->registers->method('findIdsBySlugs')->willReturn(['vocabulary' => [7]]);
		$this->schemas->method('findIdsBySlugs')->willReturnCallback(
			static function (array $slugs): array {
				$map = ['concept' => [11], 'conceptscheme' => [12]];
				$answer = [];
				foreach ($slugs as $slug) {
					$key = strtolower($slug);
					if (isset($map[$key]) === true) {
						$answer[$key] = $map[$key];
					}
				}
				return $answer;
			}
		);

		$this->provider = new SearchDictionaryProvider(
			$this->objects,
			$this->registers,
			$this->schemas,
			$this->appConfig,
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * Answer the synonym scheme with one set of rows and the stopword scheme
	 * with another.
	 *
	 * @param array $synonyms  The synonym concepts.
	 * @param array $stopwords The stopword concepts.
	 *
	 * @return void
	 */
	private function registerAnswers(array $synonyms, array $stopwords): void {
		$this->objects->method('searchObjectsPaginated')->willReturnCallback(
			static function (array $searchQuery) use ($synonyms, $stopwords): array {
				// The scheme lookup: uri in, the scheme OBJECT's uuid out. The
				// concepts are filed under that uuid, never under the uri.
				$uri = ($searchQuery['uri'] ?? null);
				if ($uri !== null) {
					return ['results' => [['@self' => ['id' => 'uuid-of-' . $uri]]], 'total' => 1];
				}

				$scheme = (string)($searchQuery['inScheme'] ?? '');
				if ($scheme === 'uuid-of-' . SearchDictionaryProvider::SYNONYM_SCHEME) {
					return ['results' => $synonyms, 'total' => count($synonyms)];
				}

				return ['results' => $stopwords, 'total' => count($stopwords)];
			}
		);
	}//end registerAnswers()

	/**
	 * The concepts an administrator wrote become the dictionary, in the
	 * language they wrote them in.
	 *
	 * @return void
	 */
	public function testAdministeredConceptsBecomeTheDictionary(): void {
		$this->registerAnswers(
			[
				[
					'prefLabel' => ['nl' => 'omgevingsvergunning', 'en' => 'planning permission'],
					'altLabel' => ['nl' => ['bouwvergunning'], 'en' => ['building permit']],
				],
			],
			[['prefLabel' => ['nl' => 'de']]]
		);

		$expansion = $this->provider->forLanguage('nl')->expand('de omgevingsvergunning', 5, 20);

		$this->assertSame('(omgevingsvergunning OR bouwvergunning)', $expansion->term());
	}//end testAdministeredConceptsBecomeTheDictionary()

	/**
	 * A concept with no label in the asked-for language contributes nothing.
	 *
	 * Falling back to another language would expand a Dutch query by an English
	 * synonym, which is not what the administrator declared.
	 *
	 * @return void
	 */
	public function testALanguageWithNoLabelsAnswersAnEmptyDictionary(): void {
		$this->registerAnswers(
			[['prefLabel' => ['nl' => 'omgevingsvergunning'], 'altLabel' => ['nl' => ['bouwvergunning']]]],
			[]
		);

		$this->assertTrue($this->provider->forLanguage('fr')->isEmpty());
		$this->assertFalse($this->provider->forLanguage('nl')->isEmpty());
	}//end testALanguageWithNoLabelsAnswersAnEmptyDictionary()

	/**
	 * A register that cannot be read answers an empty dictionary rather than
	 * failing the search it was called from.
	 *
	 * @return void
	 */
	public function testAFailingLookupAnswersAnEmptyDictionary(): void {
		$this->objects->method('searchObjectsPaginated')->willThrowException(new \RuntimeException('no register'));

		$this->assertTrue($this->provider->forLanguage('nl')->isEmpty());
	}//end testAFailingLookupAnswersAnEmptyDictionary()

	/**
	 * The dictionary is read once per language per request. Loading it issues
	 * a search, and a search per search is how one query becomes thousands.
	 *
	 * @return void
	 */
	public function testTheDictionaryIsReadOncePerRequest(): void {
		$calls = 0;
		$this->objects->method('searchObjectsPaginated')->willReturnCallback(
			function () use (&$calls): array {
				$calls++;
				return ['results' => [], 'total' => 0];
			}
		);

		$this->provider->forLanguage('nl');
		$first = $calls;
		$this->provider->forLanguage('nl');
		$this->provider->forLanguage('nl');

		$this->assertGreaterThan(0, $first);
		$this->assertSame($first, $calls, 'the register is read once per language per request');
	}//end testTheDictionaryIsReadOncePerRequest()

	/**
	 * The load's own search cannot reach back and load the dictionary again.
	 *
	 * Without the guard the first search on a cold request recurses until it
	 * dies, and the failure lands nowhere near the cause.
	 *
	 * @return void
	 */
	public function testTheLoadCannotReEnterItself(): void {
		$depth = 0;
		$this->objects->method('searchObjectsPaginated')->willReturnCallback(
			function () use (&$depth): array {
				$depth++;
				$this->assertLessThan(5, $depth, 'the provider recursed');
				// Exactly what the real search path does: it asks for the
				// dictionary while answering the dictionary's own query.
				$this->provider->forLanguage('nl');
				return ['results' => [], 'total' => 0];
			}
		);

		$this->provider->forLanguage('nl');

		$this->assertGreaterThan(0, $depth);
	}//end testTheLoadCannotReEnterItself()

	/**
	 * A register with no such scheme answers an empty dictionary, and does not
	 * pretend the uri itself is the reference.
	 *
	 * `inScheme` holds the scheme OBJECT's uuid; filtering concepts by the uri
	 * matches nothing at all, which in a feature that fails soft would have
	 * made a broken dictionary look exactly like an unused one.
	 *
	 * @return void
	 */
	public function testAMissingSchemeAnswersAnEmptyDictionary(): void {
		$this->objects->method('searchObjectsPaginated')->willReturnCallback(
			static function (array $searchQuery): array {
				if (isset($searchQuery['uri']) === true) {
					return ['results' => [], 'total' => 0];
				}

				throw new \RuntimeException('concepts must not be queried without a resolved scheme');
			}
		);

		$this->assertTrue($this->provider->forLanguage('nl')->isEmpty());
	}//end testAMissingSchemeAnswersAnEmptyDictionary()

	/**
	 * The caps are administered, with documented defaults.
	 *
	 * @return void
	 */
	public function testTheCapsAreAdministered(): void {
		$this->appConfig->method('getValueInt')->willReturnCallback(
			static function (string $app, string $key, int $default): int {
				return ($key === 'searchDictionaryPerGroup' ? 2 : $default);
			}
		);

		$this->assertSame(2, $this->provider->perGroupCap());
		$this->assertSame(SearchDictionaryProvider::DEFAULT_PER_QUERY, $this->provider->perQueryCap());
	}//end testTheCapsAreAdministered()
}//end class
