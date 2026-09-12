<?php
// DB-backed session storage — replaces PHP's default file-based sessions, which
// weren't reliably surviving on live hosting (Simply.com), plausibly because
// requests land on more than one app server and file sessions aren't shared
// between them. MySQL is the one backend the app already trusts as shared state
// across however many app servers exist, so sessions live there instead.
//
// Registered via session_set_save_handler() in config.shared.php, before that
// file's session_start() call. Required by config.shared.php BEFORE
// functions.php loads (getDB() isn't available yet at that point), so this opens
// its own PDO connection from the same DB_HOST/DB_NAME/DB_USER/DB_PASS constants
// getDB() uses, rather than depending on functions.php's load order.
class DbSessionHandler implements SessionHandlerInterface {
    private ?PDO $db = null;

    private function db(): PDO {
        if ($this->db === null) {
            $this->db = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_EMULATE_PREPARES => false]
            );
        }
        return $this->db;
    }

    public function open(string $path, string $name): bool {
        return true;
    }

    public function close(): bool {
        return true;
    }

    public function read(string $id): string|false {
        $stmt = $this->db()->prepare("SELECT data FROM sessions WHERE id = ?");
        $stmt->execute([$id]);
        $data = $stmt->fetchColumn();
        return $data === false ? '' : $data;
    }

    public function write(string $id, string $data): bool {
        $stmt = $this->db()->prepare(
            "INSERT INTO sessions (id, data, last_activity) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE data = VALUES(data), last_activity = VALUES(last_activity)"
        );
        return $stmt->execute([$id, $data, time()]);
    }

    public function destroy(string $id): bool {
        $stmt = $this->db()->prepare("DELETE FROM sessions WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function gc(int $max_lifetime): int|false {
        $stmt = $this->db()->prepare("DELETE FROM sessions WHERE last_activity < ?");
        $stmt->execute([time() - $max_lifetime]);
        return $stmt->rowCount();
    }
}
