Name:           app-glpi-agent
Version:        0.1.13
Release:        3%{?dist}
Summary:        ClearOS GLPI Agent web interface

License:        GPLv3
URL:            https://github.com/snuglinux/app-glpi-agent
Source0:        https://github.com/snuglinux/app-glpi-agent/archive/refs/tags/%{version}.tar.gz

BuildArch:      noarch
Requires:       app-base
Requires:       app-base-core
Requires:       glpi-agent >= 1.17
Requires:       openssl
Requires:       sudo
Requires(post): systemd

%description
app-glpi-agent provides a ClearOS Webconfig page for configuring the GLPI Agent
inventory server URL, SSL handling and glpi-agent systemd service state.

%prep
%setup -q -n %{name}-%{version}

%build
# Nothing to build.

%install
rm -rf %{buildroot}

install -d -m 0755 %{buildroot}/usr/clearos/apps/glpi_agent

# Expected repository layouts:
#   apps/glpi_agent/...
#   glpi_agent/...
# Fallback supports archives where app files are placed directly in root.
if [ -d apps/glpi_agent ]; then
    cp -a apps/glpi_agent/. %{buildroot}/usr/clearos/apps/glpi_agent/
elif [ -d glpi_agent ]; then
    cp -a glpi_agent/. %{buildroot}/usr/clearos/apps/glpi_agent/
elif [ -d controllers ] && [ -d libraries ] && [ -d views ] && [ -d deploy ]; then
    cp -a controllers libraries views deploy %{buildroot}/usr/clearos/apps/glpi_agent/
    [ -d htdocs ] && cp -a htdocs %{buildroot}/usr/clearos/apps/glpi_agent/
    [ -d language ] && cp -a language %{buildroot}/usr/clearos/apps/glpi_agent/
else
    echo "ERROR: Cannot find ClearOS app-glpi-agent source layout." >&2
    exit 1
fi

# Normalize executable bits inside the app tree. GitHub archives can preserve
# file modes from git, but do not rely on that for package-critical scripts.
if [ -f %{buildroot}/usr/clearos/apps/glpi_agent/deploy/install ]; then
    chmod 0755 %{buildroot}/usr/clearos/apps/glpi_agent/deploy/install
else
    echo "ERROR: missing deploy/install" >&2
    exit 1
fi

if [ -f %{buildroot}/usr/clearos/apps/glpi_agent/deploy/glpi-agent-helper.sh ]; then
    chmod 0755 %{buildroot}/usr/clearos/apps/glpi_agent/deploy/glpi-agent-helper.sh
else
    echo "ERROR: missing deploy/glpi-agent-helper.sh" >&2
    exit 1
fi

install -d -m 0755 %{buildroot}/var/clearos/glpi_agent
install -d -m 0755 %{buildroot}/usr/sbin
install -d -m 0750 %{buildroot}/etc/sudoers.d

install -m 0755 %{buildroot}/usr/clearos/apps/glpi_agent/deploy/glpi-agent-helper.sh %{buildroot}/usr/sbin/clearos-glpi-agent-helper

cat > %{buildroot}/etc/sudoers.d/clearos-glpi-agent <<'EOF'
# ClearOS GLPI Agent app helper
Defaults!/usr/sbin/clearos-glpi-agent-helper !requiretty
Defaults!/usr/sbin/clearos-glpi-agent-helper lecture=never
webconfig ALL=(root) NOPASSWD: /usr/sbin/clearos-glpi-agent-helper *
apache ALL=(root) NOPASSWD: /usr/sbin/clearos-glpi-agent-helper *
nobody ALL=(root) NOPASSWD: /usr/sbin/clearos-glpi-agent-helper *
EOF
chmod 0440 %{buildroot}/etc/sudoers.d/clearos-glpi-agent

%post
/bin/sh /usr/clearos/apps/glpi_agent/deploy/install >/dev/null 2>&1 || :

%files
%defattr(-,root,root,-)
/usr/clearos/apps/glpi_agent
%attr(0755,root,root) /usr/sbin/clearos-glpi-agent-helper
%config(noreplace) %attr(0440,root,root) /etc/sudoers.d/clearos-glpi-agent
%dir /var/clearos/glpi_agent
%ghost %config(noreplace) %attr(0644,root,root) /etc/glpi-agent/conf.d/glpi-agent.cfg
%ghost %dir %attr(0755,root,root) /etc/glpi-agent/certs

%changelog
* Thu May 21 2026 SnugLinux <khvalera@ukr.net> - 0.1.13-3
- Require glpi-agent >= 1.17.

* Thu May 21 2026 SnugLinux <khvalera@ukr.net> - 0.1.13-2
- Fix RPM packaging for /usr/sbin/clearos-glpi-agent-helper.
- Install helper as 0755 so Webconfig is_executable() check succeeds.
- Normalize deploy/install and deploy/glpi-agent-helper.sh executable bits during build.
- Run deploy/install via /bin/sh in %%post so post-install does not depend on archive executable mode.
- Add apache to static sudoers fallback.

* Thu May 21 2026 SnugLinux <khvalera@ukr.net> - 0.1.13-1
- Remove obsolete PERL5OPT=-Mwarnings=-ambiguous service drop-in.
- Fix manual inventory and service start failures on Perl builds where -ambiguous is not a known warnings category.
- Keep LOG_INFO warning filtering only as a Web output cleanup fallback.

* Thu May 21 2026 SnugLinux <khvalera@ukr.net> - 0.1.12-1
- Add GLPI certificate trust mode using ca-cert-file.
- Add Update Certificate and Check Certificate actions in Webconfig.
- Download the current GLPI HTTPS certificate, test inventory, and roll back config if the test fails.
- Run manual inventory with stderr logging and full inventory enabled so Webconfig shows useful diagnostics.

* Wed May 20 2026 SnugLinux <khvalera@ukr.net> - 0.1.11-1
- Suppress the known glpi-agent Perl LOG_INFO ambiguous warning without editing package-owned files.
- Filter the same warning from manual inventory output in the Webconfig helper.

* Tue May 19 2026 SnugLinux <khvalera@ukr.net> - 0.1.10-1
- Show SSL fingerprint in the same masked read-only style as app-zabbix-agent2 Current PSK.
- Show the log file hint as a short ClearOS information message.
- Use sudo -n and broader Webconfig/apache sudoers rules for helper actions without TTY prompts.

* Tue May 19 2026 SnugLinux <khvalera@ukr.net> - 0.1.9-1
- Show the GLPI Agent log file reminder as a ClearOS information box instead of a form field.

* Tue May 19 2026 SnugLinux <khvalera@ukr.net> - 0.1.8-1
- Mask SSL fingerprint in edit view like app-zabbix2 PSK: no show/hide button, copy action in the button row.
- Add clearos-glpi-agent-helper with sudoers for privileged Webconfig run-now/start/stop operations.
- Run manual inventory through the helper to avoid /var/lib/glpi-agent write failures.

* Tue May 19 2026 SnugLinux <khvalera@ukr.net> - 0.1.7-1
- Remove normal conf.d include status from UI.
- Use ClearOS Shell wrapper for Webconfig service start/stop.
- Translate debug level and HTTP trust.
- Show logfile only as a reminder.

* Tue May 19 2026 SnugLinux <snuglinux@users.noreply.github.com> - 0.1.7-1
- Generate SSL fingerprint in the legacy installer format: sha256$<64-hex-hash>.
- Accept and normalize existing 64-hex fingerprints to sha256$ format.
- Remove logfacility from the Webconfig UI and generated config to keep logging simple.

* Tue May 19 2026 SnugLinux <snuglinux@users.noreply.github.com> - 0.1.5-1
- Use an Enable Local HTTP Server UI toggle while still writing GLPI Agent no-httpd.
- Hide the managed config file path from the summary page.
- Add a Generate SSL fingerprint button on the settings page.
- Save configuration through the ClearOS File library to avoid Webconfig write permission errors.
- Create an empty managed glpi-agent.cfg during deploy/install if it is missing.

* Tue May 19 2026 SnugLinux <snuglinux@users.noreply.github.com> - 0.1.4-1
- Use /etc/glpi-agent/conf.d/glpi-agent.cfg as the managed config file.
- Keep agent.cfg untouched and show whether include "conf.d/" is enabled.
- Add logger/debug/httpd-trust settings and keep syslog as the default logger.
- Migrate old clearos.cfg to glpi-agent.cfg with a backup during package preparation.

* Tue May 19 2026 SnugLinux <snuglinux@users.noreply.github.com> - 0.1.3-1
- Show only the parsed GLPI Agent version instead of raw --version output.
- Hide the log file path from the read-only summary page.

* Tue May 19 2026 SnugLinux <snuglinux@users.noreply.github.com> - 0.1.2-1
- Replace the app icon with the supplied GLPI-style icon.
- Split the main page into a read-only settings summary with an Edit button, like app-nut.
- Add a dedicated settings controller for editing and saving configuration.

* Tue May 19 2026 SnugLinux <snuglinux@users.noreply.github.com> - 0.1.1-1
- Use standard ClearOS daemon sidebar integration for glpi-agent service start/stop.
- Restore install-app-tree-dev.sh for safe development copy to /usr/clearos/apps/glpi_agent.
- Keep the initial app simple: settings, service control and manual inventory run only.

* Tue May 19 2026 SnugLinux <snuglinux@users.noreply.github.com> - 0.1.0-1
- Initial simple ClearOS Webconfig app for GLPI Agent settings and service control.
