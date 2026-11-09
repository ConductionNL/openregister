<?php

/**
 * Finds the controller sources of the app, wherever a component keeps them.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The directories and files source-scanning rule tests read.
 */
final class ControllerSources
{
    /**
     * The controller directories: lib/Controller and lib/<Component>/Controller.
     *
     * @return list<string>
     */
    public static function directories(): array
    {
        $lib = dirname(__DIR__, 3).'/lib';

        return array_merge([$lib.'/Controller'], glob($lib.'/*/Controller', GLOB_ONLYDIR) ?: []);
    }//end directories()

    /**
     * Every PHP file under a controller directory, Support bundles included.
     *
     * @return list<string> Absolute paths, sorted.
     */
    public static function files(): array
    {
        $files = [];
        foreach (self::directories() as $directory) {
            $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
            foreach ($walk as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);
        return $files;
    }//end files()

    /**
     * The files directly in a controller directory, the classes routes point at.
     *
     * @return list<string> Absolute paths, sorted.
     */
    public static function controllers(): array
    {
        $files = [];
        foreach (self::directories() as $directory) {
            $files = array_merge($files, glob($directory.'/*.php') ?: []);
        }

        sort($files);
        return $files;
    }//end controllers()
}//end class
