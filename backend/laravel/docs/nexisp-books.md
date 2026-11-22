# NexISP Books — native accounting system

NexISP Books is the primary accounting and bookkeeping system for every ISP tenant. Zoho Books, QuickBooks Online, Xero and Sage are optional external integrations. They are not prerequisites for billing, invoicing, bookkeeping or financial reporting, and connecting or disconnecting one must never remove or replace the ISP's NexISP source records.

## Operating modes

Each organization has its own \`nexisp_accounting_settings\` record:
- \`native\`: use NexISP Books as the accounting workspace.
- \`hybrid\`: keep the NexISP ledger authoritative while synchronizing selected records to connected external systems.
- \`external_mirror\`: an ISP may prefer an external accounting interface, but NexISP still retains its native ledger, document history, tenant audit trail and source records.

The native ledger is deliberately not user-disableable. Optional features can be switched on or off per organization: expense tracking, tax, bank reconciliation, budgets, recurring invoices and external synchronization. A feature toggle controls the workflow; it must not delete its historical records.

## Native bookkeeping foundation in this increment

- Tenant-owned chart of accounts with a standard starter set for cash, bank, receivables, payables, tax, equity, revenue and operating expenses.
- Double-entry journal entries. Every posted journal must contain at least two lines, each line must be either a debit or a credit, and total debits must equal total credits in integer cents.
- Itemized invoices with quantity, unit price, optional tax rate, tax amount and immutable customer/item snapshots.
- Tax-exclusive and tax-inclusive calculations using the tax rate configured for the ISP.
- Automatic invoice journal posting: debit receivables, credit revenue and (when applicable) tax payable.
- Expense capture with an automatic balanced journal: debit the selected expense account and credit the selected cash, bank or payable account.
- Verified payment receipts. A payment must be tied to the invoice, tenant, amount and currency and have a non-null verification timestamp. Receipt creation is idempotent for each verified payment.
- Partial payments can create separate receipts. The invoice is marked partially paid until the receipt total settles the invoice; over-receipting is rejected.
- Verified payment receipt posting: debit cash/bank and credit accounts receivable, once per payment transaction.
- Tenant-scoped document numbering with locked per-organization/year sequences.
- CSV, XLSX and PDF export for invoices and receipts. CSV output includes spreadsheet-formula injection protection. XLSX uses OpenXML and requires PHP \`ext-zip\`. PDF output is generated as a downloadable PDF.
- Accounting overview totals derived from posted journal lines, not from unverified payment callbacks or UI-entered totals.
- Audit events for ledger bootstrap, journal posting, invoice issue, expense posting, receipt issue and settings changes.

## Initial chart of accounts

| Code | Account | Type | Normal balance |
|---|---|---|---|
| 1000 | Cash on hand | Asset | Debit |
| 1010 | Bank account | Asset | Debit |
| 1100 | Accounts receivable | Asset | Debit |
| 2000 | Accounts payable | Liability | Credit |
| 2100 | Tax payable | Liability | Credit |
| 3000 | Owner equity | Equity | Credit |
| 4000 | Internet subscription revenue | Revenue | Credit |
| 4100 | Installation and service revenue | Revenue | Credit |
| 5000 | Bandwidth and upstream costs | Expense | Debit |
| 5100 | Network equipment and maintenance | Expense | Debit |
| 5200 | Staff and operating expenses | Expense | Debit |
| 5300 | Payment processing fees | Expense | Debit |

An ISP can extend the chart of accounts with its own codes. System accounts must not be deleted while referenced by posted journals. Corrections should use reversal entries rather than silently editing posted journal lines.

## API surface

All endpoints require an authenticated, verified user and active organization. Billing authorization is enforced server-side. Tenant identity comes from active organization middleware, never from request payloads.

- \`GET /nexisp/books/overview\`
- \`GET /nexisp/books/settings\`
- \`PUT /nexisp/books/settings\`
- \`POST /nexisp/books/bootstrap\`
- \`GET /nexisp/books/accounts\`
- \`GET /nexisp/books/journals\`
- \`POST /nexisp/books/journals\`
- \`GET /nexisp/books/invoices\`
- \`POST /nexisp/books/invoices\`
- \`GET /nexisp/books/expenses\`
- \`POST /nexisp/books/expenses\`
- \`POST /nexisp/books/invoices/{invoiceId}/receipts\`
- \`GET /nexisp/books/invoices/{invoiceId}/export/{csv|xlsx|pdf}\`
- \`GET /nexisp/books/receipts/{receiptId}/export/{csv|xlsx|pdf}\`

## Planned expansion

The database foundation includes tax rates and bank account/transaction records. The next accounting increments should add:
- provider statement import and bank reconciliation matching;
- budgets, cost centres, branches and project-level reporting;
- recurring invoice schedules and automated reminders;
- credit notes, refunds, reversals and write-offs;
- accounts payable, vendor bills, purchase orders and supplier records;
- cash-flow forecasts, profit and loss, balance sheet, trial balance, general ledger and aged receivables/payables reports;
- multi-currency exchange rates, realized/unrealized FX and period close controls;
- branded document templates, configurable terms, QR verification and customer self-service invoice/receipt links;
- eTIMS/KRA tax compliance adapter after its legal and technical requirements are confirmed.

These planned items are not represented as completed just because their feature switches or supporting tables exist.

## Financial integrity rules

- Posted journal entries are immutable. Corrections are new reversal entries.
- Never create a receipt from an unverified callback payload. Payment verification must happen in the payment provider workflow before receipt issuance.
- Never infer payment settlement from a successful accounting export.
- No invoice, receipt, journal or bank transaction can be fetched across organization boundaries.
- Financial totals are calculated from integer cents or database decimal values; avoid binary floating-point arithmetic for money.
- The system stores the ISP's source records independently from external accounting provider records.
- External sync uses idempotency keys, encrypted provider credentials and audit history. Disconnection does not delete native records or already synchronized external IDs.

## Validation

From \`backend/laravel\`:

\`\`\`sh
php artisan migrate
php artisan test --filter=NexispBooksTest
php artisan test --filter=AccountingSyncServiceTest
php artisan test
\`\`

Enable PHP \`ext-zip\` on production and CI servers to support XLSX exports.
