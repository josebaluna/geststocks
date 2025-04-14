<?php
class AdminGestStocksController extends ModuleAdminController
{
    public function __construct()
    {
        $this->table = 'geststocks';
        $this->className = 'GestStocks';
        parent::__construct();
    }

    public function renderList()
    {
        $this->context->smarty->assign([
            'title' => $this->l('Gestión de Stock')
        ]);
        return $this->context->smarty->fetch($this->module->getPath() . 'views/templates/admin/configure.tpl');
    }
}