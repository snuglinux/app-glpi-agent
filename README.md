# app-glpi-agent

Development snapshot: v20 - inventory run result is shown on the summary page after manual run.

ClearOS Webconfig app for configuring and controlling the `glpi-agent` service.

## Managed configuration

The app manages only this file:

```text
/etc/glpi-agent/conf.d/glpi-agent.cfg
```

The package-owned main file is not edited by the app:

```text
/etc/glpi-agent/agent.cfg
```

`agent.cfg` must include `conf.d/` for the managed file to be loaded. The Webconfig page only shows a warning if this include is missing.

## Current scope

- GLPI server URL
- SSL mode: system CA, SHA256 fingerprint, or no SSL check
- local GLPI Agent HTTP server on/off plus IP/port/trust
- logger: syslog/file/stderr
- debug level
- inventory tag
- standard ClearOS service widget for `glpi-agent.service`
- manual inventory run

The app currently does not manage hardware UUID/serial overrides and does not edit plugin `.local` files.


## v0.1.5 notes

- The UI now shows `Увімкнути локальний HTTP-сервер` instead of the inverted `Вимкнути локальний HTTP-сервер`. Internally the app still writes the GLPI Agent option `no-httpd = yes/no`.
- The summary page no longer shows the managed configuration file path.
- The settings page has a button to fetch/generate the SHA256 SSL fingerprint from the configured GLPI URL.
- Configuration saving uses the ClearOS `File` library and `deploy/install` creates an empty `/etc/glpi-agent/conf.d/glpi-agent.cfg` when needed.


## v12

- SSL fingerprint generation now keeps the generated value visible in the edit form.
- The fingerprint generation button uses the primary ClearOS button style, like the Update button.


## v12 notes

- `ssl-fingerprint` is generated in the same format as the old shell installer: `sha256$<64-hex-hash>`.
- `logfacility` is no longer shown or written by the Webconfig page; `logger = syslog` uses the GLPI Agent default facility unless configured manually outside this app.


## v12 notes

- Removed the normal `include conf.d` status from the settings views; only a warning is shown if the include is missing.
- Service start/stop now uses the ClearOS `Shell` wrapper instead of raw `exec()` for systemctl.
- `HTTP trust` is translated as trusted HTTP clients and has a help reminder.
- The log file path is shown only as a reminder, not as a normal editable field.
- Debug is translated in the UI.


## v13 notes

- SSL fingerprint is hidden on the edit form by default and can be copied to clipboard.
- Inventory run from Webconfig uses the ClearOS Shell wrapper to avoid /var/lib/glpi-agent write permission errors.


## Privileged Webconfig actions

Webconfig uses a small fixed helper for operations that must run as root:

```text
/usr/sbin/clearos-glpi-agent-helper
/etc/sudoers.d/clearos-glpi-agent
```

The helper is limited to fixed actions:

```text
run-now
start
stop
restart-if-running
```

This is needed because running `glpi-agent --force` directly from the Webconfig user can fail when the agent needs to write to `/var/lib/glpi-agent`.


## v15 notes

- The log file path reminder is now displayed as a ClearOS Information box, not as a normal settings row.
- The SSL fingerprint field remains masked and the copy action stays in the button row, following the app-zabbix-agent2 style.


## Notes for v18

- SSL fingerprint is shown as a masked read-only value, following the app-zabbix-agent2 Current PSK style.
- Web actions use `sudo -n /usr/sbin/clearos-glpi-agent-helper ...`; deploy/install installs sudoers entries for both `webconfig` and `apache`.


## Known GLPI Agent 1.17 Perl warning

Some EL7/ClearOS installations of unpatched `glpi-agent` 1.17 print:

```text
Ambiguous use of -LOG_INFO resolved as -&LOG_INFO() at /usr/share/glpi-agent/lib/GLPI/Agent/Logger.pm line 124.
```

The app does not modify files under `/usr/share/glpi-agent` and does not set
`PERL5OPT` for the service.  Earlier development builds created a systemd
drop-in with `PERL5OPT=-Mwarnings=-ambiguous`; `deploy/install` now removes that
obsolete drop-in because some Perl builds fail with `Unknown warnings category
'-ambiguous'`.  The helper only filters the old warning line from Webconfig
manual-inventory output when an unpatched agent is still installed.

## SSL certificate workflow

The app supports a managed certificate mode for self-signed GLPI HTTPS certificates.

From the summary page:

- **Оновити сертифікат** downloads the current certificate from the configured GLPI server, stores it in `/etc/glpi-agent/certs/<host>.pem`, rewrites the managed config to use `ca-cert-file`, runs a real inventory test, and rolls the config back if the test fails.
- **Перевірити сертифікат** compares the remote server certificate with the local trusted certificate and shows certificate details.

When certificate mode is active, the managed config uses:

```ini
ca-cert-file = /etc/glpi-agent/certs/glpi.lan.pem
```

and does not write `no-ssl-check` or `ssl-fingerprint`.


## Backup cleanup

The app keeps only the newest 2 managed configuration backups:

```text
/etc/glpi-agent/conf.d/glpi-agent.cfg.bak-*
/etc/glpi-agent/conf.d/clearos.cfg.bak-*
```

This prevents repeated Webconfig saves or certificate updates from filling `/etc/glpi-agent/conf.d`.
