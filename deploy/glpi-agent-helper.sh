#!/usr/bin/env bash
#
# clearos-glpi-agent-helper
#
# Small privileged helper for app-glpi-agent Webconfig operations.
# Keep commands fixed and conservative.

set -euo pipefail

SERVICE="glpi-agent.service"
AGENT="/usr/bin/glpi-agent"
CONFIG="/etc/glpi-agent/conf.d/glpi-agent.cfg"
CERT_DIR="/etc/glpi-agent/certs"
STORAGE_DIR="/var/lib/glpi-agent"
TIMEOUT="/usr/bin/timeout"
[ -x "$TIMEOUT" ] || TIMEOUT="/bin/timeout"
CONFIG_BACKUP_KEEP="${CONFIG_BACKUP_KEEP:-2}"

log() {
    printf '%s\n' "$*"
}

filter_glpi_agent_noise() {
    sed '/^Ambiguous use of -LOG_INFO resolved as -&LOG_INFO() at \/usr\/share\/glpi-agent\/lib\/GLPI\/Agent\/Logger\.pm line 124\.$/d'
}

need_systemctl() {
    command -v systemctl >/dev/null 2>&1 || {
        log "systemctl не знайдено"
        exit 1
    }
}

ensure_storage_dir() {
    if [ ! -d "$STORAGE_DIR" ]; then
        install -d -m 0755 -o root -g root "$STORAGE_DIR"
    fi
}

ensure_cert_dir() {
    install -d -m 0755 -o root -g root "$CERT_DIR"
}

cleanup_config_backups() {
    # Keep only the newest CONFIG_BACKUP_KEEP copies of glpi-agent.cfg.bak-*.
    # This prevents Webconfig/save/certificate actions from filling conf.d with old backups.
    local keep="$CONFIG_BACKUP_KEEP"

    if ! printf '%s' "$keep" | grep -Eq '^[0-9]+$'; then
        keep="2"
    fi

    [ "$keep" -lt 1 ] && keep="1"

    local old
    old="$(ls -1t "$CONFIG".bak-* 2>/dev/null | awk 'NR > keep' keep="$keep" || true)"
    if [ -n "$old" ]; then
        printf '%s
' "$old" | while IFS= read -r file; do
            [ -n "$file" ] || continue
            rm -f "$file" 2>/dev/null || true
        done
    fi
}

sanitize_host() {
    printf '%s' "$1" | sed 's/[^A-Za-z0-9_.-]/_/g'
}

get_server_from_config() {
    awk -F= '
        /^[[:space:]]*#/ { next }
        /^[[:space:]]*server[[:space:]]*=/ {
            value=$2
            sub(/^[[:space:]]*/, "", value)
            sub(/[[:space:]]*$/, "", value)
            print value
            exit
        }
    ' "$CONFIG" 2>/dev/null || true
}

parse_server_url() {
    SERVER_URL="${1:-}"
    if [ -z "$SERVER_URL" ]; then
        SERVER_URL="$(get_server_from_config)"
    fi

    if [ -z "$SERVER_URL" ]; then
        log "Не знайдено server у $CONFIG"
        exit 1
    fi

    case "$SERVER_URL" in
        https://*) ;;
        *)
            log "Оновлення сертифіката підтримує тільки https:// URL: $SERVER_URL"
            exit 1
            ;;
    esac

    URL_NO_SCHEME="${SERVER_URL#https://}"
    HOST_PORT="${URL_NO_SCHEME%%/*}"
    HOST="${HOST_PORT%%:*}"
    if [ "$HOST_PORT" != "$HOST" ]; then
        PORT="${HOST_PORT##*:}"
    else
        PORT="443"
    fi

    if ! printf '%s' "$HOST" | grep -Eq '^[A-Za-z0-9_.-]+$'; then
        log "Некоректний host у URL: $HOST"
        exit 1
    fi

    if ! printf '%s' "$PORT" | grep -Eq '^[0-9]+$' || [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
        log "Некоректний port у URL: $PORT"
        exit 1
    fi

    SAFE_HOST="$(sanitize_host "$HOST")"
    CERT_FILE="$CERT_DIR/$SAFE_HOST.pem"
}

certificate_info() {
    local file="$1"
    if [ ! -s "$file" ]; then
        log "Сертифікат не знайдено: $file"
        return 1
    fi

    openssl x509 -in "$file" -noout -subject -issuer -dates -fingerprint -sha256 || return 1

    log "Subject Alternative Name:"
    local san_output
    san_output="$(openssl x509 -in "$file" -noout -ext subjectAltName 2>/dev/null || true)"
    if printf '%s\n' "$san_output" | grep -Eiq 'DNS[[:space:]]*:[[:space:]]*'; then
        printf '%s\n' "$san_output" | sed 's/^/  /'
    else
        log "  SAN не знайдено. Якщо майбутні версії SSL-бібліотек стануть суворішими, сертифікат краще перевипустити з DNS:$HOST."
    fi
}

fetch_certificate() {
    local tmp="$1"

    echo | openssl s_client \
        -connect "$HOST:$PORT" \
        -servername "$HOST" \
        -showcerts 2>/dev/null \
        | awk '/-----BEGIN CERTIFICATE-----/{flag=1} flag{print} /-----END CERTIFICATE-----/{exit}' \
        > "$tmp"

    if [ ! -s "$tmp" ]; then
        log "Не вдалося отримати сертифікат з $HOST:$PORT"
        return 1
    fi

    openssl x509 -in "$tmp" -noout >/dev/null 2>&1 || {
        log "Отриманий файл не схожий на X509-сертифікат"
        return 1
    }
}

verify_server_with_ca_file() {
    local ca_file="$1"
    local verify_log
    verify_log="$(mktemp /tmp/glpi-agent-cert-verify.XXXXXX)"

    set +e
    echo | openssl s_client \
        -connect "$HOST:$PORT" \
        -servername "$HOST" \
        -CAfile "$ca_file" \
        -verify_return_error \
        > "$verify_log" 2>&1
    local rc=$?
    set -e

    if [ "$rc" -eq 0 ] && grep -q 'Verify return code: 0 (ok)' "$verify_log"; then
        rm -f "$verify_log"
        return 0
    fi

    log "OpenSSL не підтвердив довіру до сертифіката через ca-cert-file."
    log "Деталі перевірки:"
    tail -40 "$verify_log" | sed 's/^/  /'
    rm -f "$verify_log"
    return 1
}

compare_remote_and_local_fingerprint() {
    local remote_file="$1"
    local local_file="$2"
    local remote_fp local_fp

    remote_fp="$(openssl x509 -in "$remote_file" -noout -fingerprint -sha256 | sed 's/^SHA256 Fingerprint=//')"
    local_fp="$(openssl x509 -in "$local_file" -noout -fingerprint -sha256 | sed 's/^SHA256 Fingerprint=//')"

    log "REMOTE SHA256: $remote_fp"
    log "LOCAL  SHA256: $local_fp"

    [ "$remote_fp" = "$local_fp" ]
}

rewrite_config_for_ca_cert() {
    local server_url="$1"
    local cert_file="$2"
    local tmp
    tmp="$(mktemp /tmp/glpi-agent-cfg.XXXXXX)"

    awk -v server="$server_url" -v cert="$cert_file" '
        BEGIN { wrote_server = 0 }
        /^[[:space:]]*#/ { print; next }
        /^[[:space:]]*server[[:space:]]*=/ {
            if (!wrote_server) {
                print "server = " server
                wrote_server = 1
            }
            next
        }
        /^[[:space:]]*(no-ssl-check|ssl-fingerprint|ca-cert-file|ca-cert-dir)[[:space:]]*=/ { next }
        { print }
        END {
            if (!wrote_server)
                print "server = " server
            print "ca-cert-file = " cert
        }
    ' "$CONFIG" > "$tmp"

    install -m 0644 -o root -g root "$tmp" "$CONFIG"
    rm -f "$tmp"
}

run_agent_force() {
    ensure_storage_dir

    set +e
    if [ -x "$TIMEOUT" ]; then
        "$TIMEOUT" 240 "$AGENT" --force --logger=stderr --full-inventory-postpone=0 2>&1 | filter_glpi_agent_noise
        rc=${PIPESTATUS[0]}
    else
        "$AGENT" --force --logger=stderr --full-inventory-postpone=0 2>&1 | filter_glpi_agent_noise
        rc=${PIPESTATUS[0]}
    fi
    set -e

    return "$rc"
}

run_now() {
    if [ ! -x "$AGENT" ]; then
        log "glpi-agent не встановлено: $AGENT"
        exit 1
    fi

    log "Запускаю інвентаризацію GLPI Agent..."
    run_agent_force
    rc=$?

    if [ "$rc" -eq 0 ]; then
        log "Інвентаризацію GLPI Agent завершено."
    else
        log "Інвентаризація GLPI Agent завершилась з помилкою: rc=$rc"
    fi

    exit "$rc"
}

check_certificate() {
    parse_server_url "${1:-}"

    log "============================================================"
    log " Перевірка сертифіката GLPI для GLPI Agent"
    log "============================================================"
    log "SERVER_URL : $SERVER_URL"
    log "HOST       : $HOST"
    log "PORT       : $PORT"
    log "CERT_FILE  : $CERT_FILE"
    log "============================================================"

    tmp="$(mktemp /tmp/glpi-agent-remote-cert.XXXXXX)"
    trap 'rm -f "$tmp"' EXIT

    log "Отримую поточний сертифікат з $HOST:$PORT ..."
    fetch_certificate "$tmp"
    log ""
    log "Сертифікат на сервері:"
    certificate_info "$tmp"

    if [ ! -s "$CERT_FILE" ]; then
        log ""
        log "Локальний довірений сертифікат ще не створено: $CERT_FILE"
        log "Натисни 'Оновити сертифікат'."
        exit 2
    fi

    log ""
    log "Локальний довірений сертифікат:"
    certificate_info "$CERT_FILE"
    log ""

    if compare_remote_and_local_fingerprint "$tmp" "$CERT_FILE"; then
        log "Fingerprint локального і серверного сертифіката збігається."
    else
        log "Fingerprint відрізняється — натисни 'Оновити сертифікат'."
        exit 2
    fi

    log ""
    log "Перевіряю SSL-довіру через openssl s_client + ca-cert-file ..."
    if verify_server_with_ca_file "$CERT_FILE"; then
        log "Сертифікат проходить перевірку довіри через ca-cert-file."
    else
        log "Сертифікат локально є, але перевірка довіри не пройшла."
        exit 2
    fi
}

update_certificate() {
    parse_server_url "${1:-}"

    if [ ! -x "$AGENT" ]; then
        log "glpi-agent не встановлено: $AGENT"
        exit 1
    fi

    if [ ! -f "$CONFIG" ]; then
        log "Конфіг не знайдено: $CONFIG"
        exit 1
    fi

    ensure_cert_dir
    tmp_cert="$(mktemp /tmp/glpi-agent-cert.XXXXXX)"
    backup_config="$CONFIG.bak-$(date +%Y%m%d-%H%M%S)"

    log "============================================================"
    log " Оновлення сертифіката GLPI для GLPI Agent"
    log "============================================================"
    log "SERVER_URL : $SERVER_URL"
    log "HOST       : $HOST"
    log "PORT       : $PORT"
    log "CERT_FILE  : $CERT_FILE"
    log "CONFIG     : $CONFIG"
    log "============================================================"

    log "Отримую сертифікат з $HOST:$PORT ..."
    fetch_certificate "$tmp_cert"
    install -m 0644 -o root -g root "$tmp_cert" "$CERT_FILE"
    rm -f "$tmp_cert"

    log "Сертифікат збережено: $CERT_FILE"
    log ""
    log "Інформація про сертифікат:"
    certificate_info "$CERT_FILE"

    log ""
    log "Перевіряю SSL-довіру через openssl s_client + ca-cert-file ..."
    if ! verify_server_with_ca_file "$CERT_FILE"; then
        log ""
        log "Сертифікат отримано, але openssl не підтвердив довіру. Конфіг не змінюю."
        exit 1
    fi
    log "OpenSSL підтвердив довіру до сертифіката через ca-cert-file."

    cp -a "$CONFIG" "$backup_config"
    cleanup_config_backups
    rewrite_config_for_ca_cert "$SERVER_URL" "$CERT_FILE"

    log ""
    log "Конфіг оновлено: $CONFIG"
    log "Backup старого конфіга: $backup_config"
    log "Тепер GLPI Agent використовує ca-cert-file замість no-ssl-check/ssl-fingerprint."
    log "Повну інвентаризацію можна запустити окремо кнопкою 'Запустити інвентаризацію зараз'."

    restart_service_if_running
}

start_service() {
    need_systemctl
    systemctl daemon-reload || true
    systemctl reset-failed "$SERVICE" || true
    systemctl enable "$SERVICE"
    systemctl start "$SERVICE"
}

stop_service() {
    need_systemctl
    systemctl stop "$SERVICE" || true
    systemctl disable "$SERVICE" || true
}

restart_service_if_running() {
    need_systemctl
    if systemctl is-active --quiet "$SERVICE"; then
        systemctl restart "$SERVICE"
        log "Службу glpi-agent перезапущено."
    else
        log "Служба glpi-agent не запущена — зміни буде застосовано при наступному запуску."
    fi
}

case "${1:-}" in
    run-now)
        run_now
        ;;
    start)
        start_service
        ;;
    stop)
        stop_service
        ;;
    restart-if-running)
        restart_service_if_running
        ;;
    update-certificate)
        shift || true
        update_certificate "${1:-}"
        ;;
    check-certificate)
        shift || true
        check_certificate "${1:-}"
        ;;
    *)
        log "Usage: $0 {run-now|start|stop|restart-if-running|update-certificate|check-certificate}"
        exit 2
        ;;
esac
