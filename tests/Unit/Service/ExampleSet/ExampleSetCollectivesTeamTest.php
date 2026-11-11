<?php

/**
 * Characterisation of how ExampleSetCollectivesStore adds groups to a collective's team.
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

use OCA\OpenRegister\Service\ExampleSet\CollectiveTeam;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetCollectivesStore;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * A Circles manager stand-in that records the session and the members added; a group named `refused` is refused.
 */
class ExampleSetCollectivesTeamCircles {

	/** @var array<int, array<int, mixed>> */
	public array $calls = [];

	public function getFederatedUser(string $id, int $type): string {
		return $type.':'.$id;
	}

	public function startSession(string $user): void {
		$this->calls[] = ['start', $user];
	}

	public function addMember(string $circleId, string $member): void {
		if ($member === '2:refused') {
			throw new RuntimeException('refused');
		}

		$this->calls[] = ['add', $circleId, $member];
	}

	public function stopSession(): void {
		$this->calls[] = ['stop'];
	}
}

/**
 * The groups of a collective's team, added in the owner's Circles session.
 */
class ExampleSetCollectivesTeamTest extends TestCase {

	/** @var array<string, object> Services by id. */
	private array $services = [];

	/** @var ExampleSetCollectivesTeamCircles The Circles manager stand-in. */
	private ExampleSetCollectivesTeamCircles $circles;

	protected function setUp(): void {
		if (class_exists('OCA\Circles\CirclesManager') === false) {
			class_alias(ExampleSetCollectivesTeamCircles::class, 'OCA\Circles\CirclesManager');
		}

		$this->circles = new ExampleSetCollectivesTeamCircles();
		$this->services['OCA\Circles\CirclesManager'] = $this->circles;
	}//end setUp()

	private function store(?object $existing): ExampleSetCollectivesStore {
		$this->services['OCA\Collectives\Service\CollectiveService'] = new class($existing) {
			public function __construct(private ?object $existing) {
			}

			public function findCollectiveByName(string $owner, string $name): object {
				if ($this->existing === null) {
					throw new RuntimeException('none');
				}

				return $this->existing;
			}

			public function createCollective(string $owner, string $lang, string $name): array {
				return [new class {
					public function getId(): int {
						return 77;
					}

					public function getCircleId(): string {
						return 'made-circle';
					}
				},
				];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id): object => $this->services[$id]);

		return new ExampleSetCollectivesStore($container, $this->createMock(IAppManager::class));
	}//end store()

	private static function found(): object {
		return new class {
			public function getId(): int {
				return 12;
			}

			public function getCircleId(): string {
				return 'found-circle';
			}
		};
	}//end found()

	public function testAFoundCollectiveGainsEachGroupInTheOwnersSessionAndARefusedGroupIsLeftOut(): void {
		$result = $this->store(existing: self::found())->collectiveOf(new CollectiveTeam('admin', ['g1', 'refused', 'g2']), 'Demo');

		$this->assertSame(['id' => 12, 'created' => false], $result);
		$this->assertSame(
			[['start', '1:admin'], ['add', 'found-circle', '2:g1'], ['add', 'found-circle', '2:g2'], ['stop']],
			$this->circles->calls
		);
	}

	public function testAMadeCollectiveGainsItsGroupsOnItsOwnCircle(): void {
		$result = $this->store(existing: null)->collectiveOf(new CollectiveTeam('admin', ['g1']), 'Demo');

		$this->assertSame(['id' => 77, 'created' => true], $result);
		$this->assertSame([['start', '1:admin'], ['add', 'made-circle', '2:g1'], ['stop']], $this->circles->calls);
	}

	public function testNoGroupsOpensNoCirclesSession(): void {
		$this->store(existing: self::found())->collectiveOf(new CollectiveTeam('admin', []), 'Demo');

		$this->assertSame([], $this->circles->calls);
	}
}
