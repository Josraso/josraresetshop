<?php
/**
 * JosraResetShop - Vacía la tienda por secciones (pedidos, clientes, catálogo...)
 * PrestaShop 1.7 a 9.
 *
 * @author josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/JosraresetshopUpdater.php';
require_once dirname(__FILE__) . '/classes/JosraResetEngine.php';
require_once dirname(__FILE__) . '/classes/JosraResetFactory.php';

class JosraResetShop extends Module
{
    /** Controlador de las llamadas AJAX: va oculto (sin entrada en el menú) */
    const AJAX_CONTROLLER = 'AdminJosraResetShop';

    public function __construct()
    {
        $this->name = 'josraresetshop';
        $this->tab = 'administration';
        $this->version = '1.2.0';
        $this->author = 'josra';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('JosraResetShop - Vaciar tienda');
        $this->description = $this->l('Vacía la tienda por secciones: pedidos, clientes, catálogo, categorías, imágenes, estadísticas y más.');
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
    }

    public function getUpdater()
    {
        return new JosraresetshopUpdater($this);
    }

    public function install()
    {
        return parent::install()
            && $this->getUpdater()->registerHooks()
            && $this->installAjaxTab();
    }

    public function uninstall()
    {
        $this->getUpdater()->notifyUninstall();
        foreach (['TS', 'INFO', 'SU', 'LT'] as $k) {
            Configuration::deleteByName('JOSRARESETSHOP_UPD_' . $k);
        }
        Configuration::deleteByName('JOSRARESETSHOP_INSTALLER_URL');
        $this->uninstallAjaxTab();
        // datos de instalación de PrestaShop que se bajaron para «Dejar como recién instalada»
        $dir = rtrim(_PS_ROOT_DIR_, '/\\') . '/var/josraresetshop/';
        if (is_dir($dir)) {
            Tools::deleteDirectory($dir, true);
        }

        return parent::uninstall();
    }

    /** Aviso al servidor de actualizaciones (solo en el back-office) */
    public function hookActionAdminControllerSetMedia($params)
    {
        $this->getUpdater()->maybePing();
    }

    /** PS 8.1+: la versión nueva aparece en Gestor de módulos > Actualizaciones */
    public function hookActionListModules($params)
    {
        return $this->getUpdater()->getNativeModuleList();
    }

    /**
     * Pestaña oculta (sin entrada en el menú) para el controlador AJAX: así PrestaShop le da token y permisos.
     */
    public function installAjaxTab()
    {
        if ((int) Tab::getIdFromClassName(self::AJAX_CONTROLLER)) {
            return true;
        }
        $tab = new Tab();
        $tab->class_name = self::AJAX_CONTROLLER;
        $tab->module = $this->name;
        $tab->id_parent = -1;
        $tab->active = 0;
        $tab->name = [];
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[(int) $lang['id_lang']] = 'JosraResetShop';
        }

        return (bool) $tab->add();
    }

    public function uninstallAjaxTab()
    {
        $id = (int) Tab::getIdFromClassName(self::AJAX_CONTROLLER);
        if ($id) {
            $tab = new Tab($id);
            $tab->delete();
        }

        return true;
    }

    /** Solo el SuperAdmin puede vaciar la tienda */
    public function isSuperAdmin()
    {
        $employee = $this->context->employee;

        return $employee && (int) $employee->id_profile === (int) _PS_ADMIN_PROFILE_;
    }

    public function getAjaxUrl()
    {
        return $this->context->link->getAdminLink(self::AJAX_CONTROLLER);
    }

    public function getContent()
    {
        $updater = $this->getUpdater();
        $output = $updater->processRequest();
        $this->installAjaxTab();

        $forms = '';
        if (!$this->isSuperAdmin()) {
            $forms = $this->displayError($this->l('Solo un empleado con perfil SuperAdmin puede vaciar la tienda.'));
        } else {
            $engine = new JosraResetEngine();
            $this->context->controller->addJS($this->_path . 'views/js/admin.js?v=' . $this->version);
            $this->context->controller->addCSS($this->_path . 'views/css/admin.css?v=' . $this->version);

            $tables = $engine->getModuleTables();
            $psModules = $engine->getConnectedModules();
            $txt = [
                'requiredBy' => $this->l('Se marca sola porque la necesita: %s'),
                'nothing' => $this->l('Marca arriba lo que quieras vaciar.'),
                'willDelete' => $this->l('Se va a vaciar:'),
                'tables' => $this->l('tablas de otros módulos'),
                'uninstall' => $this->l('módulos a desinstalar'),
                'planning' => $this->l('Preparando…'),
                'finished' => $this->l('Terminado'),
                'finishedOk' => $this->l('Vaciado terminado sin errores.'),
                'finishedErr' => $this->l('Vaciado terminado con %d errores: están en rojo en el registro.'),
                'stopped' => $this->l('Se ha parado por un error de conexión. Lo ya borrado, borrado está: vuelve a lanzarlo y seguirá con lo que falte.'),
                'reload' => $this->l('Recarga la página para ver las cifras actualizadas.'),
                'confirmJs' => $this->l('Última comprobación: ¿vaciar lo marcado? No se puede deshacer.'),
                'confirmFactory' => $this->l('Última comprobación: ¿dejar la tienda como recién instalada? Se borra todo lo de la lista y no se puede deshacer.'),
                'factoryLabel' => $this->l('Dejar como recién instalada'),
                'demoYes' => $this->l('con productos de demostración'),
                'lockedFactory' => $this->l('Incluida en «Dejar como recién instalada»'),
                'stepsDone' => $this->l('%1$d de %2$d pasos'),
            ];
            $this->context->smarty->assign([
                'jrs_txt' => json_encode($txt, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE),
                'jrs_module_tables_n' => count($tables),
                'jrs_ps_modules_n' => count($psModules),
                'jrs_ajax_url' => $this->getAjaxUrl(),
                'jrs_version' => $this->version,
                'jrs_ps_version' => _PS_VERSION_,
                'jrs_sections' => $engine->getSectionsInfo(),
                'jrs_module_tables' => $tables,
                'jrs_ps_modules' => $psModules,
                'jrs_employee' => $this->context->employee->firstname . ' ' . $this->context->employee->lastname,
                'jrs_multishop' => Shop::isFeatureActive(),
                'jrs_factory' => (new JosraResetFactory())->info(),
                'jrs_min_factory' => JosraResetFactory::MIN_VERSION,
            ]);
            $forms = $this->display(__FILE__, 'views/templates/admin/configure.tpl');
        }

        $info = $updater->getInfo();
        $panel = $updater->renderPanel();

        return !empty($info['update_available']) ? $output . $panel . $forms : $output . $forms . $panel;
    }
}
