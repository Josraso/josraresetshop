<?php
/**
 * Llamadas AJAX de JosraResetShop (controlador oculto, sin entrada en el menú).
 *
 * @author josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'josraresetshop/classes/JosraResetEngine.php';
require_once _PS_MODULE_DIR_ . 'josraresetshop/classes/JosraResetFactory.php';

class AdminJosraResetShopController extends ModuleAdminController
{
    /** Palabra que hay que escribir para confirmar */
    const CONFIRM_WORD = 'BORRAR';

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        if (!$this->ajax) {
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules') . '&configure=josraresetshop');
        }
        parent::initContent();
    }

    /** Lista de pasos de lo marcado (con las dependencias ya añadidas) */
    public function ajaxProcessPlan()
    {
        $this->guard();
        $engine = new JosraResetEngine();
        try {
            $plan = $engine->plan(
                $this->arrayParam('sections'),
                $this->arrayParam('tables'),
                $this->arrayParam('modules'),
                (bool) Tools::getValue('demo')
            );
        } catch (Exception $e) {
            $this->sendJson(['ok' => false, 'error' => $e->getMessage()]);
        }
        if (!$plan['steps']) {
            $this->sendJson(['ok' => false, 'error' => 'No hay nada marcado que borrar.']);
        }
        $this->sendJson(['ok' => true] + $plan);
    }

    /** Un paso (o un trozo de un paso: si «done» es false se vuelve a llamar con el «state» devuelto) */
    public function ajaxProcessStep()
    {
        $this->guard();
        $step = (string) Tools::getValue('step');
        $state = json_decode((string) Tools::getValue('state'), true);
        if (!is_array($state)) {
            $state = [];
        }

        $lock = $this->lock();
        if (!$lock) {
            $this->sendJson(['ok' => false, 'error' => 'Ya hay un vaciado en marcha en otra pestaña o ventana. Espera a que termine.']);
        }
        $engine = new JosraResetEngine();
        $result = $engine->run($step, $state);
        @flock($lock, LOCK_UN);
        @fclose($lock);

        if (!empty($result['state']['start_download'])) {
            // la descarga de PrestaShop puede tardar: se contesta ya y se sigue bajando (el navegador va preguntando)
            unset($result['state']['start_download']);
            $result['log'][] = '… Empieza la descarga de PrestaShop ' . _PS_VERSION_ . ' desde GitHub (unos 50 MB)';
            $this->sendJson(['ok' => true] + $result, function () {
                $factory = new JosraResetFactory();
                $factory->download();
            });
        }

        $this->sendJson(['ok' => true] + $result);
    }

    /** Solo SuperAdmin y con la palabra de confirmación */
    private function guard()
    {
        if (!$this->module->isSuperAdmin()) {
            $this->sendJson(['ok' => false, 'error' => 'Solo un empleado con perfil SuperAdmin puede vaciar la tienda.']);
        }
        if (trim((string) Tools::getValue('confirm')) !== self::CONFIRM_WORD) {
            $this->sendJson(['ok' => false, 'error' => 'Falta la confirmación: escribe ' . self::CONFIRM_WORD . ' para continuar.']);
        }
    }

    private function lock()
    {
        $file = _PS_CACHE_DIR_ . 'josraresetshop.lock';
        $h = @fopen($file, 'c');
        if (!$h) {
            // sin archivo de bloqueo se sigue: no se puede saber si hay otro, pero no se impide trabajar
            return fopen('php://memory', 'r');
        }
        if (!flock($h, LOCK_EX | LOCK_NB)) {
            fclose($h);

            return false;
        }

        return $h;
    }

    private function arrayParam($name)
    {
        $v = Tools::getValue($name, []);

        return is_array($v) ? array_values(array_map('strval', $v)) : [];
    }

    /**
     * @param callable|null $after trabajo que sigue después de contestar (si el servidor lo permite; si no, antes)
     */
    private function sendJson(array $data, $after = null)
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        $body = json_encode($data);
        if ($after && !function_exists('fastcgi_finish_request') && !function_exists('litespeed_finish_request')) {
            // sin forma de contestar antes: se hace el trabajo y luego se contesta
            call_user_func($after);
            $after = null;
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Content-Length: ' . strlen($body));
        echo $body;
        if ($after) {
            @ignore_user_abort(true);
            @flush();
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                litespeed_finish_request();
            }
            call_user_func($after);
        }
        exit;
    }
}
