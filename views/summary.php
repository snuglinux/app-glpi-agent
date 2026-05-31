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
if (! isset($additional_oem_status))
    $additional_oem_status = array();

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

if (! function_exists('glpi_agent_view_oem_status_cell')) {
    function glpi_agent_view_oem_status_cell($is_bad = NULL)
    {
        if ($is_bad === NULL)
            return '<td class="glpi-agent-oem-status">-</td>';

        if ($is_bad)
            return '<td class="glpi-agent-oem-status glpi-agent-oem-bad">' . glpi_agent_view_escape(lang('glpi_agent_dmi_suspicious')) . '</td>';

        return '<td class="glpi-agent-oem-status glpi-agent-oem-ok">' . glpi_agent_view_escape(lang('glpi_agent_dmi_ok')) . '</td>';
    }
}

if (! function_exists('glpi_agent_view_oem_details')) {
    function glpi_agent_view_oem_details($status)
    {
        if (! is_array($status))
            return '-';

        $copy_value = isset($status['serial_number']) ? trim((string) $status['serial_number']) : '';

        $html = '<div class="glpi-agent-oem-actions">';
        $html .= '<details class="glpi-agent-oem-details">';
        $html .= '<summary class="btn btn-default btn-sm">' . glpi_agent_view_escape(lang('glpi_agent_additional_information')) . '</summary>';
        $html .= '<div class="glpi-agent-oem-details-body">';
        $html .= '<table class="table table-condensed table-striped glpi-agent-oem-details-table">';

        $html .= '<tr><th>glpi-additional-oem</th><td class="glpi-agent-oem-value">' . (! empty($status['enabled']) ? glpi_agent_view_escape(lang('base_enabled')) : glpi_agent_view_escape(lang('base_disabled'))) . '</td>' . glpi_agent_view_oem_status_cell(NULL) . '</tr>';

        if (! empty($status['json']) && is_array($status['json'])) {
            $html .= '<tr><th>additional ssn</th><td class="glpi-agent-oem-value"><code>' . glpi_agent_view_dash(isset($status['json']['ssn']) ? $status['json']['ssn'] : '') . '</code></td>' . glpi_agent_view_oem_status_cell(NULL) . '</tr>';
            $html .= '<tr><th>additional msn</th><td class="glpi-agent-oem-value"><code>' . glpi_agent_view_dash(isset($status['json']['msn']) ? $status['json']['msn'] : '') . '</code></td>' . glpi_agent_view_oem_status_cell(NULL) . '</tr>';
            $html .= '<tr><th>additional uuid</th><td class="glpi-agent-oem-value"><code>' . glpi_agent_view_dash(isset($status['json']['uuid']) ? $status['json']['uuid'] : '') . '</code></td>' . glpi_agent_view_oem_status_cell(NULL) . '</tr>';
        }

        if (! empty($status['dmi']) && is_array($status['dmi'])) {
            foreach ($status['dmi'] as $field => $row) {
                $value = isset($row['value']) ? $row['value'] : '';
                $bad = ! empty($row['bad']);
                $html .= '<tr>';
                $html .= '<th>' . glpi_agent_view_escape($field) . '</th>';
                $html .= '<td class="glpi-agent-oem-value"><code>' . glpi_agent_view_dash($value) . '</code></td>';
                $html .= glpi_agent_view_oem_status_cell($bad);
                $html .= '</tr>';
            }
        }

        $primary_mac_bad = isset($status['primary_mac_bad']) ? (bool) $status['primary_mac_bad'] : TRUE;
        $html .= '<tr><th>primary_mac</th><td class="glpi-agent-oem-value"><code>' . glpi_agent_view_dash(isset($status['primary_mac']) ? $status['primary_mac'] : '') . '</code></td>' . glpi_agent_view_oem_status_cell($primary_mac_bad) . '</tr>';
        $html .= '</table>';
        $html .= '</div>';
        $html .= '</details>';
        $html .= '<button type="button" class="btn btn-default btn-sm glpi-agent-copy-serial" data-copy-value="' . glpi_agent_view_escape($copy_value) . '"' . ($copy_value === '' ? ' disabled="disabled"' : '') . '>' . glpi_agent_view_escape(lang('glpi_agent_copy_serial')) . '</button>';
        $html .= '<span class="glpi-agent-copy-status"></span>';
        $html .= '</div>';

        return $html;
    }
}

if (! function_exists('glpi_agent_view_code_compact')) {
    function glpi_agent_view_code_compact($value)
    {
        $value = trim((string) $value);
        if ($value === '')
            $value = '-';

        return '<code class="glpi-agent-serial-code" title="' . glpi_agent_view_escape($value) . '">' . glpi_agent_view_escape($value) . '</code>';
    }
}

echo '<style>' .
    '.glpi-agent-serial-code {' .
    'display:inline-block; max-width:260px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; ' .
    'vertical-align:middle; font-family:monospace;' .
    '}' .
    '.glpi-agent-oem-actions { max-width:345px; }' .
    '.glpi-agent-oem-details { display:inline-block; max-width:345px; vertical-align:top; }' .
    '.glpi-agent-oem-details summary { display:inline-block; cursor:pointer; margin-bottom:8px; }' .
    '.glpi-agent-copy-serial { margin-left:8px; margin-bottom:8px; vertical-align:top; }' .
    '.glpi-agent-copy-status { margin-left:8px; font-size:12px; color:#3c763d; vertical-align:middle; }' .
    '.glpi-agent-oem-details-body { max-width:345px; overflow:hidden; }' .
    '.glpi-agent-oem-details-table { width:345px; max-width:345px; table-layout:fixed; margin-bottom:0; font-size:12px; }' .
    '.glpi-agent-oem-details-table th { width:102px; max-width:102px; padding:4px 5px !important; ' .
    'white-space:normal; overflow-wrap:anywhere; word-break:break-word; vertical-align:top; }' .
    '.glpi-agent-oem-details-table td { padding:4px 5px !important; ' .
    'white-space:normal; overflow-wrap:anywhere; word-break:break-word; vertical-align:top; }' .
    '.glpi-agent-oem-details-table td.glpi-agent-oem-value { width:165px; max-width:165px; }' .
    '.glpi-agent-oem-details-table td.glpi-agent-oem-status { width:68px; max-width:68px; ' .
    'font-size:11px; text-align:center; white-space:normal; word-break:normal; }' .
    '.glpi-agent-oem-details-table code { white-space:normal; overflow-wrap:anywhere; word-break:break-all; ' .
    'font-size:11px; padding:1px 3px; }' .
    '.glpi-agent-oem-ok { color:#3c763d; }' .
    '.glpi-agent-oem-bad { color:#a94442; font-weight:bold; }' .
    '</style>';

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

if (is_array($additional_oem_status) && ! empty($additional_oem_status)) {
    $recommendation = isset($additional_oem_status['recommendation']) ? $additional_oem_status['recommendation'] : 'ok';
    if ($recommendation === 'enable') {
        echo infobox_warning(
            lang('base_warning'),
            glpi_agent_view_escape(lang('glpi_agent_additional_oem_warning_enable'))
        );
    } else if ($recommendation === 'review_disable') {
        echo infobox_highlight(
            lang('base_information'),
            glpi_agent_view_escape(lang('glpi_agent_additional_oem_warning_review_disable'))
        );
    }
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
if (is_array($additional_oem_status) && ! empty($additional_oem_status)) {
    $glpi_serial = isset($additional_oem_status['serial_number']) ? $additional_oem_status['serial_number'] : '';
    echo field_view(lang('glpi_agent_serial_number'), glpi_agent_view_code_compact($glpi_serial));
    echo field_view(lang('glpi_agent_additional_information'), glpi_agent_view_oem_details($additional_oem_status));
}
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

echo "<script>\n";
echo "(function(){\n";
echo "  function copyFallback(text){\n";
echo "    var ta = document.createElement('textarea');\n";
echo "    ta.value = text; ta.style.position = 'fixed'; ta.style.left = '-9999px';\n";
echo "    document.body.appendChild(ta); ta.focus(); ta.select();\n";
echo "    try { document.execCommand('copy'); } catch(e) {}\n";
echo "    document.body.removeChild(ta);\n";
echo "  }\n";
echo "  document.addEventListener('click', function(e){\n";
echo "    var btn = e.target;\n";
echo "    if (!btn || !btn.className || String(btn.className).indexOf('glpi-agent-copy-serial') === -1) return;\n";
echo "    var text = btn.getAttribute('data-copy-value') || '';\n";
echo "    if (!text) return;\n";
echo "    var box = btn.parentNode ? btn.parentNode.querySelector('.glpi-agent-copy-status') : null;\n";
echo "    var done = function(){ if (box) box.innerHTML = '" . glpi_agent_view_escape(lang('glpi_agent_copied')) . "'; };\n";
echo "    if (window.navigator && navigator.clipboard && navigator.clipboard.writeText)\n";
echo "      navigator.clipboard.writeText(text).then(done, function(){ copyFallback(text); done(); });\n";
echo "    else { copyFallback(text); done(); }\n";
echo "  });\n";
echo "})();\n";
echo "</script>\n";

echo form_footer();
echo form_close();
