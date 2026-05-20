<?php

/**
 * GLPI Agent summary view.
 *
 * @category   apps
 * @package    glpi-agent
 * @subpackage views
 * @author     SnugLinux
 * @license    http://www.gnu.org/copyleft/gpl.html GNU General Public License version 3 or later
 */

$this->lang->load('base');
$this->lang->load('glpi_agent');

if (! isset($settings))
    $settings = array();
if (! isset($ssl_mode_options))
    $ssl_mode_options = array('fingerprint' => 'Fingerprint');
if (! isset($logger_options))
    $logger_options = array('syslog' => 'syslog');
if (! isset($debug_options))
    $debug_options = array('0' => '0');
if (! isset($agent_version))
    $agent_version = '-';
if (! isset($config_warnings))
    $config_warnings = array();
if (! isset($run_now_output))
    $run_now_output = '';
if (! isset($run_now_status))
    $run_now_status = '';
if (! isset($certificate_output))
    $certificate_output = '';
if (! isset($certificate_status))
    $certificate_status = '';
if (! isset($certificate_info))
    $certificate_info = array();

if (! function_exists('glpi_agent_view_escape')) {
    function glpi_agent_view_escape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('glpi_agent_view_dash')) {
    function glpi_agent_view_dash($value)
    {
        $value = trim((string) $value);
        return ($value === '') ? '-' : glpi_agent_view_escape($value);
    }
}

$server = isset($settings['SERVER']) ? $settings['SERVER'] : 'https://glpi.lan/front/inventory.php';
$ssl_mode = isset($settings['SSL_MODE']) ? $settings['SSL_MODE'] : 'fingerprint';
$no_httpd = isset($settings['NO_HTTPD']) && $settings['NO_HTTPD'] === '1';
$httpd_enabled = ! $no_httpd;
$httpd_ip = isset($settings['HTTPD_IP']) ? $settings['HTTPD_IP'] : '127.0.0.1';
$httpd_port = isset($settings['HTTPD_PORT']) ? $settings['HTTPD_PORT'] : '62354';
$httpd_trust = isset($settings['HTTPD_TRUST']) ? $settings['HTTPD_TRUST'] : '';
$logger = isset($settings['LOGGER']) ? $settings['LOGGER'] : 'syslog';
$logfile = isset($settings['LOGFILE']) ? $settings['LOGFILE'] : '/var/log/glpi-agent.log';
$debug = isset($settings['DEBUG']) ? $settings['DEBUG'] : '0';
$tag = isset($settings['TAG']) ? $settings['TAG'] : '';
$ca_cert_file = isset($settings['CA_CERT_FILE']) ? $settings['CA_CERT_FILE'] : '';

$ssl_mode_display = isset($ssl_mode_options[$ssl_mode]) ? $ssl_mode_options[$ssl_mode] : $ssl_mode;
$logger_display = isset($logger_options[$logger]) ? $logger_options[$logger] : $logger;
$debug_display = isset($debug_options[$debug]) ? $debug_options[$debug] : $debug;

///////////////////////////////////////////////////////////////////////////////
// Standard ClearOS daemon sidebar integration
///////////////////////////////////////////////////////////////////////////////

echo "<input id='os_app_name' value='glpi_agent' type='hidden'>\n";
echo "<input id='os_daemon_name' value='glpi-agent' type='hidden'>\n";
echo "<input id='os_daemon_status_lock' value='off' type='hidden'>\n";

///////////////////////////////////////////////////////////////////////////////
// Information and warnings
///////////////////////////////////////////////////////////////////////////////

echo infobox_highlight(
    lang('base_information'),
    lang('glpi_agent_help')
);

if (is_array($config_warnings) && count($config_warnings) > 0) {
    $warning_text = '';
    foreach ($config_warnings as $warning)
        $warning_text .= glpi_agent_view_escape($warning) . '<br>';
    echo infobox_warning(lang('base_warning'), $warning_text);
}

if ($run_now_status === 'success') {
    $output_text = trim((string) $run_now_output);
    if ($output_text === '')
        $output_text = lang('glpi_agent_inventory_no_output');

    echo infobox_highlight(
        lang('glpi_agent_inventory_result'),
        '<pre style="white-space: pre-wrap; word-break: break-word; margin: 0;">' . glpi_agent_view_escape($output_text) . '</pre>'
    );
}

if ($certificate_status === 'success' || $certificate_status === 'warning') {
    $output_text = trim((string) $certificate_output);
    if ($output_text !== '') {
        $box_html = '<pre style="white-space: pre-wrap; word-break: break-word; margin: 0;">' . glpi_agent_view_escape($output_text) . '</pre>';
        if ($certificate_status === 'success')
            echo infobox_highlight(lang('glpi_agent_certificate_result'), $box_html);
        else
            echo infobox_warning(lang('glpi_agent_certificate_result'), $box_html);
    }
}

///////////////////////////////////////////////////////////////////////////////
// Settings: view mode, like app-nut summary
///////////////////////////////////////////////////////////////////////////////

echo form_open('glpi_agent/settings/edit');
echo form_header(lang('base_settings'));

echo field_view(lang('glpi_agent_version'), glpi_agent_view_dash($agent_version));
echo field_view(lang('glpi_agent_server_url'), glpi_agent_view_dash($server));
echo field_view(lang('glpi_agent_ssl_mode'), glpi_agent_view_dash($ssl_mode_display));

if ($ssl_mode === 'ca_cert') {
    echo field_view(lang('glpi_agent_ca_cert_file'), glpi_agent_view_dash($ca_cert_file));
    if (is_array($certificate_info) && isset($certificate_info['exists']) && $certificate_info['exists']) {
        $cert_summary = '';
        if (! empty($certificate_info['not_after']))
            $cert_summary .= glpi_agent_view_escape(lang('glpi_agent_certificate_valid_until') . ': ' . $certificate_info['not_after']) . '<br>';
        if (! empty($certificate_info['fingerprint']))
            $cert_summary .= glpi_agent_view_escape('SHA256: ' . $certificate_info['fingerprint']) . '<br>';
        if (! empty($certificate_info['san']))
            $cert_summary .= glpi_agent_view_escape('SAN: ' . $certificate_info['san']);
        else
            $cert_summary .= glpi_agent_view_escape(lang('glpi_agent_certificate_san_missing'));
        echo field_view(lang('glpi_agent_certificate_status'), $cert_summary);
    } else {
        echo field_view(lang('glpi_agent_certificate_status'), glpi_agent_view_escape(lang('glpi_agent_certificate_missing')));
    }
}
echo field_view(lang('glpi_agent_tag'), glpi_agent_view_dash($tag));
echo field_view(lang('glpi_agent_httpd_enabled'), $httpd_enabled ? lang('base_enabled') : lang('base_disabled'));

if ($httpd_enabled) {
    echo field_view(lang('glpi_agent_httpd_ip'), glpi_agent_view_dash($httpd_ip));
    echo field_view(lang('glpi_agent_httpd_port'), glpi_agent_view_dash($httpd_port));
    echo field_view(lang('glpi_agent_httpd_trust'), glpi_agent_view_dash($httpd_trust));
}

echo field_view(lang('glpi_agent_logger'), glpi_agent_view_dash($logger_display));
echo field_view(lang('glpi_agent_debug'), glpi_agent_view_dash($debug_display));

echo field_button_set(array(
    anchor_edit('/app/glpi_agent/settings/edit'),
    anchor_custom('/app/glpi_agent/run_now', lang('glpi_agent_run_now'), 'low')
));

echo form_footer();
echo form_close();
