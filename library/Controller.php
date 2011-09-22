<?php
/**
 * @category Management
 * @package  Lagged\Zf\Crud
 * @author   Till Klampaeckel <till@lagged.biz>
 * @license  http://www.opensource.org/licenses/bsd-license.php New BSD License
 * @version  GIT: $Id$
 * @link     http://lagged.biz
 */

namespace Lagged\Zf\Crud;
use Lagged\Zf\Crud\Form\Edit    as Edit;
use Lagged\Zf\Crud\Form\Confirm as Confirm;
use Lagged\Zf\Crud\Form\Search  as Search;
use Lagged\Zf\Crud\Form\JumpTo  as JumpTo;

/**
 * @category Management
 * @package  Lagged\Zf\Crud
 * @author   Till Klampaeckel <till@lagged.biz>
 * @license  http://www.opensource.org/licenses/bsd-license.php New BSD License
 * @version  Release: @package_release@
 * @link     http://lagged.biz
 */
abstract class Controller extends \Zend_Controller_Action
{
    /**
     * @var array $cols The columns in the table.
     * @see self::init()
     */
    protected $cols;

    /**
     * @var string $dbAdapter Name of Zend_Registry key for DbAdapter
     */
    protected $dbAdapter = 'dbAdapter';

    /**
     * @var string $model
     */
    protected $model;

    /**
     * @var Zend_Db_Table_Abstract
     * @see self::init()
     */
    protected $obj;

    /**
     * @var array $hidden Hidden columns
     * @see self::init()
     */
    protected $hidden = array();

    /**
     * @var string $where Mysql WHERE query for search form
     * @see listAction
     */
    protected $where;

    /**
     * @var int $count Maximum count when fetching all rows
     * @see listAction
     */
    protected $count = 30;

    /**
     * @var array $primaryKey
     */
    protected $primaryKey;

    /**
     * @var string $order ORDER BY column name
     */
    protected $order;

    /**
     * @var string $orderType ORDER BY type
     */
    protected $orderType = 'ASC';

    /**
     * @var string $title For the view.
     */
    protected $title = 'CRUD INTERFACE';

    /**
     * Init
     *
     * @return void
     * @uses   self::$model
     * @uses   self::$dbAdapter
     * @uses   Zend_View
     */
    public function init()
    {
        if (empty($this->model)) {
            throw new \RuntimeException("You need to define self::model");
        }

        $this->obj = new $this->model(array('db' => $this->dbAdapter));
        if (!($this->obj instanceof \Zend_Db_Table_Abstract)) {
            throw new \LogicException("The model must extend Zend_Db_Table_Abstract");
        }
        $this->view->headLink()->appendStylesheet(
            'http://twitter.github.com/bootstrap/assets/css/bootstrap-1.2.0.min.css'
        );

        $this->view->assign('ui_title', $this->title);

        $this->view->addScriptPath(dirname(__DIR__) . '/views/scripts/');
        $this->view->addHelperPath(dirname(__DIR__) . '/views/helpers/', 'Crud_View_Helper');

        $this->cols = array_diff(
            array_keys($this->obj->info(\Zend_Db_Table_Abstract::METADATA)),
            $this->hidden
        );

        $this->primaryKey = array_values($this->obj->info('primary')); // composite?

        $this->view->assign('cols', $this->cols);
        $this->view->assign('primary', $this->primaryKey);
        $this->view->action = $this->getRequest()->getActionName();
    }

    /**
     * Create!
     *
     * GET: form
     * POST: create
     *
     * @return void
     */
    public function createAction()
    {
        $form = $this->_getForm();

        if ($this->_request->isPost() === true) {
            // validate
            // save
        }

        $this->view->form = $form;
    }

    /**
     * Delete
     *
     * GET: confirm
     * POST: delete
     *
     * @return void
     */
    public function deleteAction()
    {
        if (null === ($id = $this->_getParam('id'))) {
            throw new \InvalidArgumentException("ID is not set.");
        }

        $form = new Confirm();

        $this->view->assign('pkValue', $id);

        if ($this->_request->isPost() !== true) {
            $this->view->assign('form', $form);
            return $this->render('crud/delete', null, true); // confirm
        }
        try {
            $stmt = $this->_getDeleteWhereStatement($id);
            $this->obj->delete($stmt);
            $this->_helper->redirector('list');
        } catch (\Zend_Exception $e) {
            throw $e;
        }
    }

    /**
     * Placeholder: redirect to list
     *
     * @return void
     */
    public function indexAction()
    {
        return $this->_helper->redirector('list');
    }

    /**
     * Display a single entry.
     *
     * @return void
     */
    public function readAction()
    {
        $pkey = $this->primaryKey[0];

        if (null === ($id = $this->_getParam($pkey))) {
            return $this->_helper->redirector('list');
        }

        $record = $this->obj->find($id)->toArray();
        $this->view->assign('record', $record[0]);
        $this->view->assign('pkValue', $id);
        return $this->render('crud/detail', null, true);
    }

    /**
     * Display a list of entries in a table.
     *
     * @return void
     * @todo: refactor
     */
    public function listAction()
    {
        $offset    = null;
        $page      = abs($this->_getParam('p', 1));
        $page      = ((int) $page == 0) ? 1 : $page;
        $offset    = ((int) $page - 1) * $this->count;
        $order     = $this->_getParam('o');
        $orderType = $this->_getParam('ot');

        $searchForm = new Search();
        $searchForm->columns->addMultiOptions($this->cols);

        if ($this->_request->isPost()) {
            if ($searchForm->isValid($this->_request->getPost())) {
                $data = $searchForm->getValues();
                $this->_assignSearchWhereQuery($data);
            }
        }

        if (isset($order) && isset($orderType)) {
            $this->_assignOrderBy($order, $orderType);
        }

        $paginator = $this->_getPaginator();
        $paginator->setCurrentPageNumber($page);

        $this->view->paginator = $paginator;

        if ($this->order) {
            $this->view->order = $this->order;
        }
        $this->view->otNew = $this->_getNextOrderType($this->orderType);

        $query = $this->_request->getQuery();
        $this->view->assign('urlParams', array('params' => $query));

        $url = $this->view->BetterUrl(
            array(
                'action' => 'list',
                'o'      => $this->order,
                'ot'     => $this->orderType
            )
        );

        $searchForm->columns->addMultiOptions($this->cols);
        $this->view->searchForm = $searchForm->setAction($this->view->url());

        $jumpForm = new JumpTo();
        $this->view->jumpForm = $jumpForm->setAction($url);
        return $this->render('crud/list', null, true);
    }

    /**
     * edit
     *
     * GET: form
     * POST: update
     *
     * @return void
     */
    public function editAction()
    {
        if (null === ($id = $this->_getParam('id'))) {
            throw new \Runtime_Exception('bouh');
        }

        $form = $this->_getForm();

        if ($this->_request->isGet()) {
            if ($form->isValid($this->_request->getParams())) {
                $this->_update($id, $form->getValues());
            }
        }
        $record = $this->obj->find($id)->toArray();
        $form->populate($record[0]);
        $this->view->assign('form', $form);
        $this->view->assign('pkValue', $id);

        return $this->render('crud/edit', null, true);
    }

    /**
     * Update DB row with data
     *
     * @param mixed $id   ''
     * @param array $data ''
     * @return void
     * @throws Zend_Exception if row cannot be updated
     */
    private function _update($id, $data)
    {
        $id = ((int) $id == $id) ? (int) $id : $id;
        try {
            $stmt = $this->_getWhereStatement($id);
            $this->obj->update($data, $stmt);
            $this->_helper->redirector('list');
        } catch (\Zend_Exception $e) {
            throw $e;
        }
    }

    /**
     * Create the form
     *
     * @return \Lagged\Zf\Crud\Form
     * @uses   \Zend_Db_Table_Abstract::info()
     */
    private function _getForm()
    {
        $form = new Edit();
        $form->generate(
            $this->obj->info(\Zend_Db_Table_Abstract::METADATA)
        );
        return $form;
    }

    /**
     * Create the paginator for {@link self::listAction()}.
     *
     * @return \Zend_Paginator
     * @uses   self::$dbAdapter
     * @uses   self::$obj
     */
    private function _getPaginator()
    {
        $db        = \Zend_Registry::get($this->dbAdapter);
        $table     = $this->obj->info('name');
        $select    = $db->select()->from($table);
        if ($this->where) {
            $select->where($this->where);
        }
        if ($this->order && $this->orderType) {
            $select->order($this->order . ' ' . $this->orderType);
        }

        $paginator = \Zend_Paginator::factory($select);
        $paginator->setItemCountPerPage($this->count);

        return $paginator;
    }

    /**
     * @return string
     */
    private function _getTable()
    {
        return $this->obj->info('name');
    }

    /**
     * _getDeleteWhereStatement
     *
     * @param mixed $id
     * @return string
     */
    private function _getDeleteWhereStatement($id)
    {
        $id = ((int) $id == $id) ? (int) $id : $id;
        $where = $this->obj->getAdapter()
            ->quoteInto($this->primaryKey[0] . ' = ?', $id);

        return $where;
    }

    /**
     * _assignOrderBy
     *
     * @param string $order     order column
     * @param string $type ''
     * @return void
     * @throws Zend_Exception if the string is invalid
     */
    private function _assignOrderBy($order, $type)
    {
        $validTypes = array('ASC', 'DESC');
        if (! in_array($order, $this->cols)
            || ! in_array($type, $validTypes)
        ) {
            throw new \Zend_Exception('Invalid order');
        };
        $this->order = $order;
        $this->orderType = $type;
    }

    /**
     * _getNextOrderType
     * Returns the opposite of current order
     *
     * @return string
     */
    private function _getNextOrderType()
    {
        return ($this->orderType == 'ASC')
            ? 'DESC'
            : 'ASC';
    }

    /**
     * _assignSearchWhereQuery
     *
     * @param array $data ''
     *
     * @return void
     */
    private function _assignSearchWhereQuery($data)
    {
        $search = $data['search'];
        $column = $this->cols[$data['columns']];
        $query  = ($data['exact'])
            ? sprintf("%s = '%s'", $column, $search)
            : sprintf("%s LIKE '%%%s%%'", $column, $search);

        $this->where = $query;
    }

}
