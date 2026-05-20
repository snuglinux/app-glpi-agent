<?php

/**
 * GLPI Agent settings edit view.
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
if (! isset($fingerprint_generated))
    $fingerprint_generated = FALSE;
if (! isset($certificate_output))
    $certificate_output = '';
if (! isset($certificate_status))
    $certificate_status = '';

$server = isset($settings['SERVER']) ? $settings['SERVER'] : 'https://glpi.lan/front/inventory.php';
$ssl_mode = isset($settings['SSL_MODE']) ? $settings['SSL_MODE'] : 'ca_cert';
$ssl_fingerprint = isset($settings['SSL_FINGERPRINT']) ? $settings['SSL_FINGERPRINT'] : '';
$ca_cert_file = isset($settings['CA_CERT_FILE']) ? $settings['CA_CERT_FILE'] : '/etc/glpi-agent/certs/glpi.lan.pem';
$no_httpd = isset($settings['NO_HTTPD']) && $settings['NO_HTTPD'] === '1';
$httpd_enabled = ! $no_httpd;
$httpd_ip = isset($settings['HTTPD_IP']) ? $settings['HTTPD_IP'] : '127.0.0.1';
$httpd_port = isset($settings['HTTPD_PORT']) ? $settings['HTTPD_PORT'] : '62354';
$httpd_trust = isset($settings['HTTPD_TRUST']) ? $settings['HTTPD_TRUST'] : '';
$logger = isset($settings['LOGGER']) ? $settings['LOGGER'] : 'syslog';
$logfile = isset($settings['LOGFILE']) ? $settings['LOGFILE'] : '/var/log/glpi-agent.log';
$debug = isset($settings['DEBUG']) ? $settings['DEBUG'] : '0';
$tag = isset($settings['TAG']) ? $settings['TAG'] : '';

if (! function_exists('glpi_agent_settings_escape')) {
    function glpi_agent_settings_escape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

echo '<style>' .
    '#glpi-agent-fingerprint-value {' .
    'display:block; max-width:100%; white-space:normal; overflow-wrap:anywhere; word-break:break-all; ' .
    'font-family:monospace; line-height:1.4; color:#c7254e; padding:6px 8px; ' .
    'background:#f5f5f5; border:1px solid #ddd;' .
    '}' .
    '#glpi-agent-fingerprint-value.glpi-agent-fingerprint-blurred {' .
    'filter:blur(5px); user-select:none;' .
    '}' .
    '.glpi-agent-ssl-dependent {' .
    'margin-bottom:18px; padding-bottom:14px; border-bottom:1px solid #eeeeee;' .
    '}' .
    '.glpi-agent-ssl-actions {' .
    'text-align:right; margin-top:8px;' .
    '}' .
    '.glpi-agent-ssl-actions .btn {' .
    'margin-left:6px; margin-bottom:6px;' .
    '}' .
    '</style>';

if ($ssl_fingerprint !== '') {
    $fingerprint_display =
        '<div id="glpi-agent-fingerprint-value" class="glpi-agent-fingerprint-blurred">' . glpi_agent_settings_escape($ssl_fingerprint) . '</div>';
} else {
    $fingerprint_display = lang('glpi_agent_not_configured');
}

$fingerprint_style = ($ssl_mode === 'fingerprint') ? '' : ' style="display:none;"';
$ca_cert_style = ($ssl_mode === 'ca_cert') ? '' : ' style="display:none;"';

echo infobox_highlight(
    lang('base_information'),
    lang('glpi_agent_help')
);

if ($fingerprint_generated)
    echo infobox_highlight(lang('base_information'), lang('glpi_agent_fingerprint_generated'));

if ($certificate_status === 'success' || $certificate_status === 'warning') {
    $output_text = trim((string) $certificate_output);
    if ($output_text !== '') {
        $box_html = '<pre style="white-space: pre-wrap; word-break: break-word; margin: 0;">' . glpi_agent_settings_escape($output_text) . '</pre>';
        if ($certificate_status === 'success')
            echo infobox_highlight(lang('glpi_agent_certificate_result'), $box_html);
        else
            echo infobox_warning(lang('glpi_agent_certificate_result'), $box_html);
    }
}

echo form_open('glpi_agent/settings/edit');
echo form_header(lang('base_settings'));

echo field_input('SERVER', $server, lang('glpi_agent_server_url'), FALSE);
echo field_dropdown('SSL_MODE', $ssl_mode_options, $ssl_mode, lang('glpi_agent_ssl_mode'), FALSE);

// Keep mode-specific values in the form, but show them only when the selected
// SSL mode uses them.  This avoids confusing stale fingerprint/certificate
// fields when switching between SSL modes.
echo '<div id="glpi-agent-ca-cert-section" class="glpi-agent-ssl-dependent"' . $ca_cert_style . '>';
echo form_hidden('CA_CERT_FILE', $ca_cert_file);
echo field_view(lang('glpi_agent_ca_cert_file'), glpi_agent_settings_escape($ca_cert_file));
echo '<div class="glpi-agent-ssl-actions">';
echo '<button type="submit" name="update_certificate" value="1" class="btn btn-primary">' . glpi_agent_settings_escape(lang('glpi_agent_update_certificate')) . '</button>';
echo '<button type="submit" name="check_certificate" value="1" class="btn btn-default">' . glpi_agent_settings_escape(lang('glpi_agent_check_certificate')) . '</button>';
echo '</div>';
echo '</div>';

echo '<div id="glpi-agent-fingerprint-section" class="glpi-agent-ssl-dependent"' . $fingerprint_style . '>';
echo form_hidden('SSL_FINGERPRINT', $ssl_fingerprint);
echo field_view(lang('glpi_agent_current_fingerprint'), $fingerprint_display);
echo '<span id="glpi_agent_copy_status" class="help-block"></span>';
echo '<div class="glpi-agent-ssl-actions">';
echo '<button type="submit" name="generate_fingerprint" value="1" class="btn btn-primary">' . glpi_agent_settings_escape(lang('glpi_agent_generate_fingerprint')) . '</button>';

if ($ssl_fingerprint !== '')
    echo '<button type="button" id="glpi_agent_copy_fingerprint" class="btn btn-default">' . glpi_agent_settings_escape(lang('glpi_agent_copy_fingerprint')) . '</button>';

echo '</div>';
echo '</div>';

echo field_input('TAG', $tag, lang('glpi_agent_tag'), FALSE);
echo field_toggle_enable_disable('HTTPD_ENABLED', $httpd_enabled, lang('glpi_agent_httpd_enabled'));
echo field_input('HTTPD_IP', $httpd_ip, lang('glpi_agent_httpd_ip'), FALSE);
echo field_input('HTTPD_PORT', $httpd_port, lang('glpi_agent_httpd_port'), FALSE);
echo field_input('HTTPD_TRUST', $httpd_trust, lang('glpi_agent_httpd_trust'), FALSE);
echo infobox_highlight(lang('base_information'), lang('glpi_agent_httpd_trust_help'));
echo field_dropdown('LOGGER', $logger_options, $logger, lang('glpi_agent_logger'), FALSE);
echo infobox_highlight(
    lang('base_information'),
    glpi_agent_settings_escape(lang('glpi_agent_logfile')) . ' ' .
    glpi_agent_settings_escape($logfile) . ' ' .
    glpi_agent_settings_escape(lang('glpi_agent_logfile_reminder'))
);
echo field_dropdown('DEBUG', $debug_options, $debug, lang('glpi_agent_debug'), FALSE);

echo field_button_set(array(
    form_submit_update('submit'),
    anchor_cancel('/app/glpi_agent')
));

echo "<script>\n";
echo "(function(){\n";
echo "  var sslMode = document.querySelector('[name=\"SSL_MODE\"]');\n";
echo "  var caSection = document.getElementById('glpi-agent-ca-cert-section');\n";
echo "  var fpSection = document.getElementById('glpi-agent-fingerprint-section');\n";
echo "  var value = document.getElementById('glpi-agent-fingerprint-value');\n";
echo "  var copy = document.getElementById('glpi_agent_copy_fingerprint');\n";
echo "  var status = document.getElementById('glpi_agent_copy_status');\n";
echo "  function updateSslSections(){\n";
echo "    var mode = sslMode ? sslMode.value : '';\n";
echo "    if (caSection) caSection.style.display = (mode === 'ca_cert') ? '' : 'none';\n";
echo "    if (fpSection) fpSection.style.display = (mode === 'fingerprint') ? '' : 'none';\n";
echo "  }\n";
echo "  function copyFallback(text){\n";
echo "    var ta = document.createElement('textarea');\n";
echo "    ta.value = text; ta.style.position = 'fixed'; ta.style.left = '-9999px';\n";
echo "    document.body.appendChild(ta); ta.focus(); ta.select();\n";
echo "    try { document.execCommand('copy'); } catch(e) {}\n";
echo "    document.body.removeChild(ta);\n";
echo "  }\n";
echo "  if (copy && value) {\n";
echo "    copy.onclick = function(){\n";
echo "      var text = value.textContent || value.innerText || '';\n";
echo "      var done = function(){ if (status) status.innerHTML = '" . glpi_agent_settings_escape(lang('glpi_agent_copied')) . "'; };\n";
echo "      if (window.navigator && navigator.clipboard && navigator.clipboard.writeText)\n";
echo "        navigator.clipboard.writeText(text).then(done, function(){ copyFallback(text); done(); });\n";
echo "      else { copyFallback(text); done(); }\n";
echo "    };\n";
echo "  }\n";
echo "  if (sslMode) {\n";
echo "    sslMode.onchange = updateSslSections;\n";
echo "    updateSslSections();\n";
echo "  }\n";
echo "})();\n";
echo "</script>\n";

echo form_footer();
echo form_close();
