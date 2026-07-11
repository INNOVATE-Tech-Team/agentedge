-- Run once on the server (phpMyAdmin or mysql CLI) logged in as a user
-- who has CREATE/ALTER privileges on innovate_agents.
--
-- Step 1: create the table.
CREATE TABLE IF NOT EXISTS `agentedge_dotloop_tokens` (
  `staffid`            INT          NOT NULL,
  `access_token`       TEXT         NOT NULL,
  `refresh_token`      TEXT         DEFAULT NULL,
  `expires_at`         BIGINT       DEFAULT NULL,
  `dotloop_profile_id` INT          DEFAULT NULL,
  `created_at`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`staffid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Step 2: create a write-capable DB user for AgentEdge (run as root / cPanel).
-- Replace 'STRONG-RW-PASSWORD' with the password you set in cPanel → MySQL Databases.
--
--   CREATE USER IF NOT EXISTS 'innovate_agentedge_rw'@'localhost'
--       IDENTIFIED BY 'STRONG-RW-PASSWORD';
--
--   GRANT SELECT, INSERT, UPDATE
--     ON `innovate_agents`.`agentedge_dotloop_tokens`
--     TO 'innovate_agentedge_rw'@'localhost';
--
--   FLUSH PRIVILEGES;
