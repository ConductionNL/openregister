#!/usr/bin/env python3
"""
OpenRegister Container Command API Server.

A generic API server for executing commands in the Nextcloud container.
Easily extensible for adding new commands.

Usage: python3 container-api.py
"""
import json
import subprocess
import sys
import re
from datetime import datetime
from http.server import BaseHTTPRequestHandler, HTTPServer
from typing import Dict, Any, Optional, Callable

# Import AI code fixing module.
from ai_code_fixing import (
    process_phpcs_result,
    AI_COMMANDS,
    read_file_handler,
    write_file_handler,
    backup_file_handler
)

PORT = 9090
CONTAINER_NAME = 'master-nextcloud-1'
APP_PATH = '/var/www/html/apps-extra/openregister'


# ============================================================================
# COMMAND DEFINITIONS
# ============================================================================

class CommandConfig:
    """Configuration for a command that can be executed."""
    
    def __init__(
        self,
        name: str,
        command: str,
        timeout: int = 120,
        description: str = "",
        post_processor: Optional[Callable[[subprocess.CompletedProcess], Dict[str, Any]]] = None
    ):
        """
        Initialize a command configuration.
        
        :param name: Command name (used in URL path).
        :param command: The actual command to run in the container.
        :param timeout: Timeout in seconds.
        :param description: Human-readable description.
        :param post_processor: Optional function to process the result.
        """
        self.name = name
        self.command = command
        self.timeout = timeout
        self.description = description
        self.post_processor = post_processor


# ============================================================================
# POST PROCESSORS
# ============================================================================

def process_phpqa_result(result: subprocess.CompletedProcess) -> Dict[str, Any]:
    """
    Post-process PHPQA results to extract the JSON report.
    
    :param result: The completed subprocess result.
    :return: Additional data to include in the response.
    """
    try:
        # Try to get the JSON report.
        json_result = subprocess.run(
            [
                'docker', 'exec', CONTAINER_NAME, 'bash', '-c',
                f'cat {APP_PATH}/phpqa/phpqa.json 2>/dev/null || echo "{{}}"'
            ],
            capture_output=True,
            text=True,
            timeout=10
        )
        
        phpqa_json = json.loads(json_result.stdout)
        
        return {
            "phpqa_report": phpqa_json,
            "report_files": {
                "json": "phpqa/phpqa.json",
                "html": "phpqa/phpqa-offline.html",
                "metrics": "phpqa/phpmetrics/"
            }
        }
    except (json.JSONDecodeError, subprocess.TimeoutExpired, Exception) as e:
        return {
            "phpqa_report": {"error": f"Failed to parse phpqa.json: {str(e)}"}
        }


def process_cs_fix_result(result: subprocess.CompletedProcess) -> Dict[str, Any]:
    """
    Post-process CS Fix results to count fixed files.
    
    :param result: The completed subprocess result.
    :return: Additional data to include in the response.
    """
    output_lines = result.stdout.split('\n')
    files_fixed = 0
    
    for line in output_lines:
        if 'Fixed' in line or 'fixed' in line:
            match = re.search(r'(\d+)\s+file', line)
            if match:
                files_fixed = int(match.group(1))
    
    return {
        "files_fixed": files_fixed,
        "message": f"Fixed {files_fixed} file(s)" if files_fixed > 0 else "No files needed fixing"
    }


def process_test_result(result: subprocess.CompletedProcess) -> Dict[str, Any]:
    """
    Post-process PHPUnit test results.
    
    :param result: The completed subprocess result.
    :return: Additional data to include in the response.
    """
    output = result.stdout
    
    # Try to extract test statistics.
    tests_match = re.search(r'Tests:\s+(\d+)', output)
    assertions_match = re.search(r'Assertions:\s+(\d+)', output)
    failures_match = re.search(r'Failures:\s+(\d+)', output)
    
    return {
        "tests_run": int(tests_match.group(1)) if tests_match else 0,
        "assertions": int(assertions_match.group(1)) if assertions_match else 0,
        "failures": int(failures_match.group(1)) if failures_match else 0,
        "success": result.returncode == 0
    }


# ============================================================================
# COMMAND REGISTRY
# ============================================================================

COMMANDS: Dict[str, CommandConfig] = {
    # Code Quality Commands.
    'phpqa': CommandConfig(
        name='phpqa',
        command='composer phpqa',
        timeout=300,
        description='Run full PHPQA analysis suite (PHPCS, PHPMD, PHPStan, Psalm, PHPMetrics)',
        post_processor=process_phpqa_result
    ),
    
    'cs-fix': CommandConfig(
        name='cs-fix',
        command='composer cs:fix',
        timeout=120,
        description='Auto-fix code style issues with PHP CS Fixer',
        post_processor=process_cs_fix_result
    ),
    
    'cs-check': CommandConfig(
        name='cs-check',
        command='composer cs:check',
        timeout=60,
        description='Check code style without fixing (dry-run)',
    ),
    
    # Static Analysis Commands.
    'phpstan': CommandConfig(
        name='phpstan',
        command='composer phpstan',
        timeout=120,
        description='Run PHPStan static analysis'
    ),
    
    'psalm': CommandConfig(
        name='psalm',
        command='composer psalm',
        timeout=120,
        description='Run Psalm static analysis'
    ),
    
    # Testing Commands.
    'test-unit': CommandConfig(
        name='test-unit',
        command='composer test:unit',
        timeout=180,
        description='Run PHPUnit unit tests',
        post_processor=process_test_result
    ),
    
    'test-integration': CommandConfig(
        name='test-integration',
        command='composer test:integration',
        timeout=300,
        description='Run integration tests',
        post_processor=process_test_result
    ),
    
    # Dependency Commands.
    'composer-install': CommandConfig(
        name='composer-install',
        command='composer install',
        timeout=180,
        description='Install composer dependencies'
    ),
    
    'composer-update': CommandConfig(
        name='composer-update',
        command='composer update',
        timeout=300,
        description='Update composer dependencies'
    ),
    
    'npm-install': CommandConfig(
        name='npm-install',
        command='npm install',
        timeout=180,
        description='Install npm dependencies'
    ),
    
    # Build Commands.
    'build-js': CommandConfig(
        name='build-js',
        command='npm run build',
        timeout=120,
        description='Build JavaScript/Vue assets'
    ),
    
    'watch-js': CommandConfig(
        name='watch-js',
        command='npm run watch',
        timeout=3600,  # 1 hour for watch mode.
        description='Watch and rebuild JavaScript on changes'
    ),
}

# Merge AI-powered commands.
for cmd_name, cmd_data in AI_COMMANDS.items():
    if 'handler' not in cmd_data:
        COMMANDS[cmd_name] = CommandConfig(
            name=cmd_data['name'],
            command=cmd_data['command'],
            timeout=cmd_data['timeout'],
            description=cmd_data['description'],
            post_processor=cmd_data.get('post_processor')
        )



# ============================================================================
# HTTP REQUEST HANDLER
# ============================================================================

class ContainerAPIHandler(BaseHTTPRequestHandler):
    """Handle HTTP requests for container command execution."""
    
    def do_POST(self):
        """Handle POST requests to execute commands."""
        # Remove leading slash from path.
        command_name = self.path.lstrip('/')
        
        # Handle special file operation commands.
        if command_name in ['read-file', 'write-file', 'backup-file']:
            self._handle_file_operation(command_name)
            return
        
        if command_name in COMMANDS:
            self._execute_command(COMMANDS[command_name])
        else:
            self.send_response(404)
            self.send_header('Content-type', 'application/json')
            self.end_headers()
            error = {
                "error": f"Unknown command: {command_name}",
                "available_commands": list(COMMANDS.keys()) + ['read-file', 'write-file', 'backup-file']
            }
            self.wfile.write(json.dumps(error, indent=2).encode())
    
    def _handle_file_operation(self, operation: str):
        """
        Handle file operations that require request body.
        
        :param operation: The operation name (read-file, write-file, backup-file).
        """
        try:
            # Read request body.
            content_length = int(self.headers.get('Content-Length', 0))
            if content_length == 0:
                self.send_response(400)
                self.send_header('Content-type', 'application/json')
                self.end_headers()
                error = {"error": "Request body required"}
                self.wfile.write(json.dumps(error).encode())
                return
            
            body = self.rfile.read(content_length)
            request_data = json.loads(body.decode('utf-8'))
            
            # Call the appropriate handler.
            if operation == 'read-file':
                result = read_file_handler(request_data)
            elif operation == 'write-file':
                result = write_file_handler(request_data)
            elif operation == 'backup-file':
                result = backup_file_handler(request_data)
            else:
                result = {"error": "Unknown operation"}
            
            # Send response.
            response_data = {
                "timestamp": datetime.utcnow().isoformat() + "Z",
                "operation": operation,
                "container": CONTAINER_NAME,
                **result
            }
            
            status_code = 200 if "error" not in result else 400
            self.send_response(status_code)
            self.send_header('Content-type', 'application/json')
            self.send_header('Access-Control-Allow-Origin', '*')
            self.end_headers()
            self.wfile.write(json.dumps(response_data, indent=2).encode())
            
        except json.JSONDecodeError:
            self.send_response(400)
            self.send_header('Content-type', 'application/json')
            self.end_headers()
            error = {"error": "Invalid JSON in request body"}
            self.wfile.write(json.dumps(error).encode())
        except Exception as e:
            self.send_response(500)
            self.send_header('Content-type', 'application/json')
            self.end_headers()
            error = {"error": str(e)}
            self.wfile.write(json.dumps(error).encode())
    
    def _execute_command(self, cmd_config: CommandConfig):
        """
        Execute a command in the container.
        
        :param cmd_config: The command configuration to execute.
        """
        print(f"[{datetime.now()}] Running {cmd_config.name}: {cmd_config.command}", file=sys.stderr)
        
        try:
            # Build the full docker exec command.
            docker_cmd = [
                'docker', 'exec', CONTAINER_NAME, 'bash', '-c',
                f'cd {APP_PATH} && {cmd_config.command} 2>&1'
            ]
            
            # Execute the command.
            result = subprocess.run(
                docker_cmd,
                capture_output=True,
                text=True,
                timeout=cmd_config.timeout
            )
            
            # Build base response.
            response_data = {
                "timestamp": datetime.utcnow().isoformat() + "Z",
                "command": cmd_config.name,
                "full_command": cmd_config.command,
                "status": "success" if result.returncode == 0 else "completed_with_errors",
                "exit_code": result.returncode,
                "output": result.stdout,
                "container": CONTAINER_NAME
            }
            
            # Apply post-processor if available.
            if cmd_config.post_processor:
                try:
                    extra_data = cmd_config.post_processor(result)
                    response_data.update(extra_data)
                except Exception as e:
                    response_data["post_processor_error"] = str(e)
            
            # Send response.
            self.send_response(200)
            self.send_header('Content-type', 'application/json')
            self.send_header('Access-Control-Allow-Origin', '*')
            self.end_headers()
            self.wfile.write(json.dumps(response_data, indent=2).encode())
            
            print(
                f"[{datetime.now()}] {cmd_config.name} completed with exit code {result.returncode}",
                file=sys.stderr
            )
            
        except subprocess.TimeoutExpired:
            self.send_response(408)
            self.send_header('Content-type', 'application/json')
            self.end_headers()
            error_response = {
                "error": f"Request timeout after {cmd_config.timeout} seconds",
                "command": cmd_config.name
            }
            self.wfile.write(json.dumps(error_response, indent=2).encode())
            print(f"[{datetime.now()}] {cmd_config.name} timed out", file=sys.stderr)
            
        except Exception as e:
            self.send_response(500)
            self.send_header('Content-type', 'application/json')
            self.end_headers()
            error_response = {
                "error": str(e),
                "command": cmd_config.name
            }
            self.wfile.write(json.dumps(error_response, indent=2).encode())
            print(f"[{datetime.now()}] {cmd_config.name} failed: {e}", file=sys.stderr)
    
    def do_GET(self):
        """Handle GET requests - show status and available commands."""
        if self.path == '/':
            self.send_response(200)
            self.send_header('Content-type', 'application/json')
            self.end_headers()
            
            # Build endpoint list.
            endpoints = {}
            for cmd_name, cmd_config in COMMANDS.items():
                endpoints[f"POST /{cmd_name}"] = cmd_config.description
            
            status = {
                "service": "OpenRegister Container API",
                "version": "2.0.0",
                "status": "running",
                "container": CONTAINER_NAME,
                "app_path": APP_PATH,
                "endpoints": endpoints,
                "usage": f"POST /<command_name> to execute a command"
            }
            self.wfile.write(json.dumps(status, indent=2).encode())
        else:
            self.send_response(404)
            self.end_headers()
    
    def log_message(self, format, *args):
        """Custom log format."""
        sys.stderr.write(f"[{datetime.now()}] {format % args}\n")


# ============================================================================
# MAIN
# ============================================================================

if __name__ == '__main__':
    server = HTTPServer(('localhost', PORT), ContainerAPIHandler)
    
    print('=' * 70)
    print(f'OpenRegister Container API Server v2.0.0')
    print('=' * 70)
    print(f'Port: {PORT}')
    print(f'Container: {CONTAINER_NAME}')
    print(f'App Path: {APP_PATH}')
    print()
    print('Available Commands:')
    for cmd_name, cmd_config in COMMANDS.items():
        print(f'  - POST /{cmd_name:20s} {cmd_config.description}')
    print()
    print(f'Test with: curl -X POST http://localhost:{PORT}/phpqa')
    print(f'Status:    curl http://localhost:{PORT}/')
    print('=' * 70)
    print()
    
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print('\nShutting down server...')
        server.shutdown()

