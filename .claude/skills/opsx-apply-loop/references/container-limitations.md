# Container Limitations

Reference for `opsx-apply-loop`. The loop container has no git and no GitHub access by design.

---

## What the container cannot do

The following things the sub-skills normally do **will not work inside the container** — they are handled by the host steps instead:

| Capability | Why it fails in container | Handled by |
|-----------|--------------------------|------------|
| GitHub issue checkbox updates (opsx-apply, opsx-verify) | No gh CLI / GITHUB_TOKEN | Step 13a (host) |
| GitHub issue comments | No gh CLI / GITHUB_TOKEN | Step 13b (host) |
| GitHub issue closing (opsx-archive) | No gh CLI / GITHUB_TOKEN | Step 13c (host) |
| Quality checks via `docker compose exec` (opsx-apply) | No Docker socket in container | PHP + Composer + npm run directly in container |
| curl API tests against Nextcloud (opsx-verify) | No network path to Nextcloud containers | Pre-answered "Skip testing" |
| Browser tests (opsx-verify, test-functional, etc.) | No browser in container | Test loop runs on host (Step 9) |
| git commits | No git in container | Step 12 (host) |
| opsx-archive | Moved to host — runs after test loop | Step 11 (host) |

## Container volumes

- `/workspace` → `{APP}/` (read-write: app code + openspec changes)
- `/workspace/.claude` → `.claude/` (read-only: shared skill files for the container's Claude session)

## Restricting the container network to Claude API only (optional but recommended)

The `apply-loop-net` bridge network allows general outbound by default. To lock it down to `api.anthropic.com` only, add these iptables rules on the host after creating the network:

```bash
SUBNET=$(docker network inspect apply-loop-net --format '{{range .IPAM.Config}}{{.Subnet}}{{end}}')
iptables -I DOCKER-USER -s $SUBNET -p udp --dport 53 -j ACCEPT
iptables -I DOCKER-USER -s $SUBNET -p tcp --dport 53 -j ACCEPT
iptables -I DOCKER-USER -s $SUBNET -d api.anthropic.com -j ACCEPT
iptables -I DOCKER-USER -s $SUBNET -j DROP
```

Run `iptables -D DOCKER-USER ...` with the same rules to remove them when done.
