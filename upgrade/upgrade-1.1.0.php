<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.1.0: hooks del servidor de actualizaciones y pestaña oculta para el controlador AJAX.
 */
function upgrade_module_1_1_0($module)
{
    return $module->getUpdater()->registerHooks() && $module->installAjaxTab();
}
