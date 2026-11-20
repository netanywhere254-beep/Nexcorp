# Separate Nexora and Loopza case registration numbers

The group and product case registries use independent database-backed counters.

| System | Prefix | Example |
|---|---|---|
| Nexora Group general cases | NEX | NEX-2026-000001 |
| Loopza Trace & Find cases | LTF | LTF-2026-000001 |

Each system has its own yearly sequence. Allocation runs in a database transaction and locks the system/year row. A unique system/year index prevents duplicate counter rows, and the case table's unique case_reference constraint remains the final collision safeguard.

New Loopza cases use sequential LTF references; existing references are unchanged. The Nexora general-case workflow must call \`app(App\Services\CaseReferenceNumber::class)->next('nexora')\` when that case record is persisted. This change provides the independent counter without inventing a Nexora case model or endpoint that is not yet present.

MySQL/InnoDB is the intended concurrent production database. Do not allocate references in the browser or from an in-memory counter.
