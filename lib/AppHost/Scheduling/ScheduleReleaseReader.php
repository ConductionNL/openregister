<?php

/**
 * OpenRegister AppHost: recognises a publish among object writes.
 *
 * A publish is a change of an application's `productionVersion`, or of the
 * `schedules` on the version that is production: buildiq's automation compiler
 * writes straight onto that version. Every other write answers null, so
 * re-saving an application never changes a schedule's identity.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Scheduling;

use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\WriteCause;
use OCP\IUserSession;
use Throwable;

/**
 * Turns an object write into the publish it is, or into nothing.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup is the ambient audit-cause frame.
 *
 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
 */
class ScheduleReleaseReader {
	/**
	 * The canonical slug of the register holding applications.
	 */
	private const REGISTER = 'buildiq';

	/**
	 * The application schema slug.
	 */
	private const APPLICATION = 'application';

	/**
	 * The application version schema slug.
	 */
	private const VERSION = 'applicationVersion';

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objects Reads the other half of a publish.
	 * @param RegisterMapper $registers Names an object's register.
	 * @param SchemaMapper $schemas Names an object's schema.
	 * @param RegisterSlugResolverInterface $slugs Finds the slug this instance carries.
	 * @param IUserSession $session Who is publishing.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		private readonly RegisterMapper $registers,
		private readonly SchemaMapper $schemas,
		private readonly RegisterSlugResolverInterface $slugs,
		private readonly IUserSession $session,
	) {
	}//end __construct()

	/**
	 * The publish an object write amounts to.
	 *
	 * @param ObjectEntity $object The object after the write.
	 * @param ObjectEntity|null $before The object before it, or null when created.
	 *
	 * @return ScheduleRelease|null The publish, or null when this write is none.
	 *
	 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
	 */
	public function releaseOf(ObjectEntity $object, ?ObjectEntity $before): ?ScheduleRelease {
		$after = $object->getObject();
		$prior = ($before?->getObject() ?? []);
		if ($this->productionOf(data: $after) !== $this->productionOf(data: $prior)
			&& $this->isA(object: $object, schema: self::APPLICATION) === true
		) {
			return $this->releaseOfApplication(application: $object);
		}

		if ($before !== null
			&& $this->schedulesOf(data: $after) !== $this->schedulesOf(data: $prior)
			&& $this->isA(object: $object, schema: self::VERSION) === true
		) {
			return $this->releaseOfVersion(version: $object);
		}

		return null;
	}//end releaseOf()

	/**
	 * The release of a deleted application: nothing is in production any more.
	 *
	 * @param ObjectEntity $object The deleted object.
	 *
	 * @return ScheduleRelease|null The release, or null when it is no application.
	 *
	 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
	 */
	public function releaseOfDeleted(ObjectEntity $object): ?ScheduleRelease {
		if ($this->productionOf(data: $object->getObject()) === ''
			|| $this->isA(object: $object, schema: self::APPLICATION) === false
		) {
			return null;
		}

		return new ScheduleRelease(application: $object, manifest: [], publisher: null);
	}//end releaseOfDeleted()

	/**
	 * The publish of an application whose production version changed.
	 *
	 * @param ObjectEntity $application The application.
	 *
	 * @return ScheduleRelease The publish.
	 */
	private function releaseOfApplication(ObjectEntity $application): ScheduleRelease {
		$production = $this->productionOf(data: $application->getObject());
		$manifest = [];
		if ($production !== '') {
			$version = $this->load(uuid: $production, schema: self::VERSION);
			$manifest = $this->manifestOf(data: ($version?->getObject() ?? []));
		}

		return new ScheduleRelease(application: $application, manifest: $manifest, publisher: $this->publisher());
	}//end releaseOfApplication()

	/**
	 * The publish of a version whose schedules changed, when it is in production.
	 *
	 * @param ObjectEntity $version The version.
	 *
	 * @return ScheduleRelease|null The publish.
	 */
	private function releaseOfVersion(ObjectEntity $version): ?ScheduleRelease {
		$data = $version->getObject();
		$application = $this->load(uuid: (string)($data['application'] ?? ''), schema: self::APPLICATION);
		if ($application === null || $this->productionOf(data: $application->getObject()) !== $version->getUuid()) {
			return null;
		}

		return new ScheduleRelease(application: $application, manifest: $this->manifestOf(data: $data), publisher: $this->publisher());
	}//end releaseOfVersion()

	/**
	 * Read an object of the applications register, whoever publishes.
	 *
	 * @param string $uuid The object uuid.
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectEntity|null The object, or null when it cannot be read.
	 */
	private function load(string $uuid, string $schema): ?ObjectEntity {
		$register = $this->register();
		if ($uuid === '' || $register === null) {
			return null;
		}

		try {
			return WriteCause::asLookup(
				fn () => $this->objects->find(id: $uuid, register: $register, schema: $schema, _rbac: false, _multitenancy: false)
			);
		} catch (Throwable $e) {
			return null;
		}
	}//end load()

	/**
	 * Whether an object is of this schema in the applications register.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string $schema The schema slug.
	 *
	 * @return bool False for a same-named schema in any other register.
	 */
	private function isA(ObjectEntity $object, string $schema): bool {
		try {
			$register = $this->registers->find(id: (string)$object->getRegister(), _rbac: false, _multitenancy: false);
			$found = $this->schemas->find(id: (string)$object->getSchema(), _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			return false;
		}

		return $register->getSlug() === $this->register() && $found->getSlug() === $schema;
	}//end isA()

	/**
	 * The slug the applications register has on this instance.
	 *
	 * @return string|null The slug, or null when the instance has none.
	 */
	private function register(): ?string {
		return $this->slugs->slugOrNull(canonical: self::REGISTER);
	}//end register()

	/**
	 * The signed-in user.
	 *
	 * @return string|null The uid, or null when nobody is.
	 */
	private function publisher(): ?string {
		return $this->session->getUser()?->getUID();
	}//end publisher()

	/**
	 * The production version uuid in an application's data.
	 *
	 * @param array<string, mixed> $data The data.
	 *
	 * @return string The uuid, or empty when nothing is in production.
	 */
	private function productionOf(array $data): string {
		$production = ($data['productionVersion'] ?? '');
		if (is_string($production) === false || $production === 'draft') {
			return '';
		}

		return $production;
	}//end productionOf()

	/**
	 * The schedules in a version's data.
	 *
	 * @param array<string, mixed> $data The data.
	 *
	 * @return mixed The manifest's `schedules`.
	 */
	private function schedulesOf(array $data): mixed {
		return ($this->manifestOf(data: $data)['schedules'] ?? null);
	}//end schedulesOf()

	/**
	 * The decoded manifest in a version's data.
	 *
	 * @param array<string, mixed> $data The data.
	 *
	 * @return array<string, mixed> The manifest, empty when absent or unreadable.
	 */
	private function manifestOf(array $data): array {
		$manifest = ($data['manifest'] ?? []);
		if (is_string($manifest) === true) {
			$manifest = json_decode($manifest, associative: true);
		}

		if (is_array($manifest) === false) {
			return [];
		}

		return $manifest;
	}//end manifestOf()
}//end class
