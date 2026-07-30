-- Admin-configurable weekly batch size for the Paddock Challenges content-topup pipeline
-- (cron-content-topup.yml). Separate columns per generator since Rumor and Trivia draw down
-- the shared paddock-rumors KB at different rates. Read by public/tools/get-content-batch-size.php
-- so the GitHub Actions workflow (no DB access of its own) can pick it up. Additive, safe to run
-- once per environment.
ALTER TABLE settings ADD COLUMN rumor_batch_size INT NOT NULL DEFAULT 6;
ALTER TABLE settings ADD COLUMN trivia_batch_size INT NOT NULL DEFAULT 6;
