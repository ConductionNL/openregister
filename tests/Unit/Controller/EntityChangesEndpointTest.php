<?php

/**
 * EntityChangesEndpointTest: GET /api/schemas/{id}/changes and GET /api/registers/{id}/changes.
 *
 * Asserts the page reads the entity's own edit rows through the audit
 * mapper's filters, newest first, with `_page` and `_limit` clamped to 1-100,
 * reports whether another page follows, and that both actions are
 * administrator-only and routed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/history-schema-and-settings-edits-audited/specs/audit-trail-immutable/spec.md#requirement-administrators-read-an-entitys-change-history
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\RegistersController;
use OCA\OpenRegister\Controller\SchemasController;
use OCA\OpenRegister\Controller\Support\EntityChangesPage;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class EntityChangesEndpointTest extends TestCase {

	/** @var list<ListQuery> The queries the mapper was asked. */
	private array $queries = [];

	private function page(int $rowCount): EntityChangesPage {
		$mapper = $this->createMock(AuditTrailMapperInterface::class);
		$mapper->method('findTrail')->willReturnCallback(
			function (ListQuery $query) use ($rowCount): array {
				$this->queries[] = $query;
				$rows = [];
				for ($i = 1; $i <= min($rowCount, $query->limit); $i++) {
					$row = new AuditTrail();
					$row->setAction('schema.updated');
					$row->setUser('admin-' . $i);
					$rows[] = $row;
				}

				return $rows;
			}
		);

		return new EntityChangesPage(auditTrails: $mapper);
	}

	public function testASchemaPageReadsItsOwnEditRowsNewestFirst(): void {
		$page = $this->page(3)->read('schema', 12, []);

		$this->assertCount(1, $this->queries);
		$query = $this->queries[0];
		$this->assertSame(21, $query->limit);
		$this->assertSame(0, $query->offset);
		$this->assertSame(
			['schema' => 12, 'action' => ['schema.created', 'schema.updated', 'schema.deleted']],
			$query->filters
		);
		$this->assertSame(['created' => 'DESC', 'id' => 'DESC'], $query->sort);

		$this->assertSame(1, $page['page']);
		$this->assertSame(20, $page['limit']);
		$this->assertFalse($page['hasMore']);
		$this->assertSame(['admin-1', 'admin-2', 'admin-3'], array_column($page['results'], 'user'));
		$this->assertSame('schema.updated', $page['results'][0]['action']);
	}

	public function testARegisterPageFiltersOnTheRegisterColumn(): void {
		$this->page(0)->read('register', 4, ['_page' => '3', '_limit' => '10']);

		$query = $this->queries[0];
		$this->assertSame(['register' => 4, 'action' => ['register.created', 'register.updated', 'register.deleted']], $query->filters);
		$this->assertSame(11, $query->limit);
		$this->assertSame(20, $query->offset);
	}

	public function testOneRowMoreThanThePageSaysAnotherPageFollows(): void {
		$page = $this->page(50)->read('schema', 12, ['_limit' => 5]);

		$this->assertTrue($page['hasMore']);
		$this->assertCount(5, $page['results']);
	}

	public function testTheLimitIsClampedToOneToAHundredAndThePageToOne(): void {
		$this->page(0)->read('schema', 12, ['_limit' => 5000, '_page' => -2]);
		$this->page(0)->read('schema', 12, ['_limit' => 0]);

		$this->assertSame(101, $this->queries[0]->limit);
		$this->assertSame(0, $this->queries[0]->offset);
		$this->assertSame(2, $this->queries[1]->limit);
	}

	public function testAnEntityWithoutAnEditHistoryIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->page(0)->read('object', 1, []);
	}

	public function testBothActionsAreAdministratorOnly(): void {
		foreach ([SchemasController::class, RegistersController::class] as $controller) {
			$method = new ReflectionMethod($controller, 'changes');
			$this->assertSame([], $method->getAttributes(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class), $controller);
			$this->assertStringNotContainsString('@NoAdminRequired', (string) $method->getDocComment(), $controller);
			$this->assertStringNotContainsString('@PublicPage', (string) $method->getDocComment(), $controller);
		}
	}

	public function testBothActionsAreRouted(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$urls = [];
		foreach ($routes['routes'] as $route) {
			$urls[$route['name']][] = $route['verb'] . ' ' . $route['url'];
		}

		$this->assertContains('GET /api/schemas/{id}/changes', $urls['schemas#changes']);
		$this->assertContains('GET /api/registers/{id}/changes', $urls['registers#changes']);
	}
}
