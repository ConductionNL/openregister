<?php

/**
 * Golden test pinning every field ViewService::create() writes on a new view.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Search\Db\View;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;
use OCA\OpenRegister\Search\Service\ViewService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The fields a created view carries, the default it takes over, and the refusals before the insert.
 *
 * @covers \OCA\OpenRegister\Search\Service\ViewService
 */
final class ViewServiceCreateGoldenTest extends TestCase {

	/** @var array<string, mixed> A well-formed alert as a client sends it. */
	private const ALERT = ['operator' => 'gte', 'threshold' => 20, 'recipients' => ['user:alice']];

	/**
	 * Mapper calls, in order.
	 *
	 * @var string[]
	 */
	private array $calls = [];

	/**
	 * Build the service; the mapper lists one earlier view of alice.
	 *
	 * @param View $earlier The view alice already has.
	 *
	 * @return ViewService
	 */
	private function service(View $earlier): ViewService {
		$mapper = $this->createMock(ViewMapperInterface::class);
		$mapper->method('findAll')->willReturnCallback(
			function (string $owner) use ($earlier): array {
				$this->calls[] = 'findAll:' . $owner;
				return [$earlier];
			}
		);
		$mapper->method('insert')->willReturnCallback(
			function (View $view): View {
				$this->calls[] = 'insert';
				return $view;
			}
		);
		$mapper->method('update')->willReturnCallback(
			function (View $view): View {
				$this->calls[] = 'update:' . $view->getName() . ':' . var_export($view->getIsDefault(), true);
				return $view;
			}
		);

		return new ViewService($mapper, $this->createMock(LoggerInterface::class), $this->createMock(SchemaMapperInterface::class));
	}//end service()

	/**
	 * An earlier view of alice.
	 *
	 * @param bool $isDefault Whether it is her default.
	 *
	 * @return View
	 */
	private static function earlier(bool $isDefault): View {
		$view = new View();
		$view->setName('Earlier');
		$view->setOwner('alice');
		$view->setIsDefault($isDefault);
		return $view;
	}//end earlier()

	/**
	 * Every declared field lands on the inserted view; shares lose their keys, the alert is stored canonically.
	 *
	 * @return void
	 */
	public function testEveryFieldIsWritten(): void {
		$service = $this->service(self::earlier(true));
		$view    = $service->create(
			name: 'Mine',
			description: 'My view',
			owner: 'alice',
			isPublic: true,
			isDefault: false,
			query: ['schemas' => [], 'registers' => [3]],
			presentation: ['viewType' => 'table'],
			sharedWith: ['x' => ['group' => 'g', 'mode' => 'read']],
			alert: self::ALERT
		);

		$this->assertSame(['insert'], $this->calls);
		$this->assertSame(
			[
				'name' => 'Mine',
				'description' => 'My view',
				'owner' => 'alice',
				'isPublic' => true,
				'isDefault' => false,
				'query' => ['schemas' => [], 'registers' => [3]],
				'presentation' => ['viewType' => 'table'],
				'favoredBy' => [],
				'sharedWith' => [['group' => 'g', 'mode' => 'read']],
				'alert' => ['operator' => 'gte', 'threshold' => 20, 'recipients' => ['user:alice'], 'channels' => ['nc-notification'], 'every' => 300],
				'alertState' => null,
			],
			self::fields($view)
		);
	}//end testEveryFieldIsWritten()

	/**
	 * Left out, the optional fields are empty: no presentation, no shares, no alert.
	 *
	 * @return void
	 */
	public function testOptionalFieldsDefaultToEmpty(): void {
		$view = $this->service(self::earlier(false))->create('Bare', '', 'alice', false, false, []);

		$fields = self::fields($view);
		$this->assertNull($fields['presentation']);
		$this->assertSame([], $fields['sharedWith']);
		$this->assertNull($fields['alert']);
		$this->assertSame(['insert'], $this->calls);
	}//end testOptionalFieldsDefaultToEmpty()

	/**
	 * A new default view takes the default over from the owner's earlier default, before the insert.
	 *
	 * @return void
	 */
	public function testANewDefaultClearsTheEarlierOne(): void {
		$view = $this->service(self::earlier(true))->create('New', '', 'alice', false, true, []);

		$this->assertTrue($view->getIsDefault());
		$this->assertSame(['findAll:alice', 'update:Earlier:false', 'insert'], $this->calls);
	}//end testANewDefaultClearsTheEarlierOne()

	/**
	 * A malformed alert is refused before the insert; a refused presentation before anything.
	 *
	 * @return void
	 */
	public function testRefusalsComeBeforeTheInsert(): void {
		$service = $this->service(self::earlier(false));
		try {
			$service->create('A', '', 'alice', false, false, [], null, null, ['operator' => 'nope']);
			$this->fail('A malformed alert must be refused.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame([], $this->calls);
		}

		try {
			$service->create('B', '', 'alice', false, true, [], ['viewType' => 'gantt']);
			$this->fail('An unknown presentation must be refused.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('Unknown presentation viewType: gantt', $e->getMessage());
			$this->assertSame([], $this->calls);
		}
	}//end testRefusalsComeBeforeTheInsert()

	/**
	 * The fields of a view this test pins.
	 *
	 * @param View $view The view.
	 *
	 * @return array<string, mixed>
	 */
	private static function fields(View $view): array {
		return [
			'name' => $view->getName(),
			'description' => $view->getDescription(),
			'owner' => $view->getOwner(),
			'isPublic' => $view->getIsPublic(),
			'isDefault' => $view->getIsDefault(),
			'query' => $view->getQuery(),
			'presentation' => $view->getPresentation(),
			'favoredBy' => $view->getFavoredBy(),
			'sharedWith' => $view->getSharedWith(),
			'alert' => $view->getAlert(),
			'alertState' => $view->getAlertState(),
		];
	}//end fields()
}//end class
