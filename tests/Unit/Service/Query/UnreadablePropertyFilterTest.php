<?php

/**
 * A top-level filter or sort on a property the caller may not read is refused.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Query
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Query;

use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ExplicitRevealQueryException;
use OCA\OpenRegister\Service\Query\PropertyReadGuard;
use OCA\OpenRegister\Service\Query\RelatedRowAccess;
use OCA\OpenRegister\Service\Query\RelatedRowLookups;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Filter and sort on an unreadable top-level property.
 */
class UnreadablePropertyFilterTest extends TestCase {

	/**
	 * An applier whose property read guard withholds `snapshot` and admits the rest.
	 *
	 * @return RelatedRowQueryApplier The applier.
	 */
	private function applier(): RelatedRowQueryApplier {
		$guard = $this->createMock(originalClassName: PropertyReadGuard::class);
		$guard->method('mayRead')->willReturnCallback(static fn (Schema $schema, string $property): bool => $property !== 'snapshot');

		return new RelatedRowQueryApplier(
			lookups: new RelatedRowLookups(schemaMapper: $this->createMock(originalClassName: SchemaMapper::class), tableHandler: $this->createMock(originalClassName: MagicTableHandler::class)),
			db: $this->createMock(originalClassName: IDBConnection::class),
			logger: new NullLogger(),
			access: new RelatedRowAccess(rbac: $this->createMock(originalClassName: MagicRbacHandler::class), logger: new NullLogger(), guard: $guard)
		);
	}//end applier()

	/**
	 * A schema whose `snapshot` property carries an admin-only read rule.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setProperties(
			[
				'title'    => ['type' => 'string'],
				'snapshot' => ['type' => 'string', 'authorization' => ['read' => ['admin']]],
			]
		);

		return $schema;
	}//end schema()

	/**
	 * A filter on the unreadable property is refused.
	 *
	 * @return void
	 */
	public function testAFilterOnAnUnreadablePropertyIsRefused(): void {
		$this->expectException(exception: ExplicitRevealQueryException::class);

		$this->applier()->refuseUnreadableFilterOrSort(query: ['snapshot' => ['like' => '%x%']], schema: $this->schema());
	}//end testAFilterOnAnUnreadablePropertyIsRefused()

	/**
	 * A sort on the unreadable property is refused.
	 *
	 * @return void
	 */
	public function testASortOnAnUnreadablePropertyIsRefused(): void {
		$this->expectException(exception: ExplicitRevealQueryException::class);

		$this->applier()->refuseUnreadableFilterOrSort(query: ['_order' => ['snapshot' => 'ASC']], schema: $this->schema());
	}//end testASortOnAnUnreadablePropertyIsRefused()

	/**
	 * Filters and sorts on readable properties pass, as does a query with RBAC off.
	 *
	 * @return void
	 */
	public function testReadablePropertiesAndRbacOffPass(): void {
		$applier = $this->applier();

		$applier->refuseUnreadableFilterOrSort(query: ['title' => 'a', '_order' => ['title' => 'DESC']], schema: $this->schema());
		$applier->refuseUnreadableFilterOrSort(query: ['snapshot' => 'a', '_rbac' => false], schema: $this->schema());

		$this->addToAssertionCount(count: 2);
	}//end testReadablePropertiesAndRbacOffPass()

	/**
	 * A system operation is not asked.
	 *
	 * @return void
	 */
	public function testASystemOperationIsNotAsked(): void {
		$applier = $this->applier();
		$schema  = $this->schema();

		SystemOperationContext::run(static fn () => $applier->refuseUnreadableFilterOrSort(query: ['snapshot' => 'a'], schema: $schema));

		$this->addToAssertionCount(count: 1);
	}//end testASystemOperationIsNotAsked()
}//end class
