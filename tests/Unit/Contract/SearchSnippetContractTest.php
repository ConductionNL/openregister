<?php

/**
 * The search-snippet contract opencatalogi and portaliq build on.
 *
 * Request flag `_snippet=true`; row key `@self.snippet` shaped
 * `{source, property?, fileId?, text}`; capability `openregister.search.stemming`.
 * A change here breaks those consumers, so it is a contract change, not a refactor.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Contract
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Contract;

use Doctrine\DBAL\Platforms\PostgreSQL120Platform;
use OCA\OpenRegister\Capabilities\SearchCapability;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Search\ContentSnippetSource;
use OCA\OpenRegister\Service\Search\SearchSnippetAttacher;
use OCA\OpenRegister\Service\Search\SnippetBuilder;
use OCA\OpenRegister\Service\Search\TextSearchConfiguration;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Search\SearchSnippetAttacher
 * @covers \OCA\OpenRegister\Service\Search\SnippetBuilder
 * @covers \OCA\OpenRegister\Capabilities\SearchCapability
 * @uses \OCA\OpenRegister\Service\Search\ContentSnippetSource
 * @uses \OCA\OpenRegister\Service\Search\TextSearchConfiguration
 * @uses \OCA\OpenRegister\Db\Schema
 *
 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md#requirement-a-result-shows-the-passage-that-matched-only-from-what-the-reader-may-see-req-sdl-002
 */
final class SearchSnippetContractTest extends TestCase {

	public function testTheRequestFlagIsSnippet(): void {
		$this->assertSame('_snippet', SearchSnippetAttacher::FLAG);
		foreach ([true, 'true', 'TRUE', '1', 1] as $on) {
			$this->assertTrue(SearchSnippetAttacher::asked(query: ['_search' => 'x', '_snippet' => $on]), var_export($on, true));
		}

		foreach ([false, 'false', '0', 0, 'yes', null] as $off) {
			$this->assertFalse(SearchSnippetAttacher::asked(query: ['_search' => 'x', '_snippet' => $off]), var_export($off, true));
		}

		$this->assertFalse(SearchSnippetAttacher::asked(query: ['_snippet' => true]));
	}

	public function testAPropertySnippetIsSourcePropertyText(): void {
		$schema = new Schema();
		$schema->setProperties(['titel' => ['type' => 'string']]);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($schema);

		$result = (new SearchSnippetAttacher(schemas: $schemas))->attach(
			result: ['results' => [['@self' => ['schema' => 3], 'titel' => 'Besluit op bezwaar']]],
			query: ['_search' => 'besluit', '_snippet' => 'true']
		);

		$this->assertSame(
			['source' => 'property', 'property' => 'titel', 'text' => '<mark>Besluit</mark> op bezwaar'],
			$result['results'][0]['@self']['snippet']
		);
	}

	public function testAContentSnippetIsSourceFileIdText(): void {
		$snippet = (new SnippetBuilder())->forContent(
			search: 'besluit',
			source: new ContentSnippetSource(fileId: 12, text: 'Het besluit', anonymisedCopy: true, verdict: 'clean', published: true, readableByCaller: true)
		);

		$this->assertSame(['source' => 'content', 'fileId' => 12, 'text' => 'Het <mark>besluit</mark>'], $snippet);
	}

	public function testTheTextIsBoundedAndCarriesOnlyMarks(): void {
		$snippet = (new SnippetBuilder())->forProperty(
			search: 'besluit',
			object: ['t' => str_repeat('<b>x</b> ', 100) . 'besluit' . str_repeat(' y', 300)],
			properties: ['t' => ['type' => 'string']]
		);

		$text = $snippet['text'];
		$this->assertSame($text, strip_tags($text, '<mark>'));
		$this->assertLessThanOrEqual(SnippetBuilder::MAX_LENGTH, mb_strlen(html_entity_decode(strip_tags($text))));
		$this->assertStringContainsString('<mark>besluit</mark>', $text);
	}

	public function testTheCapabilityKeyIsOpenregisterSearchStemming(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock(PostgreSQL120Platform::class));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('dutch');

		$capabilities = (new SearchCapability(textSearch: new TextSearchConfiguration(db: $db, appConfig: $appConfig)))->getCapabilities();

		$this->assertSame('dutch', $capabilities['openregister']['search']['stemming']);
	}
}
