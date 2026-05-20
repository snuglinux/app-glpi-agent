<?php

/**
 * GLPI Agent daemon controller.
 *
 * This controller exposes the standard ClearOS daemon.js.php endpoints:
 *
 *   /app/glpi_agent/server/status/glpi-agent
 *   /app/glpi_agent/server/start/glpi-agent
 *   /app/glpi_agent/server/stop/glpi-agent
 *
 * @category   apps
 * @package    glpi-agent
 * @subpackage controllers
 * @author     SnugLinux
 * @license    http://www.gnu.org/copyleft/gpl.html GNU General Public License version 3 or later
 */

class Server extends ClearOS_Controller
{
    /**
     * Hidden fields for ClearOS daemon sidebar integration.
     *
     * @return view
     */

    function index()
    {
        $this->lang->load('base');

        $data['daemon_name'] = 'glpi-agent';
        $data['app_name'] = 'glpi_agent';

        $options['javascript'] = array(clearos_app_htdocs('base') . '/daemon.js.php');

        $this->page->view_form('base/daemon', $data, lang('base_server_status'), $options);
    }

    /**
     * Service status for daemon.js.php.
     *
     * @param string $daemon_name ignored, kept for daemon.js.php compatibility
     *
     * @return JSON
     */

    function status($daemon_name = NULL)
    {
        clearos_profile(__METHOD__, __LINE__);

        header('Cache-Control: no-cache, must-revalidate');
        header('Content-type: application/json');

        $this->lang->load('base');
        $this->lang->load('glpi_agent');
        $this->load->library('glpi_agent/Glpi_Agent');

        try {
            echo json_encode(array('status' => $this->glpi_agent->get_daemon_status()));
        } catch (Exception $e) {
            echo json_encode(array('status' => 'dead'));
        }
    }

    /**
     * Start and enable service for daemon.js.php.
     *
     * @param string $daemon_name ignored, kept for daemon.js.php compatibility
     *
     * @return JSON
     */

    function start($daemon_name = NULL)
    {
        $this->_run_action(TRUE);
    }

    /**
     * Stop and disable service for daemon.js.php.
     *
     * @param string $daemon_name ignored, kept for daemon.js.php compatibility
     *
     * @return JSON
     */

    function stop($daemon_name = NULL)
    {
        $this->_run_action(FALSE);
    }

    /**
     * Runs service action.
     *
     * @param boolean $start TRUE to start/enable, FALSE to stop/disable
     *
     * @return JSON
     */

    protected function _run_action($start)
    {
        clearos_profile(__METHOD__, __LINE__);

        header('Cache-Control: no-cache, must-revalidate');
        header('Content-type: application/json');

        $this->lang->load('base');
        $this->lang->load('glpi_agent');
        $this->load->library('glpi_agent/Glpi_Agent');

        try {
            if ($start)
                $this->glpi_agent->start_and_enable_service();
            else
                $this->glpi_agent->stop_and_disable_service();

            echo json_encode('ok');
        } catch (Exception $e) {
            echo json_encode('error');
        }
    }
}
