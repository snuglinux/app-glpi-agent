#!/bin/sh
#
# install-app-tree-dev.sh
#
# Development helper for app-glpi-agent.
# Copies this repository tree into:
#   /usr/clearos/apps/glpi_agent
#
# It is intentionally not a GLPI Agent installer and does not configure GLPI.
# Use it only while developing/testing the ClearOS Webconfig app.

set -eu

APP_NAME="glpi_agent"
TARGET="/usr/clearos/apps/${APP_NAME}"
BACKUP_ROOT="/root/app-glpi-agent-dev-backups"

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
SRC_DIR="$SCRIPT_DIR"

need_dir() {
    if [ ! -d "$SRC_DIR/$1" ]; then
        echo "❌ Не знайдено каталог: $SRC_DIR/$1" >&2
        exit 1
    fi
}

if [ "$(id -u)" != "0" ]; then
    echo "❌ Запусти від root." >&2
    exit 1
fi

need_dir controllers
need_dir libraries
need_dir views
need_dir deploy
need_dir language

printf '%s\n' "============================================================"
printf '%s\n' " app-glpi-agent v22: dev-копіювання у ClearOS"
printf '%s\n' "============================================================"
printf 'SOURCE : %s\n' "$SRC_DIR"
printf 'TARGET : %s\n' "$TARGET"
printf '%s\n' "============================================================"

if [ -d "$TARGET" ]; then
    TS=$(date +%Y%m%d-%H%M%S)
    BACKUP_DIR="$BACKUP_ROOT/${APP_NAME}-${TS}"
    echo "📦 Роблю backup поточного каталогу: $BACKUP_DIR"
    mkdir -p "$BACKUP_ROOT"
    cp -a "$TARGET" "$BACKUP_DIR"
fi

echo "🧹 Очищаю $TARGET ..."
rm -rf "$TARGET"
mkdir -p "$TARGET"

echo "📁 Копіюю файли Webconfig app ..."
cp -a "$SRC_DIR/controllers" "$TARGET/"
cp -a "$SRC_DIR/libraries" "$TARGET/"
cp -a "$SRC_DIR/views" "$TARGET/"
cp -a "$SRC_DIR/deploy" "$TARGET/"
cp -a "$SRC_DIR/language" "$TARGET/"

if [ -d "$SRC_DIR/htdocs" ]; then
    cp -a "$SRC_DIR/htdocs" "$TARGET/"
fi
if [ -d "$SRC_DIR/images" ]; then
    cp -a "$SRC_DIR/images" "$TARGET/"
fi

chown -R root:root "$TARGET"
find "$TARGET" -type d -exec chmod 0755 {} \;
find "$TARGET" -type f -exec chmod 0644 {} \;
if [ -f "$TARGET/deploy/install" ]; then
    chmod 0755 "$TARGET/deploy/install"
fi
if [ -f "$TARGET/deploy/glpi-agent-helper.sh" ]; then
    chmod 0755 "$TARGET/deploy/glpi-agent-helper.sh"
fi


# Hard verification: the installed library must use direct exec() for the sudo
# helper.  Older builds used ClearOS Shell in _run_helper() and triggered
# "sudo: no tty present and no askpass program specified" in Webconfig.
echo "🔎 Перевіряю, що встановлена нова helper-логіка без ClearOS Shell ..."
if grep -n "return \$this->_run_shell(\$sudo" "$TARGET/libraries/Glpi_Agent.php" >/tmp/app-glpi-agent-old-helper-grep 2>/dev/null; then
    echo "❌ У встановленому Glpi_Agent.php знайдено старий виклик _run_shell для sudo:" >&2
    sed 's/^/   /' /tmp/app-glpi-agent-old-helper-grep >&2
    echo "   Це саме та причина помилки sudo askpass/no tty." >&2
    echo "   Каталог був скопійований некоректно або використовується старий архів." >&2
    rm -f /tmp/app-glpi-agent-old-helper-grep
    exit 1
fi
rm -f /tmp/app-glpi-agent-old-helper-grep

if grep -n "exec(\$cmd, \$output, \$exit_code)" "$TARGET/libraries/Glpi_Agent.php" >/dev/null 2>&1; then
    echo "✅ Helper-логіка правильна: sudo запускається напряму через exec(), без ClearOS Shell."
else
    echo "⚠️  Не знайшов очікуваний маркер exec(\$cmd...). Перевір $TARGET/libraries/Glpi_Agent.php вручну."
fi

if grep -n "run_now_output" "$TARGET/controllers/glpi_agent.php" >/dev/null 2>&1 \
    && grep -n "glpi_agent_inventory_result" "$TARGET/views/summary.php" >/dev/null 2>&1; then
    echo "✅ Результат ручного запуску інвентаризації буде показано на сторінці."
else
    echo "⚠️  Не знайшов маркери показу результату run_now. Перевір controllers/glpi_agent.php і views/summary.php."
fi

if grep -RniE "export[[:space:]]+PERL5OPT|Environment=PERL5OPT|exec\(.*PERL5OPT" \
    "$TARGET/deploy/glpi-agent-helper.sh" \
    "$TARGET/libraries/Glpi_Agent.php" \
    "$TARGET/deploy/install" >/tmp/app-glpi-agent-perl5opt-grep 2>/dev/null; then
    echo "❌ Знайдено активне встановлення PERL5OPT у app-файлах:" >&2
    cat /tmp/app-glpi-agent-perl5opt-grep >&2
    rm -f /tmp/app-glpi-agent-perl5opt-grep
    exit 1
else
    echo "✅ Активне встановлення PERL5OPT у app-файлах відсутнє."
fi
rm -f /tmp/app-glpi-agent-perl5opt-grep

if grep -n "filter_glpi_agent_noise" "$TARGET/deploy/glpi-agent-helper.sh" >/dev/null 2>&1; then
    echo "✅ Старий рядок Perl-попередження LOG_INFO лише фільтрується з Web-виводу, без PERL5OPT."
else
    echo "⚠️  Не знайшов filter_glpi_agent_noise у helper."
fi

if grep -n "update-certificate" "$TARGET/deploy/glpi-agent-helper.sh" >/dev/null 2>&1 \
    && grep -n "ca-cert-file" "$TARGET/libraries/Glpi_Agent.php" >/dev/null 2>&1 \
    && grep -n "glpi_agent_update_certificate" "$TARGET/views/summary.php" >/dev/null 2>&1; then
    echo "✅ Додано роботу з ca-cert-file та кнопки оновлення/перевірки сертифіката."
else
    echo "⚠️  Не знайшов усі маркери роботи з сертифікатом."
fi

echo "🔧 Запускаю deploy/install ..."
if [ -x "$TARGET/deploy/install" ]; then
    if ! "$TARGET/deploy/install"; then
        echo "❌ deploy/install завершився з помилкою." >&2
        exit 1
    fi
else
    echo "⚠️  deploy/install не executable або відсутній, пропускаю."
fi

if [ -f /etc/sudoers.d/clearos-glpi-agent ]; then
    echo "🔐 sudoers для GLPI Agent:"
    sed 's/^/   /' /etc/sudoers.d/clearos-glpi-agent
    if command -v visudo >/dev/null 2>&1; then
        visudo -cf /etc/sudoers.d/clearos-glpi-agent >/dev/null && echo "✅ sudoers синтаксис коректний"
    fi
else
    echo "❌ /etc/sudoers.d/clearos-glpi-agent не створено"
fi

echo "🔎 Перевірка ключових файлів:"
for f in \
    "$TARGET/controllers/glpi_agent.php" \
    "$TARGET/controllers/settings.php" \
    "$TARGET/controllers/server.php" \
    "$TARGET/libraries/Glpi_Agent.php" \
    "$TARGET/views/summary.php" \
    "$TARGET/views/settings.php" \
    "$TARGET/deploy/info.php" \
    "$TARGET/deploy/glpi-agent-helper.sh" \
    "/usr/sbin/clearos-glpi-agent-helper" \
    "/etc/sudoers.d/clearos-glpi-agent"; do
    if [ -f "$f" ]; then
        echo "✅ $f"
    else
        echo "❌ $f"
    fi
done


# In dev mode PHP/Webconfig can keep old controller/library code in running
# workers.  Restarting Webconfig after copying avoids testing stale PHP code.
if command -v systemctl >/dev/null 2>&1 && systemctl list-unit-files webconfig.service >/dev/null 2>&1; then
    echo "🔄 Перезапускаю webconfig, щоб не лишився старий PHP-код ..."
    systemctl restart webconfig.service || echo "⚠️  Не вдалося перезапустити webconfig автоматично. Перезапусти вручну: systemctl restart webconfig"
elif [ -x /sbin/service ]; then
    echo "🔄 Перезапускаю webconfig, щоб не лишився старий PHP-код ..."
    /sbin/service webconfig restart || echo "⚠️  Не вдалося перезапустити webconfig автоматично. Перезапусти вручну."
fi

printf '%s\n' "============================================================"
printf '%s\n' " Готово ✅"
printf '%s\n' " Відкрий: https://IP-ClearOS:81/app/glpi_agent"
printf '%s\n' " Меню: Система → Моніторинг → GLPI Agent"
printf '%s\n' "============================================================"
