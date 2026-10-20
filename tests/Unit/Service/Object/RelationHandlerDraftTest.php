<?php

/**
 * A relation lookup must not hand another user's draft to a caller who cannot read it.
 *
 * `/uses` loads its candidates without an access rule and falls back to them
 * for every uuid the per-table query did not return, so a draft the per-table
 * query left out reached the list through that fallback, and hasPermission()
 * has no draft rule.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-draft-must-not-be-changed-by-somebody-who-cannot-read-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\AnonymousEvaluationContext;
use OCA\OpenRegister\Service\Object\PerformanceHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The draft rule of the related-objects list.
 */
class RelationHandlerDraftTest extends TestCase {

	/**
	 * Build the handler and run /uses for a lead that points at one draft and one finished object.
	 *
	 * @param string|null $caller  The signed-in user, null for nobody.
	 * @param bool        $isAdmin Whether the caller is an administrator.
	 *
	 * @return string[] The uuids the list answers.
	 */
	private function uses(?string $caller, bool $isAdmin): array {
		$lead = new ObjectEntity();
		$lead->setUuid('lead-1');
		$lead->setRegister('1');
		$lead->setSchema('2');
		$lead->setRelations(['draft' => 'draft-1', 'done' => 'done-1']);

		$draft = new ObjectEntity();
		$draft->setUuid('draft-1');
		$draft->setRegister('1');
		$draft->setSchema('3');
		$draft->setStatus('draft');
		$draft->setOwner('alice');

		$done = new ObjectEntity();
		$done->setUuid('done-1');
		$done->setRegister('1');
		$done->setSchema('3');
		$done->setOwner('alice');

		$magic = $this->createMock(MagicMapper::class);
		$magic->method('find')->willReturn($lead);
		$magic->method('findMultipleAcrossAllMagicTables')->willReturn([$draft, $done]);
		// The per-table query applies the draft predicate: the draft is not in its answer.
		$magic->method('findAllInRegisterSchemaTable')->willReturn([$done]);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			static function ($id): Register {
				$register = new Register();
				$register->setId((int)$id);
				return $register;
			}
		);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			static function ($id): Schema {
				$schema = new Schema();
				$schema->setId((int)$id);
				return $schema;
			}
		);

		$rbac = $this->createMock(MagicRbacHandler::class);
		$rbac->method('isAdmin')->willReturn($isAdmin);
		$rbac->method('getCurrentUserId')->willReturn($caller);
		$rbac->method('hasPermission')->willReturn(true);

		$handler = new RelationHandler(
			objectEntityMapper: $magic,
			schemaMapper: $schemaMapper,
			performanceHandler: $this->createMock(PerformanceHandler::class),
			rbacHandler: $rbac,
			logger: $this->createMock(LoggerInterface::class),
			registerMapper: $registerMapper
		);

		$result = $handler->getUses('lead-1');

		return array_map(static fn (array $row): string => (string)($row['@self']['id'] ?? $row['id'] ?? ''), $result['results']);
	}//end uses()

	/**
	 * Somebody else's draft is not in the list, however the candidate was loaded.
	 *
	 * @return void
	 */
	public function testSomebodyElsesDraftIsLeftOut(): void {
		$this->assertSame(['done-1'], $this->uses(caller: 'bob', isAdmin: false));
		$this->assertSame(['done-1'], AnonymousEvaluationContext::run(fn (): array => $this->uses(caller: null, isAdmin: false)));
	}//end testSomebodyElsesDraftIsLeftOut()

	/**
	 * The draft's owner and an administrator see it.
	 *
	 * @return void
	 */
	public function testTheOwnerAndAnAdministratorKeepTheDraft(): void {
		$this->assertSame(['done-1', 'draft-1'], $this->uses(caller: 'alice', isAdmin: false));
		$this->assertSame(['done-1', 'draft-1'], $this->uses(caller: 'root', isAdmin: true));
	}//end testTheOwnerAndAnAdministratorKeepTheDraft()
}//end class
