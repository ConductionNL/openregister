# /tmp/resolve_threads.py — populate thread_ids from {THREAD_NODE_MAP}
import subprocess, json

thread_ids = ["PRRT_...", ...]  # node IDs for every thread in {RESOLVED}

for tid in thread_ids:
    mutation = f'mutation {{ resolveReviewThread(input: {{threadId: "{tid}"}}) {{ thread {{ id isResolved }} }} }}'
    result = subprocess.run(
        ["gh", "api", "graphql", "-f", f"query={mutation}"],
        capture_output=True, text=True
    )
    data = json.loads(result.stdout)
    resolved = data["data"]["resolveReviewThread"]["thread"]["isResolved"]
    print(f"{tid}: resolved={resolved}")
