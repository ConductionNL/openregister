# OpenRegister Direct N8N Execution - Implementation Guide

## The Challenge

You want n8n to directly execute `composer phpqa` and `composer cs:fix` in the Nextcloud container without using an external API server.

## The Problem

n8n doesn't have a built-in "Execute Command" node by default. The node type `n8n-nodes-base.executeCommand` doesn't exist in standard n8n installations.

## Available Solutions

### Solution 1: SSH Node (RECOMMENDED for direct execution)

n8n can use the SSH node to connect to the Docker host and run docker exec commands.

**Setup:**
1. Enable SSH on the host (or use existing SSH)
2. Create SSH credentials in n8n
3. Use SSH node to run: `docker exec master-nextcloud-1 bash -c 'cd /var/www/html/apps-extra/openregister && composer phpqa'`

**Pros:**
- ✅ True direct execution from n8n
- ✅ No external API server needed
- ✅ Native n8n node
- ✅ Secure (SSH authentication)

**Cons:**
- ❌ Requires SSH access configuration
- ❌ Need to manage SSH credentials

### Solution 2: Install Execute Command Community Node

Install the community "Execute Command" node in n8n.

**Setup:**
```bash
docker exec openregister-n8n npm install -g n8n-nodes-execute-command
# Restart n8n container
```

**Pros:**
- ✅ Direct command execution
- ✅ Simpler than SSH

**Cons:**
- ❌ Requires installing community node
- ❌ May have security implications
- ❌ Not part of standard n8n

### Solution 3: Current API Server Approach

The Python API server we created (what's currently working).

**Pros:**
- ✅ Already working
- ✅ Clean separation of concerns
- ✅ Easy to extend
- ✅ Can add auth, logging, rate limiting
- ✅ Works immediately

**Cons:**
- ❌ Extra process to manage
- ❌ Not "pure" n8n

## Recommended Next Steps

### Option A: Use SSH Node (Pure n8n solution)

I can create workflows using the SSH node if you:
1. Provide SSH access details (host, user, key/password)
2. Or set up SSH access to host

### Option B: Keep Current API Server (Pragmatic)

The current solution works well and follows good practices:
- Separation of concerns
- Easy to maintain and extend
- Already tested and working
- Can be dockerized if needed

### Option C: Hybrid Approach

Keep API server but simplify it to a tiny shim that just proxies to docker:

```python
# Ultra-minimal version
from http.server import BaseHTTPRequestHandler, HTTPServer
import subprocess, json

class Handler(BaseHTTPRequestHandler):
    def do_POST(self):
        cmd = self.path[1:]  # phpqa or cs-fix
        result = subprocess.run([
            'docker', 'exec', 'master-nextcloud-1', 'bash', '-c',
            f'cd /var/www/html/apps-extra/openregister && composer {cmd}'
        ], capture_output=True, text=True)
        
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.end_headers()
        self.wfile.write(json.dumps({
            'output': result.stdout,
            'error': result.stderr,
            'code': result.returncode
        }).encode())

HTTPServer(('localhost', 9090), Handler).serve_forever()
```

## What Would You Like?

1. **Create SSH-based workflows** - I'll need SSH credentials
2. **Install Execute Command node** - I'll help set it up
3. **Keep current API solution** - It's working well
4. **Simplify API to minimal shim** - Ultra-lightweight version

Let me know which direction you prefer!



