/**
 * POSTs a JSON payload to a PHP import endpoint (import-rumor-drafts.php / import-trivia-
 * drafts.php), retrying on transient failures. Added after the 2026-07-24 content-topup run:
 * live's import succeeded on every generation step but the POST came back as an HTML page
 * ("Unexpected token '<'...") instead of the endpoint's JSON — this host's WAF is documented
 * (docs/cron-jobs.md) to sometimes challenge non-browser traffic, and a manual same-code re-run
 * minutes later went through cleanly on the first attempt, consistent with a transient flake
 * rather than a hard block.
 *
 * Retries a network error or a non-JSON response (the WAF-challenge failure mode above). Does
 * NOT retry a well-formed { ok: false } — that's the endpoint itself rejecting the request (bad
 * token, bad payload), and retrying an unchanged request would just reproduce the same
 * rejection every time.
 */
async function importWithRetry(url, token, payload, { attempts = 3, baseDelayMs = 3000 } = {}) {
    let lastError;
    for (let attempt = 1; attempt <= attempts; attempt++) {
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
                body: JSON.stringify(payload),
            });
            const text = await res.text();
            let body;
            try {
                body = JSON.parse(text);
            } catch (e) {
                throw new Error(`non-JSON response (status ${res.status}): ${text.slice(0, 200)}`);
            }
            return { ok: res.ok && body.ok, body };
        } catch (e) {
            lastError = e;
            if (attempt < attempts) {
                const delay = baseDelayMs * attempt;
                console.warn(`⚠️  import attempt ${attempt}/${attempts} failed (${e.message}) — retrying in ${delay}ms...`);
                await new Promise(r => setTimeout(r, delay));
            }
        }
    }
    throw lastError;
}

module.exports = { importWithRetry };
