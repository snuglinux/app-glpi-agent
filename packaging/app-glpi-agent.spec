Name:           app-glpi-agent
Version:        0.1.15
Release:        1%{?dist}
Summary:        ClearOS GLPI Agent web interface

License:        GPLv3
URL:            https://github.com/snuglinux/app-glpi-agent
Source0:        https://github.com/snuglinux/app-glpi-agent/archive/refs/tags/%{version}.tar.gz

BuildArch:      noarch
Requires:       app-base
Requires:       app-base-core
Requires:       glpi-agent >= 1.17
Requires:       glpi-additional-oem >= 0.1.4
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

cat > %{buildroot}/etc/sudoers.d/clearos-glpi-agent <<'EOF2'
# ClearOS GLPI Agent app helper
Defaults!/usr/sbin/clearos-glpi-agent-helper !requiretty
Defaults!/usr/sbin/clearos-glpi-agent-helper lecture=never
webconfig ALL=(root) NOPASSWD: /usr/sbin/clearos-glpi-agent-helper *
apache ALL=(root) NOPASSWD: /usr/sbin/clearos-glpi-agent-helper *
nobody ALL=(root) NOPASSWD: /usr/sbin/clearos-glpi-agent-helper *
EOF2
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
* Sun May 31 2026 SnugLinux <khvalera@ukr.net> - 0.1.15-1
- Require glpi-additional-oem >= 0.1.4.
- Generate OEM additional-content before manual inventory runs.
- Show calculated OEM-MAC serial when DMI data is fully invalid.

* Sun May 31 2026 SnugLinux <khvalera@ukr.net> - 0.1.14-1
- Add glpi-additional-oem as a required package.
- Add Webconfig toggle for using OEM additional-content.
- Show DMI/OEM identity diagnostics and warnings on suspicious serial/UUID data.
- Enable/disable OEM additional-content safely by commenting the managed additional-content line.
