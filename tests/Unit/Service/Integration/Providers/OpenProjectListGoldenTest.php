<?php

/**
 * Golden tests of OpenProjectProvider::list: link rows first, then the source's envelopes.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-openproject/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- test method names + arrange/act/assert structure make intent obvious; matches KvkProviderTest.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use OCA\OpenRegister\Db\OpenProjectLink;
use OCA\OpenRegister\Service\Integration\Providers\ConnectorRouting;
use OCA\OpenRegister\Db\OpenProjectLinkMapper;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\Providers\OpenProjectProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the rows the provider lists.
 */
final class OpenProjectListGoldenTest extends TestCase {

	private function provider(array $answer, ?OpenProjectLinkMapper $mapper = null): OpenProjectProvider {
		$router = $this->createMock(ExternalIntegrationRouter::class);
		$router->method('call')->willReturn($answer);

		return new OpenProjectProvider(routing: new ConnectorRouting(router: $router, appManager: $this->createMock(IAppManager::class), l10n: $this->createMock(IL10N::class)), linkMapper: $mapper);
	}//end provider()

	private function row(string $id): array {
		return [
			'id' => $id,
			'reference' => $id,
			'subject' => $id,
			'title' => $id,
			'status' => '',
			'type' => '',
			'priority' => '',
			'assignee' => '',
			'project' => '',
			'url' => '',
		];
	}//end row()

	public static function envelopeCases(): array {
		return [
			'results' => [['results' => [['id' => 'a'], 'junk']], ['a']],
			'items' => [['items' => [['id' => 'a']]], ['a']],
			'embedded elements' => [['_embedded' => ['elements' => [['id' => 'a']]]], ['a']],
			'embedded without elements' => [['_embedded' => [['id' => 'a']]], ['a']],
			'embedded elements is no list' => [['_embedded' => ['elements' => 'x', 0 => ['id' => 'a']]], ['a']],
			'elements' => [['elements' => [['id' => 'a']]], ['a']],
			'results win over items' => [['results' => [['id' => 'a']], 'items' => [['id' => 'b']]], ['a']],
			'a key that is no list is skipped' => [['results' => 'x', 'items' => [['id' => 'b']]], ['b']],
			'bare list' => [[['id' => 'a'], 'junk'], ['a']],
			'other envelope' => [['total' => 1], []],
		];
	}//end envelopeCases()

	#[DataProvider('envelopeCases')]
	public function testEnvelopes(array $answer, array $ids): void {
		$this->assertSame(
			array_map(fn (string $id): array => $this->row(id: $id), $ids),
			$this->provider($answer)->list('r', 's', 'o')
		);
	}//end testEnvelopes()

	public function testARowKeepsItsOwnKeysAndKeyOrder(): void {
		$rows = $this->provider(
			[
				'results' => [
					[
						'extra' => 1,
						'reference' => 'ref',
						'name' => 'Named',
						'_links' => [
							'status' => ['title' => 'Open'],
							'self' => ['href' => '/wp/1'],
							'type' => ['title' => ''],
						],
						'_embedded' => ['type' => ['name' => 'Task'], 'priority' => ['name' => 7]],
					],
				],
			]
		)->list('r', 's', 'o');

		$this->assertSame(
			['extra', 'reference', 'name', '_links', '_embedded', 'id', 'subject', 'title', 'status', 'type', 'priority', 'assignee', 'project', 'url'],
			array_keys($rows[0])
		);
		$this->assertSame('ref', $rows[0]['id']);
		$this->assertSame('Named', $rows[0]['subject']);
		$this->assertSame('Named', $rows[0]['title']);
		$this->assertSame('Open', $rows[0]['status']);
		$this->assertSame('/wp/1', $rows[0]['url']);
		$this->assertSame('Task', $rows[0]['type']);
		$this->assertSame('', $rows[0]['priority']);
	}//end testARowKeepsItsOwnKeysAndKeyOrder()

	public function testLinkRowsComeFirst(): void {
		$link = new OpenProjectLink();
		$link->setWorkPackageId(12);
		$link->setSubject('Fix');
		$link->setStatus('Open');
		$link->setType(null);
		$mapper = $this->createMock(OpenProjectLinkMapper::class);
		$mapper->method('findByObjectUuid')->with('o')->willReturn([$link]);

		$rows = $this->provider(['results' => [['id' => 'remote']]], $mapper)->list('r', 's', 'o');

		$this->assertCount(1, $rows);
		$this->assertSame(
			[
				'id' => '12',
				'reference' => '12',
				'subject' => 'Fix',
				'title' => 'Fix',
				'status' => 'Open',
				'type' => '',
				'priority' => '',
				'assignee' => '',
				'project' => '',
				'url' => '',
				'data' => $link->jsonSerialize(),
			],
			$rows[0]
		);
	}//end testLinkRowsComeFirst()

	public static function emptyLinkCases(): array {
		return [
			'no links' => [[]],
			'links fail' => [new RuntimeException('down')],
		];
	}//end emptyLinkCases()

	#[DataProvider('emptyLinkCases')]
	public function testTheSourceAnswersWithoutLinks(array|RuntimeException $links): void {
		$mapper = $this->createMock(OpenProjectLinkMapper::class);
		if ($links instanceof RuntimeException) {
			$mapper->method('findByObjectUuid')->willThrowException($links);
		} else {
			$mapper->method('findByObjectUuid')->willReturn($links);
		}

		$this->assertSame(
			[$this->row(id: 'remote')],
			$this->provider(['results' => [['id' => 'remote']]], $mapper)->list('r', 's', 'o')
		);
	}//end testTheSourceAnswersWithoutLinks()
}//end class
