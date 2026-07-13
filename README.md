# block_cortex_chat

A Moodle **block** plugin that adds a course-scoped chat. Students ask questions
and receive answers grounded **only** in that course's Cortex knowledge base.

The block combines two systems:

1. **Cortex Service Plane** performs retrieval (`POST /api/v1/service/retrieve`)
   over the course's corpus and returns a *grounding state* plus supporting
   *propositions* with source references.
2. **Moodle AI subsystem** (`core_ai`) formats the final answer from the
   retrieved propositions using the site's configured AI provider.

Retrieval and generation are kept as **distinct, observable stages**. The block
does **not** use Cortex's OpenAI-compatible `POST /api/v1/service/chat/completions`
endpoint, because that endpoint both retrieves and generates inside Cortex.

## Pipeline

```
Student question
      │
      ▼
Resolve ready course reference_code (server-side, from local_cortex_course)
      │
      ▼
Cortex POST /api/v1/service/retrieve  ──►  grounding_state + propositions
      │
      ├─ declined / no propositions ─► fixed "course material only" message
      │
      ▼ strong | qualified (≥1 proposition)
Bounded prompt (numbered propositions + sources + strict instructions)
      │
      ▼
Moodle AI generate_text (core_ai\manager::process_action)
      │
      ▼
Answer + cited source references
```

## Key properties

- **Course-scoped and fail-closed.** The server derives `tenant_id` and
  `reference_code` from the course's *ready* `local_cortex_course` row. The
  browser never supplies either value, so it cannot query another course's
  corpus. Declined or empty retrieval returns a fixed decline message and never
  reaches the AI provider.
- **Uses Moodle AI properly.** Generation goes through `core_ai`, inheriting its
  provider failover, rate limits, audit records, hashed provider user identity,
  and per-user policy acceptance. The block never calls a model provider
  directly.
- **Safe output.** Model output is rendered as escaped text (never raw HTML).
- **Guardrails.** Per-user rate limiting, maximum question length, and bounded
  retrieval context (proposition count and total characters).

## Installation

This plugin lives at the durable path `plugins/block_cortex_chat/` and is
mirrored into the runtime Moodle tree at `public/blocks/cortex_chat/` by the
deployment wrapper (`entrypoint.sh` `block_*` mapping) on container start.

Requires the [`local_cortex`](../local_cortex/README.md) plugin, which owns the
Cortex tenant configuration and the per-course corpus state.

## Configuration

Site administration → Plugins → Blocks → **Cortex course chat**:

| Setting | Purpose |
| --- | --- |
| Cortex Service Plane URL | Base URL for `POST /api/v1/service/retrieve`. Separate from the ingest URL. |
| Maximum question length | Character cap on a single question. |
| Maximum retrieved propositions | Cap on snippets passed to the AI. |
| Maximum context characters | Total character budget for retrieved context. |
| Per-user request limit / window | Sliding-window rate limit. |
| Decline message | Fixed message shown when no course material matches. |

The Service URL can also be seeded on deploy via the `CORTEX_SERVICE_URL`
environment variable (see `.env.template`). The tenant id is reused from
`local_cortex`.

## Capabilities

| Capability | Default roles | Purpose |
| --- | --- | --- |
| `block/cortex_chat:use` | student, teacher, editingteacher, manager | Ask questions |
| `block/cortex_chat:viewstatus` | teacher, editingteacher, manager | See staff diagnostics |
| `block/cortex_chat:manage` | editingteacher, manager | Configure the block |
| `block/cortex_chat:addinstance` | editingteacher, manager | Add the block |

## Tests

PHPUnit tests live under `tests/`:

- `rate_limiter_test.php` — sliding-window limiter behaviour.
- `chat_service_test.php` — fail-closed gate, prompt bounds, availability states.
- `external/send_message_test.php` — validation, capability, rate limiting, and
  fail-closed behaviour of the AJAX entry point.

Run with `vendor/bin/phpunit --filter block_cortex_chat` from the Moodle root
after initialising the PHPUnit environment.

## Documentation

See [`docs/`](docs/README.md) for the full specification, architecture,
configuration/deployment, and operations guides.

## Security note

The Cortex Service Plane currently does **not** enforce consumer authentication.
Keep the Service URL reachable only from the Moodle application container(s) and
never expose `reference_code` or tenant identifiers to the browser. Add Service
Plane authentication as a Cortex follow-up before treating the deployment as
Internet-facing production.
