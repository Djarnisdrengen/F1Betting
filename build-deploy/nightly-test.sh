#!/usr/bin/env bash
# Nightly test runner — called by cron, logs to nightly.log
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
LOG_FILE="$SCRIPT_DIR/nightly.log"

# Keep the last 30 days of logs (each run appends a dated header)
echo "" >> "$LOG_FILE"
echo "══════════════════════════════════════" >> "$LOG_FILE"
echo " Nightly run: $(date '+%Y-%m-%d %H:%M:%S')" >> "$LOG_FILE"
echo "══════════════════════════════════════" >> "$LOG_FILE"

cd "$PROJECT_DIR"

# Use the node / npx from nvm if available
export NVM_DIR="${HOME}/.nvm"
if [ -s "$NVM_DIR/nvm.sh" ]; then
    # shellcheck source=/dev/null
    . "$NVM_DIR/nvm.sh"
fi

node build-deploy/nightly-report.js 2>&1 | tee -a "$LOG_FILE"

# Trim log to last 2000 lines so it doesn't grow unbounded
tail -n 2000 "$LOG_FILE" > "$LOG_FILE.tmp" && mv "$LOG_FILE.tmp" "$LOG_FILE"
