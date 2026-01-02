#!/usr/bin/env python3
"""
OpenRegister Container API with AI Code Fixing Support.

Includes PHPCS analysis, file operations, and integration with Ollama LLM.
"""
import json
import subprocess
import sys
import re
from datetime import datetime
from http.server import BaseHTTPRequestHandler, HTTPServer
from typing import Dict, Any

PORT = 9090
CONTAINER_NAME = 'master-nextcloud-1'
APP_PATH = '/var/www/html/apps-extra/openregister'
OLLAMA_URL = 'http://localhost:11434'

# ============================================================================
# POST PROCESSORS  
# ============================================================================

def process_phpcs_detailed(result: subprocess.CompletedProcess) -> Dict[str, Any]:
    """Parse PHPCS JSON output for AI processing."""
    try:
        # The output is in result.stdout
        phpcs_data = json.loads(result.stdout)
        
        issues_by_file = []
        total_errors = 0
        total_warnings = 0
        
        for filepath, file_data in phpcs_data.get('files', {}).items():
            if file_data.get('errors', 0) > 0 or file_data.get('warnings', 0) > 0:
                messages = file_data.get('messages', [])
                
                issues_by_line = {}
                for msg in messages:
                    line = msg['line']
                    if line not in issues_by_line:
                        issues_by_line[line] = []
                    
                    issues_by_line[line].append({
                        'column': msg['column'],
                        'type': msg['type'],
                        'message': msg['message'],
                        'source': msg['source']
                    })
                
                issues_by_file.append({
                    'file': filepath.replace('/var/www/html/apps-extra/openregister/', ''),
                    'errors': file_data.get('errors', 0),
                    'warnings': file_data.get('warnings', 0),
                    'issues_by_line': issues_by_line
                })
                
                total_errors += file_data.get('errors', 0)
                total_warnings += file_data.get('warnings', 0)
        
        return {
            "phpcs_issues": issues_by_file,
            "totals": {
                "files_with_issues": len(issues_by_file),
                "total_errors": total_errors,
                "total_warnings": total_warnings
            }
        }
    except Exception as e:
        return {"error": f"Failed to parse PHPCS: {str(e)}"}

# ============================================================================
# COMMANDS
# ============================================================================

COMMANDS = {
    # PHPCS for AI fixing
    'phpcs-detailed': {
        'command': './vendor/bin/phpcs --report=json --standard=PSR12 lib/',
        'timeout': 60,
        'description': 'Get detailed PHPCS issues for AI fixing',
        'post_processor': process_phpcs_detailed
    },
    
    # Original commands...
    'phpqa': {
        'command': 'composer phpqa',
        'timeout': 300,
        'description': 'Run full PHPQA suite'
    },
    'cs-fix': {
        'command': 'composer cs:fix',
        'timeout': 120,
        'description': 'Auto-fix code style'
    }
}

# ============================================================================
# HTTP HANDLER
# ============================================================================

class AICodeFixingHandler(BaseHTTPRequestHandler):
    
    def do_POST(self):
        """Handle POST requests."""
        path = self.path.lstrip('/')
        
        # File operations need request body
        if path in ['read-file', 'write-file', 'backup-file']:
            self._handle_file_operation(path)
        # LLM fix operation
        elif path == 'ai-fix-code':
            self._handle_ai_fix()
        # Regular commands  
        elif path in COMMANDS:
            self._execute_command(path)
        else:
            self._send_error(404, f"Unknown endpoint: {path}")
    
    def _execute_command(self, cmd_name: str):
        """Execute a container command."""
        cmd_config = COMMANDS[cmd_name]
        
        try:
            result = subprocess.run(
                [
                    'docker', 'exec', CONTAINER_NAME, 'bash', '-c',
                    f'cd {APP_PATH} && {cmd_config["command"]} 2>&1'
                ],
                capture_output=True,
                text=True,
                timeout=cmd_config['timeout']
            )
            
            response = {
                "timestamp": datetime.utcnow().isoformat() + "Z",
                "command": cmd_name,
                "status": "success" if result.returncode == 0 else "completed_with_errors",
                "exit_code": result.returncode,
                "output": result.stdout
            }
            
            if 'post_processor' in cmd_config:
                extra = cmd_config['post_processor'](result)
                response.update(extra)
            
            self._send_json(response)
            
        except subprocess.TimeoutExpired:
            self._send_error(408, "Timeout")
        except Exception as e:
            self._send_error(500, str(e))
    
    def _handle_file_operation(self, operation: str):
        """Handle file read/write/backup."""
        try:
            content_length = int(self.headers.get('Content-Length', 0))
            body = json.loads(self.rfile.read(content_length).decode('utf-8'))
            
            if operation == 'read-file':
                result = self._read_file(body.get('file'))
            elif operation == 'write-file':
                result = self._write_file(body.get('file'), body.get('content'))
            elif operation == 'backup-file':
                result = self._backup_file(body.get('file'))
            
            self._send_json(result)
            
        except Exception as e:
            self._send_error(500, str(e))
    
    def _read_file(self, file_path: str) -> Dict:
        """Read a file from the container."""
        if not file_path or '..' in file_path:
            return {"error": "Invalid file path"}
        
        result = subprocess.run(
            ['docker', 'exec', CONTAINER_NAME, 'bash', '-c', 
             f'cat {APP_PATH}/{file_path}'],
            capture_output=True,
            text=True,
            timeout=10
        )
        
        if result.returncode != 0:
            return {"error": "File not found"}
        
        return {
            "file": file_path,
            "content": result.stdout,
            "size": len(result.stdout),
            "lines": len(result.stdout.split('\n'))
        }
    
    def _write_file(self, file_path: str, content: str) -> Dict:
        """Write content to a file."""
        if not file_path or '..' in file_path:
            return {"error": "Invalid file path"}
        
        # Use base64 to safely transfer content
        import base64
        encoded = base64.b64encode(content.encode('utf-8')).decode('utf-8')
        
        result = subprocess.run(
            ['docker', 'exec', CONTAINER_NAME, 'bash', '-c',
             f'echo "{encoded}" | base64 -d > {APP_PATH}/{file_path}'],
            capture_output=True,
            text=True,
            timeout=10
        )
        
        if result.returncode != 0:
            return {"error": "Failed to write file"}
        
        return {
            "file": file_path,
            "bytes_written": len(content),
            "status": "success"
        }
    
    def _backup_file(self, file_path: str) -> Dict:
        """Create a backup of a file."""
        if not file_path or '..' in file_path:
            return {"error": "Invalid file path"}
        
        timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
        backup_path = f"{file_path}.backup_{timestamp}"
        
        result = subprocess.run(
            ['docker', 'exec', CONTAINER_NAME, 'bash', '-c',
             f'cp {APP_PATH}/{file_path} {APP_PATH}/{backup_path}'],
            capture_output=True,
            text=True,
            timeout=10
        )
        
        if result.returncode != 0:
            return {"error": "Failed to create backup"}
        
        return {
            "original": file_path,
            "backup": backup_path,
            "timestamp": timestamp
        }
    
    def _handle_ai_fix(self):
        """Use Ollama to fix code based on PHPCS issues."""
        try:
            content_length = int(self.headers.get('Content-Length', 0))
            body = json.loads(self.rfile.read(content_length).decode('utf-8'))
            
            file_path = body.get('file')
            issues = body.get('issues')
            content = body.get('content')
            
            if not all([file_path, issues, content]):
                self._send_error(400, "Missing required fields")
                return
            
            # Build prompt for LLM
            prompt = f"""Fix this PHP code according to PSR-12 standards.

Issues found:
{json.dumps(issues, indent=2)}

Current code:
```php
{content}
```

Provide ONLY the fixed PHP code, no explanations."""
            
            # Call Ollama
            import requests
            response = requests.post(
                f'{OLLAMA_URL}/api/generate',
                json={
                    'model': 'codellama:7b-instruct',
                    'prompt': prompt,
                    'stream': False,
                    'options': {'temperature': 0.2}
                },
                timeout=60
            )
            
            if response.status_code != 200:
                self._send_error(500, "Ollama API error")
                return
            
            fixed_code = response.json().get('response', '')
            
            # Extract code from markdown if present
            if '```php' in fixed_code:
                fixed_code = fixed_code.split('```php')[1].split('```')[0].strip()
            elif '```' in fixed_code:
                fixed_code = fixed_code.split('```')[1].split('```')[0].strip()
            
            self._send_json({
                "file": file_path,
                "original_size": len(content),
                "fixed_size": len(fixed_code),
                "fixed_code": fixed_code,
                "status": "success"
            })
            
        except Exception as e:
            self._send_error(500, str(e))
    
    def do_GET(self):
        """Show API status."""
        if self.path == '/':
            endpoints = {f"POST /{k}": v.get('description', '') for k, v in COMMANDS.items()}
            endpoints.update({
                "POST /read-file": "Read a file (body: {file: 'path'})",
                "POST /write-file": "Write a file (body: {file: 'path', content: '...'})",
                "POST /backup-file": "Backup a file (body: {file: 'path'})",
                "POST /ai-fix-code": "Fix code with AI (body: {file, issues, content})",
            })
            
            status = {
                "service": "OpenRegister AI Code Fixing API",
                "version": "3.0.0",
                "status": "running",
                "ollama_url": OLLAMA_URL,
                "endpoints": endpoints
            }
            self._send_json(status)
        else:
            self._send_error(404, "Not found")
    
    def _send_json(self, data: Dict):
        """Send JSON response."""
        self.send_response(200)
        self.send_header('Content-type', 'application/json')
        self.send_header('Access-Control-Allow-Origin', '*')
        self.end_headers()
        self.wfile.write(json.dumps(data, indent=2).encode())
    
    def _send_error(self, code: int, message: str):
        """Send error response."""
        self.send_response(code)
        self.send_header('Content-type', 'application/json')
        self.end_headers()
        self.wfile.write(json.dumps({"error": message}).encode())
    
    def log_message(self, format, *args):
        """Custom log format."""
        sys.stderr.write(f"[{datetime.now()}] {format % args}\n")

# ============================================================================
# MAIN
# ============================================================================

if __name__ == '__main__':
    server = HTTPServer(('localhost', PORT), AICodeFixingHandler)
    
    print('=' * 70)
    print('OpenRegister AI Code Fixing API v3.0.0')
    print('=' * 70)
    print(f'Port: {PORT}')
    print(f'Container: {CONTAINER_NAME}')
    print(f'Ollama: {OLLAMA_URL}')
    print(f'Model: codellama:7b-instruct')
    print('=' * 70)
    print()
    
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print('\nShutting down...')
        server.shutdown()
