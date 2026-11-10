<?php

/**
 * Behaviour tests for ExampleSetCollectivesStore: reuse-or-create of collectives
 * and pages, and the delete calls it makes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;


use OCA\OpenRegister\Service\ExampleSet\CollectivePageText;
use OCA\OpenRegister\Service\ExampleSet\CollectiveSeat;
use OCA\OpenRegister\Service\ExampleSet\CollectiveTeam;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetCollectivesStore;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Behaviour of the Collectives store against stand-in Collectives services.
 */
class ExampleSetCollectivesStoreBehaviourTest extends TestCase {

	/** @var ContainerInterface&MockObject */
	private ContainerInterface $container;

	/** @var IAppManager&MockObject */
	private IAppManager $appManager;

	/** @var array<string, object> Services by id. */
	private array $services = [];

	protected function setUp(): void {
		$this->container  = $this->createMock(ContainerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->container->method('get')->willReturnCallback(fn (string $id): object => $this->services[$id]);
	}//end setUp()

	private function store(): ExampleSetCollectivesStore {
		return new ExampleSetCollectivesStore($this->container, $this->appManager);
	}//end store()

	/**
	 * An entity stand-in answering getId/getCircleId/getTitle.
	 *
	 * @param int    $id     The id.
	 * @param string $title  The title.
	 * @param string $circle The circle id.
	 *
	 * @return object
	 */
	private static function entity(int $id, string $title='', string $circle='c'): object {
		return new class($id, $title, $circle) {
			public function __construct(private int $id, private string $title, private string $circle) {
			}

			public function getId(): int {
				return $this->id;
			}

			public function getTitle(): string {
				return $this->title;
			}

			public function getCircleId(): string {
				return $this->circle;
			}
		};
	}//end entity()

	/**
	 * A Collectives service stand-in that records its calls.
	 *
	 * @param object|null $existing The collective found by name, or null to throw.
	 *
	 * @return object
	 */
	private function collectives(?object $existing): object {
		$service = new class($existing, self::entity(id: 77)) {
			public array $calls = [];

			public function __construct(private ?object $existing, private object $made) {
			}

			public function findCollectiveByName(string $owner, string $name): object {
				$this->calls[] = ['find', $owner, $name];
				if ($this->existing === null) {
					throw new \RuntimeException('none');
				}

				return $this->existing;
			}

			public function createCollective(string $owner, string $lang, string $name): array {
				$this->calls[] = ['create', $owner, $lang, $name];
				return [$this->made];
			}

			public function trashCollective(int $id, string $owner): void {
				$this->calls[] = ['trash', $id, $owner];
			}

			public function deleteCollective(int $id, string $owner, bool $circle): void {
				$this->calls[] = ['delete', $id, $owner, $circle];
			}
		};
		$this->services['OCA\Collectives\Service\CollectiveService'] = $service;
		return $service;
	}//end collectives()

	/**
	 * A Page service stand-in that records its calls.
	 *
	 * @param object[] $pages    The existing pages.
	 * @param bool     $findOk   Whether find() succeeds.
	 *
	 * @return object
	 */
	private function pages(array $pages=[], bool $findOk=true): object {
		$service = new class($pages, $findOk, self::entity(id: 501)) {
			public array $calls = [];

			public function __construct(private array $pages, private bool $findOk, private object $made) {
			}

			public function findAll(int $collective, string $owner): array {
				$this->calls[] = ['findAll', $collective, $owner];
				return $this->pages;
			}

			public function createBase(int $collective, int $parent, string $title, ?string $template, string $owner, ?string $x, string $content): object {
				$this->calls[] = ['createBase', $collective, $parent, $title, $template, $owner, $x, $content];
				return $this->made;
			}

			public function find(int $collective, int $page, string $owner): object {
				if ($this->findOk === false) {
					throw new \RuntimeException('gone');
				}

				return $this->made;
			}

			public function trash(int $collective, int $page, string $owner, bool $direct): void {
				$this->calls[] = ['trash', $collective, $page, $owner, $direct];
			}
		};
		$this->services['OCA\Collectives\Service\PageService'] = $service;
		return $service;
	}//end pages()

	public function testNotAvailableWhenTheAppIsNotInstalled(): void {
		$this->appManager->expects($this->once())->method('isInstalled')->with('collectives')->willReturn(false);

		$this->assertFalse($this->store()->available());
	}

	public function testAnExistingCollectiveIsReusedNotCreated(): void {
		$service = $this->collectives(existing: self::entity(id: 12));

		$this->assertSame(['id' => 12, 'created' => false], $this->store()->collectiveOf(new CollectiveTeam('admin', []), 'Demo'));
		$this->assertSame([['find', 'admin', 'Demo']], $service->calls);
	}

	public function testAMissingCollectiveIsCreatedInDutch(): void {
		$service = $this->collectives(existing: null);

		$this->assertSame(['id' => 77, 'created' => true], $this->store()->collectiveOf(new CollectiveTeam('admin', []), 'Demo'));
		$this->assertSame([['find', 'admin', 'Demo'], ['create', 'admin', 'nl', 'Demo']], $service->calls);
	}

	public function testAPageWithTheSameTitleIsReused(): void {
		$pages = $this->pages(pages: [self::entity(id: 3, title: 'Other'), self::entity(id: 4, title: 'Welkom')]);

		$this->assertSame(['id' => 4, 'created' => false], $this->store()->page(new CollectiveSeat(9, 'admin'), new CollectivePageText('Welkom', 'body')));
		$this->assertSame([['findAll', 9, 'admin']], $pages->calls);
	}

	public function testANewPageIsCreatedAtTheRootWithItsContent(): void {
		$pages = $this->pages(pages: [self::entity(id: 3, title: 'Other')]);

		$this->assertSame(['id' => 501, 'created' => true], $this->store()->page(new CollectiveSeat(9, 'admin'), new CollectivePageText('Welkom', 'body')));
		$this->assertSame(['createBase', 9, 0, 'Welkom', null, 'admin', null, 'body'], $pages->calls[1]);
	}

	public function testPageExistsFollowsTheFindOutcome(): void {
		$this->pages(findOk: true);
		$this->assertTrue($this->store()->pageFound(new CollectiveSeat(1, 'admin'), 2));

		$this->pages(findOk: false);
		$this->assertFalse($this->store()->pageFound(new CollectiveSeat(1, 'admin'), 2));
	}

	public function testDeletePageTrashesItDirectly(): void {
		$pages = $this->pages();

		$this->store()->deletePage(new CollectiveSeat(1, 'admin'), 2);

		$this->assertSame([['trash', 1, 2, 'admin', true]], $pages->calls);
	}

	public function testDeleteCollectiveTrashesThenDeletesWithItsCircle(): void {
		$service = $this->collectives(existing: null);

		$this->store()->deleteCollective(5, 'admin');

		$this->assertSame([['trash', 5, 'admin'], ['delete', 5, 'admin', true]], $service->calls);
	}
}//end class
