# Security Review Log

## Contents

- [2026-05-21 — Initial review (first monthly report)](#2026-05-21--initial-review-first-monthly-report)
  - [Findings](#findings)
  - [Actions taken](#actions-taken)
  - [Expected result for next monthly report](#expected-result-for-next-monthly-report)
- [Template for future entries](#template-for-future-entries)

---

Each entry records what was examined, what was found, and what action was taken.
Add a new entry after each monthly report or any ad-hoc review.

---

## 2026-05-21 — Initial review (first monthly report)

**Reviewer:** Claude (assisted) + Thomas  
**Trigger:** First run of `monthly-security-review.yml` GitHub Actions workflow  
**Report:** `build-deploy/security-reports/2026-05-21-security-review.html`

### Findings

#### False positives — labeling gaps in security.js (fixed)

| Item | Root cause | Fix applied |
|---|---|---|
| A03 Injection | Section L named `'CWE Top 25 (Web)'` — parser didn't find `'OWASP A03'` | Renamed to `'CWE Top 25 / Injection (OWASP A03)'` |
| A08 Software/Data Integrity | Section K named `'Application Hardening'` — parser didn't find `'OWASP A08'` | Renamed to `'Application Hardening (OWASP A08)'` |
| CWE-862 Missing Authorization | Mentioned in a code comment on line 804 but not as a quoted string in any test call | Added `'CWE-862'` as the CWE reference on auth-guard fail/warn calls in section D |
| CWE-863 Incorrect Authorization | Not referenced at all | Added `'CWE-863'` to privilege-escalation test in section L |
| CWE-269 Improper Privilege Management | Only appeared inside larger string literals — regex `/'(CWE-\d+)'/g` requires standalone quoted form | Added second `warn()` call in section L 200-case with `'CWE-269'` as CWE param |
| OWASP A10 (SSRF) | Missing from `OWASP_EXEMPT` — bogus `'CWE-10'` key in `CWE_EXEMPT` never filtered anything | Moved to `OWASP_EXEMPT`; removed invalid `'CWE-10'` from `CWE_EXEMPT` |

#### Not applicable to this app (documented as exemptions)

| Item | Rationale |
|---|---|
| A04 Insecure Design | Architectural/threat-modelling concern; not testable via HTTP scanner |
| A09 Security Logging & Monitoring | Satisfied by `app.log`, `csp-report.php`, and the nightly/monthly GitHub Actions reports. Not automatable via HTTP |
| CWE-502 Deserialization | App uses `json_decode()`, never PHP `unserialize()` on user input |
| CWE-77 Command Injection | Grep confirmed no `exec()`/`shell_exec()`/`system()`/`passthru()` in user-facing PHP |
| CWE-798 Hard-coded Credentials | SAST concern; credentials live in gitignored `config.php`. Not testable via HTTP scanner |
| CWE-918 SSRF | Only outbound HTTP calls are to hardcoded URLs (`api.resend.com`, `api.jolpi.ca`). No user-controlled URL fetching |
| CWE-94 Code Injection | No `eval()` or dynamic `include` on user input anywhere in the codebase |

### Actions taken

- Fixed 6 labeling/coverage issues in `tests/security/security.js`
- Added `OWASP_EXEMPT` and `CWE_EXEMPT` maps to `build-deploy/security-review.js`; exemptions are now shown in the monthly email report with their rationale
- Added `A10` (SSRF) to `OWASP_EXEMPT`; removed bogus `'CWE-10'` key that was never filtering anything
- Updated `CWE_TOP25_WEB` comment with review date
- Baseline: OWASP 2021, CWE Top 25 2024 (web-relevant subset, 16 items)

### Expected result for next monthly report

- OWASP gaps: **none** (A04 and A09 are exempt)
- CWE gaps: **none** (all remaining items either covered or exempt)

---

## Template for future entries

```
## YYYY-MM-DD — [Monthly / Ad-hoc]

**Reviewer:**
**Trigger:** Monthly workflow run / [other reason]
**Report:** build-deploy/security-reports/YYYY-MM-DD-security-review.html

### Findings

[List any new gaps, false positives, or changed items]

### Actions taken

[What was fixed, added, or updated]

### Notes

[Anything worth remembering for next time — e.g. OWASP announced a new version]
```
