<?php

/**
 * Unit tests for promotion between OTAP environments.
 *
 * The order refusal, the uuid remapping and the draft landing of
 * `POST /api/organisations/{uuid}/promote`, plus the promotion entry in both
 * organisations' audit trail. Nothing here may write a live value: the only
 * write a promotion makes is drafts into a set.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Organisation
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Organisation;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditEntry;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditEntryWriterInterface;
use OCA\OpenRegister\Kernel\Contracts\PromotionLandingInterface;
use OCA\OpenRegister\Kernel\Contracts\PromotionRefusedException;
use OCA\OpenRegister\Service\Organisation\PromotionComponent;
use OCA\OpenRegister\Service\Organisation\PromotionPackageReaderInterface;
use OCA\OpenRegister\Service\Organisation\PromotionRemap;
use OCA\OpenRegister\Service\Organisation\PromotionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(PromotionService::class)]
#[UsesClass(PromotionRemap::class)]
#[UsesClass(PromotionComponent::class)]
#[UsesClass(PromotionRefusedException::class)]
final class PromotionServiceTest extends TestCase {

	private const SOURCE = '11111111-1111-4111-8111-111111111111';

	private const TARGET = '22222222-2222-4222-8222-222222222222';

	private const REGISTER = '33333333-3333-4333-8333-333333333333';

	private const SCHEMA = '44444444-4444-4444-8444-444444444444';

	private OrganisationMapperInterface&MockObject $organisations;

	private PromotionPackageReaderInterface&MockObject $package;

	private PromotionLandingInterface&MockObject $landing;

	private AuditEntryWriterInterface&MockObject $audit;

	private LoggerInterface&MockObject $logger;

	/**
	 * Every land() call, as [name, description, drafts].
	 *
	 * @var list<array{0: string, 1: string, 2: list<array<string, mixed>>}>
	 */
	private array $landed = [];

	/**
	 * Every batch of audit entries written.
	 *
	 * @var list<list<AuditEntry>>
	 */
	private array $audited = [];

	protected function setUp(): void {
		parent::setUp();

		$this->organisations = $this->createMock(OrganisationMapperInterface::class);
		$this->package       = $this->createMock(PromotionPackageReaderInterface::class);
		$this->landing       = $this->createMock(PromotionLandingInterface::class);
		$this->audit         = $this->createMock(AuditEntryWriterInterface::class);
		$this->logger        = $this->createMock(LoggerInterface::class);

		$this->landing->method('land')->willReturnCallback(function (string $name, string $description, array $drafts): array {
			$this->landed[] = [$name, $description, $drafts];
			return ['uuid' => 'set-' . count($this->landed), 'name' => $name, 'state' => 'open'];
		});
		$this->audit->method('writeAuditEntries')->willReturnCallback(function (array $entries): void {
			$this->audited[] = $entries;
		});
	}

	private function service(?IUser $user = null): PromotionService {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new PromotionService(
			organisations: $this->organisations,
			package: $this->package,
			landing: $this->landing,
			auditEntries: $this->audit,
			userSession: $session,
			logger: $this->logger
		);
	}

	private function organisation(string $uuid, ?string $environment, string $name): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid($uuid);
		$organisation->setName($name);
		$organisation->setEnvironment($environment);

		return $organisation;
	}

	private function knownOrganisations(?string $sourceEnvironment, ?string $targetEnvironment): void {
		$source = $this->organisation(self::SOURCE, $sourceEnvironment, 'Dev org');
		$target = $this->organisation(self::TARGET, $targetEnvironment, 'Acc org');
		$this->organisations->method('findByUuid')->willReturnCallback(
			static fn (string $uuid): Organisation => match ($uuid) {
				self::SOURCE => $source,
				self::TARGET => $target,
				default => throw new DoesNotExistException('none'),
			}
		);
	}

	private function assertLandedInAPromotionSet(): void {
		$this->assertCount(1, $this->landed);
		$this->assertSame('Promotion from Dev org', $this->landed[0][0]);
		$this->assertStringContainsString(self::SOURCE . ' (test) to ' . self::TARGET . ' (acceptance)', $this->landed[0][1]);
	}

	/**
	 * @return list<PromotionComponent>
	 */
	private function package(): array {
		return [
			new PromotionComponent(type: PromotionComponent::TYPE_REGISTER, sourceUuid: self::REGISTER, definition: ['slug' => 'cases', 'title' => 'Cases']),
			new PromotionComponent(
				type: PromotionComponent::TYPE_SCHEMA,
				sourceUuid: self::SCHEMA,
				definition: [
					'slug' => 'case',
					'properties' => ['register' => ['default' => self::REGISTER]],
					'description' => 'see /api/registers/' . self::REGISTER,
				]
			),
			new PromotionComponent(type: PromotionComponent::TYPE_WEBHOOK, sourceUuid: null, definition: ['name' => 'hook', 'schemas' => [self::SCHEMA]]),
		];
	}

	/**
	 * @return array<string, array{0: ?string, 1: ?string}>
	 */
	public static function backwardsOrSideways(): array {
		return [
			'production to development' => ['production', 'development'],
			'acceptance to test' => ['acceptance', 'test'],
			'test to test' => ['test', 'test'],
			'an organisation without an environment is production' => [null, 'acceptance'],
			'an environment outside the four' => ['staging', 'production'],
		];
	}

	#[DataProvider('backwardsOrSideways')]
	public function testAPromotionAgainstTheOtapOrderIsRefusedWithTheOrderBeforeAnythingIsRead(?string $from, ?string $to): void {
		$this->knownOrganisations($from, $to);
		$this->package->expects($this->never())->method('componentsOf');
		$this->landing->expects($this->never())->method('land');

		try {
			$this->service()->promote(self::SOURCE, self::TARGET);
			$this->fail('a promotion against the OTAP order must be refused');
		} catch (PromotionRefusedException $refusal) {
			$this->assertSame(400, $refusal->statusCode);
			$this->assertSame('Promotion must follow OTAP order: development -> test -> acceptance -> production', $refusal->getMessage());
			$this->assertSame(PromotionService::REASON_INVALID, $refusal->reason);
		}

		$this->assertSame([], $this->audited);
	}

	public function testPromotingAnOrganisationOntoItselfIsRefused(): void {
		$organisation = $this->organisation(self::SOURCE, 'development', 'Dev org');
		$this->organisations->method('findByUuid')->willReturn($organisation);
		$this->landing->expects($this->never())->method('land');

		$this->expectException(PromotionRefusedException::class);
		$this->expectExceptionMessage(PromotionService::ORDER_MESSAGE);
		$this->service()->promote(self::SOURCE, self::SOURCE);
	}

	public function testAnUnknownTargetIsA404NamingIt(): void {
		$this->knownOrganisations('test', 'acceptance');

		try {
			$this->service()->promote(self::SOURCE, 'nope');
			$this->fail('an unknown organisation must be refused');
		} catch (PromotionRefusedException $refusal) {
			$this->assertSame(404, $refusal->statusCode);
			$this->assertSame('no organisation "nope"', $refusal->getMessage());
		}
	}

	public function testASourceWithNothingToPromoteOpensNoSet(): void {
		$this->knownOrganisations('test', 'acceptance');
		$this->package->expects($this->once())->method('componentsOf')->with(self::SOURCE)->willReturn([]);
		$this->landing->expects($this->never())->method('land');

		try {
			$this->service()->promote(self::SOURCE, self::TARGET);
			$this->fail('an empty package must be refused');
		} catch (PromotionRefusedException $refusal) {
			$this->assertSame(409, $refusal->statusCode);
			$this->assertSame(PromotionService::REASON_EMPTY, $refusal->reason);
		}
	}

	public function testThePackageLandsAsDraftsInAPromotionSetWithEveryUuidRemapped(): void {
		$this->knownOrganisations('test', 'acceptance');
		$this->package->method('componentsOf')->willReturn($this->package());

		$result = $this->service()->promote(self::SOURCE, self::TARGET);

		$this->assertLandedInAPromotionSet();
		$remapped = $result['remapped'];
		$this->assertSame([self::REGISTER, self::SCHEMA], array_keys($remapped));
		$newRegister = $remapped[self::REGISTER];
		$newSchema   = $remapped[self::SCHEMA];
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $newRegister);
		$this->assertNotSame(self::REGISTER, $newRegister);
		$this->assertNotSame($newRegister, $newSchema);

		$drafts = $this->landed[0][2];
		$this->assertCount(3, $drafts);
		$this->assertSame(
			[
				'layer' => PromotionLandingInterface::LAYER_REGISTER,
				'layerRef' => $newRegister,
				'configKey' => 'register.definition',
				'value' => ['slug' => 'cases', 'title' => 'Cases', 'uuid' => $newRegister, 'organisation' => self::TARGET],
			],
			$drafts[0]
		);

		$schema = $drafts[1];
		$this->assertSame(PromotionLandingInterface::LAYER_SUBJECT, $schema['layer']);
		$this->assertSame($newSchema, $schema['layerRef']);
		$this->assertSame('schema.definition', $schema['configKey']);
		// A whole-string reference follows the register; a uuid inside text stays text.
		$this->assertSame($newRegister, $schema['value']['properties']['register']['default']);
		$this->assertSame('see /api/registers/' . self::REGISTER, $schema['value']['description']);
		$this->assertSame(self::TARGET, $schema['value']['organisation']);

		$webhook = $drafts[2];
		$this->assertSame('integration.webhook', $webhook['configKey']);
		$this->assertSame([$newSchema], $webhook['value']['schemas']);
		// A component without a source uuid still gets a fresh one of its own.
		$this->assertSame($webhook['value']['uuid'], $webhook['layerRef']);
		$this->assertNotContains($webhook['value']['uuid'], [$newRegister, $newSchema]);

		$this->assertSame(['register' => 1, 'schema' => 1, 'webhook' => 1], $result['components']);
		$this->assertSame('set-1', $result['set']['uuid']);
		$this->assertSame(self::SOURCE, $result['source']);
		$this->assertSame(self::TARGET, $result['target']);
	}

	public function testALandingRefusalReachesTheCallerAndNothingIsAudited(): void {
		$this->knownOrganisations('test', 'acceptance');
		$this->package->method('componentsOf')->willReturn($this->package());
		$landing = $this->createMock(PromotionLandingInterface::class);
		$landing->method('land')->willThrowException(new PromotionRefusedException(reason: 'invalid', message: 'refused draft', statusCode: 400));
		$this->landing = $landing;

		try {
			$this->service()->promote(self::SOURCE, self::TARGET);
			$this->fail('a refused landing must reach the caller');
		} catch (PromotionRefusedException $refusal) {
			$this->assertSame('refused draft', $refusal->getMessage());
		}

		$this->assertSame([], $this->audited);
	}

	public function testBothOrganisationsGetAPromotionEntryNamingTheSet(): void {
		$this->knownOrganisations('test', 'acceptance');
		$this->package->method('componentsOf')->willReturn($this->package());
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$user->method('getDisplayName')->willReturn('Admin');

		$this->service($user)->promote(self::SOURCE, self::TARGET);

		$this->assertCount(1, $this->audited);
		$entries = $this->audited[0];
		$this->assertSame([self::SOURCE, self::TARGET], array_map(static fn (AuditEntry $entry): ?string => $entry->objectUuid, $entries));
		$this->assertSame([self::SOURCE, self::TARGET], array_map(static fn (AuditEntry $entry): ?string => $entry->organisationId, $entries));
		foreach ($entries as $entry) {
			$this->assertSame(PromotionService::ACTION_PROMOTED, $entry->action);
			$this->assertSame('admin', $entry->user);
			$this->assertSame(
				['source' => self::SOURCE, 'target' => self::TARGET, 'set' => 'set-1', 'components' => ['register' => 1, 'schema' => 1, 'webhook' => 1]],
				$entry->changed['promotion']['new']
			);
		}

		$this->assertNotSame($entries[0]->uuid, $entries[1]->uuid);
	}

	public function testAnAuditFailureIsLoggedAndDoesNotUndoTheLandedSet(): void {
		$this->knownOrganisations('development', 'test');
		$this->package->method('componentsOf')->willReturn($this->package());
		$audit = $this->createMock(AuditEntryWriterInterface::class);
		$audit->method('writeAuditEntries')->willThrowException(new RuntimeException('disk full'));
		$this->audit = $audit;
		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains('Could not record a promotion'), $this->callback(static fn (array $context): bool => $context['exception'] === 'disk full'));

		$result = $this->service()->promote(self::SOURCE, self::TARGET);

		$this->assertSame('set-1', $result['set']['uuid']);
		$this->assertCount(3, $this->landed[0][2]);
	}
}
