#!/usr/bin/env python3
"""
Simple API server to run composer phpqa in OpenRegister.
Usage: python3 phpqa-api.py
"""
import json
import subprocess
import sys
from datetime import datetime
from http.server import BaseHTTPRequestHandler, HTTPServer

PORT = 9090


class PHPQAHandler(BaseHTTPRequestHandler):
    def do_POST(self):
        """Handle POST requests to run PHPQA or CS:FIX."""
        if self.path == '/phpqa':
            self._run_phpqa()
        elif self.path == '/cs-fix':
            self._run_cs_fix()
        else:
            self.send_response(404)
            self.end_headers()
    
    def _run_phpqa(self):
        """Run composer phpqa in the container."""
        print(f"[{datetime.now()}] Running composer phpqa...", file=sys.stderr)
        
        try:
            # Run composer phpqa in the container.
            result = subprocess.run(
                [
                    'docker', 'exec', 'master-nextcloud-1', 'bash', '-c',
                    'cd /var/www/html/apps-extra/openregister && composer phpqa 2>&1'
                ],
                capture_output=True,
                text=True,
                timeout=300  # 5 minute timeout.
            )
            
            # Try to get the JSON report.
            json_result = subprocess.run(
                [
                    'docker', 'exec', 'master-nextcloud-1', 'bash', '-c',
                    'cat /var/www/html/apps-extra/openregister/phpqa/phpqa.json 2>/dev/null || echo "{}"'
                ],
                capture_output=True,
                text=True
            )
            
            try:
                phpqa_json = json.loads(json_result.stdout)
            except json.JSONDecodeError:
                phpqa_json = {"error": "Failed to parse phpqa.json"}
            
            # Build response.
            response_data = {
                "timestamp": datetime.utcnow().isoformat() + "Z",
                "status": "success" if result.returncode == 0 else "completed_with_issues",
                "exit_code": result.returncode,
                "command_output": result.stdout,
                "phpqa_report": phpqa_json,
                "report_files": {
                    "json": "phpqa/phpqa.json",
                    "html": "phpqa/phpqa-offline.html",
                    "metrics": "phpqa/phpmetrics/"
                }
            }
            
            self.send_response(200)
            self.send_header('Content-type', 'application/json')
            self.send_header('Access-Control-Allow-Origin', '*')
            self.end_headers()
            self.wfile.write(json.dumps(response_data, indent=2).encode())
            
            print(f"[{datetime.now()}] PHPQA completed with exit code {result.returncode}", file=sys.stderr)
            
        except subprocess.TimeoutExpired:
            self.send_response(408)
            self.send_header('Content-type', 'application/json')
            self.end_headers()
            error_response = {"error": "Request timeout after 5 minutes"}
            self.wfile.write(json.dumps(error_response).encode())
            
        except Exception as e:
            self.send_response(500)
            self.send_header('Content-type', 'application/json')
            self.end_headers()
            error_response = {"error": str(e)}
            self.wfile.write(json.dumps(error_response).encode())
    
    def _run_cs_fix(self):
        """Run composer cs:fix in the container."""
        print(f"[{datetime.now()}] Running composer cs:fix...", file=sys.stderr)
        
        try:
            # Run composer cs:fix in the container.
            result = subprocess.run(
                [
                    'docker', 'exec', 'master-nextcloud-1', 'bash', '-c',
                    'cd /var/www/html/apps-extra/openregister && composer cs:fix 2>&1'
                ],
                capture_output=True,
                text=True,
                timeout=120  # 2 minute timeout.
            )
            
            # Count files fixed (parse output).
            output_lines = result.stdout.split('\n')
            files_fixed = 0
            for line in output_lines:
                if 'Fixed' in line or 'fixed' in line:
                    # Try to extract number of files fixed.
                    import re
                    match = re.search(r'(\d+)\s+file', line)
                    if match:
                        files_fixed = int(match.group(1))
            
            # Build response.
            response_data = {
                "timestamp": datetime.utcnow().isoformat() + "Z",
                "action": "cs:fix",
                "status": "success" if result.returncode == 0 else "completed_with_errors",
                "exit_code": result.returncode,
                "files_fixed": files_fixed,
                "command_output": result.stdout,
                "message": f"Fixed {files_fixed} file(s)" if files_fixed > 0 else "No files needed fixing"
            }
            
            self.send_response(200)
            self.send_header('Content-type', 'application/json')
            self.send_header('Access-Control-Allow-Origin', '*')
            self.end_headers()
            self.wfile.write(json.dumps(response_data, indent=2).encode())
            
            print(f"[{datetime.now()}] CS:FIX completed with exit code {result.returncode}, fixed {files_fixed} files", file=sys.stderr)
            
        except subprocess.TimeoutExpired:
            self.send_response(408)
            self.send_header('Content-type', 'application/json')
            self.end_headers()
            error_response = {"error": "Request timeout after 2 minutes"}
            self.wfile.write(json.dumps(error_response).encode())
            
        except Exception as e:
            self.send_response(500)
            self.send_header('Content-type', 'application/json')
            self.end_headers()
            error_response = {"error": str(e)}
            self.wfile.write(json.dumps(error_response).encode())
    
    def do_GET(self):
        """Handle GET requests - show status."""
        if self.path == '/':
            self.send_response(200)
            self.send_header('Content-type', 'application/json')
            self.end_headers()
            status = {
                "service": "OpenRegister PHPQA API",
                "status": "running",
                "endpoints": {
                    "POST /phpqa": "Run composer phpqa and return results",
                    "POST /cs-fix": "Run composer cs:fix to automatically fix code style issues"
                }
            }
            self.wfile.write(json.dumps(status, indent=2).encode())
        else:
            self.send_response(404)
            self.end_headers()
    
    def log_message(self, format, *args):
        """Custom log format."""
        sys.stderr.write(f"[{datetime.now()}] {format % args}\n")


if __name__ == '__main__':
    server = HTTPServer(('localhost', PORT), PHPQAHandler)
    print(f'Starting PHPQA API server on port {PORT}...')
    print(f'Test with: curl -X POST http://localhost:{PORT}/phpqa')
    print()
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print('\nShutting down server...')
        server.shutdown()

