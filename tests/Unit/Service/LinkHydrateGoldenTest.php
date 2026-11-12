<?php

/**
 * Golden pins for the link rows the Analytics, Collective, OpenProject, TimeTracker and Xwiki link services build.
 *
 * Each scenario records the fields of the unsaved link row built for a target
 * object, a remote entity and its metadata, and compares them with
 * tests/Unit/Service/fixtures/link-hydrate-golden.json. Run with
 * UPDATE_GOLDEN=1 to re-record.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTimeInterface;
use OCA\OpenRegister\Service\AnalyticsLinkService;
use OCA\OpenRegister\Service\CollectiveLinkService;
use OCA\OpenRegister\Service\LinkTarget;
use OCA\OpenRegister\Service\OpenProjectLinkService;
use OCA\OpenRegister\Service\TimeTracker\TimeEntry;
use OCA\OpenRegister\Service\TimeTrackerLinkService;
use OCA\OpenRegister\Service\XwikiLinkService;
use OCP\AppFramework\Db\Entity;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden pins for the link services' row builders.
 *
 * @covers \OCA\OpenRegister\Service\AnalyticsLinkService
 * @covers \OCA\OpenRegister\Service\CollectiveLinkService
 * @covers \OCA\OpenRegister\Service\OpenProjectLinkService
 * @covers \OCA\OpenRegister\Service\TimeTrackerLinkService
 * @covers \OCA\OpenRegister\Service\XwikiLinkService
 * @covers \OCA\OpenRegister\Service\LinkTarget
 * @uses   \OCA\OpenRegister\Db\AnalyticsLink
 * @uses   \OCA\OpenRegister\Db\CollectiveLink
 * @uses   \OCA\OpenRegister\Db\OpenProjectLink
 * @uses   \OCA\OpenRegister\Db\TimeTrackerLink
 * @uses   \OCA\OpenRegister\Db\XwikiLink
 */
class LinkHydrateGoldenTest extends TestCase {

	/**
	 * Scenarios: service, remote id (entry type and id for TimeTracker), metadata.
	 *
	 * @return array<string, array{0: string, 1: array<int, int|string>, 2: array<string, mixed>}>
	 */
	public static function scenarios(): array {
		return [
			'analytics full' => ['analytics', [12], ['title' => 'Omzet', 'type' => 'chart', 'subheader' => 'Q4', 'createdAt' => null, 'modifiedAt' => null]],
			'analytics no title' => ['analytics', [13], ['type' => null, 'subheader' => null, 'createdAt' => null, 'modifiedAt' => null]],
			'collective full' => ['collective', [21], ['collectiveId' => '4', 'collectiveName' => 'Team', 'title' => 'Plan', 'slug' => 'plan', 'emoji' => 'x', 'lastModified' => '2026-01-01T00:00:00+00:00', 'url' => '/c/plan']],
			'collective empty, deep link' => ['collective', [22], []],
			'openproject full' => ['openproject', [31], ['subject' => 'Fix', 'type' => ' Bug ', 'status' => 'New', 'priority' => '', 'assignee' => 7, 'project' => 'p', 'url' => 'https://op/wp/31']],
			'openproject id only' => ['openproject', [32], []],
			'timetracker client' => ['timetracker', ['client', 'c-1'], ['name' => 'Acme']],
			'timetracker task' => ['timetracker', ['task', 't-1'], ['name' => 'Build', 'duration' => 90, 'billable' => true, 'startedAt' => null]],
			'timetracker time' => ['timetracker', ['time', 'e-1'], []],
			'xwiki full' => ['xwiki', ['Main.Plan'], ['space' => 'Main', 'title' => 'Plan', 'url' => 'https://x/Main/Plan']],
			'xwiki reference only' => ['xwiki', ['Main.Other'], []],
		];
	}//end scenarios()

	/**
	 * The built row's fields match the recorded golden.
	 *
	 * @param string                  $kind The service.
	 * @param array<int, int|string>  $id   The remote id (entry type and id for TimeTracker).
	 * @param array<string, mixed>    $info The remote metadata.
	 *
	 * @return void
	 *
	 * @dataProvider scenarios
	 */
	public function testBuildsTheLinkRow(string $kind, array $id, array $info): void {
		$classes = [
			'analytics' => AnalyticsLinkService::class,
			'collective' => CollectiveLinkService::class,
			'openproject' => OpenProjectLinkService::class,
			'timetracker' => TimeTrackerLinkService::class,
			'xwiki' => XwikiLinkService::class,
		];
		$service = (new ReflectionClass($classes[$kind]))->newInstanceWithoutConstructor();
		if ($kind === 'collective') {
			$urls = $this->createMock(IURLGenerator::class);
			$urls->method('linkToRoute')->willReturn('/apps/collectives/');
			(fn () => $this->urlGenerator = $urls)->call($service);
		}

		$link = $this->build(service: $service, kind: $kind, id: $id, info: $info);

		$actual = json_decode(json_encode($this->fields(entity: $link)), true);
		$file = __DIR__ . '/fixtures/link-hydrate-golden.json';
		$all = [];
		if (is_file($file) === true) {
			$all = json_decode(file_get_contents($file), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$this->dataName()] = $actual;
			ksort($all);
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertArrayHasKey($this->dataName(), $all);
		$this->assertSame($all[$this->dataName()], $actual);
	}//end testBuildsTheLinkRow()

	/**
	 * Build the row for target obj-1 (register 3, schema 4), linked by alice.
	 *
	 * @param object                 $service The service.
	 * @param string                 $kind    The service kind.
	 * @param array<int, int|string> $id      The remote id.
	 * @param array<string, mixed>   $info    The remote metadata.
	 *
	 * @return Entity
	 */
	private function build(object $service, string $kind, array $id, array $info): Entity {
		$method = 'hydrateLink';
		if ($kind === 'analytics') {
			$method = 'buildLink';
		}

		$args = [...$id, $info];
		if ($kind === 'timetracker') {
			$args = [new TimeEntry(type: $id[0], id: $id[1]), $info];
		}

		$link = (fn (...$args) => $this->{$method}(...$args))->call($service, ...$args);
		(new LinkTarget('obj-1', 3))->inSchema(4)->stamp($link, 'alice');
		return $link;
	}//end build()

	/**
	 * The entity's fields, with timestamps set at build time reduced to their type.
	 *
	 * @param Entity $entity The entity.
	 *
	 * @return array<string, mixed>
	 */
	private function fields(Entity $entity): array {
		$fields = [];
		foreach (array_keys($entity->getFieldTypes()) as $field) {
			$value = $entity->{'get' . ucfirst($field)}();
			if ($value instanceof DateTimeInterface) {
				$value = in_array($field, ['linkedAt', 'cachedAt'], true) ? 'set' : $value->format('c');
			}

			$fields[$field] = $value;
		}

		return $fields;
	}//end fields()
}//end class
