<?php

/////////////////////////////////////////////////////////////////////////////
// General information
/////////////////////////////////////////////////////////////////////////////

$app['basename'] = 'glpi_agent';
$app['version'] = '0.1.15';
$app['release'] = '1';
$app['vendor'] = 'SnugLinux';
$app['packager'] = 'SnugLinux';
$app['license'] = 'GPLv3';
$app['license_core'] = 'LGPLv3';
$app['description'] = lang('glpi_agent_app_description');
$app['tooltip'] = lang('glpi_agent_app_tooltip');

$app['powered_by'] = array(
    'vendor' => NULL,
    'packages' => array(
        'glpi-agent' => array(
            'name' => 'GLPI Agent',
            'version' => '---',
            'url' => 'https://github.com/glpi-project/glpi-agent',
        ),
    ),
);

/////////////////////////////////////////////////////////////////////////////
// App name and categories
/////////////////////////////////////////////////////////////////////////////

$app['name'] = lang('glpi_agent_app_name');
$app['category'] = lang('base_category_system');
$app['subcategory'] = lang('base_subcategory_monitoring');

/////////////////////////////////////////////////////////////////////////////
// Controllers
/////////////////////////////////////////////////////////////////////////////

$app['controllers']['glpi_agent']['title'] = lang('glpi_agent_app_name');
$app['controllers']['settings']['title'] = lang('base_settings');
$app['controllers']['server']['title'] = lang('base_server_status');

/////////////////////////////////////////////////////////////////////////////
// Packaging
/////////////////////////////////////////////////////////////////////////////

$app['requires'] = array(
    'app-base',
);

$app['core_requires'] = array(
    'app-base-core',
    'glpi-agent',
    'openssl',
    'sudo',
);

$app['core_directory_manifest'] = array(
    '/var/clearos/glpi_agent' => array(
        'mode' => '0755',
        'owner' => 'root',
        'group' => 'root',
    ),
);

// Do not remove glpi-agent on app removal. The agent may be used outside Webconfig.
$app['delete_dependency'] = array(
    'app-glpi-agent-core',
);
