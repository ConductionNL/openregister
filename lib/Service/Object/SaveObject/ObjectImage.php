<?php

/**
 * OpenRegister ObjectImage
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;

/**
 * Sets an object's image from the value of its schema's objectImageField.
 *
 * The value is a list of file objects, of which the first one's download or access url
 * counts, or a string url. A file id, alone or in a list, sets nothing: file loading is
 * not implemented here.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class ObjectImage {

	/**
	 * @param LoggerInterface $logger Records a file id that was skipped.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Set the image of an object from a field value.
	 *
	 * @param ObjectEntity $object The object.
	 * @param mixed        $value  The value of the image field.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function apply(ObjectEntity $object, mixed $value): void {
		if (is_array($value) === true) {
			self::applyFirstFile(object: $object, file: $value[0] ?? null);
			return;
		}

		if (is_numeric($value) === true) {
			$message = '[SaveObject] File ID detected for objectImageField - file loading not yet implemented';
			$this->logger->debug(message: $message, context: ['app' => 'openregister', 'fileId' => $value]);
			return;
		}

		if (is_string($value) === true && trim($value) !== '') {
			$object->setImage(trim($value));
		}
	}//end apply()

	/**
	 * Use the url of the first entry of a list, when it is a file object.
	 *
	 * @param ObjectEntity $object The object.
	 * @param mixed        $file   The first entry.
	 *
	 * @return void
	 */
	private static function applyFirstFile(ObjectEntity $object, mixed $file): void {
		if (is_array($file) === false) {
			return;
		}

		$url = ($file['downloadUrl'] ?? $file['accessUrl'] ?? null);
		if ($url !== null) {
			$object->setImage($url);
		}
	}//end applyFirstFile()
}//end class
