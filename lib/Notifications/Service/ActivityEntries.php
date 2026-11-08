<?php

/**
 * Builds the activities of objects, registers and schemas.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service;

use OCP\IURLGenerator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\DeepLinkResolverInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns an entity and what happened to it into an activity: its title, parameters and link.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
class ActivityEntries {
	/**
	 * Constructor.
	 *
	 * @param IURLGenerator $urlGenerator The URL generator.
	 * @param LoggerInterface $logger The logger.
	 * @param SchemaMapperInterface|null $schemaMapper Resolves the schema title an object activity names; null only in hand-built tests.
	 * @param DeepLinkResolverInterface|null $deepLinks The owning app's detail route per register and schema.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 * @spec openspec/specs/activity-provider/spec.md#requirement-an-object-activity-names-the-schema-and-the-object
	 */
	public function __construct(
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
		private ?SchemaMapperInterface $schemaMapper = null,
		private ?DeepLinkResolverInterface $deepLinks = null,
	) {
	}//end __construct()

	/**
	 * The activity of an object. A deleted object has no link.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string       $action What happened.
	 *
	 * @return ActivityEntry
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function object(ObjectEntity $object, string $action): ActivityEntry {
		$title = $this->titleOf(candidates: [$object->getName(), $object->getUuid()]);
		$about = new ActivitySubject(kind: 'object', id: (string)$object->getId(), title: $title, owner: $object->getOwner());
		$link  = '';
		if ($action !== 'deleted') {
			$link = $this->buildObjectLink(object: $object);
		}

		return new ActivityEntry(about: $about, action: $action, parameters: $this->objectParameters(object: $object, title: $title), link: $link);
	}//end object()

	/**
	 * The activity of a register. A deleted register has no link.
	 *
	 * @param Register $register The register.
	 * @param string   $action   What happened.
	 *
	 * @return ActivityEntry
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function register(Register $register, string $action): ActivityEntry {
		$title = $this->titleOf(candidates: [$register->getTitle(), $register->getUuid()]);
		$about = new ActivitySubject(kind: 'register', id: (string)$register->getId(), title: $title, owner: $register->getOwner());
		$link  = '';
		if ($action !== 'deleted') {
			$link = $this->dashboardUrl() . '#/registers/' . $register->getId();
		}

		return new ActivityEntry(about: $about, action: $action, parameters: ['title' => $title], link: $link);
	}//end register()

	/**
	 * The activity of a schema. A deleted schema has no link.
	 *
	 * @param Schema $schema The schema.
	 * @param string $action What happened.
	 *
	 * @return ActivityEntry
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function schema(Schema $schema, string $action): ActivityEntry {
		$title = $this->titleOf(candidates: [$schema->getTitle(), $schema->getUuid()]);
		$about = new ActivitySubject(kind: 'schema', id: (string)$schema->getId(), title: $title, owner: $schema->getOwner());
		$link  = '';
		if ($action !== 'deleted') {
			$link = $this->dashboardUrl() . '#/schemas/' . $schema->getId();
		}

		return new ActivityEntry(about: $about, action: $action, parameters: ['title' => $title], link: $link);
	}//end schema()

	/**
	 * The subject parameters of an object activity: its title and, when it has one, its schema's.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string       $title  The object's title.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/activity-provider/spec.md#requirement-an-object-activity-names-the-schema-and-the-object
	 */
	private function objectParameters(ObjectEntity $object, string $title): array {
		$parameters  = ['title' => $title];
		$schemaTitle = trim($this->schemaTitleOf(object: $object));
		if ($schemaTitle !== '') {
			$parameters['schema'] = $schemaTitle;
		}

		return $parameters;
	}//end objectParameters()

	/**
	 * The title of an object's schema, empty when it has none or cannot be read.
	 *
	 * A read for a label only. The activity goes to the author and the owner, who
	 * both reach the object and so its schema.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/activity-provider/spec.md#requirement-an-object-activity-names-the-schema-and-the-object
	 */
	private function schemaTitleOf(ObjectEntity $object): string {
		$schemaId = $object->getSchema();
		if ($this->schemaMapper === null || $schemaId === null || $schemaId === '') {
			return '';
		}

		try {
			return (string)$this->schemaMapper->find(id: $schemaId, _rbac: false, _multitenancy: false)->getTitle();
		} catch (\Throwable $e) {
			$this->logger->debug(
				message: '[ActivityService] Schema title not resolved for an object activity',
				context: ['schema' => $schemaId, 'exception' => $e->getMessage()]
			);
			return '';
		}
	}//end schemaTitleOf()

	/**
	 * The link of an object: the owning app's deep link when it has one, else the dashboard's.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return string
	 */
	private function buildObjectLink(ObjectEntity $object): string {
		$owned = $this->ownedLink(object: $object);
		if ($owned === '') {
			return $this->dashboardUrl() . '#/registers/' . $object->getRegister() . '/schemas/' . $object->getSchema() . '/objects/' . $object->getUuid();
		}

		if (str_starts_with($owned, 'http') === true) {
			return $owned;
		}

		return $this->urlGenerator->getAbsoluteURL($owned);
	}//end buildObjectLink()

	/**
	 * The deep link the owning app registered for an object, empty when there is none.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return string
	 */
	private function ownedLink(ObjectEntity $object): string {
		$registerId = $object->getRegister();
		$schemaId   = $object->getSchema();
		if ($this->deepLinks === null || is_numeric($registerId) === false || is_numeric($schemaId) === false) {
			return '';
		}

		$uuid = (string)$object->getUuid();
		return (string)$this->deepLinks->resolveUrl(
			registerId: (int)$registerId,
			schemaId: (int)$schemaId,
			objectData: (($object->getObject() ?? []) + ['uuid' => $uuid, 'id' => $uuid])
		);
	}//end ownedLink()

	/**
	 * The absolute URL of the OpenRegister dashboard.
	 *
	 * @return string
	 */
	private function dashboardUrl(): string {
		return $this->urlGenerator->linkToRouteAbsolute('openregister.dashboard.page');
	}//end dashboardUrl()

	/**
	 * The first title that is set, else 'Unknown'.
	 *
	 * @param array<int, string|null> $candidates The titles, most wanted first.
	 *
	 * @return string
	 */
	private function titleOf(array $candidates): string {
		$titles = array_filter($candidates, static fn (mixed $title): bool => $title !== null && $title !== '');

		return (array_values($titles)[0] ?? 'Unknown');
	}//end titleOf()
}//end class
