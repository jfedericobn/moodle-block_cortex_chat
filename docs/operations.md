# Operations

## Staff diagnostics

Users with `block/cortex_chat:viewstatus` see:

- The **unavailable detail** on the block when the chat cannot run (e.g.
  "Course corpus status: processing.").
- A **Staff diagnostics** disclosure under the chat with the **last grounding
  state** returned by Cortex.

Learners never see tenant ids, the Service URL, secrets, or raw provider errors.

## Unavailable states and causes

| Message key | Cause | Resolution |
| --- | --- | --- |
| `unavailable_notconfigured` | Service URL and/or tenant id missing | Set the Service URL; configure `local_cortex` tenant id. |
| `unavailable_coursedisabled` | No enabled `local_cortex_course` row | Enable the course for Cortex in `local_cortex`. |
| `unavailable_coursenotready` | Corpus not `ready` / no reference code | Wait for ingestion to complete; check `local_cortex` status. |
| `unavailable_ainotavailable` | No enabled AI provider for `generate_text` in context | Configure/enable an AI provider; enable AI tools in the course. |

## Error categories (AJAX `errorcode`)

| Code | Meaning | Learner sees |
| --- | --- | --- |
| `serviceunavailable` | Cortex timeout or non-200 | "temporarily unavailable" |
| `aifailed` | Moodle AI returned no/failed content | "could not generate an answer" |
| `ratelimited` | Per-user window exceeded | "too many questions recently" |
| `messagetoolong` | Question over the length cap | "question is too long" |
| `emptymessage` | Blank question | "type a question first" |
| `policynotaccepted` | AI policy not accepted server-side | "accept the AI usage policy" |
| `badcontext` | Non-course context | generic unavailable |

## Troubleshooting

- **Block shows "not ready" but ingestion finished.** Confirm the
  `local_cortex_course` row has `status = ready` and a non-empty `referencecode`.
- **Always declines.** Cortex is returning `declined` or no propositions for the
  course corpus. Verify the corpus contains relevant material and that the
  `reference_code`/`tenant_id` match the ingested course.
- **`serviceunavailable`.** Check the Moodle container can reach
  `CORTEX_SERVICE_URL`, and that Moodle cURL security allows the host/port.
- **`aifailed` or `ainotavailable`.** Verify the AI provider is enabled and
  configured, that `generate_text` is enabled for the provider, and that AI tools
  are enabled in the course.
- **Policy prompt never clears.** The `core_ai` policy acceptance is stored per
  user; confirm the user has the `moodle/ai:fetchpolicy` capability.

## Privacy and retention

- The block stores **no personal data** in its own database tables.
- The user's question is transmitted to the external Cortex Service Plane for
  retrieval (declared in `classes/privacy/provider.php`).
- The composed prompt is processed by the **Moodle AI subsystem**, which records
  its normal action/audit data under the AI subsystem's retention controls.
- A short-lived per-user rate-limit counter is kept in the MUC cache only (TTL
  bounded), not in a persistent table.

## Limits and tuning

- Increase `maxpropositions` / `maxcontextchars` for richer answers at higher
  token cost; decrease them if the provider rejects large prompts.
- Tune `ratelimit` / `ratewindow` to balance abuse protection against usability.
- `maxquestionlength` bounds a single question; it is enforced both client-side
  (textarea `maxlength`) and server-side.
