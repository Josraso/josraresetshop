<?php
/**
 * JosraResetEngine - motor del vaciado.
 *
 * Cada sección se convierte en una lista de pasos. Cada paso trabaja como mucho
 * unos segundos y, si no ha terminado, se vuelve a llamar (borrado por tandas),
 * para no chocar con los límites de PHP ni del servidor web (proxy de Plesk).
 *
 * PrestaShop 1.7 a 9. Sintaxis válida para PHP 5.6.
 *
 * @author josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JosraResetEngine
{
    /** Filas por tanda en los borrados parciales */
    const BATCH = 5000;

    /** Segundos de trabajo por llamada (luego se devuelve el control al navegador) */
    const BUDGET = 8;

    /** Orden fijo en el que se ejecutan las secciones */
    private static $order = [
        'factory', 'orders', 'customers', 'carts', 'messages', 'cart_rules', 'catalog', 'categories',
        'attributes', 'manufacturers', 'employees', 'stats', 'thumbs',
    ];

    /** Secciones que obligan a marcar otras (y por qué) */
    private static $requires = [
        'factory' => ['orders', 'customers', 'carts', 'messages', 'cart_rules', 'catalog', 'categories', 'attributes', 'manufacturers', 'employees', 'stats'],
        'customers' => ['orders', 'carts'],
        'catalog' => ['carts'],
        'categories' => ['catalog'],
        'attributes' => ['catalog'],
    ];

    /** Módulos que se conectan a PrestaShop o a Addons */
    private static $connectedModules = [
        'ps_accounts', 'ps_mbo', 'ps_eventbus', 'ps_checkout', 'ps_metrics', 'ps_facebook',
        'psxmarketingwithgoogle', 'ps_distributionapiclient', 'psshipping', 'ps_edition_basic',
        'psxdesign', 'ps_analytics', 'autoupgrade', 'gamification', 'psaddonsconnect', 'ps_onboarding',
        'ps_shoppingfeed', 'ps_buybuttonlite',
    ];

    /** Columnas que delatan datos de pedidos, clientes, carritos o productos en tablas de otros módulos */
    private static $dataColumns = ['id_order', 'id_customer', 'id_cart', 'id_product', 'id_guest', 'id_address'];

    /** @var Db */
    private $db;

    private $prefix;

    private $started;

    private $log = [];

    private $errors = [];

    private $coreTables = null;

    public function __construct()
    {
        $this->db = Db::getInstance();
        $this->prefix = _DB_PREFIX_;
        $this->started = microtime(true);
    }

    // =====================================================================
    // Información para la pantalla
    // =====================================================================

    public function getSectionsInfo()
    {
        $c = function ($sql) {
            return (int) Db::getInstance()->getValue($sql);
        };
        $p = $this->prefix;

        $list = [
            'orders' => [
                'label' => 'Pedidos',
                'desc' => 'Pedidos, facturas, albaranes, pagos, devoluciones, abonos, envíos, sus mensajes y los movimientos de stock de los pedidos. Los pedidos vuelven a empezar por el 1.',
                'count' => $this->tableExists('orders') ? $c('SELECT COUNT(*) FROM `' . $p . 'orders`') : 0,
                'unit' => 'pedidos',
            ],
            'customers' => [
                'label' => 'Clientes y direcciones',
                'desc' => 'Clientes, sus direcciones, sesiones, mensajes, cupones y precios personales, y las direcciones sueltas que no son de nadie.',
                'count' => $c('SELECT COUNT(*) FROM `' . $p . 'customer`'),
                'unit' => 'clientes',
            ],
            'carts' => [
                'label' => 'Carritos sin pedido',
                'desc' => 'Carritos abandonados (los que no llegaron a ser pedido), con sus personalizaciones. Los carritos de los pedidos que se quedan no se tocan.',
                'count' => $c('SELECT COUNT(*) FROM `' . $p . 'cart` c LEFT JOIN `' . $p . 'orders` o ON o.id_cart = c.id_cart WHERE o.id_order IS NULL'),
                'unit' => 'carritos',
            ],
            'messages' => [
                'label' => 'Mensajes de atención al cliente sin pedido',
                'desc' => 'Hilos y mensajes del servicio de atención al cliente que no van unidos a un pedido (los de pedidos se borran con «Pedidos»).',
                'count' => $c('SELECT COUNT(*) FROM `' . $p . 'customer_thread` WHERE id_order = 0'),
                'unit' => 'hilos',
            ],
            'cart_rules' => [
                'label' => 'Cupones, descuentos y reglas de precios de catálogo',
                'desc' => 'Reglas de carrito y cupones, y las reglas de precios de catálogo con los precios que generan.',
                'count' => $c('SELECT COUNT(*) FROM `' . $p . 'cart_rule`') + $c('SELECT COUNT(*) FROM `' . $p . 'specific_price_rule`'),
                'unit' => 'reglas',
            ],
            'catalog' => [
                'label' => 'Catálogo (productos)',
                'desc' => 'Productos, combinaciones, precios específicos, stock y movimientos, packs, accesorios, personalizaciones, etiquetas, adjuntos y descargables, índice del buscador y las imágenes de los productos (base de datos y archivos).',
                'count' => $c('SELECT COUNT(*) FROM `' . $p . 'product`'),
                'unit' => 'productos',
            ],
            'categories' => [
                'label' => 'Categorías',
                'desc' => 'Todas las categorías y sus imágenes, menos la Raíz y el Inicio de cada tienda. Vuelve a calcular el árbol.',
                'count' => $c('SELECT COUNT(*) FROM `' . $p . 'category` WHERE id_category NOT IN (' . implode(',', $this->keptCategories()) . ')'),
                'unit' => 'categorías',
            ],
            'attributes' => [
                'label' => 'Atributos y características',
                'desc' => 'Grupos de atributos, atributos (y sus texturas de color), características y sus valores.',
                'count' => $c('SELECT COUNT(*) FROM `' . $p . 'attribute_group`') + $c('SELECT COUNT(*) FROM `' . $p . 'feature`'),
                'unit' => 'grupos y características',
            ],
            'manufacturers' => [
                'label' => 'Marcas y proveedores',
                'desc' => 'Marcas, proveedores, sus direcciones e imágenes. Si los productos se quedan, se les quita la marca y el proveedor.',
                'count' => $c('SELECT COUNT(*) FROM `' . $p . 'manufacturer`') + $c('SELECT COUNT(*) FROM `' . $p . 'supplier`'),
                'unit' => 'marcas y proveedores',
            ],
            'employees' => [
                'label' => 'Empleados (menos tú)',
                'desc' => 'Todos los empleados menos con el que estás conectado, con sus sesiones y tiendas asignadas.',
                'count' => $c('SELECT COUNT(*) FROM `' . $p . 'employee` WHERE id_employee != ' . (int) Context::getContext()->employee->id),
                'unit' => 'empleados',
            ],
            'stats' => [
                'label' => 'Estadísticas y registros',
                'desc' => 'Visitas, conexiones, páginas vistas, orígenes, búsquedas, páginas no encontradas, registro de correos y de PrestaShop, y los archivos de log del servidor.',
                'count' => $this->tableExists('connections') ? $c('SELECT COUNT(*) FROM `' . $p . 'connections`') : 0,
                'unit' => 'conexiones',
            ],
            'thumbs' => [
                'label' => 'Miniaturas de imágenes',
                'desc' => 'Borra solo las miniaturas generadas (home_default, small_default…, también .webp y .avif) y conserva las imágenes originales. Después regenera las miniaturas en Diseño > Ajustes de imágenes.',
                'count' => -1,
                'unit' => '',
            ],
        ];

        $icons = ['orders' => 'shopping_cart', 'customers' => 'people', 'carts' => 'remove_shopping_cart', 'messages' => 'forum', 'cart_rules' => 'local_offer', 'catalog' => 'inventory_2', 'categories' => 'account_tree', 'attributes' => 'tune', 'manufacturers' => 'factory', 'employees' => 'badge', 'stats' => 'insights', 'thumbs' => 'photo_size_select_large'];
        foreach ($list as $key => $info) {
            $list[$key]['key'] = $key;
            $list[$key]['icon'] = isset($icons[$key]) ? $icons[$key] : 'delete';
            $list[$key]['requires'] = isset(self::$requires[$key]) ? self::$requires[$key] : [];
            $list[$key]['requires_str'] = implode(',', $list[$key]['requires']);
            $list[$key]['group'] = $key === 'thumbs' ? 'files' : 'data';
        }

        return $list;
    }

    /**
     * Tablas de otros módulos con datos de pedidos, clientes, carritos o productos, agrupadas por módulo.
     */
    public function getModuleTables()
    {
        $core = $this->coreTables();
        $plen = strlen($this->prefix);
        $rows = $this->db->executeS(
            'SELECT t.TABLE_NAME AS t, t.TABLE_ROWS AS r, GROUP_CONCAT(c.COLUMN_NAME) AS cols
             FROM information_schema.TABLES t
             JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME
             WHERE t.TABLE_SCHEMA = DATABASE() AND LEFT(t.TABLE_NAME, ' . (int) strlen($this->prefix) . ') = \'' . pSQL($this->prefix) . '\'
             GROUP BY t.TABLE_NAME, t.TABLE_ROWS'
        );
        $modules = $this->moduleNames();
        $groups = [];
        foreach ((array) $rows as $row) {
            $table = substr($row['t'], $plen);
            if ($table === '' || in_array($table, $core, true)) {
                continue;
            }
            $cols = array_intersect(explode(',', $row['cols']), self::$dataColumns);
            $isList = preg_match('/newsletter|emailsubscription/i', $table);
            if (!$cols && !$isList) {
                continue;
            }
            $owner = $this->guessModule($table, $modules);
            if ($owner === 'josraresetshop') {
                continue;
            }
            $key = $owner ? $owner : '_';
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'module' => $owner,
                    'name' => $owner ? $this->moduleDisplayName($owner) : 'Sin módulo reconocido',
                    'installed' => $owner ? (bool) Module::isInstalled($owner) : false,
                    'tables' => [],
                ];
            }
            $groups[$key]['n'] = isset($groups[$key]['n']) ? $groups[$key]['n'] + 1 : 1;
            $groups[$key]['tables'][] = [
                'table' => $table,
                'rows' => (int) $row['r'],
                'cols' => implode(', ', $isList && !$cols ? ['email'] : $cols),
            ];
        }
        uasort($groups, function ($a, $b) {
            if ($a['module'] === null) {
                return 1;
            }
            if ($b['module'] === null) {
                return -1;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return array_values($groups);
    }

    /** Módulos instalados que se conectan a PrestaShop o a Addons */
    public function getConnectedModules()
    {
        $out = [];
        foreach (self::$connectedModules as $name) {
            if (Module::isInstalled($name)) {
                $out[] = ['name' => $name, 'display' => $this->moduleDisplayName($name)];
            }
        }

        return $out;
    }

    // =====================================================================
    // Plan: qué pasos y en qué orden
    // =====================================================================

    /**
     * @return array ['sections' => [...], 'steps' => [['id' => ..., 'label' => ...]], 'added' => [...]]
     */
    public function plan(array $sections, array $tables, array $psModules, $demo = false)
    {
        if (in_array('factory', $sections, true) && !JosraResetFactory::supported()) {
            throw new Exception('«Dejar como recién instalada» necesita PrestaShop ' . JosraResetFactory::MIN_VERSION . ' o superior.');
        }
        $sections = array_values(array_intersect(self::$order, $sections));
        // dependencias (en cadena)
        $added = [];
        do {
            $changed = false;
            foreach ($sections as $s) {
                if (isset(self::$requires[$s])) {
                    foreach (self::$requires[$s] as $need) {
                        if (!in_array($need, $sections, true)) {
                            $sections[] = $need;
                            $added[] = $need;
                            $changed = true;
                        }
                    }
                }
            }
        } while ($changed);
        $sections = array_values(array_intersect(self::$order, $sections));

        $labels = [];
        foreach ($this->getSectionsInfo() as $k => $info) {
            $labels[$k] = $info['label'];
        }
        $labels['factory'] = 'Datos de instalación de PrestaShop ' . _PS_VERSION_;
        $steps = [];
        foreach ($sections as $s) {
            foreach ($this->stepsFor($s, $sections) as $step) {
                $step['group'] = isset($labels[$s]) ? $labels[$s] : $s;
                $steps[] = $step;
            }
        }

        $valid = [];
        foreach ($this->getModuleTables() as $g) {
            foreach ($g['tables'] as $t) {
                $valid[$t['table']] = $g['name'];
            }
        }
        foreach ($tables as $t) {
            if (isset($valid[$t])) {
                $steps[] = ['id' => 'modtable:' . $t, 'label' => 'Tabla ' . $t . ' (' . $valid[$t] . ')', 'group' => 'Tablas de otros módulos'];
            }
        }

        $connected = [];
        foreach ($this->getConnectedModules() as $m) {
            $connected[$m['name']] = $m['display'];
        }
        foreach ($psModules as $m) {
            if (isset($connected[$m])) {
                $steps[] = ['id' => 'psmod:' . $m, 'label' => 'Desinstalar ' . $connected[$m] . ' (' . $m . ')', 'group' => 'Módulos de PrestaShop / Addons'];
            }
        }

        $factory = in_array('factory', $sections, true);
        if ($factory) {
            $steps[] = ['id' => 'factory.config', 'label' => 'Configuración de fábrica'];
            $steps[] = ['id' => 'factory.groups', 'label' => 'Grupos de clientes de fábrica'];
            $steps[] = ['id' => 'factory.orderstates', 'label' => 'Estados de pedido de fábrica'];
            $steps[] = ['id' => 'factory.carriers', 'label' => 'Transportistas de fábrica'];
            $steps[] = ['id' => 'factory.cms', 'label' => 'Páginas CMS de fábrica'];
            $steps[] = ['id' => 'factory.contacts', 'label' => 'Contactos de fábrica'];
            $steps[] = ['id' => 'factory.meta', 'label' => 'Títulos y URLs de las páginas'];
            $steps[] = ['id' => 'factory.misc', 'label' => 'Resto de datos de fábrica'];
        }
        // las tablas de módulos y los módulos conectados van antes de cargar la demostración
        $tail = [];
        foreach ($steps as $k => $st) {
            if (strpos($st['id'], 'modtable:') === 0 || strpos($st['id'], 'psmod:') === 0) {
                $tail[] = $st;
                unset($steps[$k]);
            }
        }
        $steps = array_merge(array_values($steps), $tail);
        if ($factory) {
            if ($demo) {
                $steps[] = ['id' => 'factory.demo', 'label' => 'Productos de demostración'];
            }
            $steps[] = ['id' => 'factory.translate' . ($demo ? ':demo' : ''), 'label' => 'Traducir los textos de fábrica'];
        }

        if ($steps) {
            $steps[] = ['id' => 'finish' . (in_array('categories', $sections, true) ? ':tree' : ''), 'label' => 'Vaciar cachés'];
        }
        foreach ($steps as $k => $st) {
            if (empty($st['group'])) {
                $steps[$k]['group'] = $st['label'];
            }
        }

        return ['sections' => $sections, 'steps' => $steps, 'added' => array_values(array_unique($added))];
    }

    /** Tablas que se pueden vaciar enteras: solo las de las listas de las secciones */
    private function allowedTruncates()
    {
        $out = [];
        foreach (self::$order as $section) {
            foreach ($this->stepsFor($section, self::$order) as $step) {
                if (strpos($step['id'], 'truncate:') === 0) {
                    $out[] = substr($step['id'], 9);
                }
            }
        }
        foreach (['customized_data', 'customization', 'cart_cart_rule', 'cart_product', 'cart'] as $t) {
            $out[] = $t; // carritos cuando ya no queda ningún pedido
        }

        return $out;
    }

    private function stepsFor($section, array $all)
    {
        $t = function ($tables) {
            $out = [];
            foreach ($tables as $table) {
                $out[] = ['id' => 'truncate:' . $table, 'label' => $table];
            }

            return $out;
        };
        switch ($section) {
            case 'factory':
                return [['id' => 'factory.installer', 'label' => 'Datos de instalación de PrestaShop ' . _PS_VERSION_]];
            case 'orders':
                return array_merge(
                    [
                        ['id' => 'orders.messages', 'label' => 'Mensajes de los pedidos'],
                        ['id' => 'orders.threads', 'label' => 'Atención al cliente de los pedidos'],
                        ['id' => 'orders.stock', 'label' => 'Movimientos de stock de los pedidos'],
                    ],
                    $t([
                        'order_detail_tax', 'order_detail', 'order_history', 'order_carrier', 'order_cart_rule',
                        'order_invoice_payment', 'order_invoice_tax', 'order_invoice', 'order_payment',
                        'order_return_detail', 'order_return', 'order_slip_detail_tax', 'order_slip_detail', 'order_slip',
                        'shipment_product', 'shipment', 'orders',
                    ]),
                    [['id' => 'orders.numbers', 'label' => 'Numeración de albaranes']]
                );
            case 'customers':
                return array_merge(
                    [
                        ['id' => 'customers.threads', 'label' => 'Mensajes de los clientes'],
                        ['id' => 'customers.cartrules', 'label' => 'Cupones personales'],
                        ['id' => 'customers.prices', 'label' => 'Precios personales'],
                        ['id' => 'customers.addresses', 'label' => 'Direcciones'],
                        ['id' => 'customers.guests', 'label' => 'Visitantes unidos a clientes'],
                    ],
                    $t([
                        'customer_session', 'business_entity_customer_b2b', 'business_entity_address',
                        'business_entity_identifier', 'business_entity', 'customer_b2b', 'customer_group', 'customer',
                    ])
                );
            case 'carts':
                return [['id' => 'carts.delete', 'label' => 'Carritos sin pedido']];
            case 'messages':
                return [
                    ['id' => 'messages.threads', 'label' => 'Hilos de atención al cliente sin pedido'],
                    ['id' => 'messages.messages', 'label' => 'Mensajes sin pedido'],
                ];
            case 'cart_rules':
                return array_merge(
                    $t([
                        'cart_cart_rule', 'cart_rule_lang', 'cart_rule_carrier', 'cart_rule_combination', 'cart_rule_country',
                        'cart_rule_group', 'cart_rule_product_rule_value', 'cart_rule_product_rule',
                        'cart_rule_product_rule_group', 'cart_rule_shop', 'cart_rule_compatible_types', 'cart_rule',
                    ]),
                    [['id' => 'cart_rules.prices', 'label' => 'Precios creados por reglas de catálogo']],
                    $t(['specific_price_rule_condition', 'specific_price_rule_condition_group', 'specific_price_rule'])
                );
            case 'catalog':
                return array_merge(
                    [
                        ['id' => 'catalog.files', 'label' => 'Archivos de productos descargables y adjuntos'],
                        ['id' => 'catalog.images', 'label' => 'Imágenes de productos (archivos)'],
                    ],
                    $t([
                        'product_attribute_combination', 'product_attribute_image', 'product_attribute_lang',
                        'product_attribute_shop', 'product_attribute', 'product_carrier', 'product_attachment',
                        'attachment_lang', 'attachment_shop', 'attachment', 'product_country_tax', 'product_download',
                        'product_group_reduction_cache', 'product_sale', 'product_supplier', 'product_tag', 'tag_count', 'tag',
                        'accessory', 'pack', 'category_product', 'feature_product', 'image_lang', 'image_shop', 'image',
                        'specific_price', 'specific_price_priority', 'stock_available', 'stock_mvt', 'stock',
                        'warehouse_product_location', 'customization_field_lang', 'customization_field',
                        'search_index', 'search_word', 'layered_price_index', 'layered_product_attribute',
                        'layered_filter_block', 'product_lang', 'product_shop', 'product',
                    ])
                );
            case 'categories':
                return [
                    ['id' => 'categories.images', 'label' => 'Imágenes de categorías (archivos)'],
                    ['id' => 'categories.delete', 'label' => 'Categorías'],
                ];
            case 'attributes':
                return array_merge(
                    [['id' => 'attributes.images', 'label' => 'Texturas de color (archivos)']],
                    $t([
                        'attribute_lang', 'attribute_shop', 'attribute', 'attribute_group_lang', 'attribute_group_shop',
                        'attribute_group', 'attribute_impact', 'feature_value_lang', 'feature_value', 'feature_lang',
                        'feature_shop', 'feature', 'layered_indexable_attribute_group', 'layered_indexable_attribute_group_lang_value',
                        'layered_indexable_attribute_lang_value', 'layered_indexable_feature', 'layered_indexable_feature_lang_value',
                        'layered_indexable_feature_value_lang_value',
                    ])
                );
            case 'manufacturers':
                return array_merge(
                    [
                        ['id' => 'manufacturers.images', 'label' => 'Imágenes de marcas y proveedores (archivos)'],
                        ['id' => 'manufacturers.addresses', 'label' => 'Direcciones de marcas y proveedores'],
                        ['id' => 'manufacturers.detach', 'label' => 'Quitar marca y proveedor a los productos'],
                    ],
                    $t(['manufacturer_lang', 'manufacturer_shop', 'manufacturer', 'product_supplier', 'supplier_lang', 'supplier_shop', 'supplier'])
                );
            case 'employees':
                return [['id' => 'employees.delete', 'label' => 'Empleados']];
            case 'stats':
                return array_merge(
                    $t([
                        'connections_page', 'connections_source', 'connections', 'page_viewed', 'date_range',
                        'referrer_cache', 'guest', 'mail', 'log', 'pagenotfound', 'statssearch', 'sekeyword',
                    ]),
                    [['id' => 'stats.files', 'label' => 'Archivos de log del servidor']]
                );
            case 'thumbs':
                return [['id' => 'thumbs.run', 'label' => 'Miniaturas']];
        }

        return [];
    }

    // =====================================================================
    // Ejecución de un paso
    // =====================================================================

    /**
     * @return array ['done' => bool, 'state' => array, 'log' => [], 'errors' => []]
     */
    public function run($step, array $state)
    {
        $this->log = [];
        $this->errors = [];
        @set_time_limit(120);
        $done = true;

        try {
            list($op, $arg) = array_pad(explode(':', $step, 2), 2, '');
            switch ($op) {
                case 'truncate':
                    if (!in_array($arg, $this->allowedTruncates(), true)) {
                        throw new Exception('La tabla ' . $arg . ' no es de ninguna sección de este módulo: no se toca.');
                    }
                    $this->truncate($arg);
                    break;
                case 'modtable':
                    $this->truncateModuleTable($arg);
                    break;
                case 'psmod':
                    $this->uninstallModule($arg);
                    break;
                case 'finish':
                    $this->finish($arg === 'tree');
                    break;
                case 'factory.installer':
                case 'factory.config':
                case 'factory.groups':
                case 'factory.orderstates':
                case 'factory.carriers':
                case 'factory.cms':
                case 'factory.contacts':
                case 'factory.meta':
                case 'factory.misc':
                case 'factory.demo':
                case 'factory.translate':
                    $factory = new JosraResetFactory();
                    $methods = [
                        'factory.installer' => 'stepInstaller', 'factory.config' => 'stepConfig', 'factory.groups' => 'stepGroups',
                        'factory.orderstates' => 'stepOrderStates', 'factory.carriers' => 'stepCarriers', 'factory.cms' => 'stepCms',
                        'factory.contacts' => 'stepContacts', 'factory.meta' => 'stepMeta', 'factory.misc' => 'stepMisc',
                        'factory.demo' => 'stepDemo', 'factory.translate' => 'stepTranslate',
                    ];
                    try {
                        if ($op === 'factory.translate') {
                            $done = $factory->stepTranslate($state, $arg === 'demo');
                        } else {
                            $done = $factory->{$methods[$op]}($state);
                        }
                    } finally {
                        $this->log = array_merge($this->log, $factory->log);
                    }
                    break;
                default:
                    $method = 'step' . str_replace(' ', '', ucwords(str_replace(['.', '_'], ' ', $op)));
                    if (!method_exists($this, $method)) {
                        throw new Exception('Paso desconocido: ' . $step);
                    }
                    $done = $this->$method($state);
            }
        } catch (Exception $e) {
            $this->errors[] = '✗ ' . $e->getMessage();
        } catch (Throwable $e) {
            $this->errors[] = '✗ ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        }

        return ['done' => (bool) $done, 'state' => $state, 'log' => $this->log, 'errors' => $this->errors];
    }

    // ----- pedidos -----------------------------------------------------------

    private function stepOrdersMessages(array &$state)
    {
        return $this->deleteByIds(
            'SELECT id_message FROM `' . $this->prefix . 'message` WHERE id_order > 0',
            ['message_readed' => 'id_message', 'message' => 'id_message'],
            'mensajes de pedidos',
            $state
        );
    }

    private function stepOrdersThreads(array &$state)
    {
        return $this->deleteByIds(
            'SELECT id_customer_thread FROM `' . $this->prefix . 'customer_thread` WHERE id_order > 0',
            ['customer_message' => 'id_customer_thread', 'customer_thread' => 'id_customer_thread'],
            'hilos de atención al cliente de pedidos',
            $state
        );
    }

    private function stepOrdersStock(array &$state)
    {
        return $this->deleteWhere('stock_mvt', 'id_order > 0', 'movimientos de stock de pedidos', $state);
    }

    private function stepOrdersNumbers(array &$state)
    {
        Configuration::updateGlobalValue('PS_DELIVERY_NUMBER', 1);
        foreach (Shop::getShops(false, null, true) as $idShop) {
            if (Configuration::hasKey('PS_DELIVERY_NUMBER', null, null, (int) $idShop)) {
                Configuration::updateValue('PS_DELIVERY_NUMBER', 1, false, null, (int) $idShop);
            }
        }
        $this->log[] = '✓ El siguiente albarán será el número 1';

        return true;
    }

    // ----- clientes ----------------------------------------------------------

    private function stepCustomersThreads(array &$state)
    {
        return $this->deleteByIds(
            'SELECT id_customer_thread FROM `' . $this->prefix . 'customer_thread` WHERE id_customer > 0',
            ['customer_message' => 'id_customer_thread', 'customer_thread' => 'id_customer_thread'],
            'hilos de atención al cliente de clientes',
            $state
        );
    }

    private function stepCustomersCartrules(array &$state)
    {
        return $this->deleteCartRules('id_customer > 0', 'cupones personales', $state);
    }

    private function stepCustomersPrices(array &$state)
    {
        return $this->deleteWhere('specific_price', 'id_customer > 0', 'precios personales', $state);
    }

    private function stepCustomersAddresses(array &$state)
    {
        // las de clientes y las sueltas (sin cliente, marca, proveedor ni almacén: restos de checkouts)
        $where = 'id_customer > 0 OR (id_customer = 0 AND id_manufacturer = 0 AND id_supplier = 0 AND id_warehouse = 0)';

        return $this->deleteWhere('address', $where, 'direcciones', $state);
    }

    private function stepCustomersGuests(array &$state)
    {
        return $this->deleteWhere('guest', 'id_customer > 0', 'visitantes unidos a clientes', $state);
    }

    // ----- carritos ----------------------------------------------------------

    private function stepCartsDelete(array &$state)
    {
        $orders = $this->tableExists('orders') ? (int) $this->db->getValue('SELECT COUNT(*) FROM `' . $this->prefix . 'orders`') : 0;
        if (!$orders && empty($state['partial'])) {
            foreach (['customized_data', 'customization', 'cart_cart_rule', 'cart_product', 'cart'] as $table) {
                $this->truncate($table);
            }

            return true;
        }
        $state['partial'] = 1;
        $p = $this->prefix;

        return $this->deleteByIds(
            'SELECT c.id_cart FROM `' . $p . 'cart` c LEFT JOIN `' . $p . 'orders` o ON o.id_cart = c.id_cart WHERE o.id_order IS NULL',
            [
                'customized_data' => ['id_customization', 'SELECT id_customization FROM `' . $p . 'customization` WHERE id_cart IN (%s)'],
                'customization' => 'id_cart',
                'cart_cart_rule' => 'id_cart',
                'cart_product' => 'id_cart',
                'cart' => 'id_cart',
            ],
            'carritos sin pedido',
            $state
        );
    }

    // ----- mensajes ----------------------------------------------------------

    private function stepMessagesThreads(array &$state)
    {
        return $this->deleteByIds(
            'SELECT id_customer_thread FROM `' . $this->prefix . 'customer_thread` WHERE id_order = 0',
            ['customer_message' => 'id_customer_thread', 'customer_thread' => 'id_customer_thread'],
            'hilos sin pedido',
            $state
        );
    }

    private function stepMessagesMessages(array &$state)
    {
        return $this->deleteByIds(
            'SELECT id_message FROM `' . $this->prefix . 'message` WHERE id_order = 0',
            ['message_readed' => 'id_message', 'message' => 'id_message'],
            'mensajes sin pedido',
            $state
        );
    }

    // ----- cupones -----------------------------------------------------------

    private function stepCartRulesPrices(array &$state)
    {
        return $this->deleteWhere('specific_price', 'id_specific_price_rule > 0', 'precios de reglas de catálogo', $state);
    }

    // ----- catálogo ----------------------------------------------------------

    private function stepCatalogFiles(array &$state)
    {
        $dir = $this->downloadDir();
        $files = [];
        if ($this->tableExists('product_download')) {
            foreach ((array) $this->db->executeS('SELECT filename FROM `' . $this->prefix . 'product_download`') as $r) {
                $files[] = $r['filename'];
            }
        }
        if ($this->tableExists('attachment')) {
            foreach ((array) $this->db->executeS('SELECT file FROM `' . $this->prefix . 'attachment`') as $r) {
                $files[] = $r['file'];
            }
        }
        $n = 0;
        foreach (array_unique($files) as $f) {
            $f = basename((string) $f);
            if ($f !== '' && $f !== 'index.php' && $f !== '.htaccess' && is_file($dir . $f) && @unlink($dir . $f)) {
                ++$n;
            }
        }
        $this->log[] = $n ? '✓ ' . $n . ' archivos de descargables y adjuntos borrados' : '— Sin archivos de descargables ni adjuntos';

        return true;
    }

    private function stepCatalogImages(array &$state)
    {
        return $this->wipeTree($this->img('p'), 'imágenes de productos', $state);
    }

    // ----- categorías --------------------------------------------------------

    private function stepCategoriesImages(array &$state)
    {
        $keep = $this->keptCategories();

        return $this->deleteFlatImages($this->img('c'), $keep, 'imágenes de categorías');
    }

    private function stepCategoriesDelete(array &$state)
    {
        $keep = implode(',', $this->keptCategories());
        $total = 0;
        foreach (['category_lang', 'category_shop', 'category_group', 'layered_category', 'group_reduction', 'category'] as $table) {
            if (!$this->tableExists($table)) {
                continue;
            }
            $this->db->execute('DELETE FROM `' . $this->prefix . $table . '` WHERE id_category NOT IN (' . $keep . ')');
            $n = (int) $this->db->Affected_Rows();
            if ($table === 'category') {
                $total = $n;
            }
        }
        $this->log[] = $total ? '✓ ' . $total . ' categorías borradas (se conservan la Raíz y el Inicio)' : '— Sin categorías que borrar';

        return true;
    }

    // ----- atributos ---------------------------------------------------------

    private function stepAttributesImages(array &$state)
    {
        return $this->deleteFlatImages($this->img('co'), [], 'texturas de color');
    }

    // ----- marcas y proveedores ----------------------------------------------

    private function stepManufacturersImages(array &$state)
    {
        $this->deleteFlatImages($this->img('m'), [], 'imágenes de marcas');

        return $this->deleteFlatImages($this->img('su'), [], 'imágenes de proveedores');
    }

    private function stepManufacturersAddresses(array &$state)
    {
        return $this->deleteWhere('address', 'id_customer = 0 AND (id_manufacturer > 0 OR id_supplier > 0)', 'direcciones de marcas y proveedores', $state);
    }

    private function stepManufacturersDetach(array &$state)
    {
        $this->db->execute('UPDATE `' . $this->prefix . 'product` SET id_manufacturer = 0, id_supplier = 0 WHERE id_manufacturer > 0 OR id_supplier > 0');
        $n = (int) $this->db->Affected_Rows();
        $this->log[] = $n ? '✓ ' . $n . ' productos sin marca ni proveedor' : '— Ningún producto tenía marca o proveedor';

        return true;
    }

    // ----- empleados ---------------------------------------------------------

    private function stepEmployeesDelete(array &$state)
    {
        $me = (int) Context::getContext()->employee->id;
        if (!$me) {
            throw new Exception('No se sabe qué empleado está conectado: no se borra ninguno.');
        }
        $n = 0;
        foreach (['employee_session', 'employee_shop', 'employee'] as $table) {
            if ($this->tableExists($table)) {
                $this->db->execute('DELETE FROM `' . $this->prefix . $table . '` WHERE id_employee != ' . $me);
                if ($table === 'employee') {
                    $n = (int) $this->db->Affected_Rows();
                }
            }
        }
        $this->log[] = $n ? '✓ ' . $n . ' empleados borrados (te quedas tú)' : '— Solo estás tú, nada que borrar';

        return true;
    }

    // ----- estadísticas ------------------------------------------------------

    private function stepStatsFiles(array &$state)
    {
        $n = 0;
        foreach ([_PS_ROOT_DIR_ . '/var/logs/', _PS_ROOT_DIR_ . '/log/'] as $dir) {
            if (is_dir($dir)) {
                foreach ((array) glob($dir . '*.log') as $file) {
                    if (is_file($file) && @unlink($file)) {
                        ++$n;
                    }
                }
            }
        }
        $this->log[] = $n ? '✓ ' . $n . ' archivos de log borrados' : '— Sin archivos de log';

        return true;
    }

    // ----- miniaturas --------------------------------------------------------

    private function stepThumbsRun(array &$state)
    {
        $dirs = [$this->img('p'), $this->img('c'), $this->img('m'), $this->img('su'), $this->img('st')];
        if (!isset($state['n'])) {
            $state['n'] = 0;
        }
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($this->outOfTime()) {
                    $this->log[] = '… ' . $state['n'] . ' miniaturas borradas hasta ahora';

                    return false;
                }
                if (!$file->isFile()) {
                    continue;
                }
                $name = $file->getFilename();
                // 123-home_default.jpg, 123-home_default2x.webp... (las originales son 123.jpg; los «sin foto» empiezan por el idioma)
                if (preg_match('/^\d+-[A-Za-z0-9_]+\.(jpe?g|png|gif|webp|avif)$/i', $name) && !preg_match('/_thumb\.[a-z]+$/i', $name)) {
                    if (@unlink($file->getPathname())) {
                        ++$state['n'];
                    }
                }
            }
        }
        $tmp = $this->img('tmp');
        if (is_dir($tmp)) {
            foreach ((array) glob($tmp . '*.{jpg,jpeg,png,gif,webp,avif}', GLOB_BRACE) as $f) {
                if (is_file($f) && @unlink($f)) {
                    ++$state['n'];
                }
            }
        }
        $this->log[] = '✓ ' . $state['n'] . ' miniaturas borradas (las originales se conservan). Regenera las miniaturas en Diseño > Ajustes de imágenes.';

        return true;
    }

    // ----- tablas de otros módulos y módulos conectados ----------------------

    private function truncateModuleTable($table)
    {
        $valid = false;
        foreach ($this->getModuleTables() as $g) {
            foreach ($g['tables'] as $t) {
                if ($t['table'] === $table) {
                    $valid = true;
                }
            }
        }
        if (!$valid) {
            throw new Exception('La tabla ' . $table . ' no está en la lista de tablas de módulos: no se toca.');
        }
        $this->truncate($table);
    }

    private function uninstallModule($name)
    {
        if (!in_array($name, self::$connectedModules, true)) {
            throw new Exception($name . ' no está en la lista de módulos conectados a PrestaShop: no se toca.');
        }
        if (!Module::isInstalled($name)) {
            $this->log[] = '— ' . $name . ': no estaba instalado';

            return;
        }
        $module = Module::getInstanceByName($name);
        if (!$module) {
            throw new Exception($name . ': no se ha podido cargar el módulo para desinstalarlo (¿faltan sus archivos?).');
        }
        try {
            $ok = $module->uninstall();
        } catch (Exception $e) {
            throw new Exception($name . ': ' . $e->getMessage());
        } catch (Throwable $e) {
            throw new Exception($name . ': ' . $e->getMessage());
        }
        if (!$ok) {
            $why = !empty($module->_errors) ? ' (' . strip_tags(implode(' ', (array) $module->_errors)) . ')' : '';
            throw new Exception($name . ': el módulo no se ha dejado desinstalar' . $why);
        }
        $this->log[] = '✓ ' . $name . ' desinstalado';
    }

    private function finish($tree)
    {
        if ($tree) {
            Category::regenerateEntireNtree();
            $this->log[] = '✓ Árbol de categorías recalculado';
        }
        if (method_exists('Tools', 'clearSmartyCache')) {
            Tools::clearSmartyCache();
        }
        if (class_exists('Media') && method_exists('Media', 'clearCache')) {
            Media::clearCache();
        }
        Cache::clean('*');
        $this->log[] = '✓ Cachés vaciadas';
    }

    // =====================================================================
    // Ayudantes
    // =====================================================================

    /** Carpetas de imágenes a partir de img/ (las constantes de cada tipo cambian entre versiones) */
    private function img($sub)
    {
        return rtrim(_PS_IMG_DIR_, '/\\') . '/' . $sub . '/';
    }

    private function downloadDir()
    {
        return defined('_PS_DOWNLOAD_DIR_') ? _PS_DOWNLOAD_DIR_ : _PS_ROOT_DIR_ . '/download/';
    }

    private function outOfTime()
    {
        return (microtime(true) - $this->started) > self::BUDGET;
    }

    private function truncate($table)
    {
        if (!$this->tableExists($table)) {
            return;
        }
        $count = (int) $this->db->getValue('SELECT COUNT(*) FROM `' . $this->prefix . bqSQL($table) . '`');
        if (!$this->db->execute('TRUNCATE TABLE `' . $this->prefix . bqSQL($table) . '`')) {
            throw new Exception('No se ha podido vaciar ' . $table . ': ' . $this->db->getMsgError());
        }
        $this->log[] = $count ? '✓ ' . $table . ': ' . $count . ' filas borradas' : '— ' . $table . ': vacía';
    }

    /**
     * DELETE ... LIMIT por tandas hasta acabar o agotar el tiempo.
     */
    private function deleteWhere($table, $where, $label, array &$state)
    {
        if (!$this->tableExists($table)) {
            return true;
        }
        if (!isset($state['n'])) {
            $state['n'] = 0;
        }
        do {
            if (!$this->db->execute('DELETE FROM `' . $this->prefix . bqSQL($table) . '` WHERE ' . $where . ' LIMIT ' . self::BATCH)) {
                throw new Exception('Error al borrar ' . $label . ': ' . $this->db->getMsgError());
            }
            $n = (int) $this->db->Affected_Rows();
            $state['n'] += $n;
            if ($n >= self::BATCH && $this->outOfTime()) {
                $this->log[] = '… ' . $label . ': ' . $state['n'] . ' borrados hasta ahora';

                return false;
            }
        } while ($n >= self::BATCH);
        $this->log[] = $state['n'] ? '✓ ' . $label . ': ' . $state['n'] . ' borrados' : '— ' . $label . ': nada que borrar';

        return true;
    }

    /**
     * Saca IDs por tandas con $select y los borra de varias tablas.
     * $tables: tabla => columna, o tabla => [columna, SELECT de IDs a partir de los de la tanda (%s)].
     */
    private function deleteByIds($select, array $tables, $label, array &$state)
    {
        if (!isset($state['n'])) {
            $state['n'] = 0;
        }
        while (true) {
            $ids = [];
            foreach ((array) $this->db->executeS($select . ' LIMIT ' . self::BATCH) as $row) {
                $ids[] = (int) current($row);
            }
            if (!$ids) {
                break;
            }
            $in = implode(',', $ids);
            foreach ($tables as $table => $col) {
                if (!$this->tableExists($table)) {
                    continue;
                }
                if (is_array($col)) {
                    $sub = [];
                    foreach ((array) $this->db->executeS(sprintf($col[1], $in)) as $r) {
                        $sub[] = (int) current($r);
                    }
                    if (!$sub) {
                        continue;
                    }
                    $this->db->execute('DELETE FROM `' . $this->prefix . $table . '` WHERE `' . $col[0] . '` IN (' . implode(',', $sub) . ')');
                } else {
                    $this->db->execute('DELETE FROM `' . $this->prefix . $table . '` WHERE `' . $col . '` IN (' . $in . ')');
                }
            }
            $state['n'] += count($ids);
            if (count($ids) < self::BATCH) {
                break;
            }
            if ($this->outOfTime()) {
                $this->log[] = '… ' . $label . ': ' . $state['n'] . ' borrados hasta ahora';

                return false;
            }
        }
        $this->log[] = $state['n'] ? '✓ ' . $label . ': ' . $state['n'] . ' borrados' : '— ' . $label . ': nada que borrar';

        return true;
    }

    private function deleteCartRules($where, $label, array &$state)
    {
        $tables = [];
        foreach ([
            'cart_rule_lang', 'cart_rule_carrier', 'cart_rule_country', 'cart_rule_group', 'cart_rule_shop',
            'cart_rule_compatible_types', 'cart_cart_rule', 'cart_rule',
        ] as $t) {
            $tables[$t] = 'id_cart_rule';
        }
        $p = $this->prefix;
        $tables['cart_rule_combination'] = 'id_cart_rule_1';
        $tables = array_merge(
            [
                'cart_rule_product_rule_value' => ['id_product_rule', 'SELECT r.id_product_rule FROM `' . $p . 'cart_rule_product_rule` r JOIN `' . $p . 'cart_rule_product_rule_group` g ON g.id_product_rule_group = r.id_product_rule_group WHERE g.id_cart_rule IN (%s)'],
                'cart_rule_product_rule' => ['id_product_rule_group', 'SELECT id_product_rule_group FROM `' . $p . 'cart_rule_product_rule_group` WHERE id_cart_rule IN (%s)'],
                'cart_rule_product_rule_group' => 'id_cart_rule',
            ],
            $tables
        );
        $done = $this->deleteByIds('SELECT id_cart_rule FROM `' . $p . 'cart_rule` WHERE ' . $where, $tables, $label, $state);
        if ($done && $this->tableExists('cart_rule_combination')) {
            $this->db->execute('DELETE FROM `' . $p . 'cart_rule_combination` WHERE id_cart_rule_2 NOT IN (SELECT id_cart_rule FROM `' . $p . 'cart_rule`)');
        }

        return $done;
    }

    /**
     * Borra todo lo que cuelga de una carpeta de imágenes en árbol (img/p): archivos y carpetas numéricas.
     * Los archivos de la raíz (index.php, imágenes «sin foto») se conservan.
     */
    private function wipeTree($dir, $label, array &$state)
    {
        if (!isset($state['n'])) {
            $state['n'] = 0;
        }
        $dir = rtrim($dir, '/\\') . '/';
        if (!is_dir($dir)) {
            $this->log[] = '— No existe la carpeta de ' . $label;

            return true;
        }
        foreach (scandir($dir) as $entry) {
            if (!ctype_digit($entry) || !is_dir($dir . $entry)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir . $entry, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $item) {
                if ($this->outOfTime()) {
                    $this->log[] = '… ' . $state['n'] . ' archivos de ' . $label . ' borrados hasta ahora';

                    return false;
                }
                if ($item->isDir()) {
                    @rmdir($item->getPathname());
                } elseif (@unlink($item->getPathname()) && $item->getFilename() !== 'index.php') {
                    ++$state['n'];
                }
            }
            @rmdir($dir . $entry);
        }
        $this->log[] = $state['n'] ? '✓ ' . $state['n'] . ' archivos de ' . $label . ' borrados' : '— Sin archivos de ' . $label;

        return true;
    }

    /**
     * Carpetas planas (img/c, img/m, img/su, img/co): borra los archivos que empiezan por un ID (12.jpg, 12-small_default.jpg, 12_thumb.jpg)
     * salvo los IDs de $keep. Las imágenes «sin foto» (empiezan por el idioma) y index.php se conservan.
     */
    private function deleteFlatImages($dir, array $keep, $label)
    {
        $dir = rtrim($dir, '/\\') . '/';
        $n = 0;
        if (is_dir($dir)) {
            foreach (scandir($dir) as $f) {
                if (preg_match('/^(\d+)[-_.]/', $f, $m) && !in_array((int) $m[1], $keep, true) && is_file($dir . $f) && @unlink($dir . $f)) {
                    ++$n;
                }
            }
        }
        $this->log[] = $n ? '✓ ' . $n . ' archivos de ' . $label . ' borrados' : '— Sin archivos de ' . $label;

        return true;
    }

    /** Raíz e Inicio (y la categoría raíz de cada tienda): no se borran nunca */
    public function keptCategories()
    {
        // las que dice la configuración (en tiendas migradas no tienen por qué ser la 1 y la 2)
        $keep = [(int) Configuration::get('PS_ROOT_CATEGORY'), (int) Configuration::get('PS_HOME_CATEGORY')];
        foreach ((array) Db::getInstance()->executeS('SELECT id_category FROM `' . _DB_PREFIX_ . 'shop`') as $r) {
            $keep[] = (int) $r['id_category'];
        }

        $keep = array_values(array_unique(array_filter($keep)));

        return $keep ? $keep : [1, 2];
    }

    private function tableExists($table)
    {
        static $cache = [];
        if (!isset($cache[$table])) {
            $cache[$table] = (bool) $this->db->getValue('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'' . pSQL($this->prefix . $table) . '\'');
        }

        return $cache[$table];
    }

    private function coreTables()
    {
        if ($this->coreTables === null) {
            $this->coreTables = require dirname(__FILE__) . '/core_tables.php';
        }

        return $this->coreTables;
    }

    private function moduleNames()
    {
        $names = [];
        foreach ((array) $this->db->executeS('SELECT name FROM `' . $this->prefix . 'module`') as $r) {
            $names[] = Tools::strtolower($r['name']);
        }
        foreach ((array) @scandir(_PS_MODULE_DIR_) as $d) {
            if ($d !== '' && $d[0] !== '.' && is_dir(_PS_MODULE_DIR_ . $d)) {
                $names[] = Tools::strtolower($d);
            }
        }
        $names = array_unique($names);
        usort($names, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        return $names;
    }

    /** Adivina de qué módulo es una tabla por su nombre (sin guiones bajos; gana el que más encaje) */
    private function guessModule($table, array $modules)
    {
        $t = Tools::strtolower($table);
        $known = [
            'layered_' => 'ps_facetedsearch', 'emailsubscription' => 'ps_emailsubscription', 'newsletter' => 'blocknewsletter',
            'wishlist' => 'blockwishlist', 'product_comment' => 'productcomments', 'statssearch' => 'statssearch',
            'pagenotfound' => 'pagesnotfound', 'mailalert' => 'ps_emailalerts', 'favorite_product' => 'favoriteproducts',
            'psgdpr' => 'psgdpr', 'sekeyword' => 'statsdata', 'bt_spr_' => 'gsnippetsreviews', 'jtp_' => 'josratpvpresta',
            'jtpfs_' => 'josratpvpresta', 'withdrawal_' => 'josradesistimiento', 'pscheckout_' => 'ps_checkout',
        ];
        foreach ($known as $start => $module) {
            if (strpos($t, $start) === 0) {
                return $module;
            }
        }
        $norm = function ($x) {
            return str_replace('_', '', $x);
        };
        $nt = $norm($t);
        // copias de tablas de PrestaShop (product_..._copia): solo cuentan los nombres de módulo enteros
        $copyOfCore = false;
        foreach ($this->coreTables() as $core) {
            if (strpos($t, $core . '_') === 0) {
                $copyOfCore = true;
                break;
            }
        }
        $best = null;
        $bestLen = 0;
        foreach ($modules as $m) {
            foreach (array_unique([$norm($m), $norm(preg_replace('/^ps_?/', '', $m))]) as $nm) {
                if (strlen($nm) < 4) {
                    continue;
                }
                if (strpos($nt, $nm) === 0) {
                    $len = strlen($nm) + 100; // el nombre entero del módulo manda
                } else {
                    $len = 0;
                    $max = min(strlen($nt), strlen($nm));
                    while ($len < $max && $nt[$len] === $nm[$len]) {
                        ++$len;
                    }
                    if ($len < 6 || $copyOfCore) {
                        continue;
                    }
                }
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $best = $m;
                }
            }
        }

        return $best;
    }

    private function moduleDisplayName($name)
    {
        $file = _PS_MODULE_DIR_ . $name . '/config_es.xml';
        if (!is_file($file)) {
            $file = _PS_MODULE_DIR_ . $name . '/config.xml';
        }
        if (is_file($file) && ($xml = @simplexml_load_file($file)) && !empty($xml->displayName)) {
            return trim(html_entity_decode((string) $xml->displayName, ENT_QUOTES, 'UTF-8'));
        }

        return $name;
    }
}
