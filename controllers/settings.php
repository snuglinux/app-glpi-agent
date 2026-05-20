<?php

/**
 * GLPI Agent settings controller.
 *
 * @category   apps
 * @package    glpi-agent
 * @subpackage controllers
 * @author     SnugLinux
 * @license    http://www.gnu.org/copyleft/gpl.html GNU General Public License version 3 or later
 */

class Settings extends ClearOS_Controller
{
    /**
     * Default view.
     *
     * @return redirect
     */

    function index()
    {
        redirect('/glpi_agent');
    }

    /**
     * Edit settings.
     *
     * @return view
     */

    function edit()
    {
        $this->lang->load('base');
        $this->lang->load('glpi_agent');
        $this->load->library('glpi_agent/Glpi_Agent');

        $fingerprint_generated = FALSE;
        $certificate_output = '';
        $certificate_status = '';

        if ($this->input->post('update_certificate')) {
            try {
                $settings = $this->_get_posted_settings();
                $settings['SSL_MODE'] = 'ca_cert';
                if ($settings['CA_CERT_FILE'] === '')
                    $settings['CA_CERT_FILE'] = '/etc/glpi-agent/certs/glpi.lan.pem';

                $_POST['SSL_MODE'] = $settings['SSL_MODE'];
                $_POST['CA_CERT_FILE'] = $settings['CA_CERT_FILE'];

                $result = $this->glpi_agent->update_certificate($settings['SERVER']);
                $certificate_output = $result['output'];
                $certificate_status = ((int) $result['exit_code'] === 0) ? 'success' : 'warning';

                if ($certificate_status === 'success')
                    $settings = $this->glpi_agent->get_settings();
            } catch (Exception $e) {
                $this->page->view_exception($e);
                return;
            }
        } else if ($this->input->post('check_certificate')) {
            try {
                $settings = $this->_get_posted_settings();
                $settings['SSL_MODE'] = 'ca_cert';
                if ($settings['CA_CERT_FILE'] === '')
                    $settings['CA_CERT_FILE'] = '/etc/glpi-agent/certs/glpi.lan.pem';

                $_POST['SSL_MODE'] = $settings['SSL_MODE'];
                $_POST['CA_CERT_FILE'] = $settings['CA_CERT_FILE'];

                $result = $this->glpi_agent->check_certificate($settings['SERVER']);
                $certificate_output = $result['output'];
                $certificate_status = ((int) $result['exit_code'] === 0) ? 'success' : 'warning';
            } catch (Exception $e) {
                $this->page->view_exception($e);
                return;
            }
        } else if ($this->input->post('generate_fingerprint')) {
            try {
                $settings = $this->_get_posted_settings();
                $settings['SSL_MODE'] = 'fingerprint';
                $settings['SSL_FINGERPRINT'] = $this->glpi_agent->get_server_ssl_fingerprint($settings['SERVER']);

                // ClearOS form helpers prefer POST values when a form is submitted.
                // Update POST too, otherwise the freshly generated fingerprint can be
                // hidden by the previously empty submitted field value.
                $_POST['SSL_MODE'] = $settings['SSL_MODE'];
                $_POST['SSL_FINGERPRINT'] = $settings['SSL_FINGERPRINT'];

                $fingerprint_generated = TRUE;
            } catch (Exception $e) {
                $this->page->view_exception($e);
                return;
            }
        } else if ($this->input->post('submit')) {
            try {
                $settings = $this->_get_posted_settings();
                $this->glpi_agent->set_settings($settings);
                $this->page->set_status_updated();
                redirect('/glpi_agent');
                return;
            } catch (Exception $e) {
                $this->page->view_exception($e);
                return;
            }
        } else {
            try {
                $settings = $this->glpi_agent->get_settings();
            } catch (Exception $e) {
                $this->page->view_exception($e);
                return;
            }
        }

        try {
            $data['settings'] = $settings;
            $data['ssl_mode_options'] = $this->glpi_agent->get_ssl_mode_options();
            $data['logger_options'] = $this->glpi_agent->get_logger_options();
            $data['debug_options'] = $this->glpi_agent->get_debug_options();
            $data['config_file'] = $this->glpi_agent->get_config_file();
            $data['fingerprint_generated'] = $fingerprint_generated;
            $data['certificate_output'] = $certificate_output;
            $data['certificate_status'] = $certificate_status;
        } catch (Exception $e) {
            $this->page->view_exception($e);
            return;
        }

        $this->page->view_form('glpi_agent/settings', $data, lang('base_settings'));
    }

    /**
     * Returns settings from POST data.
     *
     * @return array settings
     */

    protected function _get_posted_settings()
    {
        $httpd_enabled = $this->input->post('HTTPD_ENABLED') ? '1' : '0';

        return array(
            'SERVER' => trim((string) $this->input->post('SERVER')),
            'SSL_MODE' => trim((string) $this->input->post('SSL_MODE')),
            'SSL_FINGERPRINT' => trim((string) $this->input->post('SSL_FINGERPRINT')),
            'CA_CERT_FILE' => trim((string) $this->input->post('CA_CERT_FILE')),
            // GLPI Agent option is inverted: no-httpd=yes means local HTTP server is disabled.
            'NO_HTTPD' => ($httpd_enabled === '1') ? '0' : '1',
            'HTTPD_IP' => trim((string) $this->input->post('HTTPD_IP')),
            'HTTPD_PORT' => trim((string) $this->input->post('HTTPD_PORT')),
            'HTTPD_TRUST' => trim((string) $this->input->post('HTTPD_TRUST')),
            'LOGGER' => trim((string) $this->input->post('LOGGER')),
            // Reminder-only in the UI. Keep the app default unless advanced
            // users edit the managed cfg manually and switch logger=file.
            'LOGFILE' => '',
            'DEBUG' => trim((string) $this->input->post('DEBUG')),
            'TAG' => trim((string) $this->input->post('TAG')),
        );
    }
}
