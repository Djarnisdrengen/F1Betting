---
name: FileZilla uploads config.php as empty file
description: FileZilla silently uploads config.php as a zero-byte file; use Simply.com web client instead
type: feedback
---

Do NOT use FileZilla to upload `config.php` to the Simply.com test or live server — it results in an empty file with no error shown.

**Why:** FileZilla silently fails the upload (zero-byte result), likely due to a permissions or transfer-mode issue specific to that file on Simply.com hosting.

**How to apply:** Always use the Simply.com web file manager to upload `config.php`. If the symptom appears again ("Call to undefined function getDB()"), check whether config.php is empty on the server before assuming it's in the wrong location.
