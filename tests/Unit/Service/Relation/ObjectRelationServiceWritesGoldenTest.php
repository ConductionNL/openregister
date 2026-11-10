<?php

/**
 * Golden test for the rows ObjectRelationService writes and renders.
 *
 * Pins every payload handed to the mapper (keys, order and values) for
 * splits, derivations, inheritance, external links and prose references,
 * the refusals, and the rendered rows read back for both directions with and
 * without a resolvable relation type. Taken ahead of a unit-size refactor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Relation;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectRelation;
use OCA\OpenRegister\Db\ObjectRelationMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Relation\Derivation;
use OCA\OpenRegister\Service\Relation\ExternalLink;
use OCA\OpenRegister\Service\Relation\ObjectRelationService;
use OCA\OpenRegister\Service\Relation\ObjectRelationStores;
use OCA\OpenRegister\Service\Relation\ProvenanceLink;
use OCA\OpenRegister\Service\Relation\ProseMention;
use OCA\OpenRegister\Service\Relation\RelationEndpoint;
use OCA\OpenRegister\Service\Relation\RelationTypeResolver;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden pins for the relation rows written and rendered.
 */
class ObjectRelationServiceWritesGoldenTest extends TestCase {

	/**
	 * Every payload the mapper was asked to write, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * Build the service with a signed-in user and a schema 2 that declares the `cites` type.
	 *
	 * @return ObjectRelationService The service.
	 */
	private function service(): ObjectRelationService {
		$mapper = $this->createMock(ObjectRelationMapper::class);
		$mapper->method('createFromArray')->willReturnCallback(
			function (array $data): ObjectRelation {
				$this->written[] = $data;
				$row = new ObjectRelation();
				$row->hydrate($data);

				return $row;
			}
		);
		$existing = new ObjectRelation();
		$existing->setTargetUuid('known');
		$mapper->method('findByAnchor')->willReturnCallback(
			static fn (string $sourceUuid, string $anchor): array => ($anchor === 'p1' ? [$existing] : [])
		);

		$schema = new Schema();
		$schema->setId(2);
		$schema->setConfiguration(['x-openregister-relation-types' => [['key' => 'cites', 'label' => 'cites', 'inverseLabel' => 'cited by']]]);
		$schema->setProperties(['ref' => ['$ref' => 'x', 'x-openregister-relation' => ['type' => 'cites']]]);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			static function (mixed $id) use ($schema): Schema {
				if ((int)$id !== 2) {
					throw new RuntimeException('no schema');
				}

				return $schema;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ObjectRelationService(
			stores: new ObjectRelationStores(mapper: $mapper, schemaMapper: $schemaMapper, relationTypes: new RelationTypeResolver()),
			logger: $this->createMock(LoggerInterface::class),
			userSession: $session
		);
	}//end service()

	/**
	 * One object.
	 *
	 * @param string               $uuid     The uuid.
	 * @param mixed                $register The register.
	 * @param mixed                $schema   The schema.
	 * @param array<string, mixed> $data     The data.
	 *
	 * @return ObjectEntity The object.
	 */
	private static function object(string $uuid, mixed $register, mixed $schema, array $data = []): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister($register);
		$entity->setSchema($schema);
		$entity->setObject($data);

		return $entity;
	}//end object()

	/**
	 * Every write, refusal and render matches the pin.
	 *
	 * @return void
	 */
	public function testWritesAndRendersMatchThePin(): void {
		$service = $this->service();
		$parent  = self::object(uuid: 'p', register: '3', schema: 4, data: ['cls' => 'A', 'conf' => '', 'resp' => null, 'own' => 'P', 'zero' => 0]);
		$child   = self::object(uuid: 'c', register: 'x', schema: null);
		$out     = [];

		$out[] = $service->recordSplit(source: $parent, created: $child)->jsonSerialize();
		$out[] = $service->recordSplit(source: $parent, created: $child, link: new ProvenanceLink(entry: 'e1', relationType: 'cites'))->jsonSerialize();
		$out[] = $service->applyInheritance(
			parent: $parent,
			childData: ['own' => 'C', 'cls' => ''],
			inherits: ['classification' => 'cls', 'confidentiality' => 'conf', 'responsible' => 'resp', 'x' => 'own', 'y' => 'zero', 'z' => 'none', 'w' => 'cls']
		);
		$out[] = $service->recordDerived(derivation: new Derivation(parent: $parent, child: $child))->jsonSerialize();
		$out[] = $service->recordDerived(derivation: new Derivation(parent: $parent, child: $child, link: new ProvenanceLink(entry: 'e2', relationType: 'cites'), inherited: ['a' => 1]))->jsonSerialize();
		$out[] = $service->addExternalLink(source: new RelationEndpoint(uuid: 's'), link: new ExternalLink(url: ' https://example.org/a '))->jsonSerialize();
		$out[] = $service->addExternalLink(
			source: new RelationEndpoint(uuid: 's', register: 5, schema: 6),
			link: new ExternalLink(url: 'https://example.org/b', title: ' Title ', relationType: 't', label: 'l')
		)->jsonSerialize();
		$out[] = $service->addExternalLink(source: new RelationEndpoint(uuid: 's'), link: new ExternalLink(url: 'https://example.org/c', title: '  '))->jsonSerialize();
		foreach (['', '   ', 'not a url', 'ftp//x'] as $bad) {
			try {
				$service->addExternalLink(source: new RelationEndpoint(uuid: 's'), link: new ExternalLink(url: $bad));
				$out[] = 'accepted '.$bad;
			} catch (InvalidArgumentException $e) {
				$out[] = $e->getMessage();
			}
		}

		$out[] = count($service->recordProseReference(mention: new ProseMention(source: new RelationEndpoint(uuid: ''), target: new RelationEndpoint(uuid: 't'), anchor: 'p0')));
		$out[] = count($service->recordProseReference(mention: new ProseMention(source: new RelationEndpoint(uuid: 's'), target: new RelationEndpoint(uuid: ''), anchor: 'p0')));
		$out[] = count($service->recordProseReference(mention: new ProseMention(source: new RelationEndpoint(uuid: 's'), target: new RelationEndpoint(uuid: 's'), anchor: 'p0')));
		$out[] = count($service->recordProseReference(mention: new ProseMention(source: new RelationEndpoint(uuid: 's'), target: new RelationEndpoint(uuid: 'known'), anchor: 'p1')));
		$out[] = array_map(
			static fn (ObjectRelation $row): array => $row->jsonSerialize(),
			$service->recordProseReference(mention: new ProseMention(source: new RelationEndpoint(uuid: 's', register: 1),
				target: new RelationEndpoint(uuid: 'known', schema: 9),
				anchor: 'p2',
				relationType: 'cites'
			))
		);
		$out[] = array_map(
			static fn (ObjectRelation $row): array => $row->jsonSerialize(),
			$service->recordProseReference(mention: new ProseMention(source: new RelationEndpoint(uuid: 'q'), target: new RelationEndpoint(uuid: 'r'), anchor: 'p3'))
		);

		$out[] = $this->written;
		$out[] = $this->renders(service: $service);

		$actual = (string)json_encode(value: $out, flags: JSON_UNESCAPED_SLASHES);
		$this->assertSame('d9784ea45f18319c22bed3d41fb8fca0', md5(string: $actual), $actual);
	}//end testWritesAndRendersMatchThePin()

	/**
	 * Stored rows rendered in both directions: typed against a schema, typed against a missing one, untyped per origin.
	 *
	 * @param ObjectRelationService $service The service.
	 *
	 * @return array<int, mixed> The renders.
	 */
	private function renders(ObjectRelationService $service): array {
		$rows = [
			['relationType' => 'cites', 'sourceSchema' => 2, 'origin' => 'prose', 'kind' => 'object'],
			['relationType' => 'cites', 'sourceSchema' => 7, 'origin' => 'split', 'kind' => 'object', 'label' => 'own label'],
			['relationType' => 'unknown', 'sourceSchema' => 2, 'origin' => 'derive', 'kind' => 'object', 'inverseLabel' => 'inv'],
			['relationType' => null, 'sourceSchema' => 2, 'origin' => 'split', 'kind' => 'object', 'label' => '  ', 'symmetric' => true],
			['origin' => 'derive'],
			['origin' => 'prose'],
			['origin' => 'manual', 'kind' => 'external'],
		];
		$renders = [];
		foreach ($rows as $data) {
			$row = new ObjectRelation();
			$row->hydrate($data);
			$row->setUuid('u');
			foreach ([RelationTypeResolver::DIRECTION_OUTGOING, RelationTypeResolver::DIRECTION_INCOMING] as $direction) {
				$renders[] = $service->render(row: $row, direction: $direction, language: 'en')['relation'];
			}
		}

		return $renders;
	}//end renders()
}//end class
