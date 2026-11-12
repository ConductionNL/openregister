<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The properties of a shared object that the share's creator may read.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Federation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Federation;

use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Rbac\ExplicitRevealMasks;
use OCA\OpenRegister\Service\PropertyRbacHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Renders an object served through a share with the property rules read as the share's creator.
 *
 * A share token is served without access control, so a property the creator
 * may not read is left out, as it would be for the creator. An explicit-reveal
 * property is served masked: a share consumer cannot reveal it. An object whose
 * schema does not resolve, or whose rules cannot be evaluated, is served
 * without any of its properties.
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
 */
class CreatorPropertyFilter {

	/**
	 * Constructor.
	 *
	 * @param ShareCreatorCheck             $creator    Runs a check as the share's creator.
	 * @param SchemaMapperInterface         $schemas    Resolves the object's schema.
	 * @param PropertyRbacHandlerInterface  $properties Applies the schema's property rules.
	 * @param LoggerInterface               $logger     Logger.
	 */
	public function __construct(
		private readonly ShareCreatorCheck $creator,
		private readonly SchemaMapperInterface $schemas,
		private readonly PropertyRbacHandlerInterface $properties,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The rendered object with only the properties the share's creator may read.
	 *
	 * @param FederatedShare       $share    The share being served.
	 * @param ObjectEntity         $object   The stored object.
	 * @param array<string, mixed> $rendered The object as rendered.
	 *
	 * @return array<string, mixed> The rendered object, filtered.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ExplicitRevealMasks' rules are pure functions.
	 */
	public function readable(FederatedShare $share, ObjectEntity $object, array $rendered): array {
		$bare = $this->withoutProperties(rendered: $rendered);

		$schemaId = trim((string)$object->getSchema());
		if ($schemaId === '') {
			return $bare;
		}

		try {
			$schema = $this->schemas->find(id: $schemaId, _rbac: false, _multitenancy: false);
			$readable = $this->creator->asCreator(
				share: $share,
				check: fn (): array => $object->filterAsRecord(
					data: $rendered,
					filter: fn (array $probe): array => $this->properties->filterReadableProperties(schema: $schema, object: $probe)
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[Federation] shared object served without properties: ' . $e->getMessage(),
				['shareId' => $share->getId()]
			);
			return $bare;
		}

		return ExplicitRevealMasks::maskAll(schema: $schema, object: $readable);
	}//end readable()

	/**
	 * The rendered object reduced to its record metadata and id.
	 *
	 * @param array<string, mixed> $rendered The object as rendered.
	 *
	 * @return array<string, mixed>
	 */
	private function withoutProperties(array $rendered): array {
		return array_intersect_key($rendered, ['@self' => true, 'id' => true]);
	}//end withoutProperties()
}//end class
