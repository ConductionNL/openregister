<?php

/**
 * Golden test for what declaring kinds and text blocks stores and what kind fields accept.
 *
 * Expected values are literals recorded on the code before it was split into units.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Timeline
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Timeline;

use OCA\OpenRegister\Notifications\Db\TextBlock;
use OCA\OpenRegister\Notifications\Db\TextBlockMapper;
use OCA\OpenRegister\Notifications\Db\TimelineKind;
use OCA\OpenRegister\Notifications\Db\TimelineKindMapper;
use OCA\OpenRegister\Notifications\Service\Timeline\TextBlockService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineKindService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineValidationException;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Notifications\Service\PrincipalDirectory;
use OCP\IUserManager;

/**
 * Drives the services through the collaborators they build.
 */
class TimelineKindGoldenTest extends TestCase {

	private function kind(): TimelineKind {
		$kind = new TimelineKind();
		$kind->setSlug('meting');
		$kind->setRequired(['r']);
		$kind->setProperties(
			[
				'n' => ['type' => 'number'],
				'i' => ['type' => 'integer'],
				'b' => ['type' => 'boolean'],
				'a' => ['type' => 'array'],
				'o' => ['type' => 'object'],
				's' => [],
				'typeless' => ['type' => 5],
				'plain' => 'no declaration',
				'e' => ['type' => 'unknown', 'enum' => ['1', 2]],
				'x' => ['type' => 'unknown'],
				'r' => [],
				7 => ['type' => 'integer'],
			]
		);

		return $kind;
	}//end kind()

	public static function fieldCases(): array {
		$required = ['r' => 'ok'];
		return [
			'fits' => [
				$required + ['n' => 1.5, 'i' => 2, 'b' => false, 'a' => [1], 'o' => ['k' => 1], 's' => 't', 'typeless' => 't', 'plain' => 't', 'e' => 2, 'x' => null, 7 => 'dropped', 'undeclared' => 1],
				['n' => 1.5, 'i' => 2, 'b' => false, 'a' => [1], 'o' => ['k' => 1], 's' => 't', 'typeless' => 't', 'plain' => 't', 'e' => 2, 'x' => null, 'r' => 'ok'],
				null,
			],
			'an integer is a number' => [$required + ['n' => 3], ['n' => 3, 'r' => 'ok'], null],
			'every wrong field at once' => [
				['n' => '1', 'i' => 1.5, 'b' => 1, 'a' => 'x', 'o' => 'x', 's' => 1, 'typeless' => 1, 'plain' => 1],
				null,
				[
					'n' => 'Expected a value of type number',
					'i' => 'Expected a value of type integer',
					'b' => 'Expected a value of type boolean',
					'a' => 'Expected a value of type array',
					'o' => 'Expected a value of type object',
					's' => 'Expected a value of type string',
					'typeless' => 'Expected a value of type string',
					'plain' => 'Expected a value of type string',
					'r' => 'This field is required by kind meting',
				],
			],
			'enum compares strictly' => [$required + ['e' => 1], null, ['e' => 'Expected one of: 1, 2']],
			'enum outside the list' => [$required + ['e' => 'z'], null, ['e' => 'Expected one of: 1, 2']],
		];
	}//end fieldCases()

	#[DataProvider('fieldCases')]
	public function testFieldsAgainstAKind(array $fields, ?array $accepted, ?array $errors): void {
		$mapper = $this->createMock(TimelineKindMapper::class);
		$mapper->method('findBySlug')->willReturnCallback(fn (string $slug): ?TimelineKind => $slug === 'meting' ? $this->kind() : null);
		$service = new TimelineKindService($mapper);

		try {
			$actual = $service->validateFields(' Meting ', $fields);
		} catch (TimelineValidationException $e) {
			$this->assertSame($errors, $e->getErrors());
			return;
		}

		$this->assertSame($accepted, $actual);
	}//end testFieldsAgainstAKind()

	public function testAPlainNoteTakesNoFieldsAndNoFollowUp(): void {
		$mapper = $this->createMock(TimelineKindMapper::class);
		$mapper->expects($this->never())->method('findBySlug');
		$service = new TimelineKindService($mapper);

		$this->assertSame([], $service->validateFields('  ', ['a' => 1]));
		$this->assertSame([], $service->validateFields(null, ['a' => 1]));
		$this->assertFalse($service->carriesFollowUp('  '));
		$this->assertFalse($service->carriesFollowUp(null));
	}//end testAPlainNoteTakesNoFieldsAndNoFollowUp()

	public function testAKindDeclarationIsStoredNormalised(): void {
		$mapper = $this->createMock(TimelineKindMapper::class);
		$mapper->method('insert')->willReturnArgument(0);
		$service = new TimelineKindService($mapper);

		$kind = $service->declareKind(
			[
				'slug' => ' MeTing ',
				'title' => ' Titel ',
				'description' => '  ',
				'properties' => ['a' => ['type' => 'string']],
				'required' => ['a', 5, 'b'],
				'followUp' => 0,
				'register' => 'r1',
				'schema' => 7,
			]
		);

		$this->assertSame('meting', $kind->getSlug());
		$this->assertSame('Titel', $kind->getTitle());
		$this->assertNull($kind->getDescription());
		$this->assertSame(['a' => ['type' => 'string']], $kind->getProperties());
		$this->assertSame(['a', 'b'], $kind->getRequired());
		$this->assertFalse($kind->getFollowUp());
		$this->assertSame('r1', $kind->getRegister());
		$this->assertNull($kind->getSchema());
		$this->assertNotNull($kind->getCreated());
		$this->assertNotNull($kind->getUpdated());
	}//end testAKindDeclarationIsStoredNormalised()

	public function testADeclarationWithoutTheOptionalPartsTakesTheDefaults(): void {
		$mapper = $this->createMock(TimelineKindMapper::class);
		$mapper->method('insert')->willReturnArgument(0);

		$kind = (new TimelineKindService($mapper))->declareKind(['slug' => 'k', 'required' => 'a']);

		$this->assertSame([], $kind->getProperties());
		$this->assertSame([], $kind->getRequired());
		$this->assertFalse($kind->getFollowUp());
	}//end testADeclarationWithoutTheOptionalPartsTakesTheDefaults()

	public function testAnUnusableDeclarationNeverReachesTheMapper(): void {
		$mapper = $this->createMock(TimelineKindMapper::class);
		$mapper->expects($this->never())->method('findBySlug');
		$service = new TimelineKindService($mapper);

		foreach ([[['slug' => 5], 'slug'], [['slug' => '  '], 'slug'], [['slug' => 'k', 'properties' => 'x'], 'properties']] as [$data, $key]) {
			try {
				$service->declareKind($data);
				$this->fail('declared');
			} catch (TimelineValidationException $e) {
				$this->assertSame([$key], array_keys($e->getErrors()));
			}
		}
	}//end testAnUnusableDeclarationNeverReachesTheMapper()

	private function blockService(TextBlockMapper $mapper): TextBlockService {
		return new TextBlockService($mapper, new PrincipalDirectory($this->createMock(IUserManager::class), $this->createMock(IGroupManager::class), $this->createMock(IUserSession::class)));
	}//end blockService()

	public function testATextBlockIsStoredNormalisedWithItsBodyAsWritten(): void {
		$mapper = $this->createMock(TextBlockMapper::class);
		$mapper->method('insert')->willReturnArgument(0);
		$mapper->expects($this->once())->method('findBySlug')->with('groet')->willReturn(null);

		$block = $this->blockService($mapper)->declareBlock(
			['slug' => ' GroeT ', 'body' => ' Hallo ', 'title' => ' T ', 'register' => 'r', 'schema' => 's', 'groupId' => ' g ']
		);

		$this->assertSame('groet', $block->getSlug());
		$this->assertSame(' Hallo ', $block->getBody());
		$this->assertSame('T', $block->getTitle());
		$this->assertSame('r', $block->getRegister());
		$this->assertSame('s', $block->getSchema());
		$this->assertSame('g', $block->getGroupId());
		$this->assertNotNull($block->getUuid());
		$this->assertNotNull($block->getCreated());
		$this->assertNotNull($block->getUpdated());
	}//end testATextBlockIsStoredNormalisedWithItsBodyAsWritten()

	public function testRedeclaringKeepsTheBlockIdAndWritesTheNewBody(): void {
		$existing = new TextBlock();
		$existing->setUuid('keep');
		$mapper = $this->createMock(TextBlockMapper::class);
		$mapper->method('findBySlug')->willReturn($existing);
		$mapper->expects($this->never())->method('insert');
		$mapper->method('update')->willReturnArgument(0);

		$block = $this->blockService($mapper)->declareBlock(['slug' => 'g', 'body' => 'Nieuw']);

		$this->assertSame('keep', $block->getUuid());
		$this->assertSame('Nieuw', $block->getBody());
		$this->assertNull($block->getCreated());
	}//end testRedeclaringKeepsTheBlockIdAndWritesTheNewBody()

	public function testABlankSlugOrBodyIsRefusedSlugFirst(): void {
		$mapper = $this->createMock(TextBlockMapper::class);
		$mapper->expects($this->never())->method('findBySlug');
		$service = $this->blockService($mapper);

		foreach ([[['slug' => ' ', 'body' => ' '], 'slug'], [['slug' => 'g', 'body' => "  \n"], 'body'], [['slug' => 'g'], 'body']] as [$data, $key]) {
			try {
				$service->declareBlock($data);
				$this->fail('declared');
			} catch (TimelineValidationException $e) {
				$this->assertSame([$key], array_keys($e->getErrors()));
			}
		}
	}//end testABlankSlugOrBodyIsRefusedSlugFirst()

	public function testPlaceholdersOnlyTakeScalars(): void {
		$service = $this->blockService($this->createMock(TextBlockMapper::class));

		$this->assertSame(
			'0 {{ leeg }} {{ lijst }} {{ weg }} 1',
			$service->substitute('{{ nul }} {{ leeg }} {{ lijst }} {{ weg }} {{waar}}', ['nul' => 0, 'leeg' => null, 'lijst' => [1], 'waar' => true])
		);
	}//end testPlaceholdersOnlyTakeScalars()
}//end class
