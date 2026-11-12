<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\InverseBatch;
use OCA\OpenRegister\Service\Object\InverseLookup;
use OCA\OpenRegister\Service\Object\InverseRelationCache;
use OCA\OpenRegister\Service\Object\InverseRelationPreload;
use OCA\OpenRegister\Service\Object\InverseRelationRender;
use OCA\OpenRegister\Service\Object\RenderObject;
use ReflectionMethod;

/**
 * Reaches the inverse relation classes a RenderObject under test creates.
 */
trait InverseRelationAccess {
	/**
	 * The object that owns a private method: the renderer or one of its inverse relation classes.
	 *
	 * @param RenderObject $handler The renderer under test.
	 * @param string       $method  The method name.
	 *
	 * @return object
	 */
	private function methodOwner(RenderObject $handler, string $method): object {
		$owners = [
			InverseRelationCache::class => 'cache',
			InverseRelationPreload::class => 'inversePreload',
			InverseRelationRender::class => 'inverseRelations',
		];
		foreach ($owners as $class => $getter) {
			if (method_exists($class, $method) === true) {
				return $this->inversePart($handler, $getter);
			}
		}

		return $handler;
	}

	/**
	 * One inverse relation class of a renderer.
	 *
	 * @param RenderObject $handler The renderer under test.
	 * @param string       $getter  The renderer's private getter.
	 *
	 * @return object
	 */
	private function inversePart(RenderObject $handler, string $getter = 'cache'): object {
		$method = new ReflectionMethod($handler, $getter === 'cache' ? 'inverseSupport' : $getter);
		$method->setAccessible(true);
		$part = $method->invoke($handler);

		return $getter === 'cache' ? $part->objects->cache : $part;
	}

	/**
	 * A batch for one property.
	 *
	 * @param array  $fields   The inversedBy fields.
	 * @param array  $uuids    The entity UUIDs.
	 * @param string $propName The property name.
	 *
	 * @return InverseBatch
	 */
	private function inverseBatch(array $fields, array $uuids, string $propName): InverseBatch {
		$batch = new InverseBatch();
		$batch->inversedByFields = $fields;
		$batch->entityUuids = $uuids;
		$batch->propName = $propName;

		return $batch;
	}

	/**
	 * A lookup for one entity.
	 *
	 * @param ObjectEntity $entity             The entity.
	 * @param array        $inversedProperties The inverse properties.
	 *
	 * @return InverseLookup
	 */
	private function inverseLookup(ObjectEntity $entity, array $inversedProperties): InverseLookup {
		$lookup = new InverseLookup();
		$lookup->entity = $entity;
		$lookup->inversedProperties = $inversedProperties;

		return $lookup;
	}
}
