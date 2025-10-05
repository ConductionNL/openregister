<?php
/**
 * Test script to make API calls and save responses to files
 * This will help us debug the type filtering issue
 */

declare(strict_types=1);

echo "🧪 Testing OpenRegister API and saving responses\n";
echo "=============================================\n\n";

// Configuration
$baseUrl = 'http://localhost';
$username = 'admin';
$password = 'admin';
$outputDir = __DIR__ . '/api_responses';

// Create output directory
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// Helper function to make API call and save response
function makeApiCall($endpoint, $filename, $description) {
    global $baseUrl, $username, $password, $outputDir;
    
    echo "\nℹ️  Testing: $description\n";
    echo "Endpoint: $endpoint\n";
    echo "Saving to: $filename\n";
    
    // Initialize cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $baseUrl . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    // Execute request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        echo "❌ cURL Error: $error\n";
        file_put_contents("$outputDir/$filename.error", $error);
    } else {
        // Save response to file
        file_put_contents("$outputDir/$filename", $response);
        echo "✅ Response saved to $filename (HTTP $httpCode)\n";
        
        // Show response preview
        echo "Response preview:\n";
        $preview = substr($response, 0, 500);
        echo $preview . (strlen($response) > 500 ? "\n... (truncated)" : "") . "\n";
    }
    echo "\n";
}

echo "Starting API response testing...\n";

// Test 1: Get all organizations without type filter
makeApiCall(
    '/index.php/apps/openregister/api/objects/voorzieningen/organisatie?_limit=10&_page=1&_extend[]=@self.schema&_source=database',
    'all_organizations.json',
    'All organizations (no type filter)'
);

// Test 2: Try type filtering with samenwerking
makeApiCall(
    '/index.php/apps/openregister/api/objects/voorzieningen/organisatie?_limit=10&_page=1&_extend[]=@self.schema&_source=database&type[]=samenwerking',
    'type_samenwerking.json',
    'Organizations with type=samenwerking'
);

// Test 3: Try type filtering with community
makeApiCall(
    '/index.php/apps/openregister/api/objects/voorzieningen/organisatie?_limit=10&_page=1&_extend[]=@self.schema&_source=database&type[]=community',
    'type_community.json',
    'Organizations with type=community'
);

// Test 4: Try type filtering with both types
makeApiCall(
    '/index.php/apps/openregister/api/objects/voorzieningen/organisatie?_limit=10&_page=1&_extend[]=@self.schema&_source=database&type[]=samenwerking&type[]=community',
    'type_both.json',
    'Organizations with type=samenwerking OR type=community'
);

// Test 5: Try with Solr source instead of database
makeApiCall(
    '/index.php/apps/openregister/api/objects/voorzieningen/organisatie?_limit=10&_page=1&_extend[]=@self.schema&_source=index&type[]=samenwerking&type[]=community',
    'type_both_solr.json',
    'Organizations with type filter using Solr source'
);

// Test 6: Get specific organizations by name to see their structure
makeApiCall(
    '/index.php/apps/openregister/api/objects/voorzieningen/organisatie?_limit=10&_page=1&_extend[]=@self.schema&_source=database&name[]=Samenwerking%201',
    'name_samenwerking_1.json',
    'Samenwerking 1 organization structure'
);

makeApiCall(
    '/index.php/apps/openregister/api/objects/voorzieningen/organisatie?_limit=10&_page=1&_extend[]=@self.schema&_source=database&name[]=Community%201',
    'name_community_1.json',
    'Community 1 organization structure'
);

echo "✅ API response testing completed!\n";
echo "All responses saved to api_responses/ directory\n\n";

echo "📝 Files created:\n";
$files = scandir($outputDir);
foreach ($files as $file) {
    if ($file !== '.' && $file !== '..') {
        $size = filesize("$outputDir/$file");
        echo "  - $file (" . number_format($size) . " bytes)\n";
    }
}

echo "\n🎯 You can now examine the response files to identify the type filtering issue!\n";
