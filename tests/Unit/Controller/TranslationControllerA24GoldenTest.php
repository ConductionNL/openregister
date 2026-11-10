<?php

/**
 * Golden outcomes of TranslationController, pinned before a refactor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\Support\TranslationControllerServices;
use OCA\OpenRegister\Controller\Support\TranslationMappers;
use OCA\OpenRegister\Controller\TranslationController;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\Translation;
use OCA\OpenRegister\Db\TranslationMapperInterface;
use OCA\OpenRegister\Db\TranslationSlot;
use OCA\OpenRegister\Service\BulkTranslationServiceInterface;
use OCA\OpenRegister\Service\TranslationSearch;
use OCA\OpenRegister\Service\TranslationStatusServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use OCA\OpenRegister\Service\ObjectTranslationRequest;

/**
 * Pins search parameter handling and every response shape.
 */
class TranslationControllerA24GoldenTest extends TestCase {

	/**
	 * status.
	 *
	 * @var TranslationStatusServiceInterface&MockObject
	 */
	private TranslationStatusServiceInterface&MockObject $status;

	/**
	 * translations.
	 *
	 * @var TranslationMapperInterface&MockObject
	 */
	private TranslationMapperInterface&MockObject $translations;

	/**
	 * schemas.
	 *
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface&MockObject $schemas;

	/**
	 * bulk.
	 *
	 * @var BulkTranslationServiceInterface&MockObject
	 */
	private BulkTranslationServiceInterface&MockObject $bulk;

	/**
	 * objects.
	 *
	 * @var MagicMapperInterface&MockObject
	 */
	private MagicMapperInterface&MockObject $objects;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->status = $this->createMock(TranslationStatusServiceInterface::class);
		$this->translations = $this->createMock(TranslationMapperInterface::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->bulk = $this->createMock(BulkTranslationServiceInterface::class);
		$this->objects = $this->createMock(MagicMapperInterface::class);
	}//end setUp()

	/**
	 * Build the controller over these request params.
	 *
	 * @param array<string, mixed> $params Request params (raw, as the router sees them).
	 *
	 * @return TranslationController
	 */
	private function controller(array $params = []): TranslationController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($params) {
				if (array_key_exists(key: $key, array: $params) === true) {
					return $params[$key];
				}

				return $default;
			}
		);
		$request->method('getParams')->willReturn($params);

		return new TranslationController(
			$request,
			new TranslationControllerServices(
				$this->status,
				$this->bulk,
				new TranslationMappers($this->translations, $this->schemas, $this->objects)
			)
		);
	}//end controller()

	/**
	 * Record what a search call received.
	 *
	 * @param string $method search or searchWithSourceValues.
	 *
	 * @return void
	 */
	private function recordSearch(string $method): void {
		$this->status->method($method)->willReturnCallback(
			static fn (TranslationSearch $search): array => [
				[$method, $search->query, $search->filter->language, $search->filter->status, $search->filter->objectUuid, $search->limit, $search->filter->sourceLanguage, $search->isOutOfDate],
			]
		);
	}//end recordSearch()

	/**
	 * Search, as the router would call it: raw params in the request, cast arguments positionally.
	 *
	 * @param array<string, mixed> $raw  Raw request params.
	 * @param array<int, mixed>    $args The dispatcher-cast arguments, positionally.
	 *
	 * @return array<string, mixed>
	 */
	private function search(array $raw, array $args): array {
		$response = $this->controller($raw)->search(...$args);
		$this->assertSame(200, $response->getStatus());
		return $response->getData();
	}//end search()

	/**
	 * Search defaults.
	 *
	 * @return void
	 */
	public function testSearchDefaults(): void {
		$this->recordSearch('search');
		$this->status->expects($this->never())->method('searchWithSourceValues');
		$this->assertSame(
			['results' => [['search', null, null, null, null, 100, null, false]], 'count' => 1],
			$this->search([], [])
		);
	}

	/**
	 * Search passes filters and clamps the limit.
	 *
	 * @return void
	 */
	public function testSearchPassesFiltersAndClampsTheLimit(): void {
		$this->recordSearch('search');
		$raw = [
			'query' => 'q',
			'language' => 'nl',
			'status' => 'draft',
			'objectUuid' => 'u',
			'limit' => '5000',
			'sourceLanguage' => 'en',
			'isOutOfDate' => ' Yes '
		];
		$this->assertSame(
			[['search', 'q', 'nl', 'draft', 'u', 1000, 'en', true]],
			$this->search($raw, ['q', 'nl', 'draft', 'u', 5000, 'en', ' Yes '])['results']
		);
		$this->assertSame(1, $this->search(['limit' => '0'], [null, null, null, null, 0])['results'][0][5]);
		$this->assertSame(1, $this->search(['limit' => '-4'], [null, null, null, null, -4])['results'][0][5]);
		$this->assertSame(1, $this->search(['limit' => ''], [null, null, null, null, 0])['results'][0][5]);
		$this->assertSame(7, $this->search(['limit' => '7'], [null, null, null, null, 7])['results'][0][5]);
		$this->assertFalse($this->search(['isOutOfDate' => 'nope'], [null, null, null, null, 100, null, 'nope'])['results'][0][7]);
		$this->assertFalse($this->search(['isOutOfDate' => ''], [null, null, null, null, 100, null, ''])['results'][0][7]);
		$this->assertTrue($this->search(['isOutOfDate' => 'ON'], [null, null, null, null, 100, null, 'ON'])['results'][0][7]);
	}

	/**
	 * Search compares to source when asked.
	 *
	 * @return void
	 */
	public function testSearchComparesToSourceWhenAsked(): void {
		$this->recordSearch('searchWithSourceValues');
		$this->status->expects($this->never())->method('search');
		$this->assertSame(
			['results' => [['searchWithSourceValues', null, 'de', null, null, 100, null, true]], 'count' => 1],
			$this->search(['language' => 'de', 'isOutOfDate' => '1', 'compareToSource' => 'true'], [null, 'de', null, null, 100, null, '1', 'true'])
		);
	}

	/**
	 * Show by object.
	 *
	 * @return void
	 */
	public function testShowByObject(): void {
		$row = $this->createMock(Translation::class);
		$row->method('jsonSerialize')->willReturn(['property' => 'title']);
		$this->translations->method('findByObject')->with('u-1')->willReturn([$row]);
		$schema = new Schema();
		$this->schemas->method('find')->willReturnCallback(
			function (string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true) use ($schema): Schema {
				$this->assertSame([false, false], [$_rbac, $_multitenancy]);
				if ($id === 'gone') {
					throw new DoesNotExistException('x');
				}

				if ($id === 'boom') {
					throw new RuntimeException('x');
				}

				return $schema;
			}
		);
		$this->status->method('completenessForObject')->with('u-1', $schema)->willReturn(['nl' => 1.0]);
		$c = $this->controller();
		$this->assertSame(['translations' => [['property' => 'title']], 'completeness' => []], $c->showByObject('u-1')->getData());
		$this->assertSame(['translations' => [['property' => 'title']], 'completeness' => []], $c->showByObject('u-1', '')->getData());
		$this->assertSame(['translations' => [['property' => 'title']], 'completeness' => []], $c->showByObject('u-1', 'gone')->getData());
		$this->assertSame(['translations' => [['property' => 'title']], 'completeness' => []], $c->showByObject('u-1', 'boom')->getData());
		$this->assertSame(['translations' => [['property' => 'title']], 'completeness' => ['nl' => 1.0]], $c->showByObject('u-1', 'zaak')->getData());
	}

	/**
	 * Set status.
	 *
	 * @return void
	 */
	public function testSetStatus(): void {
		$row = $this->createMock(Translation::class);
		$row->method('jsonSerialize')->willReturn(['status' => 'human_reviewed']);
		$this->status->method('setStatus')->willReturnCallback(
			function (TranslationSlot $slot, string $status) use ($row): Translation {
				if ($status === 'bogus') {
					throw new InvalidArgumentException('bad status');
				}

				return $row;
			}
		);
		$c = $this->controller();
		$r = $c->setStatus('u', 'p', 'nl');
		$this->assertSame([400, ['error' => 'status is required']], [$r->getStatus(), $r->getData()]);
		$r = $c->setStatus('u', 'p', 'nl', '');
		$this->assertSame([400, ['error' => 'status is required']], [$r->getStatus(), $r->getData()]);
		$r = $c->setStatus('u', 'p', 'nl', 'bogus');
		$this->assertSame([400, ['error' => 'bad status']], [$r->getStatus(), $r->getData()]);
		$r = $c->setStatus('u', 'p', 'nl', 'human_reviewed');
		$this->assertSame([200, ['status' => 'human_reviewed']], [$r->getStatus(), $r->getData()]);
	}

	/**
	 * Bulk translate.
	 *
	 * @return void
	 */
	public function testBulkTranslate(): void {
		$object = new ObjectEntity();
		$this->objects->method('find')->willReturnCallback(function (string|int $id) use ($object): ObjectEntity {
			if ($id === 'gone') {
				throw new DoesNotExistException('x');
			}

			if ($id === 'boom') {
				throw new RuntimeException('x');
			}

			return $object;
		});
		$this->bulk->method('translate')->with(new ObjectTranslationRequest(object: $object, fromLong: 'nl', toLong: 'en'), ['title'])->willReturn(['translated' => ['title' => 'T'], 'skipped' => []]);
		$c = $this->controller();
		foreach ([[null, 'en'], ['', 'en'], ['nl', null], ['nl', '']] as [$from, $to]) {
			$r = $c->bulkTranslate('u', $from, $to);
			$this->assertSame([400, ['error' => 'from and to are required']], [$r->getStatus(), $r->getData()]);
		}

		foreach (['gone', 'boom'] as $uuid) {
			$r = $c->bulkTranslate($uuid, 'nl', 'en');
			$this->assertSame([404, ['error' => 'object not found', 'uuid' => $uuid]], [$r->getStatus(), $r->getData()]);
		}

		$r = $c->bulkTranslate('u', 'nl', 'en', ['title']);
		$this->assertSame(
			[200, ['uuid' => 'u', 'from' => 'nl', 'to' => 'en', 'translated' => ['title' => 'T'], 'skipped' => []]],
			[$r->getStatus(), $r->getData()]
		);
	}
}//end class
