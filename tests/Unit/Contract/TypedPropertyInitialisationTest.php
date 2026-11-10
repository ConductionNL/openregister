<?php

/**
 * Guards against typed properties that nothing ever assigns.
 *
 * WHY THIS EXISTS. On 2026-08-26 three services in lib/Service declared
 * `readonly` typed properties and had NO CONSTRUCTOR at all:
 *
 *   NotificationService  $notificationManager, $groupManager, $logger
 *   EndpointService      $endpointLogMapper, $logger, $userSession, $groupManager
 *   UploadService        $client
 *
 * PHP does not complain about that at parse time, at autoload time, or in any
 * static analyser the fleet runs. It complains the first time the property is
 * READ, with a fatal:
 *
 *   Typed property NotificationService::$notificationManager must not be
 *   accessed before initialization
 *
 * So the whole configuration-update notification path was dead, and so were
 * EndpointService's permission checks — while every gate in this repository
 * stayed green, because nothing constructed the objects.
 *
 * It took an e2e run in a DIFFERENT repository (learniq) to surface it, in a
 * server log line about `POST /apps/openregister/api/configurations/8/import`.
 * A defect that only a downstream consumer's browser test can see is exactly
 * the kind that needs a mechanical guard here.
 *
 * The check is deliberately source-based rather than reflection-based: loading
 * these classes requires the Nextcloud server, which unit tests do not have.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Contract
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Contract;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every declared typed property must be assignable.
 */
class TypedPropertyInitialisationTest extends TestCase
{


    /**
     * Absolute path to the app's lib/ directory.
     *
     * @return string The directory path.
     */
    private function libDir(): string
    {
        return dirname(__DIR__, 3).'/lib';

    }//end libDir()


    /**
     * Find typed instance properties that are declared with no default, never
     * promoted through a constructor, and never assigned via `$this->x =`.
     *
     * @return list<string> Offending "path::$property" entries.
     */
    private function unassignedTypedProperties(): array
    {
        $offenders = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->libDir()));
        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->isFile() === false || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            // Declarations with no default value: `private readonly Foo $bar;`
            preg_match_all(
                '/^\s*(?:private|protected|public)\s+(?:readonly\s+)?\??[A-Za-z_\\\\][\w\\\\|]*\s+\$(\w+)\s*;/m',
                $source,
                $declared
            );
            if ($declared[1] === []) {
                continue;
            }

            // Constructor-promoted parameters: `private readonly Foo $bar,`
            preg_match_all(
                '/(?:private|protected|public)\s+(?:readonly\s+)?\??[A-Za-z_\\\\][\w\\\\|]*\s+\$(\w+)\s*[,)]/',
                $source,
                $promoted
            );

            // Explicit assignment anywhere in the class body.
            preg_match_all('/\$this->(\w+)\s*=/', $source, $assigned);

            $satisfied = array_flip(array_merge($promoted[1], $assigned[1]));

            foreach (array_unique($declared[1]) as $property) {
                if (array_key_exists($property, $satisfied) === true) {
                    continue;
                }

                $relative   = str_replace(dirname(__DIR__, 3).'/', '', $file->getPathname());
                $offenders[] = $relative.'::$'.$property;
            }
        }

        sort($offenders);

        return $offenders;

    }//end unassignedTypedProperties()


    /**
     * No class under lib/ may declare a typed property nothing can assign.
     *
     * @return void
     */
    public function testNoTypedPropertyIsLeftUninitialised(): void
    {
        $offenders = $this->unassignedTypedProperties();

        $this->assertSame(
            [],
            $offenders,
            "These typed properties are declared but never promoted or assigned, so reading one is a FATAL "
            ."('must not be accessed before initialization') rather than a null:\n  - "
            .implode("\n  - ", $offenders)
            ."\nGive the class a constructor that assigns them, or promote them."
        );

    }//end testNoTypedPropertyIsLeftUninitialised()


    /**
     * The detector must actually detect — a guard that cannot fail is not a guard.
     *
     * Feeds the check a synthetic class carrying the exact defect and asserts it
     * is reported, so a future refactor of the regexes cannot silently turn this
     * suite into a no-op.
     *
     * @return void
     */
    public function testTheDetectorReportsAKnownOffender(): void
    {
        $probe = $this->libDir().'/__typed_property_probe.php';

        file_put_contents(
            $probe,
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace OCA\\OpenRegister;\n\n"
            ."class TypedPropertyProbe {\n"
            ."\tprivate readonly \\Psr\\Log\\LoggerInterface \$probeLogger;\n"
            ."\tpublic function go(): void { \$this->probeLogger->info('x'); }\n"
            ."}\n"
        );

        try {
            $offenders = $this->unassignedTypedProperties();
        } finally {
            unlink($probe);
        }

        $this->assertContains(
            'lib/__typed_property_probe.php::$probeLogger',
            $offenders,
            'the detector no longer recognises the very defect it exists to catch'
        );

    }//end testTheDetectorReportsAKnownOffender()


}//end class
