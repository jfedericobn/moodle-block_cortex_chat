# Configuration and deployment

## Dependencies

- **`local_cortex`** must be installed and configured (ingest URL, tenant id,
  signing secret). This block reuses its **tenant id** and its per-course corpus
  state (`local_cortex_course`).
- A **Moodle AI provider** supporting `generate_text` must be installed, enabled,
  and configured (e.g. `aiprovider_openai`), and AI tools must be enabled in the
  course context.
- A reachable **Cortex Service Plane** exposing `POST /api/v1/service/retrieve`.

## Site settings

Site administration → Plugins → Blocks → **Cortex course chat**
(`/admin/settings.php?section=blocksettingcortex_chat`):

| Setting | Config name | Default | Notes |
| --- | --- | --- | --- |
| Cortex Service Plane URL | `serviceurl` | – | Base URL, no trailing `/api/v1`. Separate from the ingest URL. |
| Maximum question length | `maxquestionlength` | `1000` | Characters. |
| Maximum retrieved propositions | `maxpropositions` | `8` | Snippets passed to the AI. |
| Maximum context characters | `maxcontextchars` | `6000` | Total context budget. |
| Per-user request limit | `ratelimit` | `20` | Requests per window. |
| Rate-limit window (seconds) | `ratewindow` | `3600` | Rolling window. |
| Decline message | `declinemessage` | localized default | Shown when no material matches. |

The tenant id is **not** a block setting — it is read from `local_cortex`.

## Environment-based seeding

The deployment wrapper seeds the Service URL from an environment variable inside
`ensure_cortex_settings()` in `entrypoint.sh`:

```bash
# .env
CORTEX_SERVICE_URL=https://cortex.internal:8001
```

`compose.yml` passes `CORTEX_SERVICE_URL` to the Moodle service, and the
entrypoint runs `set_config('serviceurl', ..., 'block_cortex_chat')` on startup.
Seeding runs only when the core Cortex variables (`CORTEX_INGEST_URL`,
`CORTEX_TENANT_ID`, `CORTEX_CALLBACK_SECRET`) are also present.

## Deployment path

- Durable source: `plugins/block_cortex_chat/`.
- Runtime mirror: `public/blocks/cortex_chat/` inside the Moodle tree.

`entrypoint.sh` maps `block_*` plugins to `public/blocks/<name>` and re-syncs on
every container start, so restarting the Moodle container installs/updates the
block. After deployment, run the Moodle upgrade (the entrypoint does this
automatically) to register the block, its capabilities, and web service.

## Networking and security

- The Moodle application container must reach `CORTEX_SERVICE_URL`.
- The Cortex Service Plane **currently does not enforce consumer authentication.**
  Restrict network access so only Moodle application containers can reach the
  Service endpoint; never expose it to browsers or the public Internet.
- Cortex often runs on a private host/port. Moodle cURL security may block private
  hosts/ports. In DEVELOPMENT mode the entrypoint clears `curlsecurityblockedhosts`;
  in production, allow the host/port under
  Site administration → Security → HTTP security.
- Add Service Plane token/JWT authentication as a Cortex follow-up before treating
  the deployment as Internet-facing production.

## Adding the block to a course

1. Enable the course for Cortex via `local_cortex` and wait until the course
   corpus status is `ready`.
2. Turn editing on in the course and add the **Cortex course chat** block.
3. Optionally set a custom block title in the block configuration.
