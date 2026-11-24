<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Confidentiality;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\ConditionUserResolvers;
use OCA\OpenRegister\Service\Confidentiality\ConfidentialityReadGate;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The single-object read the confidentiality block admits or refuses.
 *
 * @covers \OCA\OpenRegister\Service\Confidentiality\ConfidentialityReadGate
 * @uses   \OCA\OpenRegister\Service\Confidentiality\ConfidentialityRule
 * @uses   \OCA\OpenRegister\Service\Confidentiality\ConfidentialityAnnotationValidator
 * @uses   \OCA\OpenRegister\Service\ConditionMatcher
 * @uses   \OCA\OpenRegister\Service\ConditionUserResolvers
 * @uses   \OCA\OpenRegister\Service\OperatorEvaluator
 * @uses   \OCA\OpenRegister\Service\ComparableInstants
 * @uses   \OCA\OpenRegister\Service\RecordMetadataConditions
 * @uses   \OCA\OpenRegister\Service\UndeclaredPropertyConditions
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Schema\AnnotationVocabulary
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 *
 * @spec openspec/changes/confidentiality-classification-primitive/specs/confidentiality-classification/spec.md#requirement-read-access-to-a-classified-object-must-require-tier-clearance-or-a-met-release-condition
 */
final class ConfidentialityReadGateTest extends TestCase {

	private const PROPERTIES = [
		'confidentialityTier' => ['type' => 'string'],
		'confidentialityGround' => ['type' => 'string'],
		'confidentialityReleaseUntil' => ['type' => 'string', 'format' => 'date-time'],
	];

	private const BESLUIT = [
		'tiers' => ['public', 'confidential'],
		'tierProperty' => 'confidentialityTier',
		'groundProperty' => 'confidentialityGround',
		'releaseAtProperty' => 'confidentialityReleaseUntil',
		'clearance' => ['confidential' => 'raadsleden'],
	];

	/**
	 * The gate over a real matcher, logging to the given logger.
	 *
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return ConfidentialityReadGate
	 */
	private function gate(?LoggerInterface $logger = null): ConfidentialityReadGate {
		$matcher = new ConditionMatcher(
			new ConditionUserResolvers($this->createMock(IUserSession::class)),
			$this->createMock(ContainerInterface::class),
			new OperatorEvaluator(new NullLogger()),
			new NullLogger()
		);

		return new ConfidentialityReadGate(matcher: $matcher, logger: ($logger ?? new NullLogger()));
	}

	/**
	 * A besluit schema carrying the given block, or none.
	 *
	 * @param array<string, mixed>|null $block The block.
	 * @param int                       $id    The schema id.
	 *
	 * @return Schema
	 */
	private static function schema(?array $block = self::BESLUIT, int $id = 4401): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('besluit');
		$schema->setProperties(self::PROPERTIES);
		$configuration = [];
		if ($block !== null) {
			$configuration['x-openregister-confidentiality'] = $block;
		}

		$schema->setConfiguration($configuration);

		return $schema;
	}

	/**
	 * Whether pieter (no clearance), in the given groups, is refused the object.
	 *
	 * @param array<string, mixed> $object The object data.
	 * @param array<int, string>   $groups The caller's groups.
	 * @param Schema|null          $schema The schema.
	 * @param string|null          $userId The caller.
	 *
	 * @return bool
	 */
	private function refused(array $object, array $groups = ['staff'], ?Schema $schema = null, ?string $userId = 'pieter'): bool {
		return $this->gate()->refuses(
			schema: ($schema ?? self::schema()),
			action: 'read',
			envelope: $object,
			userId: $userId,
			groups: $groups,
			owner: 'someone-else'
		);
	}

	public function testReadIsRefusedBelowClearanceAndBeforeRelease(): void {
		$this->assertTrue($this->refused(['confidentialityTier' => 'confidential']));
	}

	public function testReadIsAdmittedWithClearance(): void {
		$this->assertFalse($this->refused(['confidentialityTier' => 'confidential'], ['raadsleden']));
	}

	public function testAPastReleaseAdmitsRegardlessOfClearance(): void {
		$besluit = ['confidentialityTier' => 'confidential', 'confidentialityReleaseUntil' => '2020-01-01T00:00:00+00:00'];
		$this->assertFalse($this->refused($besluit));
		$this->assertFalse($this->refused($besluit, [], null, null), 'an anonymous caller too');
	}

	public function testAFutureReleaseDoesNotAdmit(): void {
		$besluit = ['confidentialityTier' => 'confidential', 'confidentialityReleaseUntil' => '2099-01-01T00:00:00+00:00'];
		$this->assertTrue($this->refused($besluit));
	}

	public function testTheLeastRestrictiveTierAnUnsetTierAndAnUnknownTierAreOpen(): void {
		$this->assertFalse($this->refused(['confidentialityTier' => 'public']));
		$this->assertFalse($this->refused([]));
		$this->assertFalse($this->refused(['confidentialityTier' => 'unheard-of']));
	}

	public function testATierWithoutClearanceInheritsTheLessRestrictiveRequirement(): void {
		$schema = self::schema(['tiers' => ['public', 'internal', 'confidential'], 'tierProperty' => 'confidentialityTier', 'clearance' => ['confidential' => 'raadsleden']]);
		$this->assertFalse($this->refused(['confidentialityTier' => 'internal'], ['staff'], $schema));
		$this->assertTrue($this->refused(['confidentialityTier' => 'confidential'], ['staff'], $schema));
	}

	public function testTheOwnerAndAnAdministratorPass(): void {
		$besluit = ['confidentialityTier' => 'confidential'];
		$owner = $this->gate()->refuses(schema: self::schema(), action: 'read', envelope: $besluit, userId: 'pieter', groups: [], owner: 'pieter');
		$admin = $this->gate()->refuses(schema: self::schema(), action: 'read', envelope: $besluit, userId: 'root', groups: ['admin'], owner: 'someone-else');

		$this->assertFalse($owner);
		$this->assertFalse($admin);
	}

	public function testAnAnonymousCallerIsNeverTheOwnerOfAnUnownedObject(): void {
		$refused = $this->gate()->refuses(schema: self::schema(), action: 'read', envelope: ['confidentialityTier' => 'confidential'], userId: null, groups: [], owner: null);
		$this->assertTrue($refused);
	}

	public function testOnlyTheReadIsGoverned(): void {
		$refused = $this->gate()->refuses(schema: self::schema(), action: 'update', envelope: ['confidentialityTier' => 'confidential'], userId: 'pieter', groups: [], owner: 'someone-else');
		$this->assertFalse($refused);
	}

	public function testASchemaWithoutTheBlockIsUnaffected(): void {
		$this->assertNull(ConfidentialityReadGate::ruleFor(self::schema(null)));
		$this->assertFalse($this->refused(['confidentialityTier' => 'confidential'], [], self::schema(null)));
	}

	public function testAnInvalidBlockFailsClosedAndLogsAnError(): void {
		$invalid = array_merge(self::BESLUIT, ['clearance' => ['secret' => 'college']]);
		$schema = self::schema($invalid, 4402);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			$this->stringContains('invalid'),
			$this->callback(static fn (array $context): bool => $context['schema'] === 'besluit' && str_contains($context['errors'], 'secret'))
		);

		$gate = $this->gate($logger);
		$tiered = $gate->refuses(schema: $schema, action: 'read', envelope: ['confidentialityTier' => 'public'], userId: 'jan', groups: ['raadsleden'], owner: 'someone-else');
		$untiered = $gate->refuses(schema: $schema, action: 'read', envelope: [], userId: 'jan', groups: ['raadsleden'], owner: 'someone-else');

		$this->assertTrue($tiered, 'any object with a tier is hidden, even its least restrictive one');
		$this->assertFalse($untiered, 'an object without a tier stays readable');
	}
}
