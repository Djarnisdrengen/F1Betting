<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();
require_once __DIR__ . '/F1Intelligence.php';

// ── POST handler — returns JSON, never renders HTML ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    header('Content-Type: application/json');

    $question = trim(sanitizeString($_POST['question'] ?? ''));

    if ($question === '' || mb_strlen($question) > 500) {
        http_response_code(400);
        echo json_encode(['error' => 'Question must be 1–500 characters.']);
        exit;
    }

    $intel  = new F1Intelligence(F1_INTELLIGENCE_API_URL, F1_INTELLIGENCE_TIMEOUT, F1_INTELLIGENCE_DEBUG);
    $result = $intel->query($question);

    if (!$result) {
        http_response_code(502);
        echo json_encode(['error' => 'The AI did not respond. Check Vercel logs or try again.']);
        exit;
    }

    echo json_encode($result);
    exit;
}

// ── GET — render page ─────────────────────────────────────────────────────────
$currentUser = getCurrentUser();
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>F1 Intelligence — Admin Query</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
            background: #111;
            color: #f0f0f0;
            min-height: 100vh;
            padding: 24px 16px 48px;
        }

        .page { max-width: 760px; margin: 0 auto; }

        /* header */
        .page-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 28px;
            padding-bottom: 16px;
            border-bottom: 2px solid #e3000b;
        }
        .page-header h1 { font-size: 1.25rem; font-weight: 700; }
        .page-header .badge {
            font-size: 0.7rem;
            background: #e3000b;
            color: #fff;
            padding: 2px 8px;
            border-radius: 99px;
            font-weight: 600;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .page-header a {
            margin-left: auto;
            color: #888;
            font-size: 0.85rem;
            text-decoration: none;
        }
        .page-header a:hover { color: #f0f0f0; }

        /* card */
        .card {
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .card-title {
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #888;
            margin-bottom: 14px;
        }

        /* form */
        textarea {
            width: 100%;
            background: #111;
            border: 1px solid #333;
            border-radius: 6px;
            color: #f0f0f0;
            font-family: inherit;
            font-size: 0.95rem;
            padding: 12px;
            resize: vertical;
            min-height: 100px;
            transition: border-color 0.15s;
        }
        textarea:focus { outline: none; border-color: #e3000b; }
        textarea::placeholder { color: #555; }

        .form-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 10px;
            gap: 12px;
        }
        .char-count {
            font-size: 0.8rem;
            color: #666;
            flex-shrink: 0;
        }
        .char-count.warn { color: #e3000b; }

        button[type="submit"] {
            background: #e3000b;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 10px 24px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: opacity 0.15s;
            white-space: nowrap;
        }
        button[type="submit"]:hover:not(:disabled) { opacity: 0.85; }
        button[type="submit"]:disabled { opacity: 0.45; cursor: not-allowed; }

        .spinner {
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
            display: none;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* inline error */
        .form-error {
            font-size: 0.85rem;
            color: #e3000b;
            margin-top: 8px;
            display: none;
        }

        /* answer card */
        #answer-card { display: none; }

        .answer-meta {
            font-size: 0.8rem;
            color: #666;
            margin-bottom: 14px;
        }
        .answer-meta strong { color: #aaa; }

        .answer-body {
            font-size: 0.95rem;
            line-height: 1.7;
            color: #ddd;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .sources-list {
            margin-top: 16px;
            padding-top: 14px;
            border-top: 1px solid #2a2a2a;
        }
        .sources-list h4 {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #666;
            margin-bottom: 8px;
        }
        .sources-list ul { list-style: none; }
        .sources-list li {
            font-size: 0.85rem;
            color: #888;
            padding: 4px 0;
            display: flex;
            justify-content: space-between;
            gap: 12px;
        }
        .sources-list li .sim {
            color: #555;
            flex-shrink: 0;
            font-variant-numeric: tabular-nums;
        }

        /* error card */
        #error-card {
            display: none;
            background: #1a1a1a;
            border: 1px solid #5a1a1a;
            border-radius: 10px;
            padding: 16px 20px;
            color: #e88;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
<div class="page">

    <div class="page-header">
        <h1>🏎 F1 Intelligence</h1>
        <span class="badge">Admin</span>
        <a href="/admin.php">← Back to admin</a>
    </div>

    <div class="card">
        <div class="card-title">Ask a question</div>
        <form id="query-form" method="POST">
            <?= csrfField() ?>
            <textarea
                id="question"
                name="question"
                rows="4"
                maxlength="500"
                placeholder="Ask an F1 question… e.g. Who are the favourites for the podium at Monza?"
                required
                autocomplete="off"
            ></textarea>
            <div class="form-footer">
                <span class="char-count" id="char-count">0 / 500</span>
                <button type="submit" id="submit-btn">
                    <span class="spinner" id="spinner"></span>
                    <span id="btn-label">Ask</span>
                </button>
            </div>
            <div class="form-error" id="form-error"></div>
        </form>
    </div>

    <div class="card" id="answer-card">
        <div class="card-title">Answer</div>
        <div class="answer-meta" id="answer-meta"></div>
        <div class="answer-body" id="answer-body"></div>
        <div class="sources-list" id="sources-list"></div>
    </div>

    <div id="error-card"></div>

</div>

<script>
(function () {
    const form      = document.getElementById('query-form');
    const textarea  = document.getElementById('question');
    const charCount = document.getElementById('char-count');
    const submitBtn = document.getElementById('submit-btn');
    const spinner   = document.getElementById('spinner');
    const btnLabel  = document.getElementById('btn-label');
    const formError = document.getElementById('form-error');
    const answerCard = document.getElementById('answer-card');
    const answerMeta = document.getElementById('answer-meta');
    const answerBody = document.getElementById('answer-body');
    const sourcesList = document.getElementById('sources-list');
    const errorCard  = document.getElementById('error-card');

    const MAX = 500;

    // ── character counter ────────────────────────────────────────────────
    textarea.addEventListener('input', function () {
        const len = this.value.length;
        charCount.textContent = len + ' / ' + MAX;
        charCount.classList.toggle('warn', len >= MAX - 20);
        submitBtn.disabled = len === 0 || len > MAX;
        hideError();
    });

    // ── helpers ──────────────────────────────────────────────────────────
    function showError(msg) {
        formError.textContent = msg;
        formError.style.display = 'block';
    }
    function hideError() {
        formError.style.display = 'none';
    }
    function setLoading(on) {
        spinner.style.display  = on ? 'block' : 'none';
        btnLabel.textContent   = on ? 'Asking…' : 'Ask';
        submitBtn.disabled     = on;
        textarea.disabled      = on;
    }
    function escapeHtml(str) {
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ── submit ───────────────────────────────────────────────────────────
    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        hideError();
        answerCard.style.display = 'none';
        errorCard.style.display  = 'none';

        const question = textarea.value.trim();
        if (!question) { showError('Please enter a question.'); return; }
        if (question.length > MAX) { showError('Question is too long.'); return; }

        setLoading(true);
        const started = Date.now();

        // 35-second timeout
        const controller = new AbortController();
        const timeoutId  = setTimeout(() => controller.abort(), 35000);

        try {
            const body = new URLSearchParams();
            body.append('question', question);
            body.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

            const res  = await fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
                signal: controller.signal,
            });

            clearTimeout(timeoutId);
            const data = await res.json();

            if (!res.ok) {
                showApiError(data.error || 'Something went wrong.');
                return;
            }

            renderAnswer(data, Date.now() - started);

        } catch (err) {
            clearTimeout(timeoutId);
            if (err.name === 'AbortError') {
                showApiError('Request timed out. The AI is taking too long — try again.');
            } else {
                showApiError('Could not reach the server. Check your connection.');
            }
        } finally {
            setLoading(false);
            textarea.disabled = false;
        }
    });

    function showApiError(msg) {
        errorCard.textContent    = '⚠️ ' + msg;
        errorCard.style.display  = 'block';
        answerCard.style.display = 'none';
    }

    function renderAnswer(data, ms) {
        const secs = (ms / 1000).toFixed(1);
        answerMeta.innerHTML = 'Answered in <strong>' + secs + 's</strong>';

        // Escape then preserve newlines as <br>
        answerBody.innerHTML = escapeHtml(data.answer).replace(/\n/g, '<br>');

        if (data.sources && data.sources.length) {
            let html = '<h4>Sources</h4><ul>';
            data.sources.forEach(function (s) {
                const pct = Math.round(s.similarity * 100);
                html += '<li><span>' + escapeHtml(s.title) + '</span>'
                      + '<span class="sim">' + pct + '%</span></li>';
            });
            html += '</ul>';
            sourcesList.innerHTML = html;
        } else {
            sourcesList.innerHTML = '';
        }

        answerCard.style.display = 'block';
        answerCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // disable submit on load (textarea is empty)
    submitBtn.disabled = true;
})();
</script>
</body>
</html>
