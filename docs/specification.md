# Specification

## Purpose

`block_cortex_chat` is a Moodle block that lets enrolled users ask natural-language
questions about a single course and receive answers grounded **only** in that
course's knowledge base held in Cortex. It is the read/query counterpart to
`local_cortex`, which ingests a course's content into Cortex.

## Scope

In scope for the MVP:

- A course-context block rendering an accessible chat widget.
- A server-side pipeline: (Cortex retrieval + live Moodle schedule) → Moodle AI
  generation.
- A live course agent that reads the asking user's visible `assign` and `quiz`
  activities: their existence, whether they are graded (from the grade item, not
  a learner's grade), and the user's effective dates (including personal
  overrides).
- Fail-closed course scoping (tenant and reference code resolved server-side).
- Source citations returned with each grounded answer.
- Guardrails: per-user rate limit, question length, bounded retrieval context,
  bounded live-activity count.
- Reuse of the Moodle AI subsystem for generation, policy, and auditing.

Explicitly **out of scope** for the MVP:

- A plugin-owned conversation transcript table (conversation is page-local only).
- Cross-course or site-wide search.
- Streaming responses.
- Direct calls to a model provider (all generation goes through `core_ai`).
- Use of Cortex's OpenAI-compatible `/chat/completions` endpoint.
- Live data beyond `assign`/`quiz` existence, gradedness, and schedule. In
  particular: no learner grades, submissions, completion, or other learners'
  data, and no transmission of live personal data to Cortex. (Gradedness here
  means the activity's grade-item configuration, not any learner's grade.)

## Functional requirements

1. The block renders only in a **course context** and only for users with
   `block/cortex_chat:use`.
2. When the course is not usable, the block shows an explicit **unavailable**
   state. A course is usable only when **all** of the following hold:
   - the block is configured (Service URL + tenant id present);
   - the course has an enabled `local_cortex_course` row;
   - the row status is `ready` with a non-empty `referencecode`;
   - Moodle AI `generate_text` is available and enabled in the context.
3. Before generation, the user must have accepted the **Moodle AI policy**. This
   is enforced both client-side (acceptance prompt) and server-side.
4. For each question the server:
   - resolves `tenant_id` (from `local_cortex`) and `reference_code` (from the
     course row) — never from the browser;
   - calls Cortex `POST /api/v1/service/retrieve`;
   - when `livedataenabled` is on, also collects the asking user's visible
     `assign`/`quiz` schedule via the live course agent (in-process, read-only,
     bounded by `maxliveactivities`);
   - **declines** (fixed message, no AI call) only when Cortex grounding is not
     `strong`/`qualified`-with-propositions **and** the live agent returned no
     visible facts; if Cortex was instead unavailable and there is no live data,
     a retryable error is returned;
   - otherwise composes a bounded merged prompt (live schedule + course
     material) and calls Moodle AI `generate_text`;
   - returns the generated answer plus an ordered, de-duplicated list of source
     references.
5. Answers and questions are rendered as **escaped text**, never raw HTML.
6. Staff (`block/cortex_chat:viewstatus`) can see non-sensitive diagnostics
   (course readiness, last grounding state, failure category). Learners never
   see tenant ids, secrets, or raw provider errors.

## Non-functional requirements

- **Security:** course isolation is server-enforced and fails closed. The
  Service URL and identifiers stay server-side.
- **Resilience:** Cortex timeouts / non-200 responses map to a generic,
  retryable error without leaking internals.
- **Bounded cost:** retrieval context is capped by proposition count and total
  characters to avoid provider token overflow.
- **Privacy:** the block stores no personal data in its own database tables. The
  question is transmitted to Cortex for retrieval; the composed prompt is handled
  by the Moodle AI subsystem under its retention controls. The live agent reads
  only the asking user's own effective schedule for activities they can already
  see; it never reads other learners' data and never sends live personal dates
  to Cortex.

## Behavioural contract (AJAX `block_cortex_chat_send_message`)

Input: `contextid` (course context), `message` (plain text).

Output fields:

| Field | Meaning |
| --- | --- |
| `status` | `answered` \| `declined` \| `unavailable` \| `error` |
| `answer` | Generated answer (for `answered`) or fixed decline text (for `declined`) |
| `sources` | Ordered `{index, reference}` list (grounded answers only) |
| `groundingstate` | Cortex grounding state (diagnostic) |
| `errorcode` | Non-sensitive reason category for `unavailable`/`error` |

Error/reason categories include `serviceunavailable`, `aifailed`, `ratelimited`,
`messagetoolong`, `emptymessage`, `policynotaccepted`, and `badcontext`.
