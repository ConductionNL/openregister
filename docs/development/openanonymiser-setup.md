# OpenAnonymiser setup (local development)

OpenRegister's `openanonymiser` entity-recognition method (and, through it,
DocuDesk's anonymisation) talks to **OpenAnonymiser** — a Dutch-focused PII
detection / anonymisation service. OpenAnonymiser runs as a Nextcloud **AppAPI
ExApp** (a Docker container managed by Nextcloud), not as an in-process library.

Two flavors are published as separate ExApps:

| App id                 | Flavor    | NLP engine        | Host requirement            |
| ---------------------- | --------- | ----------------- | --------------------------- |
| `openanonymiser_light` | CPU       | spaCy             | any server, ~2 GB image     |
| `openanonymiser`       | GPU       | spaCy + GLiNER    | NVIDIA CUDA GPU, ~6 GB image|

For local development on a machine without a GPU, use **`openanonymiser_light`**.

> The code that calls OpenAnonymiser already ships in OpenRegister
> (`EntityRecognitionHandler::detectWithOpenAnonymiser()`) and DocuDesk. This
> document only covers standing up and wiring the ExApp so that code has
> something to talk to.

## Prerequisites

- The **AppAPI** app installed and enabled (`occ app:list | grep app_api`).
- A **deploy daemon** registered in AppAPI. AppAPI cannot deploy an ExApp
  without one — this is a one-time setup, see below.

## 1. Start the Docker Socket Proxy (deploy-daemon backend)

AppAPI deploys containers through a Docker Socket Proxy (DSP). In
`nextcloud-docker-dev` the DSP service is already defined in
`docker-compose.yml`; start it from the repo root:

```bash
docker compose up -d appapi-dsp
```

(See `nextcloud-docker-dev/docs/services/app_api.md` for the HTTPS variant.)

## 2. Register the deploy daemon (one-time)

```bash
docker exec -u www-data master-nextcloud-1 php occ app_api:daemon:register \
  dsp_http "DSP HTTP" docker-install http \
  "nextcloud-appapi-dsp-http" "http://nextcloud.local" \
  --net=master_default --set-default
```

Verify:

```bash
docker exec -u www-data master-nextcloud-1 php occ app_api:daemon:list
```

The daemon should be listed with `*` in the `Def` (default) column.

> **Note (NC 35+):** direct Docker-socket-proxy daemons are deprecated in favour
> of HaRP (`--harp`). DSP still works today; expect to migrate later.

## 3. Deploy the ExApp

With a default daemon in place, deploying pulls the image named in the app's
`info.xml`, creates the container, and runs its `/init`. Either:

- **App Store:** Apps → **External apps** → OpenAnonymiser (Light) → *Download
  and enable*, **or**
- **CLI** (deploys the exact image from a local checkout's manifest):

```bash
docker exec -u www-data master-nextcloud-1 php occ app_api:app:register \
  openanonymiser_light dsp_http \
  --info-xml /var/www/html/apps-extra/openanonymiser_light/appinfo/info.xml \
  --wait-finish
```

Confirm it is deployed and enabled:

```bash
docker exec -u www-data master-nextcloud-1 php occ app_api:app:list
```

(For the GPU flavor, substitute `openanonymiser`.)

## 4. Point OpenRegister at the ExApp

AppAPI exposes the ExApp's routes under its proxy path
`/index.php/apps/app_api/proxy/<app-id>`. In OpenRegister's **File settings**:

- **Detection method:** `openanonymiser`
- **OpenAnonymiser API endpoint:**
  `http://nextcloud.local/index.php/apps/app_api/proxy/openanonymiser_light`

OpenRegister appends `/api/v1/analyze` to that base
(`EntityRecognitionHandler`), so do **not** include a trailing path or slash.
Use the **Test OpenAnonymiser API connection** button to verify.

## Verification

- `occ app_api:app:list` shows the ExApp `enabled`.
- The ExApp container is healthy (`docker ps | grep openanonymiser`; its
  `/heartbeat` returns 200).
- The "Test OpenAnonymiser API connection" button in File settings succeeds.
- If the endpoint is unset or unreachable, OpenRegister logs a warning and
  falls back to the regex detector — anonymisation still runs, but with lower
  recall.

## Troubleshooting

- **Deploy fails / never becomes enabled:** the `info.xml` image must be the
  **ExApp wrapper** image (it carries `/heartbeat`, `/init`, `/enabled`), not
  the upstream service base image. See each ExApp repo's `PUBLISHING.md`.
- **`no daemon` on deploy:** step 2 was skipped or the daemon is not default.
- **Slow / stalling GPU flavor on a CPU host:** expected — use the Light flavor.

## Running the deploy daemon on Kubernetes

**Important:** AppAPI supports **Docker container deployment only**. It does not
schedule ExApps as Kubernetes pods — there is no Kubernetes deploy daemon. A
deploy daemon (DSP, or the newer HaRP) always fronts a **Docker Engine**, and
AppAPI creates ExApp **containers** on that engine. So even when Nextcloud
itself runs in Kubernetes, OpenAnonymiser will run as a Docker container on some
Docker host, not as a native pod.

Why DSP doesn't drop cleanly onto Kubernetes: DSP proxies a **Docker socket**,
but Kubernetes nodes run containerd/CRI, not `dockerd` — there is usually no
`docker.sock` to proxy, and mounting a node's container socket to let AppAPI
spawn sibling containers bypasses the scheduler (an anti-pattern).

Two workable patterns:

### A. External Docker host (recommended)

Run a dedicated Docker Engine (a VM or bare node running `dockerd`) as the
deploy target. Front it with the DSP/HaRP container and register it as a
**remote** daemon over HTTPS:

- Deploy the proxy (`ghcr.io/nextcloud/nextcloud-appapi-dsp`) on that Docker host
  with TLS certificates and a strong `NC_HAPROXY_PASSWORD`.
- From Nextcloud (in-cluster), register the daemon pointing at
  `https://<docker-host>:2375`, `--net=host`, HaProxy password set. See the
  HTTPS section of `nextcloud-docker-dev/docs/services/app_api.md` and the
  [Docker Socket Proxy docs](https://github.com/nextcloud/docker-socket-proxy).
- ExApps run as Docker containers on that host; Kubernetes never sees them.
- **Never** expose a plaintext (`http`) socket proxy off-host — it is
  unauthenticated root-equivalent access to the Docker daemon.

### B. Docker-in-Docker sidecar (dev / PoC only)

Run a privileged `dind` container plus the DSP as a sidecar in the same Pod,
sharing the socket via an `emptyDir`; register the daemon against the sidecar.
Caveats: requires a privileged container, ExApp containers are invisible to
Kubernetes (no Service, probes, or scheduling), and everything is ephemeral
across Pod restarts unless volumes are mounted carefully. Not for production.

> Direction of travel: HaRP replaces DSP (DSP removal targeted for NC 35) and
> needs no host Docker socket, but it is still Docker-oriented — it does not add
> native Kubernetes pod scheduling. Track the
> [AppAPI deploy configuration docs](https://nextcloud.github.io/app_api/DeployConfigurations.html)
> for changes.
