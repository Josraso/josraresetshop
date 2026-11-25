<?php
/**
 * JosraResetFactory - «Dejar como recién instalada».
 *
 * Usa el propio instalador de PrestaShop (clases de src/PrestaShopBundle/Install, que trae la tienda
 * desde 1.7.8) con la carpeta de datos de instalación de la MISMA versión, que se baja de GitHub
 * (o se sube a mano). Vuelve a cargar de ahí la configuración, transportistas, páginas CMS, contactos,
 * grupos, estados de pedido, URLs... y, si se pide, los productos de demostración.
 *
 * Se conservan: el empleado conectado, el dominio y el SSL, los idiomas, países, impuestos y monedas,
 * el tema y los módulos instalados con su configuración.
 *
 * @author josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JosraResetFactory
{
    const MIN_VERSION = '1.7.8.0';

    /** Configuración que no se toca (dominio, idioma, país, moneda, logos, claves...) */
    private static $keepConfig = [
        'PS_SHOP_DOMAIN', 'PS_SHOP_DOMAIN_SSL', 'PS_SSL_ENABLED', 'PS_SSL_ENABLED_EVERYWHERE', 'PS_REWRITING_SETTINGS',
        'PS_SHOP_NAME', 'PS_SHOP_EMAIL', 'PS_SHOP_DEFAULT', 'PS_LANG_DEFAULT', 'PS_COUNTRY_DEFAULT', 'PS_CURRENCY_DEFAULT',
        'PS_TIMEZONE', 'PS_LOCALE_LANGUAGE', 'PS_LOCALE_COUNTRY', 'PS_WEIGHT_UNIT', 'PS_VOLUME_UNIT', 'PS_DISTANCE_UNIT',
        'PS_DIMENSION_UNIT', 'PS_BASE_DISTANCE_UNIT', 'PS_INSTALL_VERSION', 'PS_VERSION_DB', 'PS_LOGO', 'PS_LOGO_MAIL',
        'PS_LOGO_INVOICE', 'PS_FAVICON', 'PS_STORES_ICON', 'SHOP_LOGO_WIDTH', 'SHOP_LOGO_HEIGHT', 'PS_THEME_V11',
        'PS_SECURITY_TOKEN', 'PS_SMARTY_CONSOLE_KEY', 'PS_DEBUG_COOKIE_NAME', 'PS_DEBUG_COOKIE_VALUE', 'PS_COOKIE_CHECKIP',
        'PS_COOKIE_SAMESITE', 'PS_COOKIE_LIFETIME_FO', 'PS_COOKIE_LIFETIME_BO', 'PS_ROOT_CATEGORY', 'PS_HOME_CATEGORY',
        'PS_UNIDENTIFIED_GROUP', 'PS_GUEST_GROUP', 'PS_CUSTOMER_GROUP', 'PS_CARRIER_DEFAULT',
    ];

    /** Datos de la tienda que una instalación nueva deja vacíos */
    private static $emptyConfig = [
        'PS_SHOP_DETAILS', 'PS_SHOP_ADDR1', 'PS_SHOP_ADDR2', 'PS_SHOP_CODE', 'PS_SHOP_CITY', 'PS_SHOP_PHONE', 'PS_SHOP_FAX',
        'PS_SHOP_STATE', 'PS_SHOP_STATE_ID', 'PS_SHOP_COUNTRY', 'PS_SHOP_COUNTRY_ID',
    ];

    /** Fijas de la demostración que no se cargan: ya vienen de los datos de instalación o tocarían permisos */
    private static $skipFixtures = ['cart_rule_type', 'profile', 'access'];

    /** Tablas _lang que se traducen al idioma de cada uno tras cargar los datos en inglés */
    private static $translateTables = [
        'carrier_lang', 'cms_category_lang', 'configuration_lang', 'contact_lang', 'gender_lang', 'group_lang', 'meta_lang',
        'order_message_lang', 'order_return_state_lang', 'order_state_lang', 'quick_access_lang', 'risk_lang',
        'stock_mvt_reason_lang',
    ];

    private static $translateDemoTables = [
        'attribute_group_lang', 'attribute_lang', 'feature_lang', 'feature_value_lang', 'category_lang', 'order_message_lang',
    ];

    private $db;

    private $prefix;

    private $started;

    public $log = [];

    public function __construct()
    {
        $this->db = Db::getInstance();
        $this->prefix = _DB_PREFIX_;
        $this->started = microtime(true);
    }

    // =====================================================================
    // Datos de instalación
    // =====================================================================

    public static function supported()
    {
        return version_compare(_PS_VERSION_, self::MIN_VERSION, '>=')
            && class_exists('PrestaShopBundle\\Install\\XmlLoader');
    }

    public function baseDir()
    {
        return rtrim(_PS_ROOT_DIR_, '/\\') . '/var/josraresetshop/';
    }

    public function installDir()
    {
        return $this->baseDir() . 'install-' . _PS_VERSION_ . '/';
    }

    public function isReady()
    {
        $d = $this->installDir();

        return is_file($d . 'data/xml/configuration.xml') && is_file($d . 'langs/en/language.xml') && is_file($d . '.complete');
    }

    public function downloadUrl()
    {
        $custom = (string) Configuration::getGlobalValue('JOSRARESETSHOP_INSTALLER_URL');

        return $custom !== '' ? $custom : 'https://github.com/PrestaShop/PrestaShop/archive/refs/tags/' . rawurlencode(_PS_VERSION_) . '.zip';
    }

    public function info()
    {
        return [
            'supported' => self::supported(),
            'ready' => $this->isReady(),
            'version' => _PS_VERSION_,
            'path' => $this->installDir(),
            'base' => $this->baseDir(),
            'url' => $this->downloadUrl(),
            'can_download' => function_exists('curl_init') && class_exists('ZipArchive'),
            'zip' => class_exists('ZipArchive'),
        ];
    }

    private function prepareDir()
    {
        $base = $this->baseDir();
        if (!is_dir($base) && !@mkdir($base, 0755, true)) {
            throw new Exception('No se puede crear la carpeta ' . $base . ' (permisos de var/).');
        }
        if (!is_file($base . '.htaccess')) {
            @file_put_contents($base . '.htaccess', "Require all denied\nDeny from all\n");
        }
        if (!is_file($base . 'index.php')) {
            @file_put_contents($base . 'index.php', "<?php\nexit;\n");
        }
    }

    private function statusFile()
    {
        return $this->baseDir() . 'download.json';
    }

    public function readStatus()
    {
        $s = @json_decode((string) @file_get_contents($this->statusFile()), true);

        return is_array($s) ? $s : [];
    }

    private function writeStatus(array $s)
    {
        @file_put_contents($this->statusFile(), json_encode($s));
    }

    /** Zip ya presente (bajado o subido a mano por FTP a la carpeta del módulo en var/) */
    public function findZip()
    {
        foreach ((array) glob($this->baseDir() . '*.zip') as $f) {
            if (is_file($f) && filesize($f) > 1000000) {
                return $f;
            }
        }

        return null;
    }

    /** ¿Hay una descarga en marcha? (el proceso que descarga tiene cogido el bloqueo) */
    public function downloading()
    {
        $h = @fopen($this->baseDir() . 'download.lock', 'c');
        if (!$h) {
            return false;
        }
        $free = flock($h, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($h, LOCK_UN);
        }
        fclose($h);

        return !$free;
    }

    /**
     * Descarga el zip de GitHub. Bloquea el proceso el tiempo que tarde (lo llama el controlador
     * después de haber contestado al navegador, si el servidor lo permite).
     */
    public function download()
    {
        $this->prepareDir();
        $lock = fopen($this->baseDir() . 'download.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return;
        }
        @set_time_limit(1800);
        @ignore_user_abort(true);
        $part = $this->baseDir() . 'prestashop-' . _PS_VERSION_ . '.zip.part';
        $url = $this->downloadUrl();
        $this->writeStatus(['state' => 'downloading', 'url' => $url, 'started' => time(), 'file' => $part]);
        $fp = fopen($part, 'w');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 1500,
            CURLOPT_USERAGENT => 'josraresetshop',
            CURLOPT_FAILONERROR => true,
        ]);
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if (!$ok || filesize($part) < 1000000) {
            @unlink($part);
            $this->writeStatus(['state' => 'error', 'error' => 'No se ha podido bajar ' . $url . ' (' . ($err ? $err : 'HTTP ' . $code) . ').']);
        } else {
            rename($part, $this->baseDir() . 'prestashop-' . _PS_VERSION_ . '.zip');
            $this->writeStatus(['state' => 'downloaded']);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    /**
     * Saca del zip la carpeta de instalación (install-dev del código fuente o install/ del zip oficial).
     * Solo lo que hace falta: data/, langs/ (inglés y los idiomas de la tienda), fixtures/ y la clase de error.
     */
    public function extract($zipFile)
    {
        if (!class_exists('ZipArchive')) {
            throw new Exception('El servidor no tiene la extensión zip de PHP: no se puede abrir ' . basename($zipFile) . '.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            @unlink($zipFile);
            throw new Exception('El zip ' . basename($zipFile) . ' está dañado. Se ha borrado: vuelve a lanzarlo para bajarlo otra vez.');
        }
        // zip oficial: dentro va otro prestashop.zip
        if ($zip->locateName('prestashop.zip') !== false) {
            $inner = $this->baseDir() . 'inner-' . _PS_VERSION_ . '.zip';
            copy('zip://' . $zipFile . '#prestashop.zip', $inner);
            $zip->close();
            @unlink($zipFile);

            return $this->extract($inner);
        }
        $prefix = null;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^(.*?)(install-dev|install)/data/xml/configuration\.xml$#', $name, $m)) {
                $prefix = $m[1] . $m[2] . '/';
                break;
            }
        }
        if ($prefix === null) {
            $zip->close();
            throw new Exception('En ' . basename($zipFile) . ' no está la carpeta de instalación de PrestaShop (install-dev/ o install/).');
        }
        $isos = ['en'];
        foreach (Language::getLanguages(false) as $l) {
            $isos[] = Tools::strtolower($l['iso_code']);
        }
        $dest = $this->installDir();
        Tools::deleteDirectory($dest, true);
        @mkdir($dest, 0755, true);
        $len = strlen($prefix);
        $n = 0;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if (strpos($name, $prefix) !== 0 || substr($name, -1) === '/') {
                continue;
            }
            $rel = substr($name, $len);
            $want = strpos($rel, 'data/') === 0 || strpos($rel, 'fixtures/') === 0 || $rel === 'classes/exception.php';
            if (!$want && preg_match('#^langs/([^/]+)/#', $rel, $m)) {
                $want = in_array($m[1], $isos, true);
            }
            if (!$want && preg_match('#^fixtures/[^/]+/langs/([^/]+)/#', $rel, $m)) {
                $want = in_array($m[1], $isos, true);
            }
            if (!$want || strpos($rel, '..') !== false) {
                continue;
            }
            $target = $dest . $rel;
            if (!is_dir(dirname($target))) {
                @mkdir(dirname($target), 0755, true);
            }
            if (file_put_contents($target, $zip->getFromIndex($i)) !== false) {
                ++$n;
            }
        }
        $zip->close();
        @unlink($zipFile);
        if (!is_file($dest . 'data/xml/configuration.xml') || !is_file($dest . 'langs/en/language.xml')) {
            throw new Exception('Al sacar los datos de instalación falta algún archivo: vuelve a lanzarlo.');
        }
        file_put_contents($dest . '.complete', date('c'));
        $this->writeStatus(['state' => 'ready']);

        return $n;
    }

    // =====================================================================
    // Pasos
    // =====================================================================

    /**
     * Paso «datos de instalación». Devuelve true cuando ya están listos.
     * $state['wait'] lo pone el controlador cuando lanza la descarga y contesta antes de acabar.
     */
    public function stepInstaller(array &$state)
    {
        if ($this->isReady()) {
            $this->log[] = '✓ Datos de instalación de PrestaShop ' . _PS_VERSION_ . ' listos';

            return true;
        }
        $this->prepareDir();
        if ($zip = $this->findZip()) {
            $n = $this->extract($zip);
            $this->log[] = '✓ Datos de instalación de PrestaShop ' . _PS_VERSION_ . ' preparados (' . $n . ' archivos)';

            return true;
        }
        $status = $this->readStatus();
        if ($this->downloading()) {
            $size = isset($status['file']) && is_file($status['file']) ? filesize($status['file']) : 0;
            $this->log[] = '… Bajando PrestaShop ' . _PS_VERSION_ . ' de GitHub: ' . round($size / 1048576, 1) . ' MB';
            $state['polls'] = isset($state['polls']) ? $state['polls'] + 1 : 1;
            usleep(1500000);

            return false;
        }
        if (isset($status['state']) && $status['state'] === 'error' && !empty($state['started'])) {
            throw new Exception($status['error'] . ' Puedes subir tú el zip de PrestaShop ' . _PS_VERSION_ . ' (el oficial o el del código fuente de GitHub) por FTP a ' . $this->baseDir() . ' y volver a lanzarlo.');
        }
        // hay que bajarlo: lo hace el controlador (start_download)
        $state['start_download'] = 1;
        $state['started'] = 1;

        return false;
    }

    public function stepConfig(array &$state)
    {
        $this->seedIds();
        $loader = $this->loader();
        $xml = $this->xml('data/xml/configuration.xml');
        $xmlLang = $this->xml('langs/en/data/configuration.xml', false);
        $langValues = [];
        if ($xmlLang) {
            foreach ($xmlLang->configuration as $node) {
                $langValues[(string) $node['id']] = (string) $node->value;
            }
        }
        $keep = array_flip(self::$keepConfig);
        $names = [];
        $n = 0;
        foreach ($xml->entities->configuration as $node) {
            $name = (string) $node['name'];
            if ($name === '' || isset($keep[$name]) || strpos($name, 'PS_OS_') === 0) {
                continue;
            }
            $id = (string) $node['id'];
            $names[] = $name;
            if (isset($langValues[$id])) {
                $vals = [];
                foreach (Language::getIDs(false) as $idLang) {
                    $vals[(int) $idLang] = $langValues[$id];
                }
                Configuration::updateGlobalValue($name, $vals, true);
            } else {
                Configuration::updateGlobalValue($name, (string) $node->value, true);
            }
            ++$n;
        }
        foreach (self::$emptyConfig as $name) {
            Configuration::updateGlobalValue($name, '');
            $names[] = $name;
        }
        Configuration::updateGlobalValue('PS_SHOP_EMAIL', Context::getContext()->employee->email);
        // los valores propios de cada tienda o grupo de tiendas de esas claves desaparecen
        if ($names) {
            $in = '\'' . implode('\',\'', array_map('pSQL', $names)) . '\'';
            $ids = $this->db->executeS('SELECT id_configuration FROM `' . $this->prefix . 'configuration` WHERE name IN (' . $in . ') AND (id_shop IS NOT NULL OR id_shop_group IS NOT NULL)');
            $list = [];
            foreach ((array) $ids as $r) {
                $list[] = (int) $r['id_configuration'];
            }
            if ($list) {
                $this->db->execute('DELETE FROM `' . $this->prefix . 'configuration_lang` WHERE id_configuration IN (' . implode(',', $list) . ')');
                $this->db->execute('DELETE FROM `' . $this->prefix . 'configuration` WHERE id_configuration IN (' . implode(',', $list) . ')');
            }
        }
        Configuration::loadConfiguration();
        $this->saveIds($loader);
        $this->log[] = '✓ Configuración de fábrica: ' . $n . ' ajustes (se conservan dominio, SSL, idioma, país, moneda, logos y la de los módulos)';

        return true;
    }

    public function stepGroups(array &$state)
    {
        $loader = $this->loader();
        $map = ['Visitor' => 'PS_UNIDENTIFIED_GROUP', 'Guest' => 'PS_GUEST_GROUP', 'Customer' => 'PS_CUSTOMER_GROUP'];
        $keep = [];
        $xml = $this->xml('data/xml/group.xml');
        $names = $this->langNames('group', 'name');
        foreach ($xml->entities->group as $node) {
            $ident = (string) $node['id'];
            if (!isset($map[$ident])) {
                continue;
            }
            $id = (int) Configuration::get($map[$ident]);
            if (!$id) {
                continue;
            }
            $keep[] = $id;
            $loader->storeId('group', $ident, $id);
            $this->update('group', [
                'reduction' => (float) $node['reduction'],
                'price_display_method' => (int) $node['price_display_method'],
                'show_prices' => (int) $node['show_prices'],
            ], 'id_group = ' . $id);
            if (isset($names[$ident])) {
                $this->update('group_lang', ['name' => pSQL($names[$ident])], 'id_group = ' . $id);
            }
        }
        if (!$keep) {
            throw new Exception('No se encuentran los grupos Visitante, Invitado y Cliente en la configuración.');
        }
        $in = implode(',', $keep);
        $deleted = (int) $this->db->getValue('SELECT COUNT(*) FROM `' . $this->prefix . 'group` WHERE id_group NOT IN (' . $in . ')');
        foreach (['group_lang', 'group_shop', 'group_reduction', 'category_group', 'module_group', 'carrier_group', 'cart_rule_group', 'product_group_reduction_cache', 'group'] as $t) {
            if ($this->tableExists($t)) {
                $this->db->execute('DELETE FROM `' . $this->prefix . $t . '` WHERE id_group NOT IN (' . $in . ')');
            }
        }
        $this->db->execute('DELETE FROM `' . $this->prefix . 'specific_price` WHERE id_group > 0 AND id_group NOT IN (' . $in . ')');
        // los tres grupos en todas las tiendas, y todos los módulos visibles para ellos (como en una instalación)
        foreach (Shop::getShops(false, null, true) as $idShop) {
            foreach ($keep as $g) {
                $this->db->execute('INSERT IGNORE INTO `' . $this->prefix . 'group_shop` (id_group, id_shop) VALUES (' . (int) $g . ', ' . (int) $idShop . ')');
                $this->db->execute('INSERT IGNORE INTO `' . $this->prefix . 'module_group` (id_module, id_shop, id_group)
                    SELECT id_module, ' . (int) $idShop . ', ' . (int) $g . ' FROM `' . $this->prefix . 'module_shop` WHERE id_shop = ' . (int) $idShop);
            }
        }
        $this->saveIds($loader);
        $this->log[] = '✓ Grupos de clientes de fábrica (Visitante, Invitado, Cliente)' . ($deleted ? '; ' . $deleted . ' grupos propios borrados' : '');

        return true;
    }

    public function stepOrderStates(array &$state)
    {
        $loader = $this->loader();
        $xml = $this->xml('data/xml/order_state.xml');
        $names = $this->langNames('order_state', 'name');
        $templates = $this->langNames('order_state', 'template');
        // el instalador numera los estados en el orden del XML; las claves PS_OS_* dicen cuál es cuál en esta tienda
        $byIndex = [];
        $keysByIndex = [];
        foreach ($this->xml('data/xml/configuration.xml')->entities->configuration as $c) {
            $name = (string) $c['name'];
            if (strpos($name, 'PS_OS_') === 0 && ctype_digit((string) $c->value)) {
                $keysByIndex[(int) $c->value][] = $name;
                $current = (int) Configuration::getGlobalValue($name);
                if ($current && $this->db->getValue('SELECT 1 FROM `' . $this->prefix . 'order_state` WHERE id_order_state = ' . $current)) {
                    $byIndex[(int) $c->value] = $current;
                }
            }
        }
        $flags = ['invoice', 'send_email', 'color', 'unremovable', 'hidden', 'logable', 'delivery', 'shipped', 'paid', 'pdf_delivery', 'pdf_invoice'];
        $i = 0;
        $kept = [];
        $created = 0;
        foreach ($xml->entities->order_state as $node) {
            ++$i;
            $ident = (string) $node['id'];
            $module = (string) $node->module_name;
            $id = isset($byIndex[$i]) ? $byIndex[$i] : 0;
            if (!$id && $module !== '') {
                // ya creado antes (estado de un módulo de pago de serie): se reutiliza
                $id = (int) $this->db->getValue('SELECT id_order_state FROM `' . $this->prefix . 'order_state` WHERE module_name = \'' . pSQL($module) . '\'' . ($kept ? ' AND id_order_state NOT IN (' . implode(',', $kept) . ')' : '') . ' ORDER BY id_order_state');
            }
            if (!$id) {
                // no existe en esta tienda: se crea como en una instalación
                $os = new OrderState();
                foreach ($flags as $f) {
                    if (isset($node[$f])) {
                        $os->{$f} = $f === 'color' ? (string) $node[$f] : (int) $node[$f];
                    }
                }
                $os->module_name = $module;
                foreach (Language::getIDs(false) as $l) {
                    $os->name[$l] = isset($names[$ident]) ? $names[$ident] : $ident;
                    $os->template[$l] = isset($templates[$ident]) ? $templates[$ident] : '';
                }
                if (!$os->add()) {
                    throw new Exception('No se ha podido crear el estado de pedido ' . $ident);
                }
                $id = (int) $os->id;
                ++$created;
                $img = $this->installDir() . 'data/img/os/' . $ident . '.gif';
                if (is_file($img)) {
                    @copy($img, rtrim(_PS_IMG_DIR_, '/\\') . '/os/' . $id . '.gif');
                }
            }
            if (isset($keysByIndex[$i])) {
                foreach ($keysByIndex[$i] as $key) {
                    Configuration::updateGlobalValue($key, $id);
                }
            }
            $kept[] = $id;
            $loader->storeId('order_state', $ident, $id);
            $data = ['deleted' => 0, 'module_name' => pSQL($module)];
            foreach ($flags as $f) {
                if (isset($node[$f])) {
                    $data[$f] = pSQL((string) $node[$f]);
                }
            }
            $this->update('order_state', $data, 'id_order_state = ' . (int) $id);
            if (isset($names[$ident])) {
                $this->update('order_state_lang', [
                    'name' => pSQL($names[$ident]),
                    'template' => pSQL(isset($templates[$ident]) ? $templates[$ident] : ''),
                ], 'id_order_state = ' . (int) $id);
            }
        }
        // los que creó la tienda a mano (sin módulo) desaparecen; los de otros módulos (Redsys, MBE...) se quedan
        $where = '(module_name IS NULL OR module_name = \'\')' . ($kept ? ' AND id_order_state NOT IN (' . implode(',', $kept) . ')' : '');
        $del = [];
        foreach ((array) $this->db->executeS('SELECT id_order_state FROM `' . $this->prefix . 'order_state` WHERE ' . $where) as $r) {
            $del[] = (int) $r['id_order_state'];
        }
        if ($del) {
            $this->db->execute('DELETE FROM `' . $this->prefix . 'order_state_lang` WHERE id_order_state IN (' . implode(',', $del) . ')');
            $this->db->execute('DELETE FROM `' . $this->prefix . 'order_state` WHERE id_order_state IN (' . implode(',', $del) . ')');
        }
        Configuration::loadConfiguration();
        $this->saveIds($loader);
        $this->log[] = '✓ Estados de pedido de fábrica: ' . count($kept) . ($created ? ' (' . $created . ' creados de nuevo)' : '') . ($del ? '; ' . count($del) . ' propios borrados' : '') . ' (los de otros módulos se quedan)';

        return true;
    }

    public function stepCarriers(array &$state)
    {
        $loader = $this->loader();
        $p = $this->prefix;
        $ids = [];
        $refs = [];
        foreach ((array) $this->db->executeS('SELECT id_carrier, id_reference FROM `' . $p . 'carrier` WHERE is_module = 0 AND (external_module_name IS NULL OR external_module_name = \'\')') as $r) {
            $ids[] = (int) $r['id_carrier'];
            $refs[] = (int) $r['id_reference'];
        }
        if ($ids) {
            $in = implode(',', $ids);
            foreach (['carrier_lang', 'carrier_group', 'carrier_zone', 'carrier_shop', 'carrier_tax_rules_group_shop', 'delivery', 'range_price', 'range_weight', 'warehouse_carrier', 'cart_rule_carrier'] as $t) {
                if ($this->tableExists($t)) {
                    $this->db->execute('DELETE FROM `' . $p . $t . '` WHERE id_carrier IN (' . $in . ')');
                }
            }
            $rin = implode(',', array_unique($refs));
            $this->db->execute('DELETE FROM `' . $p . 'module_carrier` WHERE id_reference IN (' . $rin . ')');
            $this->db->execute('DELETE FROM `' . $p . 'product_carrier` WHERE id_carrier_reference IN (' . $rin . ')');
            $this->db->execute('DELETE FROM `' . $p . 'carrier` WHERE id_carrier IN (' . $in . ')');
            foreach ($ids as $id) {
                @unlink(rtrim(_PS_IMG_DIR_, '/\\') . '/s/' . $id . '.jpg');
            }
        }
        $this->populate($loader, ['carrier', 'carrier_group', 'carrier_zone', 'carrier_tax_rules_group_shop']);
        $first = null;
        foreach ($this->xml('data/xml/carrier.xml')->entities->carrier as $node) {
            $id = (int) $loader->retrieveId('carrier', (string) $node['id']);
            if ($id) {
                $this->update('carrier', ['id_reference' => $id], 'id_carrier = ' . $id);
                // los módulos de pago que ya tenían transportistas lo tienen también
                $this->db->execute('INSERT IGNORE INTO `' . $p . 'module_carrier` (id_module, id_shop, id_reference)
                    SELECT DISTINCT id_module, id_shop, ' . $id . ' FROM `' . $p . 'module_carrier`');
                if ($first === null) {
                    $first = $id;
                }
            }
        }
        if ($first) {
            Configuration::updateGlobalValue('PS_CARRIER_DEFAULT', $first);
        }
        $this->saveIds($loader);
        $this->log[] = '✓ Transportistas de fábrica' . ($ids ? '; ' . count($ids) . ' propios borrados' : '') . ' (los de los módulos se quedan)';

        return true;
    }

    public function stepCms(array &$state)
    {
        $loader = $this->loader();
        foreach (['cms_role_lang', 'cms_role', 'cms_lang', 'cms_shop', 'cms', 'cms_category_lang', 'cms_category_shop', 'cms_category'] as $t) {
            $this->truncate($t);
        }
        $this->populate($loader, ['cms_category', 'cms', 'cms_role']);
        $this->saveIds($loader);
        $this->log[] = '✓ Páginas CMS de fábrica (' . (int) $this->db->getValue('SELECT COUNT(*) FROM `' . $this->prefix . 'cms`') . ')';

        return true;
    }

    public function stepContacts(array &$state)
    {
        $loader = $this->loader();
        foreach (['contact_lang', 'contact_shop', 'contact'] as $t) {
            $this->truncate($t);
        }
        $this->populate($loader, ['contact']);
        $this->saveIds($loader);
        $this->log[] = '✓ Contactos de atención al cliente de fábrica';

        return true;
    }

    public function stepMeta(array &$state)
    {
        $xml = $this->xml('data/xml/meta.xml');
        $lang = $this->xml('langs/en/data/meta.xml', false);
        $texts = [];
        if ($lang) {
            foreach ($lang->meta as $m) {
                $texts[(string) $m['id']] = ['title' => (string) $m->title, 'description' => (string) $m->description, 'url_rewrite' => (string) $m->url_rewrite];
            }
        }
        $pages = [];
        $n = 0;
        foreach ($xml->entities->meta as $node) {
            $page = (string) $node->page;
            $pages[] = $page;
            $id = (int) $this->db->getValue('SELECT id_meta FROM `' . $this->prefix . 'meta` WHERE page = \'' . pSQL($page) . '\'');
            if (!$id) {
                $meta = new Meta();
                $meta->page = $page;
                $meta->configurable = (int) $node->configurable;
                foreach (Language::getIDs(false) as $l) {
                    $meta->url_rewrite[$l] = isset($texts[(string) $node['id']]) ? $texts[(string) $node['id']]['url_rewrite'] : '';
                    $meta->title[$l] = isset($texts[(string) $node['id']]) ? $texts[(string) $node['id']]['title'] : '';
                }
                $meta->add();
                $id = (int) $meta->id;
            } else {
                $this->update('meta', ['configurable' => (int) $node->configurable], 'id_meta = ' . $id);
            }
            if ($id && isset($texts[(string) $node['id']])) {
                $t = $texts[(string) $node['id']];
                $data = [
                    'title' => pSQL($t['title']),
                    'description' => pSQL($t['description']),
                    'url_rewrite' => pSQL($t['url_rewrite']),
                ];
                if ($this->hasColumn('meta_lang', 'keywords')) {
                    $data['keywords'] = '';
                }
                $this->update('meta_lang', $data, 'id_meta = ' . $id);
            }
            ++$n;
        }
        // páginas que añadió la tienda a mano (las de los módulos, module-..., se quedan)
        $in = '\'' . implode('\',\'', array_map('pSQL', $pages)) . '\'';
        $del = [];
        foreach ((array) $this->db->executeS('SELECT id_meta FROM `' . $this->prefix . 'meta` WHERE page NOT IN (' . $in . ') AND page NOT LIKE \'module-%\'') as $r) {
            $del[] = (int) $r['id_meta'];
        }
        if ($del) {
            $this->db->execute('DELETE FROM `' . $this->prefix . 'meta_lang` WHERE id_meta IN (' . implode(',', $del) . ')');
            $this->db->execute('DELETE FROM `' . $this->prefix . 'meta` WHERE id_meta IN (' . implode(',', $del) . ')');
        }
        $this->log[] = '✓ Títulos y URLs de las páginas de fábrica (' . $n . ' páginas)' . ($del ? '; ' . count($del) . ' propias borradas' : '');

        return true;
    }

    public function stepMisc(array &$state)
    {
        $loader = $this->loader();
        $reload = [
            'gender' => ['gender_lang', 'gender'],
            'order_return_state' => ['order_return_state_lang', 'order_return_state'],
            'quick_access' => ['quick_access_lang', 'quick_access'],
            'risk' => ['risk_lang', 'risk'],
            'stock_mvt_reason' => ['stock_mvt_reason_lang', 'stock_mvt_reason'],
        ];
        foreach ($reload as $entity => $tables) {
            if (!is_file($this->installDir() . 'data/xml/' . $entity . '.xml')) {
                continue;
            }
            foreach ($tables as $t) {
                $this->truncate($t);
            }
            $this->populate($loader, [$entity]);
        }
        $empty = [
            'store_lang', 'store_shop', 'store', 'order_message_lang', 'order_message', 'webservice_permission',
            'webservice_account_shop', 'webservice_account', 'request_sql', 'alias', 'translation', 'referrer_shop',
            'referrer', 'customer_message_sync_imap', 'search_index', 'search_word',
        ];
        foreach ($empty as $t) {
            $this->truncate($t);
        }
        $dir = rtrim(_PS_IMG_DIR_, '/\\') . '/st/';
        foreach ((array) @scandir($dir) as $f) {
            if (preg_match('/^\d+[-_.]/', $f)) {
                @unlink($dir . $f);
            }
        }
        $this->saveIds($loader);
        $this->log[] = '✓ Tratamientos, estados de devolución, accesos rápidos, motivos de stock de fábrica; tiendas físicas, mensajes predefinidos, webservice, consultas SQL, alias de búsqueda y traducciones propias vaciados';

        return true;
    }

    public function stepTranslate(array &$state, $demo = false)
    {
        $tables = $demo ? array_merge(self::$translateTables, self::$translateDemoTables) : self::$translateTables;
        $full = [];
        foreach ($tables as $t) {
            if ($this->tableExists($t)) {
                $full[] = $this->prefix . $t;
            }
        }
        $n = 0;
        foreach (Language::getLanguages(false) as $l) {
            if ($l['iso_code'] === 'en') {
                continue;
            }
            if (method_exists('Language', 'updateMultilangTables')) {
                Language::updateMultilangTables(new Language((int) $l['id_lang']), $full);
            } else {
                Language::updateMultilangTable($l['iso_code']);
            }
            ++$n;
        }
        $this->log[] = $n ? '✓ Textos de fábrica traducidos a ' . $n . ($n === 1 ? ' idioma' : ' idiomas') : '— Solo hay inglés, nada que traducir';

        return true;
    }

    /**
     * Productos de demostración: las fijas del instalador, unas cuantas entidades por llamada.
     */
    public function stepDemo(array &$state)
    {
        $loader = $this->loader(true);
        $entities = $loader->getSortedEntities();
        $entities = array_values(array_diff($entities, self::$skipFixtures));
        $i = isset($state['i']) ? (int) $state['i'] : 0;
        if ($i === 0) {
            // lo que hace la clase de las fijas antes de empezar: el grupo de impuestos de la dirección de John
            $tax = 0;
            $us = (int) Country::getByIso('US');
            $fl = $us ? (int) State::getIdByIso('FL', $us) : 0;
            if ($us && $fl) {
                $tax = (int) $this->db->getValue('SELECT id_tax_rules_group FROM `' . $this->prefix . 'tax_rule` WHERE id_country = ' . $us . ' AND id_state = ' . $fl);
            }
            $loader->storeId('tax_rules_group', 'default_tax_rule_group', $tax ? $tax : 1);
        }
        while ($i < count($entities)) {
            $entity = $entities[$i];
            $this->withUnityIncrement(function () use ($loader, $entity) {
                $loader->populateEntity($entity);
            });
            if ($errors = $loader->getErrors()) {
                throw new Exception('Productos de demostración (' . $entity . '): ' . strip_tags(implode(' ', (array) $errors)));
            }
            ++$i;
            $this->saveIds($loader);
            if ($this->outOfTime(20)) {
                break;
            }
        }
        $state['i'] = $i;
        if ($i < count($entities)) {
            $this->log[] = '… Productos de demostración: ' . $i . ' de ' . count($entities) . ' partes';

            return false;
        }
        // lo que hace la clase de las fijas al acabar
        $this->db->execute('UPDATE `' . $this->prefix . 'country` SET active = 1 WHERE id_country IN (SELECT id_country FROM `' . $this->prefix . 'address`)');
        Category::regenerateEntireNtree();
        if (class_exists('Search')) {
            Search::indexation(true);
        }
        if (Module::isInstalled('ps_facetedsearch') && ($m = Module::getInstanceByName('ps_facetedsearch')) && method_exists($m, 'rebuildPriceIndexTable')) {
            try {
                $m->rebuildPriceIndexTable();
                if (method_exists($m, 'invalidateLayeredFilterBlockCache')) {
                    $m->invalidateLayeredFilterBlockCache();
                }
            } catch (Exception $e) {
                $this->log[] = '— Búsqueda por facetas: reconstruye el índice desde su configuración (' . $e->getMessage() . ')';
            }
        }
        $this->log[] = '✓ Productos de demostración cargados (' . (int) $this->db->getValue('SELECT COUNT(*) FROM `' . $this->prefix . 'product`') . ' productos)';

        return true;
    }

    // =====================================================================
    // Instalador de PrestaShop
    // =====================================================================

    /** @return \PrestaShopBundle\Install\XmlLoader */
    private function loader($fixtures = false)
    {
        if (!$this->isReady()) {
            throw new Exception('Faltan los datos de instalación de PrestaShop ' . _PS_VERSION_ . '.');
        }
        $dir = $this->installDir();
        if (!defined('_PS_INSTALL_DATA_PATH_')) {
            define('_PS_INSTALL_DATA_PATH_', $dir . 'data/');
        }
        if (!defined('_PS_INSTALL_LANGS_PATH_')) {
            define('_PS_INSTALL_LANGS_PATH_', $dir . 'langs/');
        }
        if (!defined('_PS_INSTALL_FIXTURES_PATH_')) {
            define('_PS_INSTALL_FIXTURES_PATH_', $dir . 'fixtures/');
        }
        if (!class_exists('PrestashopInstallerException', false)) {
            require_once $dir . 'classes/exception.php';
        }
        \PrestaShopBundle\Install\LanguageList::getInstance()->setLanguage('en');
        if ($fixtures) {
            require_once $dir . 'fixtures/fashion/install.php';
            $loader = new InstallFixturesFashion();
            $loader->setFixturesPath($dir . 'fixtures/fashion/');
        } else {
            $loader = new \PrestaShopBundle\Install\XmlLoader();
        }
        $loader->setTranslator(Context::getContext()->getTranslator());
        $languages = [];
        foreach (Language::getLanguages(false) as $l) {
            $languages[(int) $l['id_lang']] = $l['iso_code'];
        }
        $loader->setLanguages($languages);
        $loader->setIds($this->loadIds());

        return $loader;
    }

    private function populate($loader, array $entities)
    {
        foreach ($entities as $entity) {
            if (!is_file($this->installDir() . 'data/xml/' . $entity . '.xml')) {
                continue;
            }
            $this->withUnityIncrement(function () use ($loader, $entity) {
                $loader->populateEntity($entity);
            });
            if ($errors = $loader->getErrors()) {
                throw new Exception($entity . ': ' . strip_tags(implode(' ', (array) $errors)));
            }
        }
    }

    private function withUnityIncrement($fn)
    {
        $this->db->execute('SET SESSION auto_increment_increment = 1', false);
        $this->db->execute('SET SESSION auto_increment_offset = 1', false);
        $fn();
    }

    private function idsFile()
    {
        return $this->baseDir() . 'ids.json';
    }

    /** IDs de los datos de instalación que ya existen en la tienda (para que las relaciones del instalador encajen) */
    public function seedIds()
    {
        $ids = [];
        $ids['category:Root'] = (int) Configuration::get('PS_ROOT_CATEGORY');
        $ids['category:Home'] = (int) Configuration::get('PS_HOME_CATEGORY');
        foreach (['Visitor' => 'PS_UNIDENTIFIED_GROUP', 'Guest' => 'PS_GUEST_GROUP', 'Customer' => 'PS_CUSTOMER_GROUP'] as $k => $c) {
            $ids['group:' . $k] = (int) Configuration::get($c);
        }
        if ($xml = $this->xml('data/xml/zone.xml', false)) {
            foreach ($xml->entities->zone as $z) {
                $id = (int) Zone::getIdByName(str_replace('_', ' ', (string) $z['id']));
                if ($id) {
                    $ids['zone:' . (string) $z['id']] = $id;
                }
            }
        }
        if ($xml = $this->xml('data/xml/country.xml', false)) {
            foreach ($xml->entities->country as $c) {
                $id = (int) Country::getByIso((string) $c['iso_code']);
                if ($id) {
                    $ids['country:' . (string) $c['id']] = $id;
                }
            }
        }
        if ($xml = $this->xml('data/xml/state.xml', false)) {
            foreach ($xml->entities->state as $s) {
                $country = isset($ids['country:' . (string) $s['id_country']]) ? $ids['country:' . (string) $s['id_country']] : 0;
                $id = $country ? (int) State::getIdByIso((string) $s['iso_code'], $country) : 0;
                if ($id) {
                    $ids['state:' . (string) $s['id']] = $id;
                }
            }
        }
        @file_put_contents($this->idsFile(), json_encode($ids));
    }

    private function loadIds()
    {
        $ids = @json_decode((string) @file_get_contents($this->idsFile()), true);

        return is_array($ids) ? $ids : [];
    }

    private function saveIds($loader)
    {
        @file_put_contents($this->idsFile(), json_encode($loader->getIds()));
    }

    /** @return SimpleXMLElement|null */
    private function xml($rel, $required = true)
    {
        $file = $this->installDir() . $rel;
        $xml = is_file($file) ? @simplexml_load_file($file) : false;
        if (!$xml && $required) {
            throw new Exception('Falta o está mal el archivo de instalación ' . $rel);
        }

        return $xml ? $xml : null;
    }

    /** Textos en inglés de una entidad (langs/en/data/<entidad>.xml): identificador => valor */
    private function langNames($entity, $field)
    {
        $out = [];
        if ($xml = $this->xml('langs/en/data/' . $entity . '.xml', false)) {
            foreach ($xml->$entity as $node) {
                $out[(string) $node['id']] = isset($node->$field) ? (string) $node->$field : (string) $node[$field];
            }
        }

        return $out;
    }

    /** UPDATE que avisa si falla (en vez de seguir como si nada) */
    private function update($table, array $data, $where)
    {
        if (!$this->db->update($table, $data, $where)) {
            throw new Exception('Error al actualizar ' . $table . ': ' . $this->db->getMsgError());
        }
    }

    private function hasColumn($table, $column)
    {
        return (bool) $this->db->getValue('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'' . pSQL($this->prefix . $table) . '\' AND COLUMN_NAME = \'' . pSQL($column) . '\'');
    }

    private function truncate($table)
    {
        if ($this->tableExists($table)) {
            $this->db->execute('TRUNCATE TABLE `' . $this->prefix . bqSQL($table) . '`');
        }
    }

    private function tableExists($table)
    {
        static $cache = [];
        if (!isset($cache[$table])) {
            $cache[$table] = (bool) $this->db->getValue('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'' . pSQL($this->prefix . $table) . '\'');
        }

        return $cache[$table];
    }

    private function outOfTime($budget)
    {
        return (microtime(true) - $this->started) > $budget;
    }
}
