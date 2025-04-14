<?php
/**
* 2007-2025 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2025 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

class Geststocks extends Module
{
    protected $config_form = false;
    protected $sftp_path = '/stock.csv'; // Ruta al archivo CSV en el servidor SFTP
    protected $local_path; // Ruta local donde se guardará temporalmente el archivo

    public function __construct()
    {
        $this->name = 'geststocks';
        $this->tab = 'administration';
        $this->version = '1.2.0';
        $this->author = 'Sebastian Luna';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Gestión Stock');
        $this->description = $this->l('Módulo para la gestión de stock');

        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => '8.2');
        
        // Definir la ruta local para el archivo temporal
        $this->local_path = _PS_MODULE_DIR_ . $this->name . '/temp_stock.csv';
    }

    public function install()
    {
        return parent::install() &&
            Configuration::updateValue('GESTSTOCKS_HOST', '') &&
            Configuration::updateValue('GESTSTOCKS_USER', '') &&
            Configuration::updateValue('GESTSTOCKS_PASSWORD', '') &&
            Configuration::updateValue('GESTSTOCKS_PORT', '22') &&
            Configuration::updateValue('GESTSTOCKS_REMOTE_PATH', '/stock.csv');
    }

    public function uninstall()
    {
        return parent::uninstall() &&
            Configuration::deleteByName('GESTSTOCKS_HOST') &&
            Configuration::deleteByName('GESTSTOCKS_USER') &&
            Configuration::deleteByName('GESTSTOCKS_PASSWORD') &&
            Configuration::deleteByName('GESTSTOCKS_PORT') &&
            Configuration::deleteByName('GESTSTOCKS_REMOTE_PATH');
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitGeststocks')) {
            Configuration::updateValue('GESTSTOCKS_HOST', Tools::getValue('GESTSTOCKS_HOST'));
            Configuration::updateValue('GESTSTOCKS_USER', Tools::getValue('GESTSTOCKS_USER'));
            Configuration::updateValue('GESTSTOCKS_PASSWORD', Tools::getValue('GESTSTOCKS_PASSWORD'));
            Configuration::updateValue('GESTSTOCKS_PORT', Tools::getValue('GESTSTOCKS_PORT'));
            Configuration::updateValue('GESTSTOCKS_REMOTE_PATH', Tools::getValue('GESTSTOCKS_REMOTE_PATH'));

            $output .= $this->displayConfirmation($this->l('Configuración guardada.'));
        }

        if (Tools::isSubmit('syncStock')) {
            $output .= $this->syncStockFromSFTP();
        }

        return $output . $this->renderForm();
    }

    protected function renderForm()
    {
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');

        $fields_form[0]['form'] = [
            'legend' => [
                'title' => $this->l('Configuración SFTP'),
            ],
            'input' => [
                [
                    'type' => 'text',
                    'label' => $this->l('Host SFTP'),
                    'name' => 'GESTSTOCKS_HOST',
                    'required' => true
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Usuario SFTP'),
                    'name' => 'GESTSTOCKS_USER',
                    'required' => true
                ],
                [
                    'type' => 'password',
                    'label' => $this->l('Contraseña SFTP'),
                    'name' => 'GESTSTOCKS_PASSWORD',
                    'required' => true
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Puerto SFTP'),
                    'name' => 'GESTSTOCKS_PORT',
                    'default_value' => '22'
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Ruta remota del archivo CSV'),
                    'name' => 'GESTSTOCKS_REMOTE_PATH',
                    'desc' => $this->l('Ejemplo: /ruta/al/archivo/stock.csv'),
                    'required' => true
                ],
            ],
            'submit' => [
                'title' => $this->l('Guardar'),
                'name' => 'submitGeststocks',
            ],
        ];

        $fields_form[0]['form']['buttons'][] = [
            'type' => 'submit',
            'name' => 'syncStock',
            'title' => $this->l('Sincronizar Stock desde SFTP'),
            'icon' => 'process-icon-refresh'
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->identifier = $this->identifier;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->title = $this->displayName;
        $helper->show_cancel_button = false;
        $helper->toolbar_scroll = false;
        $helper->submit_action = 'submitGeststocks';
        $helper->fields_value = [
            'GESTSTOCKS_HOST' => Configuration::get('GESTSTOCKS_HOST'),
            'GESTSTOCKS_USER' => Configuration::get('GESTSTOCKS_USER'),
            'GESTSTOCKS_PASSWORD' => Configuration::get('GESTSTOCKS_PASSWORD'),
            'GESTSTOCKS_PORT' => Configuration::get('GESTSTOCKS_PORT', '22'),
            'GESTSTOCKS_REMOTE_PATH' => Configuration::get('GESTSTOCKS_REMOTE_PATH', '/stock.csv'),
        ];

        return $helper->generateForm($fields_form);
    }

    private function syncStockFromSFTP()
    {
        // Verificar si la extensión SSH2 está instalada
        if (!function_exists('ssh2_connect')) {
            return $this->displayError($this->l('La extensión SSH2 no está instalada en el servidor.'));
        }

        $host = Configuration::get('GESTSTOCKS_HOST');
        $user = Configuration::get('GESTSTOCKS_USER');
        $password = Configuration::get('GESTSTOCKS_PASSWORD');
        $port = Configuration::get('GESTSTOCKS_PORT', '22');
        $remote_path = Configuration::get('GESTSTOCKS_REMOTE_PATH', '/stock.csv');

        if (empty($host) || empty($user) || empty($password)) {
            return $this->displayError($this->l('Por favor, configure los datos de conexión SFTP primero.'));
        }

        try {
            // Establecer conexión SFTP
            $connection = @ssh2_connect($host, $port);
            if (!$connection) {
                return $this->displayError($this->l('No se pudo conectar al servidor SFTP.'));
            }

            // Autenticación
            if (!@ssh2_auth_password($connection, $user, $password)) {
                return $this->displayError($this->l('Autenticación fallida. Verifique usuario y contraseña.'));
            }

            // Iniciar SFTP
            $sftp = @ssh2_sftp($connection);
            if (!$sftp) {
                return $this->displayError($this->l('No se pudo inicializar el subsistema SFTP.'));
            }

            // Descargar el archivo
            $remote_file = "ssh2.sftp://{$sftp}{$remote_path}";
            $local_file = $this->local_path;

            if (!@copy($remote_file, $local_file)) {
                return $this->displayError($this->l('No se pudo descargar el archivo desde el servidor SFTP.'));
            }

            // Procesar el archivo CSV
            $result = $this->processCSV($local_file);

            // Eliminar el archivo temporal
            @unlink($local_file);

            return $this->displayConfirmation($this->l('Sincronización completada: ') . $result);
        } catch (Exception $e) {
            return $this->displayError($this->l('Error durante la sincronización: ') . $e->getMessage());
        }
    }

    private function processCSV($file_path)
    {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return $this->l('El archivo CSV no existe o no se puede leer.');
        }

        $handle = fopen($file_path, 'r');
        if ($handle === false) {
            return $this->l('No se pudo abrir el archivo CSV.');
        }

        $updated = 0;
        $errors = 0;
        $line = 0;

        // Asumimos que el CSV tiene el formato: referencia,cantidad
        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
            $line++;
            
            // Saltar la primera línea si es un encabezado
            if ($line == 1 && !is_numeric($data[1])) {
                continue;
            }

            if (count($data) < 2) {
                $errors++;
                continue;
            }

            $reference = trim($data[0]);
            $quantity = (int)$data[1];

            // Buscar el producto por referencia
            $productId = $this->getProductIdByReference($reference);
            
            if ($productId) {
                $this->updateStock($productId, $quantity);
                $updated++;
            } else {
                $errors++;
            }
        }

        fclose($handle);

        return sprintf($this->l('Productos actualizados: %d, Errores: %d'), $updated, $errors);
    }

    private function getProductIdByReference($reference)
    {
        $sql = new DbQuery();
        $sql->select('id_product');
        $sql->from('product');
        $sql->where('reference = \'' . pSQL($reference) . '\'');

        return (int)Db::getInstance()->getValue($sql);
    }

    private function updateStock($productId, $stockQuantity)
    {
        $product = new Product($productId);
        if (Validate::isLoadedObject($product)) {
            // Actualizar cantidad y guardar
            StockAvailable::setQuantity($productId, 0, $stockQuantity);
            
            // Si necesitas actualizar otras propiedades del producto:
            // $product->quantity = $stockQuantity;
            // $product->save();
            
            return true;
        }
        return false;
    }
}