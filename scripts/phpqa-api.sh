#!/bin/bash
# Simple API server to run composer phpqa in OpenRegister
# Usage: ./phpqa-api.sh

PORT=9090

echo "Starting PHPQA API server on port $PORT..."
echo "Test with: curl -X POST http://localhost:$PORT/phpqa"
echo ""

while true; do
    response=$(echo -e "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nAccess-Control-Allow-Origin: *\r\n\r\n" && \
    {
        echo "Running composer phpqa..." >&2
        cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister
        
        # Run composer phpqa and capture output.
        output=$(docker exec master-nextcloud-1 bash -c 'cd /var/www/html/apps-extra/openregister && composer phpqa 2>&1')
        exit_code=$?
        
        # Try to read the JSON report.
        json_report=$(docker exec master-nextcloud-1 bash -c 'cat /var/www/html/apps-extra/openregister/phpqa/phpqa.json 2>/dev/null' || echo '{}')
        
        # Create JSON response.
        cat <<EOF
{
  "timestamp": "$(date -u +%Y-%m-%dT%H:%M:%S.%3NZ)",
  "status": "$([ $exit_code -eq 0 ] && echo 'success' || echo 'completed_with_issues')",
  "exit_code": $exit_code,
  "command_output": $(echo "$output" | jq -Rs .),
  "phpqa_report": $json_report,
  "report_files": {
    "json": "phpqa/phpqa.json",
    "html": "phpqa/phpqa-offline.html",
    "metrics": "phpqa/phpmetrics/"
  }
}
EOF
    })
    
    echo "$response" | nc -l -p $PORT -q 1
done



