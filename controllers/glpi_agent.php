<?php

/**
 * GLPI Agent main controller.
 *
 * @category   apps
 * @package    glpi-agent
 * @subpackage controllers
 * @author     SnugLinux
 * @license    http://www.gnu.org/copyleft/gpl.html GNU General Public License version 3 or later
 */

class Glpi_Agent extends ClearOS_Controller
{
    /**
     * Main summary page.
     *
     * @return view
     */

    function index()
    {
        $this->lang->load('base');
        $this->lang->load('glpi_agent');
        $this->load->library('glpi_agent/Glpi_Agent');

        try {
            $data = $this->_get_summary_data();
        } catch (Exception $e) {
            $this->page->view_exception($e);
            return;
        }

        $options['javascript'] = array(clearos_app_htdocs('base') . '/daemon.js.php');

        $this->page->view_form('glpi_agent/summary', $data, lang('glpi_agent_app_name'), $options);
    }

    /**
     * Run inventory now.
     *
     * @return redirect
     */

    function run_now()
    {
        $this->lang->load('base');
        $this->lang->load('glpi_agent');
        $this->load->library('glpi_agent/Glpi_Agent');

        try {
            $data = $this->_get_summary_data();
            $data['run_now_output'] = $this->glpi_agent->run_now();
            $data['run_now_status'] = 'success';
        } catch (Exception $e) {
            $this->page->view_exception($e);
            return;
        }

        $options['javascript'] = array(clearos_app_htdocs('base') . '/daemon.js.php');

        $this->page->view_form('glpi_agent/summary', $data, lang('glpi_agent_app_name'), $options);
    }

    /**
     * Update trusted GLPI certificate and test inventory.
     *
     * @return view
     */

    function update_certificate()
    {
        $this->lang->load('base');
        $this->lang->load('glpi_agent');
        $this->load->library('glpi_agent/Glpi_Agent');

        try {
            $data = $this->_get_summary_data();
            $result = $this->glpi_agent->update_certificate($data['settings']['SERVER']);
            $data['certificate_output'] = $result['output'];
            $data['certificate_status'] = ((int) $result['exit_code'] === 0) ? 'success' : 'warning';
            $data = $this->_get_summary_data() + $data;
        } catch (Exception $e) {
            $this->page->view_exception($e);
            return;
        }

        $options['javascript'] = array(clearos_app_htdocs('base') . '/daemon.js.php');

        $this->page->view_form('glpi_agent/summary', $data, lang('glpi_agent_app_name'), $options);
    }

    /**
     * Check current trusted GLPI certificate.
     *
     * @return view
     */

    function check_certificate()
    {
        $this->lang->load('base');
        $this->lang->load('glpi_agent');
        $this->load->library('glpi_agent/Glpi_Agent');

        try {
            $data = $this->_get_summary_data();
            $result = $this->glpi_agent->check_certificate($data['settings']['SERVER']);
            $data['certificate_output'] = $result['output'];
            $data['certificate_status'] = ((int) $result['exit_code'] === 0) ? 'success' : 'warning';
        } catch (Exception $e) {
            $this->page->view_exception($e);
            return;
        }

        $options['javascript'] = array(clearos_app_htdocs('base') . '/daemon.js.php');

        $this->page->view_form('glpi_agent/summary', $data, lang('glpi_agent_app_name'), $options);
    }

    /**
     * Gets summary page data.
     *
     * @return array summary data
     */

    protected function _get_summary_data()
    {
        $data = array();

        $data['settings'] = $this->glpi_agent->get_settings();
        $data['ssl_mode_options'] = $this->glpi_agent->get_ssl_mode_options();
        $data['logger_options'] = $this->glpi_agent->get_logger_options();
        $data['debug_options'] = $this->glpi_agent->get_debug_options();
        $data['agent_version'] = $this->glpi_agent->get_agent_version();
        $data['config_file'] = $this->glpi_agent->get_config_file();
        $data['config_warnings'] = $this->glpi_agent->get_config_warnings();
        $data['certificate_info'] = $this->glpi_agent->get_certificate_info();

        return $data;
    }
}
