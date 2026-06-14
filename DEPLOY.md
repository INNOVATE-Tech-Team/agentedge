# AgentEdge — deploy to agentedge.innovateonline.com (cPanel)

Agent-facing visual dashboard. PHP + MySQL, reads the Perfex DB (`innovate_agents`)
**read-only**, reuses Perfex (`tblstaff`) logins. Only ever writes files in this
folder; never touches the existing app or its tables.

## One-time setup in cPanel

1. **Subdomain** — Domains → Subdomains → create `agentedge` on `innovateonline.com`.
   Note its **Document Root** (e.g. `public_html/agentedge`).

2. **Read-only DB user** — MySQL Databases:
   - Add a new user, e.g. `innovate_agentedge_ro`, with a strong password.
   - "Add User to Database": add it to **innovate_agents** and grant **SELECT only**.
   - (Later, for AgentEdge's own data, create a separate DB it owns full access to.)

3. **PHP version** — Select PHP Version / MultiPHP: use **8.0+**.

## Upload

1. Put all files from this folder into the subdomain's Document Root
   (File Manager → Upload a zip → Extract, or SFTP).
2. Copy `config.sample.php` to `config.php` and fill in the read-only DB
   user/password you created. (`config.php` is git-ignored — never commit it.)

## Verify

- Visit `https://agentedge.innovateonline.com/login.php`
- Sign in with an agent's existing INNOVATE email + password.
- You should land on the dashboard (tiles + cap wheel render; transaction +
  Darwin data wire in next).
