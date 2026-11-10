<?php

/**
 * Golden record of XwikiProvider list(), get(), create(), update() and delete().
 *
 * Router-backed lists over every envelope the provider understands, the
 * Tier-2 link-table path with enrichment that answers, partially answers or
 * fails, and the four entity calls. Both the returned rows and the router
 * calls are recorded in fixtures/xwiki-provider-golden.json; set
 * XWIKI_PROVIDER_GOLDEN_RECORD=1 to write it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
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
 * @spec exclude Pins the observable behaviour of the XWiki provider.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

use OCA\OpenRegister\Db\XwikiLink;
use OCA\OpenRegister\Db\XwikiLinkMapper;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouterInterface;
use OCA\OpenRegister\Service\Integration\Providers\XwikiProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Observable behaviour of the XWiki provider.
 *
 * @covers \OCA\OpenRegister\Service\Integration\Providers\XwikiProvider
 * @uses   \OCA\OpenRegister\Db\XwikiLink
 */
class XwikiProviderGoldenTest extends TestCase {

	private const FIXTURE = __DIR__.'/fixtures/xwiki-provider-golden.json';

	/**
	 * What the router was asked.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * Source rows exercising every normalisation fallback.
	 *
	 * @return list<mixed> The rows.
	 */
	private function sourceRows(): array {
		return [
			[
				'reference' => 'Main.WebHome', 'space' => 'Main.Sub', 'page' => 'WebHome', 'title' => 'Home',
				'url' => 'https://wiki/home', 'content' => '<p>hi</p>', 'extra' => 'kept',
			],
			['pageReference' => 'A.B', 'spaceName' => 'A', 'pageName' => 'B', 'link' => 'https://wiki/ab', 'renderedContent' => '<b>x</b>'],
			['id' => 'C.D', 'name' => 'D', 'xwikiAbsoluteUrl' => 'https://wiki/cd', 'breadcrumb' => ['C', 'D']],
			['id' => 'Only.Ref', 'breadcrumb' => []],
			['reference' => 'Zero', 'space' => 'a.0.b', 'title' => 'Zero'],
			[
				'reference' => 'L.Page', 'links' => [
					'not-an-array',
					['rel' => 'http://www.xwiki.org/rel/page'],
					['rel' => 'http://www.xwiki.org/rel/space', 'href' => 'https://wiki/space'],
					['rel' => 'http://www.xwiki.org/rel/page', 'href' => 'https://wiki/l/page'],
				],
			],
			['reference' => 'L.Child', 'links' => [['rel' => 'http://www.xwiki.org/rel/pagechild', 'href' => 'https://wiki/l/child']]],
			['reference' => 'L.None', 'links' => 'nope'],
			['reference' => 'L.Miss', 'links' => [['href' => 'https://wiki/no-rel']]],
			'not-a-row',
			[],
		];
	}//end sourceRows()

	/**
	 * Build the provider.
	 *
	 * @param array<string, mixed> $answers Router answers by path; a Throwable is thrown.
	 * @param list<XwikiLink>|null $links   Link rows, or null for no mapper.
	 *
	 * @return XwikiProvider The provider.
	 */
	private function provider(array $answers, ?array $links = null): XwikiProvider {
		$router = $this->createMock(ExternalIntegrationRouterInterface::class);
		$router->method('call')->willReturnCallback(
			function ($provider, string $method, string $path, array $options = []) use ($answers): array {
				$this->calls[] = ['method' => $method, 'path' => $path, 'options' => $options];
				$answer = $answers[$path] ?? [];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}
				return $answer;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'T:'.$text);
		$mapper = null;
		if ($links !== null) {
			$mapper = $this->createMock(XwikiLinkMapper::class);
			$mapper->method('findByObjectUuid')->willReturnCallback(
				function (string $uuid) use ($links): array {
					$this->calls[] = ['mapper' => $uuid];
					return $links;
				}
			);
		}
		return new XwikiProvider(
			router: $router,
			appManager: $this->createMock(IAppManager::class),
			l10n: $l10n,
			xwikiLinkMapper: $mapper
		);
	}//end provider()

	/**
	 * Link rows: one filled, one bare-ish, one whose enrichment fails.
	 *
	 * @return list<XwikiLink> The links.
	 */
	private function links(): array {
		$full = new XwikiLink();
		$full->setPageReference('Main.WebHome');
		$full->setTitle('Cached home');
		$full->setSpace('Main');
		$full->setUrl('https://wiki/cached');
		$bare = new XwikiLink();
		$bare->setPageReference('Bare.Page');
		$bare->setTitle('Bare');
		$broken = new XwikiLink();
		$broken->setPageReference('Broken Page/1');
		$broken->setTitle('Broken');
		$broken->setSpace('X.Y');
		return [$full, $bare, $broken];
	}//end links()

	/**
	 * Every scenario: label => closure returning the provider's answer.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: list<XwikiLink>|null, 2: callable}> The scenarios.
	 */
	private function scenarios(): array {
		$rows = $this->sourceRows();
		$list = static fn (string $objectId, array $filters = []) =>
			static fn (XwikiProvider $p) => $p->list('reg', 'sch', $objectId, $filters);
		$enrich = [
			'Main.WebHome' => ['title' => 'Live home', 'space' => '', 'url' => 'https://wiki/live', 'content' => null],
			'Bare.Page' => ['reference' => 'Bare.Page', 'page' => 'Page'],
			'Broken%20Page%2F1' => new RuntimeException('down'),
		];
		return [
			'list results envelope' => [
				['' => ['results' => $rows, 'items' => [['id' => 'ignored']]]],
				null,
				$list('obj-1', ['_search' => 'q', 'register' => 'overridden']),
			],
			'list items envelope' => [['' => ['items' => [$rows[1]]]], null, $list('obj-1')],
			'list pageSummaries envelope' => [['' => ['pageSummaries' => [$rows[5]]]], null, $list('obj-1')],
			'list searchResults envelope' => [['' => ['searchResults' => [$rows[2]]]], null, $list('obj-1')],
			'list non-array results falls through' => [['' => ['results' => 'x', 'searchResults' => [$rows[3]]]], null, $list('obj-1')],
			'list bare list' => [['' => [$rows[0], $rows[6]]], null, $list('obj-1')],
			'list unknown envelope' => [['' => ['total' => 2, 'data' => [$rows[0]]]], null, $list('obj-1')],
			'list empty results' => [['' => ['results' => []]], null, $list('obj-1')],
			'list empty response' => [['' => []], null, $list('obj-1')],
			'list with mapper but no object' => [['' => ['results' => [$rows[4]]]], $this->links(), $list('', ['_limit' => 5])],
			'list linked pages enriched' => [$enrich, $this->links(), $list('obj-1', ['_search' => 'ignored'])],
			'list mapper with no links' => [[], [], $list('obj-1')],
			'get' => [
				['Main.Web Home' => [], 'Main.Web%20Home' => $rows[1]],
				null,
				static fn (XwikiProvider $p) => $p->get('reg', 'sch', 'obj-1', 'Main.Web Home'),
			],
			'create' => [
				['' => $rows[0]],
				null,
				static fn (XwikiProvider $p) => $p->create('reg', 'sch', 'obj-1', ['reference' => 'https://wiki/x', 'object' => 'overridden']),
			],
			'update' => [['A%2FB' => $rows[6]], null, static fn (XwikiProvider $p) => $p->update('reg', 'sch', 'obj-1', 'A/B', ['title' => 'New'])],
			'delete' => [[], null, static fn (XwikiProvider $p) => $p->delete('reg', 'sch', 'obj-1', 'A/B')],
			'metadata' => [[], null, static fn (XwikiProvider $p) => [
				$p->getId(), $p->getLabel(), $p->getIcon(), $p->authRequirements(), $p->getGroup(),
				$p->getStorageStrategy(), $p->getOpenConnectorSource(),
			]],
		];
	}//end scenarios()

	/**
	 * Every scenario answers as recorded.
	 *
	 * @return void
	 */
	public function testProviderMatchesTheRecording(): void {
		$seen = [];
		foreach ($this->scenarios() as $label => [$answers, $links, $run]) {
			$this->calls = [];
			$result = $run($this->provider(answers: $answers, links: $links));
			$seen[$label] = ['result' => $result, 'calls' => $this->calls];
		}

		$json = json_decode((string)json_encode($seen), true);
		if (getenv('XWIKI_PROVIDER_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
			$this->markTestIncomplete('recorded');
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $json);
	}//end testProviderMatchesTheRecording()
}//end class
