<?php

/**
 * GLPI Agent ClearOS integration library.
 *
 * @category   apps
 * @package    glpi-agent
 * @subpackage libraries
 * @author     SnugLinux
 * @license    http://www.gnu.org/copyleft/lgpl.html GNU Lesser General Public License version 3 or later
 */

///////////////////////////////////////////////////////////////////////////////
// N A M E S P A C E
///////////////////////////////////////////////////////////////////////////////

namespace clearos\apps\glpi_agent;

///////////////////////////////////////////////////////////////////////////////
// B O O T S T R A P
///////////////////////////////////////////////////////////////////////////////

$bootstrap = getenv('CLEAROS_BOOTSTRAP') ? getenv('CLEAROS_BOOTSTRAP') : '/usr/clearos/framework/shared';
require_once $bootstrap . '/bootstrap.php';

///////////////////////////////////////////////////////////////////////////////
// T R A N S L A T I O N S
///////////////////////////////////////////////////////////////////////////////

clearos_load_language('base');
clearos_load_language('glpi_agent');

///////////////////////////////////////////////////////////////////////////////
// D E P E N D E N C I E S
///////////////////////////////////////////////////////////////////////////////

use \clearos\apps\base\Daemon as Daemon;
use \clearos\apps\base\Engine_Exception as Engine_Exception;
use \clearos\apps\base\File as File;
use \clearos\apps\base\Shell as Shell;
use \clearos\apps\base\Validation_Exception as Validation_Exception;

clearos_load_library('base/Daemon');
clearos_load_library('base/Engine_Exception');
clearos_load_library('base/File');
clearos_load_library('base/Shell');
clearos_load_library('base/Validation_Exception');

///////////////////////////////////////////////////////////////////////////////
// C L A S S
///////////////////////////////////////////////////////////////////////////////

class Glpi_Agent extends Daemon
{
    ///////////////////////////////////////////////////////////////////////////
    // C O N S T A N T S
    ///////////////////////////////////////////////////////////////////////////

    const DIR_CONFIG = '/etc/glpi-agent/conf.d';
    const FILE_AGENT_CONFIG = '/etc/glpi-agent/agent.cfg';
    const FILE_CONFIG = '/etc/glpi-agent/conf.d/glpi-agent.cfg';
    const FILE_OLD_CONFIG = '/etc/glpi-agent/conf.d/clearos.cfg';
    const FILE_LOG_DEFAULT = '/var/log/glpi-agent.log';
    const DIR_CERTS = '/etc/glpi-agent/certs';
    const SERVICE_UNIT = 'glpi-agent.service';
    const COMMAND_AGENT = '/usr/bin/glpi-agent';
    const COMMAND_HELPER = '/usr/sbin/clearos-glpi-agent-helper';
    const COMMAND_ADDITIONAL_OEM = '/usr/lib/glpi-agent/glpi-additional-oem';
    const FILE_ADDITIONAL_OEM_CONFIG = '/etc/glpi-agent/conf.d/20-additional-oem.cfg';
    const FILE_ADDITIONAL_OEM_JSON = '/run/glpi-agent/additional-content.json';
    const FILE_BAD_UUIDS = '/etc/glpi-additional-oem/bad-uuids.list';
    const FILE_BAD_VALUES = '/etc/glpi-additional-oem/bad-values.list';
    const CONFIG_BACKUP_KEEP = 2;

    ///////////////////////////////////////////////////////////////////////////
    // V A R I A B L E S
    ///////////////////////////////////////////////////////////////////////////

    protected $is_loaded = FALSE;
    protected $settings = array();

    ///////////////////////////////////////////////////////////////////////////
    // M E T H O D S
    ///////////////////////////////////////////////////////////////////////////

    /**
     * Constructor.
     */

    public function __construct()
    {
        clearos_profile(__METHOD__, __LINE__);

        parent::__construct('glpi-agent');
    }

    /**
     * Returns managed config file path.
     *
     * @return string path
     */

    public function get_config_file()
    {
        clearos_profile(__METHOD__, __LINE__);

        return self::FILE_CONFIG;
    }

    /**
     * Returns app settings.
     *
     * @return array settings
     * @throws Engine_Exception
     */

    public function get_settings()
    {
        clearos_profile(__METHOD__, __LINE__);

        if (! $this->is_loaded)
            $this->_load_settings();

        return $this->settings;
    }

    /**
     * Returns SSL mode options.
     *
     * @return array options
     */

    public function get_ssl_mode_options()
    {
        clearos_profile(__METHOD__, __LINE__);

        return array(
            'system' => lang('glpi_agent_ssl_mode_system'),
            'ca_cert' => lang('glpi_agent_ssl_mode_ca_cert'),
            'fingerprint' => lang('glpi_agent_ssl_mode_fingerprint'),
            'no_ssl_check' => lang('glpi_agent_ssl_mode_no_ssl_check'),
        );
    }

    /**
     * Returns logger backend options.
     *
     * @return array options
     */

    public function get_logger_options()
    {
        clearos_profile(__METHOD__, __LINE__);

        return array(
            'syslog' => lang('glpi_agent_logger_syslog'),
            'file' => lang('glpi_agent_logger_file'),
            'stderr' => lang('glpi_agent_logger_stderr'),
        );
    }

    /**
     * Returns debug level options.
     *
     * @return array options
     */

    public function get_debug_options()
    {
        clearos_profile(__METHOD__, __LINE__);

        return array(
            '0' => lang('base_disabled'),
            '1' => lang('glpi_agent_debug_level_1'),
            '2' => lang('glpi_agent_debug_level_2'),
        );
    }

    /**
     * Returns TRUE when agent.cfg includes conf.d.
     *
     * @return bool status
     */

    public function is_conf_d_included()
    {
        clearos_profile(__METHOD__, __LINE__);

        if (! file_exists(self::FILE_AGENT_CONFIG))
            return FALSE;

        $lines = @file(self::FILE_AGENT_CONFIG, FILE_IGNORE_NEW_LINES);
        if (! is_array($lines))
            return FALSE;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^#/', $line))
                continue;

            if (preg_match('/^include\s+["\']?conf\.d\/?["\']?/i', $line))
                return TRUE;
        }

        return FALSE;
    }

    /**
     * Returns config warnings for summary page.
     *
     * @return array warnings
     */

    public function get_config_warnings()
    {
        clearos_profile(__METHOD__, __LINE__);

        $warnings = array();

        // Do not show the normal conf.d include status in the UI. Only warn
        // when the managed file would not be loaded by the agent.
        if (! $this->is_conf_d_included())
            $warnings[] = lang('glpi_agent_warning_conf_d_disabled');

        if (file_exists(self::FILE_OLD_CONFIG))
            $warnings[] = sprintf(lang('glpi_agent_warning_old_config'), self::FILE_OLD_CONFIG);

        return $warnings;
    }

    /**
     * Saves settings and writes glpi-agent config.
     *
     * @param array $settings settings
     *
     * @return void
     * @throws Engine_Exception
     * @throws Validation_Exception
     */

    public function set_settings($settings)
    {
        clearos_profile(__METHOD__, __LINE__);

        $normalized = $this->_normalize_settings($settings);
        $this->_validate_settings($normalized);

        if ($normalized['SSL_MODE'] === 'fingerprint' && $normalized['SSL_FINGERPRINT'] === '')
            $normalized['SSL_FINGERPRINT'] = $this->get_server_ssl_fingerprint($normalized['SERVER']);

        if ($normalized['SSL_MODE'] === 'ca_cert' && $normalized['CA_CERT_FILE'] === '')
            $normalized['CA_CERT_FILE'] = $this->_get_default_ca_cert_file($normalized['SERVER']);

        $additional_oem_was_enabled = $this->is_additional_oem_enabled();
        $additional_oem_should_be_enabled = ($normalized['ADDITIONAL_OEM_ENABLED'] === '1');

        $this->_write_config($normalized);

        if ($additional_oem_was_enabled !== $additional_oem_should_be_enabled)
            $this->_set_additional_oem_enabled($additional_oem_should_be_enabled);

        $normalized['ADDITIONAL_OEM_ENABLED'] = $this->is_additional_oem_enabled() ? '1' : '0';
        $this->settings = $normalized;
        $this->is_loaded = TRUE;
    }

    /**
     * Returns service status summary.
     *
     * @return array summary
     */

    public function get_service_summary()
    {
        clearos_profile(__METHOD__, __LINE__);

        $active = $this->_is_systemd_state(self::SERVICE_UNIT, 'is-active');
        $enabled = $this->_is_systemd_state(self::SERVICE_UNIT, 'is-enabled');

        return array(
            'unit' => self::SERVICE_UNIT,
            'active' => $active,
            'enabled' => $enabled,
            'active_label' => $active ? lang('base_running') : lang('base_stopped'),
            'enabled_label' => $enabled ? lang('base_enabled') : lang('base_disabled'),
        );
    }

    /**
     * Returns daemon.js.php compatible daemon status.
     *
     * @return string ClearOS daemon status: running, stopped, starting, stopping or dead
     */

    public function get_daemon_status()
    {
        clearos_profile(__METHOD__, __LINE__);

        $result = $this->_systemctl('is-active', self::SERVICE_UNIT, TRUE);
        $status = trim(implode("\n", $result['output']));

        if ($status === 'active')
            return Daemon::STATUS_RUNNING;
        if ($status === 'activating')
            return Daemon::STATUS_STARTING;
        if ($status === 'deactivating')
            return Daemon::STATUS_STOPPING;
        if ($status === 'failed')
            return Daemon::STATUS_DEAD;

        return Daemon::STATUS_STOPPED;
    }

    /**
     * Starts and enables the service from the standard ClearOS daemon widget.
     *
     * @return void
     * @throws Engine_Exception
     */

    public function start_and_enable_service()
    {
        clearos_profile(__METHOD__, __LINE__);

        $this->_run_helper('start', FALSE);
    }

    /**
     * Stops and disables the service from the standard ClearOS daemon widget.
     *
     * @return void
     * @throws Engine_Exception
     */

    public function stop_and_disable_service()
    {
        clearos_profile(__METHOD__, __LINE__);

        $this->_run_helper('stop', FALSE);
    }

    /**
     * Returns installed GLPI Agent version.
     *
     * @return string version/output
     */

    public function get_agent_version()
    {
        clearos_profile(__METHOD__, __LINE__);

        if (! is_executable(self::COMMAND_AGENT))
            return lang('glpi_agent_not_installed');

        // Keep stderr out here: some older GLPI Agent builds can print Perl
        // warnings before the real version line. Do not use PERL5OPT here:
        // not all ClearOS/EL7 Perl builds support the same warnings categories.
        $output = array();
        $exit_code = 0;
        exec('/usr/bin/timeout 20 ' . escapeshellarg(self::COMMAND_AGENT) . ' --version 2>/dev/null', $output, $exit_code);
        $result = trim(implode("\n", $output));

        if ($result === '')
            return '-';

        // Typical output: GLPI Agent (1.17)
        if (preg_match('/GLPI\s+Agent\s*\(([^)]+)\)/i', $result, $matches))
            return trim($matches[1]);

        // Fallbacks for alternate packaging/version formats.
        if (preg_match('/\bGLPI\s+Agent\s+v?([0-9]+(?:\.[0-9]+)+(?:[-._A-Za-z0-9]*)?)\b/i', $result, $matches))
            return trim($matches[1]);

        if (preg_match('/\b([0-9]+(?:\.[0-9]+)+(?:[-._A-Za-z0-9]*)?)\b/', $result, $matches))
            return trim($matches[1]);

        return strtok($result, "\n");
    }

    /**
     * Returns log tail.
     *
     * @param int $lines number of lines
     *
     * @return string log tail
     */

    public function get_log_tail($lines = 80)
    {
        clearos_profile(__METHOD__, __LINE__);

        $settings = $this->get_settings();
        if (! isset($settings['LOGGER']) || $settings['LOGGER'] !== 'file')
            return '';

        $logfile = isset($settings['LOGFILE']) && $settings['LOGFILE'] !== '' ? $settings['LOGFILE'] : self::FILE_LOG_DEFAULT;

        if (! preg_match('#^/[-A-Za-z0-9_./]+$#', $logfile))
            return '';

        if (! file_exists($logfile) || ! is_readable($logfile))
            return '';

        $lines = intval($lines);
        if ($lines < 1)
            $lines = 80;
        if ($lines > 300)
            $lines = 300;

        return $this->_run_command('/usr/bin/tail -n ' . intval($lines) . ' ' . escapeshellarg($logfile), TRUE);
    }

    /**
     * Enables the service.
     *
     * @return void
     * @throws Engine_Exception
     */

    public function enable_service()
    {
        clearos_profile(__METHOD__, __LINE__);

        $this->_systemctl('enable', self::SERVICE_UNIT, FALSE);
    }

    /**
     * Disables the service.
     *
     * @return void
     * @throws Engine_Exception
     */

    public function disable_service()
    {
        clearos_profile(__METHOD__, __LINE__);

        $this->_systemctl('disable', self::SERVICE_UNIT, FALSE);
    }

    /**
     * Starts the service.
     *
     * @return void
     * @throws Engine_Exception
     */

    public function start_service()
    {
        clearos_profile(__METHOD__, __LINE__);

        $this->_systemctl('start', self::SERVICE_UNIT, FALSE);
    }

    /**
     * Stops the service.
     *
     * @return void
     * @throws Engine_Exception
     */

    public function stop_service()
    {
        clearos_profile(__METHOD__, __LINE__);

        $this->_systemctl('stop', self::SERVICE_UNIT, FALSE);
    }

    /**
     * Restarts the service.
     *
     * @return void
     * @throws Engine_Exception
     */

    public function restart_service()
    {
        clearos_profile(__METHOD__, __LINE__);

        $this->_systemctl('restart', self::SERVICE_UNIT, FALSE);
    }

    /**
     * Runs inventory immediately.
     *
     * @return string command output
     * @throws Engine_Exception
     */

    public function run_now()
    {
        clearos_profile(__METHOD__, __LINE__);

        if (! is_executable(self::COMMAND_AGENT))
            throw new Engine_Exception(lang('glpi_agent_not_installed'), CLEAROS_ERROR);

        // Run through a small sudo helper, like the app-zabbix2 pattern for
        // privileged Webconfig operations. Running glpi-agent directly from
        // Webconfig can fail because the agent needs write access to
        // /var/lib/glpi-agent.
        $result = $this->_run_helper('run-now', FALSE);

        return trim(implode("\n", $result['output']));
    }

    /**
     * Updates the trusted GLPI server certificate and tests inventory.
     *
     * @param string $server optional server URL
     *
     * @return array command output and exit code
     * @throws Engine_Exception
     */

    public function update_certificate($server = '')
    {
        clearos_profile(__METHOD__, __LINE__);

        if ($server === '') {
            $settings = $this->get_settings();
            $server = $settings['SERVER'];
        }

        $result = $this->_run_helper('update-certificate', TRUE, array($server));
        $this->is_loaded = FALSE;

        return array(
            'exit_code' => isset($result['exit_code']) ? $result['exit_code'] : 1,
            'output' => trim(implode("\n", $result['output']))
        );
    }

    /**
     * Checks the trusted GLPI server certificate without changing config.
     *
     * @param string $server optional server URL
     *
     * @return array command output and exit code
     * @throws Engine_Exception
     */

    public function check_certificate($server = '')
    {
        clearos_profile(__METHOD__, __LINE__);

        if ($server === '') {
            $settings = $this->get_settings();
            $server = $settings['SERVER'];
        }

        $result = $this->_run_helper('check-certificate', TRUE, array($server));

        return array(
            'exit_code' => isset($result['exit_code']) ? $result['exit_code'] : 1,
            'output' => trim(implode("\n", $result['output']))
        );
    }

    /**
     * Returns information about configured CA certificate file.
     *
     * @return array certificate information
     */

    public function get_certificate_info()
    {
        clearos_profile(__METHOD__, __LINE__);

        $settings = $this->get_settings();
        $file = isset($settings['CA_CERT_FILE']) ? $settings['CA_CERT_FILE'] : '';

        $info = array(
            'file' => $file,
            'exists' => FALSE,
            'summary' => '',
            'fingerprint' => '',
            'not_after' => '',
            'san' => '',
        );

        if ($file === '')
            return $info;

        if (! preg_match('#^/[-A-Za-z0-9_./]+$#', $file))
            return $info;

        if (! file_exists($file) || ! is_readable($file))
            return $info;

        $info['exists'] = TRUE;
        $output = $this->_run_command('/usr/bin/openssl x509 -in ' . escapeshellarg($file) . ' -noout -subject -issuer -dates -fingerprint -sha256 -ext subjectAltName', TRUE);
        $info['summary'] = trim($output);

        if (preg_match('/sha256[[:space:]]+Fingerprint=([^\n]+)/i', $output, $matches))
            $info['fingerprint'] = trim($matches[1]);
        if (preg_match('/notAfter=([^\n]+)/', $output, $matches))
            $info['not_after'] = trim($matches[1]);

        // OpenSSL prints SAN on the next line, for example:
        //   X509v3 Subject Alternative Name:
        //       DNS:glpi.lan
        // Be tolerant of upper/lowercase output and optional spaces.
        if (preg_match_all('/DNS[[:space:]]*:[[:space:]]*([^,[:space:]]+)/i', $output, $matches) && ! empty($matches[1])) {
            $sans = array();
            foreach ($matches[1] as $san)
                $sans[] = 'DNS:' . trim($san);
            $info['san'] = implode(', ', array_unique($sans));
        }

        return $info;
    }

    /**
     * Returns TRUE when OEM additional-content is enabled.
     *
     * @return boolean enabled
     */

    public function is_additional_oem_enabled()
    {
        clearos_profile(__METHOD__, __LINE__);

        return $this->_is_additional_oem_enabled();
    }

    /**
     * Returns DMI/OEM additional-content status.
     *
     * @return array status
     */

    public function get_additional_oem_status()
    {
        clearos_profile(__METHOD__, __LINE__);

        $dmi_fields = array(
            'sys_vendor',
            'product_name',
            'product_serial',
            'product_uuid',
            'board_vendor',
            'board_name',
            'board_serial',
        );

        $preview = $this->_get_additional_oem_preview();

        $dmi = array();
        foreach ($dmi_fields as $field) {
            $value = '';
            if (isset($preview['dmi'][$field]) && trim((string) $preview['dmi'][$field]) !== '')
                $value = trim((string) $preview['dmi'][$field]);
            else
                $value = $this->_read_dmi($field);

            $bad = ($field === 'product_uuid') ? $this->_is_bad_uuid($value) : $this->_is_bad_oem_value($field, $value);
            $dmi[$field] = array(
                'value' => $value,
                'bad' => $bad,
            );
        }

        if (isset($preview['primary_mac']) && trim((string) $preview['primary_mac']) !== '')
            $primary_mac = trim((string) $preview['primary_mac']);
        else
            $primary_mac = $this->_get_primary_physical_mac();

        $primary_mac_bad = $this->_is_bad_mac($primary_mac);
        $serial_bad = $dmi['product_serial']['bad'] && $dmi['board_serial']['bad'];
        $uuid_bad = $dmi['product_uuid']['bad'];
        $suspicious = ($serial_bad && $uuid_bad);
        $enabled = $this->_is_additional_oem_enabled();

        $json = $this->_read_additional_oem_json_values();
        if (is_array($preview) && isset($preview['json']) && is_array($preview['json'])) {
            foreach (array('ssn', 'msn', 'uuid') as $key) {
                if (empty($json[$key]) && ! empty($preview['json'][$key]))
                    $json[$key] = $preview['json'][$key];
            }
        }

        $serial_number = $this->_get_glpi_serial_number($enabled, $dmi, $json, $primary_mac);

        $recommendation = 'ok';
        if ($suspicious && ! $enabled)
            $recommendation = 'enable';
        else if (! $suspicious && $enabled)
            $recommendation = 'review_disable';

        return array(
            'installed' => is_executable(self::COMMAND_ADDITIONAL_OEM),
            'enabled' => $enabled,
            'config_file' => self::FILE_ADDITIONAL_OEM_CONFIG,
            'json_file' => self::FILE_ADDITIONAL_OEM_JSON,
            'json_exists' => file_exists(self::FILE_ADDITIONAL_OEM_JSON),
            'serial_number' => $serial_number,
            'json' => $json,
            'primary_mac' => $primary_mac,
            'primary_mac_bad' => $primary_mac_bad,
            'dmi' => $dmi,
            'suspicious' => $suspicious,
            'recommendation' => $recommendation,
        );
    }

    /**
     * Gets SHA256 SSL fingerprint from server URL.
     *
     * @param string $url server URL
     *
     * @return string fingerprint
     * @throws Engine_Exception
     */

    public function get_server_ssl_fingerprint($url)
    {
        clearos_profile(__METHOD__, __LINE__);

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']))
            throw new Engine_Exception(lang('glpi_agent_invalid_server_url'), CLEAROS_ERROR);

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'https';
        if ($scheme !== 'https')
            return '';

        $host = $parts['host'];
        $port = isset($parts['port']) ? intval($parts['port']) : 443;

        if (! preg_match('/^[A-Za-z0-9_.-]+$/', $host) || $port < 1 || $port > 65535)
            throw new Engine_Exception(lang('glpi_agent_invalid_server_url'), CLEAROS_ERROR);

        // Keep the same value format as the old shell installer:
        //   SHA256 Fingerprint=AA:BB:CC -> sha256$aabbcc
        // Add -servername for SNI hosts, but keep the same openssl/sed-compatible idea.
        $command = 'echo | /usr/bin/timeout 20 /usr/bin/openssl s_client -servername ' . escapeshellarg($host) .
            ' -connect ' . escapeshellarg($host . ':' . $port) . ' 2>/dev/null | /usr/bin/openssl x509 -fingerprint -sha256 -noout 2>/dev/null';

        $output = $this->_run_command($command, TRUE);

        if (preg_match('/^([A-Za-z0-9]+)\s+Fingerprint=([0-9A-Fa-f:]+)/m', $output, $matches)) {
            $algorithm = strtolower($matches[1]);
            $hash = strtolower(str_replace(':', '', $matches[2]));

            if ($algorithm === '')
                $algorithm = 'sha256';

            if ($algorithm === 'sha256' && preg_match('/^[0-9a-f]{64}$/', $hash))
                return 'sha256$' . $hash;
        }

        if (preg_match('/Fingerprint=([0-9A-Fa-f:]+)/', $output, $matches)) {
            $hash = strtolower(str_replace(':', '', $matches[1]));
            if (preg_match('/^[0-9a-f]{64}$/', $hash))
                return 'sha256$' . $hash;
        }

        throw new Engine_Exception(lang('glpi_agent_fingerprint_failed'), CLEAROS_ERROR);
    }

    /**
     * Normalizes an SSL fingerprint value for GLPI Agent.
     *
     * @param string $fingerprint fingerprint
     *
     * @return string normalized fingerprint
     */

    protected function _normalize_ssl_fingerprint($fingerprint)
    {
        $fingerprint = strtolower(trim((string) $fingerprint));
        if ($fingerprint === '')
            return '';

        $fingerprint = str_replace(':', '', $fingerprint);

        if (preg_match('/^sha256\$([0-9a-f]{64})$/', $fingerprint, $matches))
            return 'sha256$' . $matches[1];

        if (preg_match('/^[0-9a-f]{64}$/', $fingerprint))
            return 'sha256$' . $fingerprint;

        return $fingerprint;
    }

    /**
     * Loads settings from managed config.
     *
     * @return void
     */

    protected function _load_settings()
    {
        clearos_profile(__METHOD__, __LINE__);

        $settings = $this->_get_default_settings();

        $source_file = self::FILE_CONFIG;
        if (! file_exists($source_file) && file_exists(self::FILE_OLD_CONFIG))
            $source_file = self::FILE_OLD_CONFIG;

        if (file_exists($source_file)) {
            $lines = @file($source_file, FILE_IGNORE_NEW_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || preg_match('/^#/', $line))
                        continue;
                    if (! preg_match('/^([A-Za-z0-9_-]+)\s*=\s*(.*)$/', $line, $matches))
                        continue;

                    $key = strtolower($matches[1]);
                    $value = trim($matches[2]);

                    if ($key === 'server')
                        $settings['SERVER'] = $value;
                    else if ($key === 'ssl-fingerprint') {
                        $settings['SSL_MODE'] = 'fingerprint';
                        $settings['SSL_FINGERPRINT'] = $value;
                    } else if ($key === 'no-ssl-check')
                        $settings['SSL_MODE'] = $this->_is_enabled_value($value) ? 'no_ssl_check' : $settings['SSL_MODE'];
                    else if ($key === 'ca-cert-file') {
                        $settings['SSL_MODE'] = 'ca_cert';
                        $settings['CA_CERT_FILE'] = $value;
                    }
                    else if ($key === 'no-httpd')
                        $settings['NO_HTTPD'] = $this->_is_enabled_value($value) ? '1' : '0';
                    else if ($key === 'httpd-ip')
                        $settings['HTTPD_IP'] = $value;
                    else if ($key === 'httpd-port')
                        $settings['HTTPD_PORT'] = $value;
                    else if ($key === 'httpd-trust')
                        $settings['HTTPD_TRUST'] = $value;
                    else if ($key === 'logger')
                        $settings['LOGGER'] = strtolower($value);
                    else if ($key === 'logfile')
                        $settings['LOGFILE'] = $value;
                    else if ($key === 'debug')
                        $settings['DEBUG'] = $value;
                    else if ($key === 'tag')
                        $settings['TAG'] = $value;
                }
            }
        }

        $this->settings = $this->_normalize_settings($settings);
        $this->settings['ADDITIONAL_OEM_ENABLED'] = $this->is_additional_oem_enabled() ? '1' : '0';
        $this->is_loaded = TRUE;
    }

    /**
     * Returns default settings.
     *
     * @return array defaults
     */

    protected function _get_default_settings()
    {
        return array(
            'SERVER' => 'https://glpi.lan/front/inventory.php',
            'SSL_MODE' => 'ca_cert',
            'SSL_FINGERPRINT' => '',
            'CA_CERT_FILE' => '/etc/glpi-agent/certs/glpi.lan.pem',
            'NO_HTTPD' => '1',
            'HTTPD_IP' => '127.0.0.1',
            'HTTPD_PORT' => '62354',
            'HTTPD_TRUST' => '',
            'LOGGER' => 'syslog',
            'LOGFILE' => self::FILE_LOG_DEFAULT,
            'DEBUG' => '0',
            'TAG' => '',
            'ADDITIONAL_OEM_ENABLED' => '0',
        );
    }

    /**
     * Normalizes settings.
     *
     * @param array $settings raw settings
     *
     * @return array normalized settings
     */

    protected function _normalize_settings($settings)
    {
        $defaults = $this->_get_default_settings();
        if (! is_array($settings))
            $settings = array();

        foreach ($defaults as $key => $value) {
            if (! isset($settings[$key]))
                $settings[$key] = $value;
            $settings[$key] = trim((string) $settings[$key]);
        }

        $ssl_modes = $this->get_ssl_mode_options();
        if (! isset($ssl_modes[$settings['SSL_MODE']]))
            $settings['SSL_MODE'] = 'ca_cert';

        $logger_options = $this->get_logger_options();
        $settings['LOGGER'] = strtolower($settings['LOGGER']);
        if (! isset($logger_options[$settings['LOGGER']]))
            $settings['LOGGER'] = 'syslog';

        if (! in_array($settings['DEBUG'], array('0', '1', '2'), TRUE))
            $settings['DEBUG'] = '0';

        $settings['SSL_FINGERPRINT'] = $this->_normalize_ssl_fingerprint($settings['SSL_FINGERPRINT']);

        if ($settings['CA_CERT_FILE'] === '')
            $settings['CA_CERT_FILE'] = $this->_get_default_ca_cert_file($settings['SERVER']);

        $settings['NO_HTTPD'] = $this->_is_enabled_value($settings['NO_HTTPD']) ? '1' : '0';
        $settings['ADDITIONAL_OEM_ENABLED'] = $this->_is_enabled_value($settings['ADDITIONAL_OEM_ENABLED']) ? '1' : '0';

        if ($settings['LOGFILE'] === '')
            $settings['LOGFILE'] = self::FILE_LOG_DEFAULT;
        if ($settings['HTTPD_IP'] === '')
            $settings['HTTPD_IP'] = '127.0.0.1';
        if ($settings['HTTPD_PORT'] === '')
            $settings['HTTPD_PORT'] = '62354';

        return $settings;
    }

    /**
     * Validates settings.
     *
     * @param array $settings settings
     *
     * @return void
     * @throws Validation_Exception
     */

    protected function _validate_settings($settings)
    {
        if ($settings['SERVER'] === '' || ! preg_match('#^https?://#i', $settings['SERVER']))
            throw new Validation_Exception(lang('glpi_agent_invalid_server_url'));

        if ($settings['SSL_MODE'] === 'fingerprint' && $settings['SSL_FINGERPRINT'] !== '' && ! preg_match('/^sha256\$[0-9a-f]{64}$/', $settings['SSL_FINGERPRINT']))
            throw new Validation_Exception(lang('glpi_agent_invalid_fingerprint'));

        if ($settings['LOGFILE'] !== '' && ! preg_match('#^/[-A-Za-z0-9_./]+$#', $settings['LOGFILE']))
            throw new Validation_Exception(lang('glpi_agent_invalid_logfile'));

        if ($settings['CA_CERT_FILE'] !== '' && ! preg_match('#^/[-A-Za-z0-9_./]+$#', $settings['CA_CERT_FILE']))
            throw new Validation_Exception(lang('glpi_agent_invalid_ca_cert_file'));

        if ($settings['TAG'] !== '' && ! preg_match('/^[A-Za-z0-9_.-]+$/', $settings['TAG']))
            throw new Validation_Exception(lang('glpi_agent_invalid_tag'));

        if ($settings['HTTPD_IP'] !== '' && ! preg_match('/^[A-Za-z0-9_.:-]+$/', $settings['HTTPD_IP']))
            throw new Validation_Exception(lang('glpi_agent_invalid_httpd_ip'));

        if ($settings['HTTPD_TRUST'] !== '' && ! preg_match('/^[A-Za-z0-9_.,:\/-]+$/', $settings['HTTPD_TRUST']))
            throw new Validation_Exception(lang('glpi_agent_invalid_httpd_trust'));

        $port = intval($settings['HTTPD_PORT']);
        if ($port < 1 || $port > 65535)
            throw new Validation_Exception(lang('glpi_agent_invalid_httpd_port'));
    }

    /**
     * Writes managed config file.
     *
     * @param array $settings settings
     *
     * @return void
     * @throws Engine_Exception
     */

    protected function _write_config($settings)
    {
        if (! is_dir(self::DIR_CONFIG))
            throw new Engine_Exception(lang('base_directory_not_found') . ': ' . self::DIR_CONFIG . '. Run /usr/clearos/apps/glpi_agent/deploy/install', CLEAROS_ERROR);

        $lines = array();
        $lines[] = '# Managed by ClearOS app-glpi-agent';
        $lines[] = '# Do not edit this file manually while using the Webconfig page.';
        $lines[] = '';
        $lines[] = 'server = ' . $settings['SERVER'];

        if ($settings['TAG'] !== '')
            $lines[] = 'tag = ' . $settings['TAG'];

        $lines[] = 'logger = ' . $settings['LOGGER'];
        if ($settings['LOGGER'] === 'file')
            $lines[] = 'logfile = ' . $settings['LOGFILE'];

        $lines[] = 'color = 0';
        $lines[] = 'debug = ' . $settings['DEBUG'];
        $lines[] = 'no-httpd = ' . (($settings['NO_HTTPD'] === '1') ? 'yes' : 'no');

        if ($settings['NO_HTTPD'] !== '1') {
            $lines[] = 'httpd-ip = ' . $settings['HTTPD_IP'];
            $lines[] = 'httpd-port = ' . $settings['HTTPD_PORT'];
            if ($settings['HTTPD_TRUST'] !== '')
                $lines[] = 'httpd-trust = ' . $settings['HTTPD_TRUST'];
        }

        if ($settings['SSL_MODE'] === 'ca_cert')
            $lines[] = 'ca-cert-file = ' . $settings['CA_CERT_FILE'];
        else if ($settings['SSL_MODE'] === 'fingerprint')
            $lines[] = 'ssl-fingerprint = ' . $settings['SSL_FINGERPRINT'];
        else if ($settings['SSL_MODE'] === 'no_ssl_check')
            $lines[] = 'no-ssl-check = 1';

        $contents = implode("\n", $lines) . "\n";

        try {
            if (file_exists(self::FILE_CONFIG)) {
                try {
                    $source = new File(self::FILE_CONFIG, TRUE);
                    $source->copy_to(self::FILE_CONFIG . '.bak-' . date('Ymd-His'));
                    $this->_cleanup_config_backups(self::FILE_CONFIG);
                } catch (\Exception $e) {
                    // Backup is helpful, but a backup failure should not block saving.
                }
            }

            $target = new File(self::FILE_CONFIG, TRUE);
            if (! $target->exists())
                $target->create('root', 'root', '0644');

            $tempfile = tempnam(defined('CLEAROS_TEMP_DIR') ? CLEAROS_TEMP_DIR : sys_get_temp_dir(), 'app-glpi-agent-');
            if ($tempfile === FALSE)
                throw new Engine_Exception(lang('base_file_write_error') . ': ' . self::FILE_CONFIG, CLEAROS_ERROR);

            if (file_put_contents($tempfile, $contents) === FALSE) {
                @unlink($tempfile);
                throw new Engine_Exception(lang('base_file_write_error') . ': ' . self::FILE_CONFIG, CLEAROS_ERROR);
            }

            $target->replace($tempfile);
            $target->chown('root', 'root');
            $target->chmod('0644');
        } catch (\Exception $e) {
            throw new Engine_Exception(lang('base_file_write_error') . ': ' . self::FILE_CONFIG, CLEAROS_ERROR);
        }

        if (file_exists(self::FILE_OLD_CONFIG)) {
            $old_backup = self::FILE_OLD_CONFIG . '.bak-' . date('Ymd-His');
            @rename(self::FILE_OLD_CONFIG, $old_backup);
            $this->_cleanup_config_backups(self::FILE_OLD_CONFIG);
        }

        $this->_cleanup_config_backups(self::FILE_CONFIG);
    }

    /**
     * Keeps only the last few configuration backups.
     *
     * @param string $base_file base config path without .bak-* suffix
     *
     * @return void
     */

    protected function _cleanup_config_backups($base_file = NULL)
    {
        clearos_profile(__METHOD__, __LINE__);

        if ($base_file === NULL)
            $base_file = self::FILE_CONFIG;

        $files = glob($base_file . '.bak-*');
        if (! is_array($files) || count($files) <= self::CONFIG_BACKUP_KEEP)
            return;

        usort($files, function($a, $b) {
            $mtime_a = @filemtime($a);
            $mtime_b = @filemtime($b);
            if ($mtime_a == $mtime_b)
                return strcmp($b, $a);
            return ($mtime_a < $mtime_b) ? 1 : -1;
        });

        $old_files = array_slice($files, self::CONFIG_BACKUP_KEEP);
        foreach ($old_files as $file)
            @unlink($file);
    }

    /**
     * Enables or disables glpi-additional-oem through the privileged helper.
     *
     * @param boolean $enabled enabled
     *
     * @return void
     */

    protected function _set_additional_oem_enabled($enabled)
    {
        $this->_run_helper($enabled ? 'additional-oem-enable' : 'additional-oem-disable', FALSE);
    }

    /**
     * Checks active OEM additional-content config line.
     *
     * @return boolean enabled
     */

    protected function _is_additional_oem_enabled()
    {
        if (! file_exists(self::FILE_ADDITIONAL_OEM_CONFIG))
            return FALSE;

        $lines = @file(self::FILE_ADDITIONAL_OEM_CONFIG, FILE_IGNORE_NEW_LINES);
        if (! is_array($lines))
            return FALSE;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^#/', $line))
                continue;
            if (preg_match('#^additional-content\s*=\s*' . preg_quote(self::FILE_ADDITIONAL_OEM_JSON, '#') . '\s*$#', $line))
                return TRUE;
        }

        return FALSE;
    }

    protected function _get_glpi_serial_number($additional_oem_enabled, $dmi, $json, $primary_mac = '')
    {
        if ($additional_oem_enabled) {
            if (is_array($json) && ! empty($json['ssn']))
                return $json['ssn'];

            return $this->_make_oem_serial_from_dmi($dmi, $primary_mac);
        }

        if (isset($dmi['product_serial']['value']) && empty($dmi['product_serial']['bad']))
            return trim((string) $dmi['product_serial']['value']);

        if (isset($dmi['board_serial']['value']) && empty($dmi['board_serial']['bad']))
            return trim((string) $dmi['board_serial']['value']);

        return '';
    }

    protected function _make_oem_serial_from_dmi($dmi, $primary_mac)
    {
        $primary_mac = strtoupper(trim((string) $primary_mac));
        if ($this->_is_bad_mac($primary_mac))
            return '';

        $mac = str_replace(':', '', $primary_mac);

        $vendor = isset($dmi['sys_vendor']['value']) ? trim((string) $dmi['sys_vendor']['value']) : '';
        if ($this->_is_bad_oem_value('sys_vendor', $vendor))
            $vendor = isset($dmi['board_vendor']['value']) ? trim((string) $dmi['board_vendor']['value']) : '';

        $board = isset($dmi['board_name']['value']) ? trim((string) $dmi['board_name']['value']) : '';

        if ($this->_is_bad_oem_value('sys_vendor', $vendor) || $this->_is_bad_oem_value('board_name', $board))
            return 'OEM-MAC-' . $mac;

        return 'OEM-' . $this->_vendor_alias($vendor) . '-' . $this->_sanitize_oem_token($board) . '-' . $mac;
    }

    protected function _vendor_alias($vendor)
    {
        $vendor_upper = strtoupper((string) $vendor);

        if (strpos($vendor_upper, 'GIGABYTE') !== FALSE)
            return 'GIGABYTE';
        if (strpos($vendor_upper, 'ASUSTEK') !== FALSE || strpos($vendor_upper, 'ASUS') !== FALSE)
            return 'ASUS';
        if (strpos($vendor_upper, 'MICRO-STAR') !== FALSE || strpos($vendor_upper, 'MSI') !== FALSE)
            return 'MSI';
        if (strpos($vendor_upper, 'HEWLETT') !== FALSE || strpos($vendor_upper, 'HP') !== FALSE)
            return 'HP';
        if (strpos($vendor_upper, 'LENOVO') !== FALSE)
            return 'LENOVO';
        if (strpos($vendor_upper, 'DELL') !== FALSE)
            return 'DELL';
        if (strpos($vendor_upper, 'ACER') !== FALSE)
            return 'ACER';

        return $this->_sanitize_oem_token($vendor);
    }

    protected function _sanitize_oem_token($value)
    {
        $value = strtoupper((string) $value);
        $value = preg_replace('/[^A-Z0-9]+/', '-', $value);
        $value = preg_replace('/-+/', '-', $value);
        $value = trim($value, '-');

        return $value;
    }

    protected function _read_additional_oem_json_values()
    {
        $values = array(
            'ssn' => '',
            'msn' => '',
            'uuid' => '',
        );

        if (! is_readable(self::FILE_ADDITIONAL_OEM_JSON))
            return $values;

        $raw = @file_get_contents(self::FILE_ADDITIONAL_OEM_JSON);
        if ($raw === FALSE || trim($raw) === '')
            return $values;

        $json = json_decode($raw, TRUE);
        if (! is_array($json) || empty($json['content']) || ! is_array($json['content']))
            return $values;

        if (! empty($json['content']['bios']) && is_array($json['content']['bios'])) {
            if (isset($json['content']['bios']['ssn']))
                $values['ssn'] = trim((string) $json['content']['bios']['ssn']);
            if (isset($json['content']['bios']['msn']))
                $values['msn'] = trim((string) $json['content']['bios']['msn']);
        }

        if (! empty($json['content']['hardware']) && is_array($json['content']['hardware'])) {
            if (isset($json['content']['hardware']['uuid']))
                $values['uuid'] = trim((string) $json['content']['hardware']['uuid']);
        }

        return $values;
    }


    protected function _get_additional_oem_preview()
    {
        $preview = array(
            'dmi' => array(),
            'primary_mac' => '',
            'identity_serial' => '',
            'json' => array(
                'ssn' => '',
                'msn' => '',
                'uuid' => '',
            ),
        );

        if (! is_file(self::COMMAND_HELPER) || ! is_executable(self::COMMAND_HELPER))
            return $preview;

        $result = $this->_run_helper('additional-oem-report', TRUE);
        if (! isset($result['output']) || ! is_array($result['output']))
            return $preview;

        $text = implode("
", $result['output']);
        foreach (explode("
", $text) as $line) {
            if (preg_match('/^(sys_vendor|product_name|product_serial|product_uuid|board_vendor|board_name|board_serial|primary_mac|identity_serial)\s*=\s*(.*)$/', trim($line), $matches)) {
                $key = $matches[1];
                $value = trim($matches[2]);
                if ($key === 'primary_mac' || $key === 'identity_serial')
                    $preview[$key] = $value;
                else
                    $preview['dmi'][$key] = $value;
            }
        }

        $json_start = strpos($text, '{');
        $json_end = strrpos($text, '}');
        if ($json_start !== FALSE && $json_end !== FALSE && $json_end > $json_start) {
            $raw_json = substr($text, $json_start, $json_end - $json_start + 1);
            $json = json_decode($raw_json, TRUE);
            if (is_array($json) && ! empty($json['content']) && is_array($json['content'])) {
                if (! empty($json['content']['bios']) && is_array($json['content']['bios'])) {
                    if (isset($json['content']['bios']['ssn']))
                        $preview['json']['ssn'] = trim((string) $json['content']['bios']['ssn']);
                    if (isset($json['content']['bios']['msn']))
                        $preview['json']['msn'] = trim((string) $json['content']['bios']['msn']);
                }
                if (! empty($json['content']['hardware']) && is_array($json['content']['hardware']) && isset($json['content']['hardware']['uuid']))
                    $preview['json']['uuid'] = trim((string) $json['content']['hardware']['uuid']);
            }
        }

        return $preview;
    }

    protected function _read_dmi($field)
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $field))
            return '';

        $path = '/sys/class/dmi/id/' . $field;
        if (! is_readable($path))
            return '';

        $value = @file_get_contents($path);
        if ($value === FALSE)
            return '';

        $value = str_replace("\0", '', $value);
        $value = trim($value);

        return $value;
    }

    protected function _get_primary_physical_mac()
    {
        $paths = glob('/sys/class/net/*');
        if (! is_array($paths))
            return '';

        sort($paths);
        $fallback = '';

        foreach ($paths as $path) {
            $iface = basename($path);
            if (preg_match('/^(lo|docker|br-|virbr|veth|tun|tap|wg|tailscale|zt|vmnet|vboxnet|cni|flannel|kube|podman|dummy|ifb)/', $iface))
                continue;
            if (! is_readable($path . '/address'))
                continue;

            $mac = strtoupper(trim((string) @file_get_contents($path . '/address')));
            if ($this->_is_bad_mac($mac))
                continue;

            if (file_exists($path . '/device'))
                return $mac;

            if ($fallback === '')
                $fallback = $mac;
        }

        return $fallback;
    }

    protected function _is_bad_mac($mac)
    {
        $mac = strtoupper(trim((string) $mac));

        if ($mac === '')
            return TRUE;
        if (! preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/', $mac))
            return TRUE;
        if ($mac === '00:00:00:00:00:00' || $mac === 'FF:FF:FF:FF:FF:FF')
            return TRUE;

        $first_octet = hexdec(substr($mac, 0, 2));
        if (($first_octet & 1) === 1)
            return TRUE;

        return FALSE;
    }

    protected function _is_bad_uuid($value)
    {
        $value = strtolower(trim((string) $value));
        if ($value === '')
            return TRUE;

        if ($this->_is_bad_oem_value('product_uuid', $value))
            return TRUE;

        if ($this->_bad_list_contains(self::FILE_BAD_UUIDS, 'product_uuid', $value))
            return TRUE;

        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value))
            return TRUE;

        return FALSE;
    }

    protected function _is_bad_oem_value($field, $value)
    {
        $value = trim((string) $value);
        if ($value === '')
            return TRUE;

        $lower = strtolower($value);
        $bad_values = array(
            'oem',
            'default string',
            'to be filled by o.e.m.',
            'to be filled by oem',
            'system serial number',
            'chassis serial number',
            'base board serial number',
            'none',
            'unknown',
            'not specified',
            'not available',
            'no asset information',
            'n/a',
            'na',
            'null',
            '00000000-0000-0000-0000-000000000000',
            'ffffffff-ffff-ffff-ffff-ffffffffffff',
        );

        if (in_array($lower, $bad_values, TRUE))
            return TRUE;
        if (preg_match('/^0+$/', $lower) || preg_match('/^f+$/', $lower) || preg_match('/^x+$/', $lower))
            return TRUE;

        return $this->_bad_list_contains(self::FILE_BAD_VALUES, $field, $value);
    }

    protected function _bad_list_contains($file, $field, $value)
    {
        if (! is_readable($file))
            return FALSE;

        $value = trim((string) $value);
        $field = strtolower(trim((string) $field));
        $lower = strtolower($value);
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if (! is_array($lines))
            return FALSE;

        foreach ($lines as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if ($line === '')
                continue;

            if (preg_match('/^([A-Za-z0-9_\-]+)=(.*)$/', $line, $matches)) {
                if (strtolower($matches[1]) !== $field)
                    continue;
                if (strtolower(trim($matches[2])) === $lower)
                    return TRUE;
                continue;
            }

            if (preg_match('/^([A-Za-z0-9_\-]+):\/(.*)\/$/', $line, $matches)) {
                if (strtolower($matches[1]) !== $field)
                    continue;
                if (@preg_match('/' . str_replace('/', '\/', $matches[2]) . '/i', $value))
                    return TRUE;
                continue;
            }

            if (preg_match('/^\/(.*)\/$/', $line, $matches)) {
                if (@preg_match('/' . str_replace('/', '\/', $matches[1]) . '/i', $value))
                    return TRUE;
                continue;
            }

            if (strtolower($line) === $lower)
                return TRUE;
        }

        return FALSE;
    }

    /**
     * Runs privileged helper through sudo.
     *
     * @param string  $action        helper action
     * @param boolean $ignore_errors ignore non-zero exit code
     *
     * @return array command result
     * @throws Engine_Exception
     */

    protected function _run_helper($action, $ignore_errors = FALSE, $args = array())
    {
        clearos_profile(__METHOD__, __LINE__);

        if (! preg_match('/^(additional-oem-enable|additional-oem-disable|additional-oem-status|additional-oem-report|run-now|start|stop|restart-if-running|update-certificate|check-certificate)$/', $action))
            throw new Engine_Exception('Invalid helper action', CLEAROS_ERROR);

        if (! is_file(self::COMMAND_HELPER) || ! is_executable(self::COMMAND_HELPER))
            throw new Engine_Exception(self::COMMAND_HELPER . ' not found or not executable. Run /usr/clearos/apps/glpi_agent/deploy/install', CLEAROS_ERROR);

        // Use the same practical pattern as app-zabbix-agent2: execute the
        // fixed privileged helper via sudo from PHP. Do not use ClearOS Shell
        // here: on Webconfig it can still trigger sudo askpass/tty handling even
        // when a NOPASSWD helper rule exists.
        $sudo = is_executable('/usr/bin/sudo') ? '/usr/bin/sudo' : '/bin/sudo';
        if (! is_executable($sudo))
            $sudo = 'sudo';

        $cmd = escapeshellcmd($sudo) . ' -n ' . escapeshellarg(self::COMMAND_HELPER) . ' ' . escapeshellarg($action);
        if (is_array($args)) {
            foreach ($args as $arg)
                $cmd .= ' ' . escapeshellarg($arg);
        }
        $cmd .= ' 2>&1';

        $output = array();
        $exit_code = 0;
        exec($cmd, $output, $exit_code);

        $result = array(
            'exit_code' => $exit_code,
            'output' => $output,
            'cmd' => $cmd,
        );

        if (! $ignore_errors && $exit_code !== 0)
            throw new Engine_Exception($this->_format_command_error($result), CLEAROS_ERROR);

        return $result;
    }

    /**
     * Runs systemctl action for fixed GLPI unit.
     *
     * @param string $action action
     *
     * @return void
     * @throws Engine_Exception
     */

    protected function _systemctl($action, $unit = '', $ignore_errors = FALSE)
    {
        if (! preg_match('/^(daemon-reload|reset-failed|is-active|is-enabled|enable|disable|start|stop|restart|status)$/', $action))
            throw new Engine_Exception('Invalid systemctl action', CLEAROS_ERROR);

        if ($unit !== '' && ! preg_match('/^[A-Za-z0-9_.@\\-]+$/', $unit))
            throw new Engine_Exception('Invalid systemd unit', CLEAROS_ERROR);

        $args = $action;
        if ($unit !== '')
            $args .= ' ' . $unit;

        return $this->_run_shell($this->_get_systemctl_command(), $args, $ignore_errors);
    }

    /**
     * Checks systemd state.
     *
     * @param string $unit unit name
     * @param string $mode is-active or is-enabled
     *
     * @return bool state
     */

    protected function _is_systemd_state($unit, $mode)
    {
        if (! preg_match('/^(is-active|is-enabled)$/', $mode))
            return FALSE;

        $result = $this->_run_shell($this->_get_systemctl_command(), $mode . ' --quiet ' . $unit, TRUE);
        return ($result['exit_code'] === 0);
    }

    /**
     * Returns systemctl command path.
     *
     * @return string command
     */

    protected function _get_systemctl_command()
    {
        if (file_exists('/usr/bin/systemctl'))
            return '/usr/bin/systemctl';

        return '/bin/systemctl';
    }

    /**
     * Runs a command through ClearOS Shell.
     *
     * @param string  $command       command path
     * @param string  $args          command arguments
     * @param boolean $ignore_errors ignore non-zero exit code
     *
     * @return array command result
     * @throws Engine_Exception
     */

    protected function _run_shell($command, $args = '', $ignore_errors = FALSE)
    {
        clearos_profile(__METHOD__, __LINE__);

        if (! file_exists($command)) {
            $result = array(
                'exit_code' => 127,
                'output' => array($command . ' not found'),
            );

            if (! $ignore_errors)
                throw new Engine_Exception($this->_format_command_error($result), CLEAROS_ERROR);

            return $result;
        }

        $shell = new Shell();
        $options = array('validate_exit_code' => FALSE);
        $exit_code = $shell->execute($command, $args, TRUE, $options);
        $output = $shell->get_output();

        if (! is_array($output))
            $output = array($output);

        $result = array(
            'exit_code' => $exit_code,
            'output' => $output,
        );

        if (! $ignore_errors && $exit_code !== 0)
            throw new Engine_Exception($this->_format_command_error($result), CLEAROS_ERROR);

        return $result;
    }

    /**
     * Formats command result for an exception.
     *
     * @param array $result command result
     *
     * @return string text
     */

    protected function _format_command_error($result)
    {
        $output = isset($result['output']) && is_array($result['output']) ? $result['output'] : array();
        $text = trim(implode("\n", $output));

        if ($text === '')
            $text = 'exit=' . (isset($result['exit_code']) ? $result['exit_code'] : 'unknown');

        return $text;
    }

    /**
     * Runs shell command.
     *
     * @param string $command command line
     * @param bool $ignore_errors ignore exit code
     *
     * @return string output
     * @throws Engine_Exception
     */

    protected function _run_command($command, $ignore_errors = FALSE)
    {
        $output = array();
        $exit_code = 0;
        exec($command . ' 2>&1', $output, $exit_code);
        $result = trim(implode("\n", $output));

        if (! $ignore_errors && $exit_code !== 0)
            throw new Engine_Exception(($result === '' ? $command : $result), CLEAROS_ERROR);

        return $result;
    }

    /**
     * Returns default local CA certificate file for a server URL.
     *
     * @param string $server server URL
     *
     * @return string path
     */

    protected function _get_default_ca_cert_file($server)
    {
        $parts = parse_url($server);
        $host = (is_array($parts) && ! empty($parts['host'])) ? $parts['host'] : 'glpi.lan';
        $host = preg_replace('/[^A-Za-z0-9_.-]/', '_', $host);
        if ($host === '')
            $host = 'glpi.lan';

        return self::DIR_CERTS . '/' . $host . '.pem';
    }

    /**
     * Converts yes/no-like values.
     *
     * @param string $value value
     *
     * @return bool enabled
     */

    protected function _is_enabled_value($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, array('1', 'yes', 'true', 'on', 'enabled'), TRUE);
    }
}
