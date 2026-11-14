<?php

/**
 * Unit tests for the `text` match rule of x-openregister-dedup.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Quality
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-a-schema-can-declare-text-similarity-as-a-match-rule-req-ndt-003
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Quality;

use DateTime;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\TextSignature as TextSignatureRow;
use OCA\OpenRegister\Db\TextSignatureMapper;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Search\Controller\DuplicateController;
use OCA\OpenRegister\Search\Controller\Support\DuplicateControllerServices;
use OCA\OpenRegister\Search\Service\Quality\DedupScoringSources;
use OCA\OpenRegister\Search\Service\Quality\DismissedPairStore;
use OCA\OpenRegister\Search\Service\Quality\DuplicateDetectionService;
use OCA\OpenRegister\Search\Service\Quality\ObjectTextSimilarity;
use OCA\OpenRegister\Search\Service\Quality\SimilarityCalculator;
use OCA\OpenRegister\Search\Service\Quality\TextGroupFinder;
use OCA\OpenRegister\Service\Quality\DedupAnnotationValidator;
use OCA\OpenRegister\Service\Quality\TextSignature;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Two objects compared by the text of their attached files, through DuplicateController::index() (REQ-NDT-003).
 *
 * @covers \OCA\OpenRegister\Search\Service\Quality\ObjectTextSimilarity
 * @covers \OCA\OpenRegister\Search\Service\Quality\DuplicateDetectionService
 * @covers \OCA\OpenRegister\Search\Service\Quality\PayloadComparator
 * @covers \OCA\OpenRegister\Search\Service\Quality\DedupRuleResolver
 * @covers \OCA\OpenRegister\Service\Quality\DedupAnnotationValidator
 * @uses   \OCA\OpenRegister\Search\Controller\DuplicateController
 * @uses   \OCA\OpenRegister\Search\Controller\Support\DuplicateControllerServices
 * @uses   \OCA\OpenRegister\Controller\Support\ControllerSession
 * @uses   \OCA\OpenRegister\Search\Service\Quality\DedupRun
 * @uses   \OCA\OpenRegister\Search\Service\Quality\DedupScoringSources
 * @uses   \OCA\OpenRegister\Search\Service\Quality\SimilarityCalculator
 * @uses   \OCA\OpenRegister\Search\Service\Quality\ComparedValues
 * @uses   \OCA\OpenRegister\Search\Service\Quality\TextGroupFinder
 * @uses   \OCA\OpenRegister\Search\Service\Quality\TextGroups
 * @uses   \OCA\OpenRegister\Service\RegisterScopedSchemaResolvers
 * @uses   \OCA\OpenRegister\Db\SchemaCatalog
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\TextSignature
 * @uses   \OCA\OpenRegister\Service\Quality\TextSignature
 */
final class DuplicateTextRuleTest extends TestCase {

	/**
	 * The stored signature rows, keyed by file id.
	 *
	 * @var array<int, TextSignatureRow>
	 */
	private array $rows = [];

	/**
	 * The files attached to each object.
	 *
	 * @var array<string, list<int>>
	 */
	private array $objectFiles = [];

	/**
	 * Store the signature row of a text for a file.
	 *
	 * @param int    $fileId The file id.
	 * @param string $text   The text.
	 *
	 * @return void
	 */
	private function store(int $fileId, string $text): void {
		$this->rows[$fileId] = TextSignatureRow::fromRecord($fileId, (new TextSignature())->sign($text), new DateTime('2026-11-13 10:00:00'));
	}//end store()

	/**
	 * An object with this uuid and payload.
	 *
	 * @param string               $uuid    The uuid.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntity The object.
	 */
	private static function object(string $uuid, array $payload): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject($payload);

		return $object;
	}//end object()

	/**
	 * The duplicates review of a schema whose annotation holds these rules, over these objects.
	 *
	 * @param list<array<string, mixed>> $rules   The schema's match rules.
	 * @param list<ObjectEntity>         $objects The stored objects.
	 *
	 * @return array<string, mixed> The response data of DuplicateController::index().
	 */
	private function review(array $rules, array $objects): array {
		$objectService = $this->createMock(ObjectOperationsInterface::class);
		$objectService->method('findAll')->willReturn($objects);

		$register = new Register();
		$register->setId(1);
		$register->setSlug('woo');
		$register->setSchemas([1]);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($register);
		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')->willReturn(['x-openregister-dedup' => ['matchRules' => $rules, 'threshold' => 0.9]]);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('findInIds')->willReturn($schema);

		$files = $this->createMock(FileMapperInterface::class);
		$files->method('getFileIdsForObjects')->willReturnCallback(
			fn (array $uuids): array => array_intersect_key($this->objectFiles, array_flip($uuids))
		);
		$signatures = $this->createMock(TextSignatureMapper::class);
		$signatures->method('findByFileIds')->willReturnCallback(
			fn (array $fileIds): array => array_intersect_key($this->rows, array_flip($fileIds))
		);

		$dismissals = $this->createMock(DismissedPairStore::class);
		$dismissals->method('activeFor')->willReturn([]);

		$service = new DuplicateDetectionService(
			$objectService,
			new DedupScoringSources(
				new SchemaCatalog(registers: $registers, schemas: $schemas, tables: $this->createMock(MagicMapperInterface::class)),
				new SimilarityCalculator()
			),
			$dismissals,
			new NullLogger(),
			$this->createMock(TextGroupFinder::class),
			new ObjectTextSimilarity($files, $signatures)
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default=null): mixed => $default);
		$controller = new DuplicateController(
			$request,
			new DuplicateControllerServices(
				$service,
				$dismissals,
				$objectService,
				new ControllerSession($this->createMock(IUserSession::class), $this->createMock(IGroupManager::class), new NullLogger())
			)
		);

		return $controller->index(register: 'woo', schema: 'besluit')->getData();
	}//end review()

	/**
	 * Two objects whose attached files are near-identical are paired, matched on text.
	 *
	 * @return void
	 */
	public function testTwoObjectsWithNearIdenticalFilesArePaired(): void {
		$letter = NearDuplicateTexts::letter(seed: 7);
		$this->store(fileId: 1, text: $letter);
		$this->store(fileId: 2, text: NearDuplicateTexts::letter(seed: 8));
		$this->store(fileId: 3, text: NearDuplicateTexts::edited(text: $letter, positions: [3]));
		$this->store(fileId: 4, text: NearDuplicateTexts::letter(seed: 9));
		$this->objectFiles = ['a' => [1, 2], 'b' => [3], 'c' => [4]];

		$data = $this->review(
			rules: [['method' => 'text']],
			objects: [
				self::object(uuid: 'a', payload: ['title' => 'Besluit']),
				self::object(uuid: 'b', payload: ['title' => 'Doorgestuurd']),
				self::object(uuid: 'c', payload: ['title' => 'Anders']),
			]
		);

		$this->assertSame(1, $data['total']);
		$this->assertSame(['a', 'b'], [$data['items'][0]['objectA'], $data['items'][0]['objectB']]);
		$this->assertSame(['text'], $data['items'][0]['matchedOn']);
		$this->assertGreaterThanOrEqual(0.9, $data['items'][0]['score']);
	}//end testTwoObjectsWithNearIdenticalFilesArePaired()

	/**
	 * An object without files scores zero on the text rule, so equal titles alone no longer reach the cut-off.
	 *
	 * @return void
	 */
	public function testAnObjectWithoutFilesScoresZero(): void {
		$this->store(fileId: 1, text: NearDuplicateTexts::letter(seed: 7));
		$this->objectFiles = ['a' => [1], 'b' => []];
		$objects           = [self::object(uuid: 'a', payload: ['title' => 'Besluit']), self::object(uuid: 'b', payload: ['title' => 'Besluit'])];

		$data = $this->review(rules: [['field' => 'title', 'method' => 'exact'], ['method' => 'text']], objects: $objects);
		$this->assertSame(0, $data['total']);

		$titleOnly = $this->review(rules: [['field' => 'title', 'method' => 'exact']], objects: $objects);
		$this->assertSame(1, $titleOnly['total']);
		$this->assertSame(['title'], $titleOnly['items'][0]['matchedOn']);
	}//end testAnObjectWithoutFilesScoresZero()

	/**
	 * The annotation validator accepts a text rule without a field, and still refuses other rules without one.
	 *
	 * @return void
	 */
	public function testTheValidatorAcceptsATextRuleWithoutAField(): void {
		$validator = new DedupAnnotationValidator();

		$this->assertSame([], $validator->validate(['x-openregister-dedup' => ['matchRules' => [['method' => 'text', 'weight' => 2]]]]));
		$heavy = $validator->validate(['x-openregister-dedup' => ['matchRules' => [['method' => 'text', 'weight' => 'heavy']]]]);
		$this->assertSame('dedup.bad-weight', $heavy[0]['code']);
		$this->assertSame('dedup.missing-field', $validator->validate(['x-openregister-dedup' => ['matchRules' => [['method' => 'exact']]]])[0]['code']);
	}//end testTheValidatorAcceptsATextRuleWithoutAField()
}//end class
