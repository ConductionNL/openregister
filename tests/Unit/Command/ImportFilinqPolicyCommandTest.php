<?php

/**
 * ImportFilinqPolicyCommandTest: filinq's lists become term rules, once.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#requirement-filinqs-lists-are-imported-into-the-engine-req-rpd-007
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\ImportFilinqPolicyCommand;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\RedactionPolicy\FilinqPolicyImporter;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ImportFilinqPolicyCommand::class)]
#[CoversClass(FilinqPolicyImporter::class)]
class ImportFilinqPolicyCommandTest extends TestCase {

	/**
	 * The stored objects per "register/schema".
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Every save: the data, the options and the access flags.
	 *
	 * @var list<array{0: array<string, mixed>, 1: ObjectSaveOptions, 2: bool, 3: bool}>
	 */
	private array $saves = [];

	/**
	 * Whether the import ran inside runAsSystem().
	 *
	 * @var bool
	 */
	private bool $asSystem = false;

	/**
	 * Whether a findAll() ran outside the system context or with RBAC or tenancy on.
	 *
	 * @var bool
	 */
	private bool $scopedRead = false;

	private const PROHIBITION = [
		'@self'          => ['id' => 'f-prohibition-1'],
		'primaryName'    => 'Beschermde Getuige A',
		'entityType'     => 'PERSON',
		'matchRules'     => [['type' => 'normalized', 'value' => 'Beschermde Getuige A'], ['type' => 'regex', 'value' => '.*']],
		'reason'         => 'Gerechtelijk bevel',
		'legalAuthority' => 'Rechtbank Den Haag KG ZA 24/123',
		'bases'          => ['5.1.2e'],
		'validFrom'      => '2026-01-01T00:00:00+00:00',
		'active'         => true,
		'severity'       => 'critical',
	];

	private const ENTITY_CONSENT = [
		'@self'      => ['id' => 'f-consent-entity'],
		'scope'      => 'entity',
		'entityType' => 'PERSON',
		'entityText' => 'Burgemeester B',
		'matchRules' => [['type' => 'exact', 'value' => 'Burgemeester B']],
		'legalBasis' => 'Woo 5.1 lid 2 (openbaar ambt)',
		'notes'      => 'Staande toestemming',
		'validUntil' => '2030-12-31T23:59:59+00:00',
		'active'     => false,
	];

	private const DOCUMENT_CONSENT = [
		'@self'      => ['id' => 'f-consent-document'],
		'scope'      => 'document',
		'documentId' => 'doc-1',
		'entityType' => 'PERSON',
		'entityText' => 'Jan Jansen',
	];

	public function testAProhibitionBecomesAnAlwaysRuleOnce(): void {
		$this->filinq(prohibitions: [self::PROHIBITION]);

		$first  = $this->runImport();
		$second = $this->runImport();

		$this->assertCount(1, $this->saves, 'The second run finds the rule by its source and writes nothing.');
		$this->assertSame(
			[
				'list'           => 'always',
				'entityType'     => 'PERSON',
				'matchRules'     => [['type' => 'normalized', 'value' => 'Beschermde Getuige A']],
				'reason'         => 'Gerechtelijk bevel',
				'legalAuthority' => 'Rechtbank Den Haag KG ZA 24/123',
				'bases'          => ['5.1.2e'],
				'validFrom'      => '2026-01-01T00:00:00+00:00',
				'active'         => true,
				'scope'          => 'organisation',
				'layer'          => 'local',
				'source'         => 'f-prohibition-1',
			],
			$this->saves[0][0]
		);
		$this->assertSame('redaction-policy', $this->saves[0][1]->target->register);
		$this->assertSame('termRule', $this->saves[0][1]->target->schema);
		$this->assertNull($this->saves[0][1]->uuid);
		$this->assertCount(1, $this->store['redaction-policy/termRule']);
		$this->assertStringContainsString('1 created, 0 updated, 0 unchanged, 0 skipped', $first);
		$this->assertStringContainsString('0 created, 0 updated, 1 unchanged, 0 skipped', $second);
	}

	public function testAChangedProhibitionUpdatesItsRuleInPlace(): void {
		$this->filinq(prohibitions: [self::PROHIBITION]);
		$this->runImport();
		$this->store['filinq/publicationProhibition'][0]['active'] = false;

		$output = $this->runImport();

		$this->assertCount(2, $this->saves);
		$this->assertSame('rule-1', $this->saves[1][1]->uuid, 'The update names the rule written the first time.');
		$this->assertFalse($this->saves[1][0]['active']);
		$this->assertCount(1, $this->store['redaction-policy/termRule']);
		$this->assertStringContainsString('0 created, 1 updated, 0 unchanged', $output);
	}

	public function testAnEntityScopedConsentBecomesANeverRule(): void {
		$this->filinq(consents: [self::ENTITY_CONSENT]);

		$this->runImport();

		$this->assertCount(1, $this->saves);
		$this->assertSame(
			[
				'list'           => 'never',
				'entityType'     => 'PERSON',
				'matchRules'     => [['type' => 'exact', 'value' => 'Burgemeester B']],
				'reason'         => 'Staande toestemming',
				'legalAuthority' => 'Woo 5.1 lid 2 (openbaar ambt)',
				'validUntil'     => '2030-12-31T23:59:59+00:00',
				'active'         => false,
				'scope'          => 'organisation',
				'layer'          => 'local',
				'source'         => 'f-consent-entity',
			],
			$this->saves[0][0]
		);
	}

	public function testADocumentScopedConsentIsNotImported(): void {
		$this->filinq(consents: [self::DOCUMENT_CONSENT, ['@self' => ['id' => 'f-consent-unscoped'], 'entityText' => 'Piet']]);

		$output = $this->runImport();

		$this->assertSame([], $this->saves);
		$this->assertStringContainsString('0 created, 0 updated, 0 unchanged, 0 skipped', $output);
	}

	public function testWithoutTheFilinqRegisterNothingIsImported(): void {
		$tester = $this->tester(hasFilinq: false);

		$this->assertSame(0, $tester->execute([]));
		$this->assertStringContainsString('no filinq register on this instance, so there is nothing to import', $tester->getDisplay());
		$this->assertSame([], $this->saves);
	}

	public function testItReadsAndWritesAsTheSystemWithoutScoping(): void {
		$this->filinq(prohibitions: [self::PROHIBITION]);

		$this->runImport();

		$this->assertFalse($this->scopedRead);
		$this->assertFalse($this->saves[0][2], 'RBAC is off for the write.');
		$this->assertFalse($this->saves[0][3], 'Tenancy is off for the write.');
	}

	public function testAnObjectWithoutAMatchIsSkippedAndNamed(): void {
		$this->filinq(prohibitions: [['@self' => ['id' => 'f-empty'], 'matchRules' => [['type' => 'regex', 'value' => 'x']]]]);

		$output = $this->runImport();

		$this->assertSame([], $this->saves);
		$this->assertStringContainsString('Skipped f-empty: it has no match rule and no name to match on.', $output);
	}

	public function testAProhibitionWithoutMatchRulesMatchesItsNameExactly(): void {
		$rule = FilinqPolicyImporter::ruleFor(row: ['@self' => ['id' => 'f-2'], 'primaryName' => 'Getuige C'], list: 'always');

		$this->assertSame([['type' => 'exact', 'value' => 'Getuige C']], $rule['matchRules']);
		$this->assertTrue($rule['active'], 'A prohibition without an active flag applies.');
	}

	public function testAFailingImportExitsOne(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('runAsSystem')->willThrowException(new RuntimeException('database gone'));
		$tester = new CommandTester(new ImportFilinqPolicyCommand(new FilinqPolicyImporter($objects)));

		$this->assertSame(1, $tester->execute([]));
		$this->assertStringContainsString('The filinq import failed: database gone', $tester->getDisplay());
	}

	public function testTheCommandName(): void {
		$command = new ImportFilinqPolicyCommand(new FilinqPolicyImporter($this->createMock(ObjectOperationsInterface::class)));

		$this->assertSame('openregister:redaction-policy:import-filinq', $command->getName());
	}

	/**
	 * Seed filinq's register.
	 *
	 * @param array<int, array<string, mixed>> $prohibitions The prohibitions.
	 * @param array<int, array<string, mixed>> $consents     The consents.
	 *
	 * @return void
	 */
	private function filinq(array $prohibitions = [], array $consents = []): void {
		$this->store['filinq/publicationProhibition'] = $prohibitions;
		$this->store['filinq/publicationConsent']     = $consents;
	}

	private function runImport(): string {
		$tester = $this->tester(hasFilinq: true);
		$this->assertSame(0, $tester->execute([]));
		return $tester->getDisplay();
	}

	private function tester(bool $hasFilinq): CommandTester {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('runAsSystem')->willReturnCallback(
			function (callable $operation) {
				$this->asSystem = true;
				try {
					return $operation();
				} finally {
					$this->asSystem = false;
				}
			}
		);
		$objects->method('setRegister')->willReturnCallback(
			function (string $register) use ($hasFilinq, $objects) {
				if ($register === 'filinq' && $hasFilinq === false) {
					throw new DoesNotExistException('no register filinq');
				}
				return $objects;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->asSystem === false || $_rbac === true || $_multitenancy === true) {
					$this->scopedRead = true;
				}
				$key  = $config['filters']['register'] . '/' . $config['filters']['schema'];
				$rows = array_slice(($this->store[$key] ?? []), $config['offset'], $config['limit']);
				// Stored term rules come back as entities, filinq's as arrays: both shapes are read.
				return array_map(
					static function (array $row) use ($key) {
						if ($key !== 'redaction-policy/termRule') {
							return $row;
						}
						$entity = new class($row) extends ObjectEntity {
							public function __construct(private readonly array $row) {
							}
							public function jsonSerialize(): array {
								return $this->row;
							}
						};
						return $entity;
					},
					$rows
				);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options, bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$this->saves[] = [$object, $options, $_rbac, $_multitenancy];
				$rules         = ($this->store['redaction-policy/termRule'] ?? []);
				if ($options->uuid === null) {
					$rules[] = array_merge($object, ['@self' => ['id' => 'rule-' . (count($rules) + 1)]]);
				} else {
					foreach ($rules as $i => $rule) {
						if ($rule['@self']['id'] === $options->uuid) {
							$rules[$i] = array_merge($object, ['@self' => ['id' => $options->uuid]]);
						}
					}
				}
				$this->store['redaction-policy/termRule'] = $rules;
				return new ObjectEntity();
			}
		);

		return new CommandTester(new ImportFilinqPolicyCommand(new FilinqPolicyImporter($objects)));
	}
}//end class
