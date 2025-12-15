<?php
/**
 * God Class Analyzer
 *
 * This script analyzes PHP Metrics data to identify 'god classes' - classes with high complexity metrics.
 *
 * @category Analysis
 * @package  OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

/**
 * Analyzes PHP Metrics data to find god classes.
 *
 * @return void
 */
function analyzeGodClasses(): void
{
    // Read the classes.js file.
    $classesFile = __DIR__ . '/phpqa/phpmetrics/classes.js';
    if (!file_exists($classesFile)) {
        echo "Error: {$classesFile} not found. Run 'composer phpqa' first.\n";
        exit(1);
    }

    $content = file_get_contents($classesFile);
    // Remove 'var classes = ' prefix and ';' suffix to get pure JSON.
    $content = preg_replace('/^var classes = /', '', $content);
    $content = preg_replace('/;$/', '', $content);

    $classes = json_decode($content, true);

    if ($classes === null) {
        echo "Error: Failed to parse JSON from classes.js\n";
        exit(1);
    }

    echo "\n=== God Class Analysis for OpenRegister ===\n\n";
    echo "Total classes analyzed: " . count($classes) . "\n\n";

    // Define thresholds for god classes.
    $godClasses = [];

    foreach ($classes as $class) {
        $name = $class['name'] ?? 'Unknown';
        $lloc = $class['lloc'] ?? 0;              // Logical lines of code.
        $wmc = $class['wmc'] ?? 0;                // Weighted Method Count.
        $ccn = $class['ccn'] ?? 0;                // Cyclomatic Complexity.
        $lcom = $class['lcom'] ?? 0;              // Lack of Cohesion of Methods.
        $bugs = $class['bugs'] ?? 0;              // Estimated bugs.
        $methodCount = count($class['methods'] ?? []);

        // God class criteria (adjust these as needed).
        $isGodClass = (
            $lloc > 500 ||                         // More than 500 logical lines.
            $wmc > 50 ||                           // Weighted method count > 50.
            $ccn > 50 ||                           // Cyclomatic complexity > 50.
            $methodCount > 30                      // More than 30 methods.
        );

        if ($isGodClass) {
            $godClasses[] = [
                'name' => $name,
                'lloc' => $lloc,
                'wmc' => $wmc,
                'ccn' => $ccn,
                'lcom' => $lcom,
                'bugs' => $bugs,
                'methods' => $methodCount,
            ];
        }
    }

    // Sort by lloc (logical lines of code) descending.
    usort($godClasses, function ($a, $b) {
        return $b['lloc'] <=> $a['lloc'];
    });

    echo "Found " . count($godClasses) . " god classes (LLOC > 500, WMC > 50, CCN > 50, or Methods > 30)\n\n";

    // Display table header.
    printf(
        "%-80s | %6s | %6s | %6s | %6s | %6s | %8s\n",
        'Class Name',
        'LLOC',
        'WMC',
        'CCN',
        'LCOM',
        'Bugs',
        'Methods'
    );
    echo str_repeat('-', 150) . "\n";

    // Display each god class.
    foreach ($godClasses as $class) {
        printf(
            "%-80s | %6d | %6d | %6d | %6.2f | %6.2f | %8d\n",
            substr($class['name'], 0, 80),
            $class['lloc'],
            $class['wmc'],
            $class['ccn'],
            $class['lcom'],
            $class['bugs'],
            $class['methods']
        );
    }

    echo "\n=== Metrics Explanation ===\n";
    echo "LLOC:    Logical Lines of Code (excluding comments/blank lines)\n";
    echo "WMC:     Weighted Method Count (sum of method complexities)\n";
    echo "CCN:     Cyclomatic Complexity Number (code branches/paths)\n";
    echo "LCOM:    Lack of Cohesion of Methods (higher = less cohesive)\n";
    echo "Bugs:    Estimated number of bugs\n";
    echo "Methods: Total number of methods in the class\n\n";

    echo "=== Recommended Actions ===\n";
    echo "1. Classes with LLOC > 1000: Critical priority for refactoring\n";
    echo "2. Classes with WMC > 100: Break into smaller services\n";
    echo "3. Classes with CCN > 100: Simplify logic, extract methods\n";
    echo "4. Classes with Methods > 50: Consider using composition/handlers\n";
    echo "5. Classes with LCOM > 2: Low cohesion, split responsibilities\n\n";

    // Generate categorized report.
    echo "=== Categorized by Severity ===\n\n";

    $critical = array_filter($godClasses, function ($c) {
        return $c['lloc'] > 1000 || $c['wmc'] > 100;
    });
    $high = array_filter($godClasses, function ($c) {
        return $c['lloc'] > 750 && $c['lloc'] <= 1000;
    });
    $medium = array_filter($godClasses, function ($c) {
        return $c['lloc'] > 500 && $c['lloc'] <= 750;
    });

    echo "CRITICAL (" . count($critical) . " classes):\n";
    foreach ($critical as $class) {
        echo "  - {$class['name']} (LLOC: {$class['lloc']}, WMC: {$class['wmc']}, Methods: {$class['methods']})\n";
    }

    echo "\nHIGH (" . count($high) . " classes):\n";
    foreach ($high as $class) {
        echo "  - {$class['name']} (LLOC: {$class['lloc']}, WMC: {$class['wmc']}, Methods: {$class['methods']})\n";
    }

    echo "\nMEDIUM (" . count($medium) . " classes):\n";
    foreach ($medium as $class) {
        echo "  - {$class['name']} (LLOC: {$class['lloc']}, WMC: {$class['wmc']}, Methods: {$class['methods']})\n";
    }

    echo "\n";
}

analyzeGodClasses();

