# NexCore AI — Shared Intelligence Foundation

Status: initial Laravel primary-platform foundation committed for review; not deployed.

CI trigger note: both the default workflow and the commerce-base workflow now include pull requests targeting `implementation/**`. This commit updates the AI branch after those trigger fixes so GitHub receives a fresh pull-request synchronization event.

## Purpose and ownership

NexCore AI is the shared AI capability layer for Nexora Group. It serves approved features in Nexora, NexISP, and Loopza without merging their business data, tenant boundaries, permissions, or product identities.

- Nexora Group owns AI governance, provider selection, budgets, security policy, and approved use cases.
- NexCore AI provides a provider-adapter boundary, request controls, and usage metering.
- NexISP and Loopza call only features explicitly exposed to their authenticated tenant context.
- The AI model is an assistant, not an administrator, payment authority, network operator, or campaign publisher.

## Implemented in this foundation

- Environment-configured OpenAI-compatible chat-completions adapter. Provider URL, key, model, timeout, token limit, and feature flag are configuration values; no credentials are committed.
- Authenticated Laravel endpoint: POST /ai/assist.
- Requires the existing auth session and organization.active middleware. Tenant context is taken from the server-verified active organization, never from request JSON.
- Allow-listed feature identifiers: general, nexisp.support, loopza.marketing.
- Input length validation (2–4,000 characters).
- Provider timeout, bounded output tokens, and generic unavailable response.
- Tenant/user/product/feature usage metering, token counts, latency, and request correlation ID.
- Per-organization monthly request limits and output-token ceilings, enforced with a database row lock before calling the provider. Budgets fail closed unless explicitly enabled for that organization.
- Prompts and completions are not written to the usage log.
- System instructions prohibit disclosure of other tenants' data, requesting passwords/payment PINs/one-time codes, and claiming to perform privileged actions.
- Automated service tests cover provider success, usage logging, unavailable configuration, and unsupported feature rejection.
- Endpoint security tests verify unauthenticated requests are rejected and a client-supplied organization ID cannot bypass the server-verified session tenant context.

## Configuration

Set these values in the Laravel environment (never commit real secrets):

- NEXCORE_AI_ENABLED=false until the provider and privacy review are complete.
- NEXCORE_AI_PROVIDER=openai-compatible
- NEXCORE_AI_BASE_URL=https://api.openai.com/v1
- NEXCORE_AI_API_KEY= (secret supplied by deployment secret manager)
- NEXCORE_AI_MODEL=gpt-4o-mini
- NEXCORE_AI_TIMEOUT=30
- NEXCORE_AI_MAX_TOKENS=800

The default is disabled and fails closed. A deployment must set the API key through its secret manager and explicitly enable the feature after review.

## Request example

Authenticated users with an active organization context may send:

    {
      "feature": "nexisp.support",
      "message": "My home connection is slow. What should I check?"
    }

The client must not send organization_id, provider credentials, model overrides, permissions, or a claimed user identity.

## Security and governance boundaries

- AI requests are tenant-scoped by the existing active-organization middleware.
- Provider credentials are server-side only.
- This endpoint does not expose tools/function calling, database search, file retrieval, network device access, payment actions, account changes, or administrative operations.
- AI output is untrusted text. Any future action-taking agent requires a separately authorized tool, narrow permissions, explicit human confirmation, and immutable audit records.
- The system prompt is a safety instruction, not a security boundary. Authorization, data filtering, output handling, and rate limiting must remain enforced by application code.
- Do not send sensitive personal, payment, authentication, or confidential customer data to an external provider without a documented lawful basis, privacy notice, retention review, and provider agreement.
- Per-user quotas, provider cost reconciliation, abuse monitoring, redaction, and retention policies remain launch requirements.

## Product roadmap

1. Foundation (this change): secure tenant-aware text completion, provider adapter configuration, usage records.
2. NexISP agent: troubleshooting playbooks and read-only network telemetry integration; no autonomous router changes.
3. Loopza agent: campaign drafting and analytics interpretation; user approval required before publishing or spending.
4. Nexora internal agents: finance/operations/technology/security assistance with strict departmental scopes.
5. Agent orchestration: multi-agent conferences with explicit task boundaries, source attribution, budgets, and human sign-off.
6. Retrieval and knowledge: approved document ingestion with tenant ACL enforcement, source citations, deletion/retention controls.
7. Provider resilience: provider interface and health checks, secondary provider configuration, circuit breaker and fallback policy.
8. Standby parity: implement the same contract in the NestJS/PostgreSQL architecture before calling it a working standby.

## Not yet implemented

No chat UI, streaming, persistent conversation history, vector database/RAG, external tool calling, autonomous agents, multi-agent conferences, voice/image input, provider cost reconciliation, per-user quotas, or NestJS standby implementation is included in this foundation. CI and security review are required; this is not production deployment.
