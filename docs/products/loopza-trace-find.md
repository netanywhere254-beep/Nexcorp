# Loopza Trace & Find — Product and Architecture Specification

**Status:** Approved product direction / architecture baseline  
**Product:** Loopza  
**Module:** Trace & Find  
**Initial operational market:** Kenya  
**Architecture target:** International from first release

## 1. Product vision

Loopza Trace & Find is an international digital discovery, missing-person assistance, and professional investigation platform. It connects families, verified authorities, approved volunteers, and vetted private investigators while keeping sensitive case information protected.

**Positioning:** One world. Connected searches.

The module has two coordinated capabilities:

- **TRACE:** lawful digital research, public-source discovery, visual similarity discovery, case intelligence, evidence organisation, and professional investigator workflows.
- **FIND:** missing-person reports, unidentified-person referrals, sightings, community alerts, authority coordination, and family reunification.

Trace & Find is a distinct Loopza product module. It may share platform infrastructure, but must have separate data boundaries, permissions, administration, retention policies, and security controls from Loopza Amplify advertising data.

## 2. International and country-wise operating model

The product must be international-ready from its first release. Country-wise registration and governance must not limit lawful cross-border search.

### Global layer
- Global case identifiers and controlled cross-border case matching.
- International public-source discovery through permitted providers and sources.
- Translation and multilingual alert support.
- Partner integrations and cross-border referral workflows.
- Shared technical standards for audit, security, and evidence provenance.

### Country layer
- Country-specific registration, phone validation, identity checks, and language.
- Country-specific privacy, data residency, retention, and disclosure policies.
- Local authority and organisation verification.
- Jurisdiction and regional operating permissions.
- Country-specific emergency contacts and escalation paths.

### Case layer
- Case-specific access grants and assigned roles.
- Separate public alert data and restricted case records.
- Evidence provenance, access history, and review history.
- Cross-border sharing only with a documented lawful basis and authorised approval.

A user's country of registration, current location, home region, and the incident location are separate attributes and must not be conflated.

## 3. User categories and access

### Families / individual reporters
- Create and manage reports concerning a missing or unidentified person.
- Upload photographs, descriptions, last-known information, and supporting documents.
- Receive case updates and submit or review leads.
- Request correction, restriction, or removal where legally appropriate.
- Access only their own cases and information explicitly shared with them.

### Authorities
- Must be institutionally verified and assigned jurisdiction.
- Review reports and supporting documentation.
- Confirm authority awareness or case references through approved channels.
- Validate leads, coordinate referrals, and approve sensitive cross-border sharing.
- Access only cases within their jurisdiction or cases explicitly assigned to them.

### Approved volunteers
- Must pass identity and suitability checks proportionate to their role.
- Receive only public or specifically assigned alerts.
- Submit sightings and tips without accessing restricted evidence or family contact details.
- Cannot independently change case status, verify documents, or access unrestricted case records.

### Private investigators
- Dedicated professional workspace for vetted investigators and firms.
- Verify identity, professional credentials, organisation, and lawful authority or client mandate for each engagement.
- Manage authorised cases, research notes, source references, evidence, and reports.
- No unrestricted access to missing-person records or authority-only data.
- No covert tracking, stalking, unlawful surveillance, or unauthorised personal-data acquisition.

### Loopza platform administrators
- Platform operations, abuse response, account controls, security monitoring, and compliance.
- Administrative access to sensitive case content must be exceptional, purpose-limited, approved, and audited.
- Platform administration must not be treated as authority to conduct investigations.

## 4. Registration, location authentication, and posting controls

For individual accounts, onboarding should support:
1. Country and regional registration.
2. Verified phone number using OTP.
3. Device location permission and a location consistency check where available.
4. Live selfie and liveness check to help establish the submitter's presence and reduce impersonation.
5. Identity and account-risk checks proportionate to the action and case sensitivity.
6. Clear consent, privacy notices, and retention information.

Location verification establishes an approximate device location at the time of submission; it does not prove identity, residence, relationship to the missing person, or truth of a report. GPS can be inaccurate or spoofed and must not be the sole security control.

### Regional posting policy
- A user may publish as a locally verified reporter only within regions for which the account has the required verification or permission.
- Users must not be able to misrepresent themselves as being from another region.
- A person reporting an incident in another region or country must still be able to submit it through a cross-region referral workflow.
- Cross-region submissions are routed to the relevant regional review team or authority and remain provisional until reviewed.
- Do not block legitimate reports from travellers, relatives abroad, displaced people, or people unable to authenticate their location.
- Use risk-based checks and human review for location conflicts, suspicious location changes, and attempted circumvention.

### Organisation and authority onboarding
- Vet the organisation, registration or legal status, official contacts, jurisdiction, and authorised representatives.
- Assign named staff accounts with individual credentials and role-based permissions.
- Do not require a new selfie for every post from an already-vetted organisation.
- Staff still require individual authentication, secure sessions, and audit trails.
- Revalidate organisational credentials periodically and when material details change.

## 5. Missing-person case creation and official report documents

A case submission should capture, as appropriate:
- Full name or known name, age or age range, and approved identifying description.
- Recent photograph(s), with uploader's authority and lawful basis confirmed.
- Date and time last seen, last-known location, and circumstances.
- Reporting person's relationship or authority to submit.
- Safe contact route, preferably privacy-preserving relay rather than public phone display.
- Police or authority report information and supporting documentation.
- Consent, publication scope, and emergency context.

### OB / police report attachment
For Kenya, allow the family or authorised reporter to attach an original scan or photograph of the Occurrence Book (OB) extract or police acknowledgement. For other countries, support equivalent police incident reports, missing-person reference documents, or official acknowledgements.

- Preserve the original upload in encrypted, restricted storage.
- Never expose the original OB or police document on the public case page.
- Extract fields using OCR only as an assistance feature.
- Flag missing fields, inconsistencies, duplicates, and possible alterations for human review.
- Do not claim AI can prove document authenticity.
- Where possible, verify the reference with the issuing authority through an approved channel.
- Display only a safe verification badge and approved case reference publicly.
- Maintain access logs, document version history, and evidence provenance.

Suggested statuses:
1. Draft
2. Submitted — review pending
3. Supporting document received
4. Authority confirmation requested
5. Authority confirmation received
6. Provisionally approved / published with restrictions
7. Approved for public alert
8. Resolved / found — verification pending
9. Closed / archived

Uploading a document does not itself prove authenticity or authority confirmation. Provide an urgent provisional reporting route when paperwork is unavailable, with prompt follow-up and review.

## 6. Digital Trace and AI-assisted discovery

Build a lawful discovery layer using approved search providers, permitted APIs, licensed datasets, and publicly accessible sources where collection and reuse are allowed.

Capabilities may include:
- Reverse-image and visually similar image discovery across indexed public websites and approved providers.
- Search of permitted public social-media content and approved platform integrations.
- Duplicate and repost detection for submitted images.
- OCR and extraction of visible text from submitted materials.
- Analysis of available image metadata, without assuming metadata is accurate.
- Contextual clue extraction, such as visible landmarks, signs, transport markings, or environmental features.
- Timeline construction and comparison of reported sightings.
- Multilingual search, translation, and summarisation.
- Lead clustering, duplicate-tip detection, and prioritisation.
- Source URLs, timestamps, collection method, confidence indicators, and analyst notes for every result.

The platform must not claim it can search every photograph on the internet. Private, encrypted, unindexed, or access-restricted content is out of scope unless the relevant owner or provider grants lawful access.

Visual similarity is not identity proof. AI results must be presented as candidate leads for trained human review. Do not automatically identify, accuse, track, or declare a person found based solely on AI output.

## 7. CCTV and camera partnerships

Do not scan random open IP cameras, bypass camera security, or access private CCTV feeds without authorisation.

Create a Partner Camera Network:
- Enrol CCTV owners and organisations through written permission and appropriate agreements.
- Define permitted purposes, jurisdictions, time windows, retention, and access roles.
- Support authority-led requests for relevant footage.
- Where legally permitted, allow AI-assisted review of specifically authorised footage for a defined case.
- Log every access, export, and disclosure.
- Apply short, purpose-limited retention and deletion controls.
- Do not enable global, continuous facial tracking or indiscriminate surveillance.

## 8. Find, sightings, and reunification

- Public case alerts contain only approved minimum information.
- Tips and sightings use a secure submission workflow.
- Public users must not see private family contact numbers or restricted documents.
- Similar sightings are grouped for human review.
- Authorities and authorised case managers validate potential matches.
- A case cannot be marked found solely by an AI match or unverified public claim.
- Reunification coordination must protect the missing person's safety, consent, and privacy, especially for adults and vulnerable people.
- Provide correction, takedown, and appeal workflows for inaccurate or unsafe public information.

## 9. Security, privacy, and governance

Mandatory controls:
- Encryption in transit and at rest, with stricter controls for identity documents and case evidence.
- Role-based and case-based access control, least privilege, and separation of duties.
- Multi-factor authentication for authorities, investigators, and privileged staff.
- Immutable or tamper-evident audit records for sensitive access and changes.
- Secure upload scanning, file-type validation, malware checks, and evidence hashing.
- Data minimisation, purpose limitation, consent/legal-basis tracking, and retention schedules.
- Country-specific privacy and data-transfer assessments.
- Access revocation, incident response, abuse reporting, and breach procedures.
- No sale of missing-person case data or use of sensitive case material for advertising.
- No public scraping of case data or bulk export.
- Human review for high-impact decisions and sensitive disclosures.

The service must undergo privacy and security impact assessments before live deployment in each country. Legal review is required for local missing-person procedures, biometric/liveness processing, cross-border data transfers, investigator licensing, and law-enforcement access.

## 10. Commercial and operating model

- Basic family missing-person reporting, approved public alerts, and tip submission should remain free.
- Potential funding: grants, donations, institutional contracts, and vetted partner programmes.
- Professional investigator subscriptions or case-management fees may be considered, but must not buy privileged access to family or authority data.
- Any paid investigative engagement requires verification, lawful mandate, transparent terms, and safeguards against misuse.

## 11. Suggested product modules

1. Country Registration & Verification
2. Identity, OTP & Liveness
3. Regional Permission Engine
4. Organisation & Authority Vetting
5. Case Management
6. OB / Official Document Vault
7. Public Alert & Tip Portal
8. Volunteer Assignment
9. Investigator Workspace
10. Digital Discovery & Visual Search
11. AI Lead Triage & Timeline
12. Authority Referral & Cross-Border Coordination
13. Partner Camera Network
14. Evidence & Audit Management
15. Privacy, Retention & Compliance Console
16. Reunification & Case Closure

## 12. Delivery phases

### Phase 1 — Safe reporting foundation
Country-aware onboarding, account verification, regional permissions, family case submission, secure document vault, OB attachment, manual review, public alerts, and secure tips.

### Phase 2 — Role-based operations
Authority and organisation vetting, volunteer approvals, investigator workspace, case assignment, audit trail, and cross-region referral.

### Phase 3 — AI discovery
Approved public-source search integrations, reverse-image provider integrations, OCR, duplicate detection, multilingual support, timeline analysis, and human-reviewed lead scoring.

### Phase 4 — International partnerships
Country-by-country rollout, authority and NGO integrations, partner camera network, cross-border case coordination, and international expansion.

## 13. Acceptance criteria for the first implementation

- No public self-registration path can grant authority, investigator, organisation-admin, or platform-admin privileges.
- Individual users cannot publish as locally verified reporters in a region they have not verified or been authorised to represent.
- A legitimate cross-region or cross-border report can still be submitted and routed for review.
- An urgent report can be provisionally submitted when location or official paperwork is unavailable.
- Family users cannot view another family's restricted case records.
- Volunteers see only public or specifically assigned case information.
- Investigators see only cases for which their credentials and mandate have been approved.
- Organisation staff use individual accounts; organisation vetting is not repeated for every post.
- Original OB and police documents are never publicly exposed.
- AI outputs are labelled as leads and require human verification.
- Every sensitive access, approval, export, and disclosure is auditable.
- Public case information can be restricted, corrected, or removed through an authorised workflow.
- Cross-border sharing requires recorded legal basis and approval.

---

**Implementation note:** This document is the approved product and architecture baseline. It does not claim that the listed application features, external search integrations, authority partnerships, or compliance reviews are already implemented. Each delivery phase must be built, tested, and verified before being marked complete.
