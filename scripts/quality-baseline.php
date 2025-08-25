<?php
/**
 * Quality Baseline Generator
 * 
 * Generates a baseline of quality metrics for comparison in future runs.
 * This script runs all quality tools and stores the results in a JSON file.
 * 
 * @category Script
 * @package  OCA\OpenRegister\Scripts
 * 
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * 
 * @version GIT: <git-id>
 * 
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

/**
 * Main execution function
 * 
 * @return void
 */
function main(): void
{
    $baselineFile = __DIR__ . '/../quality-baseline.json';
    $reportsDir = __DIR__ . '/../quality-reports';
    
    // Create reports directory if it doesn't exist
    if (!is_dir($reportsDir)) {
        mkdir($reportsDir, 0755, true);
    }
    
    echo "🎯 Generating Quality Baseline...\n\n";
    
    $baseline = [
        'generated_at' => date('c'),
        'git_commit' => getGitCommit(),
        'git_branch' => getGitBranch(),
        'scores' => []
    ];
    
    // 1. Run PHPCS
    echo "📊 Running PHPCS...\n";
    $phpcsResult = runPhpcs($reportsDir);
    $baseline['scores']['phpcs'] = $phpcsResult;
    
    // 2. Run PHPMD
    echo "📊 Running PHPMD...\n";
    $phpmdResult = runPhpmd($reportsDir);
    $baseline['scores']['phpmd'] = $phpmdResult;
    
    // 3. Run Psalm
    echo "📊 Running Psalm...\n";
    $psalmResult = runPsalm($reportsDir);
    $baseline['scores']['psalm'] = $psalmResult;
    
    // 4. Calculate total score
    $totalScore = calculateTotalScore($baseline['scores']);
    $baseline['scores']['total'] = [
        'score' => $totalScore,
        'grade' => getQualityGrade($totalScore)
    ];
    
    // Save baseline
    file_put_contents($baselineFile, json_encode($baseline, JSON_PRETTY_PRINT));
    
    echo "\n✅ Quality baseline generated successfully!\n";
    echo "📁 Saved to: $baselineFile\n";
    echo "📊 Reports saved to: $reportsDir/\n\n";
    
    // Display summary
    displaySummary($baseline['scores']);
}

/**
 * Run PHPCS and return results
 * 
 * @param string $reportsDir Directory to save reports
 * 
 * @return array<string, mixed>
 */
function runPhpcs(string $reportsDir): array
{
    $jsonFile = $reportsDir . '/phpcs.json';
    $summaryFile = $reportsDir . '/phpcs-summary.txt';
    
    // Run PHPCS with JSON output
    exec("./vendor/bin/phpcs --standard=phpcs.xml --report=json --report-file=$jsonFile lib/ 2>/dev/null", $output, $returnCode);
    exec("./vendor/bin/phpcs --standard=phpcs.xml --report=summary lib/ > $summaryFile 2>/dev/null");
    
    if (!file_exists($jsonFile)) {
        return [
            'score' => 1000,
            'errors' => 0,
            'warnings' => 0,
            'grade' => 'A+'
        ];
    }
    
    $data = json_decode(file_get_contents($jsonFile), true);
    $errors = $data['totals']['errors'] ?? 0;
    $warnings = $data['totals']['warnings'] ?? 0;
    $score = max(0, 1000 - $errors - intval($warnings / 2));
    
    return [
        'score' => $score,
        'errors' => $errors,
        'warnings' => $warnings,
        'grade' => getQualityGrade($score)
    ];
}

/**
 * Run PHPMD and return results
 * 
 * @param string $reportsDir Directory to save reports
 * 
 * @return array<string, mixed>
 */
function runPhpmd(string $reportsDir): array
{
    $jsonFile = $reportsDir . '/phpmd.json';
    
    // Run PHPMD with JSON output
    exec("phpmd lib/ json phpmd.xml --reportfile $jsonFile 2>/dev/null", $output, $returnCode);
    
    if (!file_exists($jsonFile) || filesize($jsonFile) === 0) {
        return [
            'score' => 1000,
            'violations' => 0,
            'grade' => 'A+'
        ];
    }
    
    $data = json_decode(file_get_contents($jsonFile), true);
    $violations = count($data['files'] ?? []);
    $score = max(0, 1000 - ($violations * 10));
    
    return [
        'score' => $score,
        'violations' => $violations,
        'grade' => getQualityGrade($score)
    ];
}

/**
 * Run Psalm and return results
 * 
 * @param string $reportsDir Directory to save reports
 * 
 * @return array<string, mixed>
 */
function runPsalm(string $reportsDir): array
{
    $jsonFile = $reportsDir . '/psalm.json';
    
    // Check if psalm.xml exists
    if (!file_exists('psalm.xml')) {
        return [
            'score' => 1000,
            'errors' => 0,
            'grade' => 'A+',
            'note' => 'Psalm configuration not found'
        ];
    }
    
    // Run Psalm with JSON output
    exec("psalm --output-format=json --report=$jsonFile --no-cache 2>/dev/null", $output, $returnCode);
    
    if (!file_exists($jsonFile) || filesize($jsonFile) === 0) {
        return [
            'score' => 1000,
            'errors' => 0,
            'grade' => 'A+'
        ];
    }
    
    $data = json_decode(file_get_contents($jsonFile), true);
    $errors = count($data ?? []);
    $score = max(0, 1000 - ($errors * 5));
    
    return [
        'score' => $score,
        'errors' => $errors,
        'grade' => getQualityGrade($score)
    ];
}

/**
 * Calculate total quality score
 * 
 * @param array<string, array<string, mixed>> $scores Individual scores
 * 
 * @return int
 */
function calculateTotalScore(array $scores): int
{
    $phpcsScore = $scores['phpcs']['score'] ?? 0;
    $phpmdScore = $scores['phpmd']['score'] ?? 0;
    $psalmScore = $scores['psalm']['score'] ?? 0;
    
    return intval(($phpcsScore + $phpmdScore + $psalmScore) / 3);
}

/**
 * Get quality grade based on score
 * 
 * @param int $score Quality score (0-1000)
 * 
 * @return string
 */
function getQualityGrade(int $score): string
{
    if ($score >= 950) return 'A+';
    if ($score >= 900) return 'A';
    if ($score >= 850) return 'B+';
    if ($score >= 800) return 'B';
    if ($score >= 750) return 'C+';
    if ($score >= 700) return 'C';
    if ($score >= 650) return 'D+';
    if ($score >= 600) return 'D';
    return 'F';
}

/**
 * Get current git commit hash
 * 
 * @return string
 */
function getGitCommit(): string
{
    $commit = trim(shell_exec('git rev-parse HEAD 2>/dev/null') ?? '');
    return $commit ?: 'unknown';
}

/**
 * Get current git branch
 * 
 * @return string
 */
function getGitBranch(): string
{
    $branch = trim(shell_exec('git branch --show-current 2>/dev/null') ?? '');
    return $branch ?: 'unknown';
}

/**
 * Display quality summary
 * 
 * @param array<string, array<string, mixed>> $scores Quality scores
 * 
 * @return void
 */
function displaySummary(array $scores): void
{
    echo "📊 Quality Summary:\n";
    echo "==================\n";
    echo sprintf("PHPCS:  %4d (%s) - %d errors, %d warnings\n", 
        $scores['phpcs']['score'], 
        $scores['phpcs']['grade'],
        $scores['phpcs']['errors'],
        $scores['phpcs']['warnings']
    );
    echo sprintf("PHPMD:  %4d (%s) - %d violations\n", 
        $scores['phpmd']['score'], 
        $scores['phpmd']['grade'],
        $scores['phpmd']['violations']
    );
    echo sprintf("Psalm:  %4d (%s) - %d errors\n", 
        $scores['psalm']['score'], 
        $scores['psalm']['grade'],
        $scores['psalm']['errors']
    );
    echo "==================\n";
    echo sprintf("TOTAL:  %4d (%s)\n", 
        $scores['total']['score'], 
        $scores['total']['grade']
    );
    echo "\n";
    
    // Quality recommendations
    if ($scores['total']['score'] < 880) {
        echo "💡 Recommendations:\n";
        if ($scores['phpcs']['score'] < 800) {
            echo "   - Run 'composer cs:fix' to fix code style issues\n";
        }
        if ($scores['phpmd']['score'] < 900) {
            echo "   - Review and refactor code to reduce complexity\n";
        }
        if ($scores['psalm']['score'] < 950) {
            echo "   - Add type hints and fix static analysis issues\n";
        }
        echo "\n";
    }
}

// Run the script
main();


