<?php
/***********************************************************************
| Cerb(tm) developed by Webgroup Media, LLC.
|-----------------------------------------------------------------------
| All source code & content (c) Copyright 2002-2017, Webgroup Media LLC
|   unless specifically noted otherwise.
|
| This source code is released under the Devblocks Public License.
| The latest version of this license can be found here:
| http://cerb.ai/license
|
| By using this software, you acknowledge having read this license
| and agree to be bound thereby.
| ______________________________________________________________________
|	http://cerb.ai	    http://webgroup.media
***********************************************************************/

class DAO_WorkspacePage extends Cerb_ORMHelper {
	const EXTENSION_ID = 'extension_id';
	const ID = 'id';
	const NAME = 'name';
	const OWNER_CONTEXT = 'owner_context';
	const OWNER_CONTEXT_ID = 'owner_context_id';
	const UPDATED_AT = 'updated_at';
	
	const _CACHE_ALL = 'ch_workspace_pages';
	
	private function __construct() {}

	static function getFields() {
		$validation = DevblocksPlatform::services()->validation();
		
		// varchar(255)
		$validation
			->addField(self::EXTENSION_ID)
			->string()
			->setMaxLength(255)
			->setRequired(true)
			->addValidator(function($value, &$error=null) {
				if(false == Extension_WorkspacePage::get($value)) {
					$error = sprintf("is not a valid workspace page extension (%s).", $value);
					return false;
				}
				
				return true;
			})
			;
		// int(10) unsigned
		$validation
			->addField(self::ID)
			->id()
			->setEditable(false)
			;
		// varchar(255)
		$validation
			->addField(self::NAME)
			->string()
			->setMaxLength(255)
			->setRequired(true)
			;
		// varchar(255)
		$validation
			->addField(self::OWNER_CONTEXT)
			->context()
			->setRequired(true)
			;
		// int(10) unsigned
		$validation
			->addField(self::OWNER_CONTEXT_ID)
			->id()
			->setRequired(true)
			;
		// int(10) unsigned
		$validation
			->addField(self::UPDATED_AT)
			->timestamp()
			;
		$validation
			->addField('_links')
			->string()
			->setMaxLength(65535)
			;
			
		return $validation->getFields();
	}
	
	static function create($fields) {
		$db = DevblocksPlatform::services()->database();

		$sql = "INSERT INTO workspace_page () VALUES ()";
		$db->ExecuteMaster($sql);
		$id = $db->LastInsertId();

		self::update($id, $fields);

		return $id;
	}

	static function update($ids, $fields, $check_deltas=true) {
		if(!is_array($ids))
			$ids = [$ids];
		
		$context = CerberusContexts::CONTEXT_WORKSPACE_PAGE;
		
		if(!isset($fields[self::UPDATED_AT]))
			$fields[self::UPDATED_AT] = time();
		
		self::_updateAbstract($context, $ids, $fields);
		
		// Make a diff for the requested objects in batches
		
		$chunks = array_chunk($ids, 100, true);
		while($batch_ids = array_shift($chunks)) {
			if(empty($batch_ids))
				continue;
				
			// Send events
			if($check_deltas) {
				CerberusContexts::checkpointChanges($context, $batch_ids);
			}
			
			// Make changes
			parent::_update($batch_ids, 'workspace_page', $fields);
			
			// Send events
			if($check_deltas) {
				// Trigger an event about the changes
				$eventMgr = DevblocksPlatform::services()->event();
				$eventMgr->trigger(
					new Model_DevblocksEvent(
						'dao.workspace_page.update',
						array(
							'fields' => $fields,
						)
					)
				);
				
				// Log the context update
				DevblocksPlatform::markContextChanged($context, $batch_ids);
			}
		}
		
		self::clearCache();
	}

	static function updateWhere($fields, $where) {
		parent::_updateWhere('workspace_page', $fields, $where);
		self::clearCache();
	}
	
	static public function onBeforeUpdateByActor($actor, $fields, $id=null, &$error=null) {
		$context = CerberusContexts::CONTEXT_WORKSPACE_PAGE;
		
		if(!self::_onBeforeUpdateByActorCheckContextPrivs($actor, $context, $id, $error))
			return false;
		
		@$owner_context = $fields[self::OWNER_CONTEXT];
		@$owner_context_id = intval($fields[self::OWNER_CONTEXT_ID]);
		
		// Verify that the actor can use this new owner
		if($owner_context) {
			if(!CerberusContexts::isOwnableBy($owner_context, $owner_context_id, $actor)) {
				$error = DevblocksPlatform::translate('error.core.no_acl.owner');
				return false;
			}
		}
		
		return true;
	}

	static function getAll($nocache=false) {
		$cache = DevblocksPlatform::services()->cache();
		
		if($nocache || null === ($pages = $cache->load(self::_CACHE_ALL))) {
			$pages = self::getWhere(
				null,
				DAO_WorkspacePage::NAME,
				true,
				null,
				Cerb_ORMHelper::OPT_GET_MASTER_ONLY
			);
			
			if(!is_array($pages))
				return false;
			
			$cache->save($pages, self::_CACHE_ALL);
		}
		
		return $pages;
	}
	
	/**
	 * @param string $where
	 * @param mixed $sortBy
	 * @param mixed $sortAsc
	 * @param integer $limit
	 * @return Model_WorkspacePage[]
	 */
	static function getWhere($where=null, $sortBy=DAO_WorkspacePage::NAME, $sortAsc=true, $limit=null, $options=null) {
		$db = DevblocksPlatform::services()->database();

		list($where_sql, $sort_sql, $limit_sql) = self::_getWhereSQL($where, $sortBy, $sortAsc, $limit);

		// SQL
		$sql = "SELECT id, name, owner_context, owner_context_id, extension_id, updated_at ".
			"FROM workspace_page ".
			$where_sql.
			$sort_sql.
			$limit_sql
			;

		if($options & Cerb_ORMHelper::OPT_GET_MASTER_ONLY) {
			$rs = $db->ExecuteMaster($sql, _DevblocksDatabaseManager::OPT_NO_READ_AFTER_WRITE);
		} else {
			$rs = $db->ExecuteSlave($sql);
		}
			
		return self::_getObjectsFromResult($rs);
	}

	static function getByOwner($context, $context_id, $sortBy=null, $sortAsc=true, $limit=null) {
		$pages = array();
		
		$all_pages = self::getAll();
		foreach($all_pages as $page_id => $page) { /* @var $page Model_WorkspacePage */
			if($page->owner_context == $context
				&& $page->owner_context_id == $context_id) {
				
				$pages[$page_id] = $page;
			}
		}

		return $pages;
	}

	static function getByWorker($worker) {
		if(is_a($worker,'Model_Worker')) {
			// This is what we want
		} elseif(is_numeric($worker)) {
			$worker = DAO_Worker::get($worker);
		} else {
			return array();
		}

		$memberships = $worker->getMemberships();
		$roles = $worker->getRoles();
		
		$pages = array();
		$all_pages = self::getAll();
		
		foreach($all_pages as $page_id => $page) { /* @var $page Model_WorkspacePage */
			switch($page->owner_context) {
				case CerberusContexts::CONTEXT_ROLE:
					if(isset($roles[$page->owner_context_id]))
						$pages[$page_id] = $page;
					break;
					
				case CerberusContexts::CONTEXT_GROUP:
					if(isset($memberships[$page->owner_context_id]))
						$pages[$page_id] = $page;
					break;
					
				case CerberusContexts::CONTEXT_WORKER:
					if($worker->id == $page->owner_context_id)
						$pages[$page_id] = $page;
					break;
			}
		}

		return $pages;
	}
	
	static function getUsers($page_id) {
		$results = array();
		
		if(false == ($instances = DAO_WorkerPref::getByKey('menu_json')) || !is_array($instances) || empty($instances))
			return array();
		
		foreach($instances as $worker_id => $instance) {
			if(false == ($menu = json_decode($instance)))
				continue;
			
			if(in_array($page_id, $menu))
				$results[] = $worker_id;
		}
		
		return $results;
	}

	/**
	 * @param integer $id
	 * @return Model_WorkspacePage
	 */
	static function get($id) {
		if(empty($id))
			return null;
		
		$objects = self::getAll();
		
		if(isset($objects[$id]))
			return $objects[$id];

		return null;
	}
	
	/**
	 * 
	 * @param array $ids
	 * @return Model_WorkspacePage[]
	 */
	static function getIds($ids) {
		if(!is_array($ids))
			$ids = array($ids);

		if(empty($ids))
			return [];

		$objects = self::getAll();
		
		$ids = DevblocksPlatform::importVar($ids, 'array:integer');
		
		return array_intersect_key($objects, array_flip($ids));
	}

	/**
	 * @param resource $rs
	 * @return Model_WorkspacePage[]
	 */
	static private function _getObjectsFromResult($rs) {
		$objects = array();
		
		if(!($rs instanceof mysqli_result))
			return false;

		while($row = mysqli_fetch_assoc($rs)) {
			$object = new Model_WorkspacePage();
			$object->id = intval($row['id']);
			$object->name = $row['name'];
			$object->owner_context = $row['owner_context'];
			$object->owner_context_id = intval($row['owner_context_id']);
			$object->extension_id = $row['extension_id'];
			$object->updated_at = intval($row['updated_at']);
			$objects[$object->id] = $object;
		}

		mysqli_free_result($rs);

		return $objects;
	}
	
	static function random() {
		return self::_getRandom('workspace_page');
	}

	static function deleteByOwner($owner_context, $owner_context_ids) {
		if(!is_array($owner_context_ids))
			$owner_context_ids = array($owner_context_ids);

		foreach($owner_context_ids as $owner_context_id) {
			$pages = DAO_WorkspacePage::getByOwner($owner_context, $owner_context_id);
			DAO_WorkspacePage::delete(array_keys($pages));
		}
	}

	static function delete($ids) {
		if(!is_array($ids)) $ids = array($ids);
		$db = DevblocksPlatform::services()->database();

		if(empty($ids))
			return;

		$ids_list = implode(',', $ids);

		// Cascade delete tabs and lists
		DAO_WorkspaceTab::deleteByPage($ids);
		
		// Delete pages
		$db->ExecuteMaster(sprintf("DELETE FROM workspace_page WHERE id IN (%s)", $ids_list));

		self::clearCache();
		
		return true;
	}

	public static function getSearchQueryComponents($columns, $params, $sortBy=null, $sortAsc=null) {
		$fields = SearchFields_WorkspacePage::getFields();

		list($tables,$wheres) = parent::_parseSearchParams($params, $columns, 'SearchFields_WorkspacePage', $sortBy);

		$select_sql = sprintf("SELECT ".
			"workspace_page.id as %s, ".
			"workspace_page.name as %s, ".
			"workspace_page.updated_at as %s, ".
			"workspace_page.owner_context as %s, ".
			"workspace_page.owner_context_id as %s, ".
			"workspace_page.extension_id as %s ",
			SearchFields_WorkspacePage::ID,
			SearchFields_WorkspacePage::NAME,
			SearchFields_WorkspacePage::UPDATED_AT,
			SearchFields_WorkspacePage::OWNER_CONTEXT,
			SearchFields_WorkspacePage::OWNER_CONTEXT_ID,
			SearchFields_WorkspacePage::EXTENSION_ID
		);
			
		$join_sql = "FROM workspace_page ";

		$where_sql = "".
			(!empty($wheres) ? sprintf("WHERE %s ",implode(' AND ',$wheres)) : "WHERE 1 ");
			
		$sort_sql = self::_buildSortClause($sortBy, $sortAsc, $fields, $select_sql, 'SearchFields_WorkspacePage');

		return array(
			'primary_table' => 'workspace_page',
			'select' => $select_sql,
			'join' => $join_sql,
			'where' => $where_sql,
			'sort' => $sort_sql,
		);
	}

	/**
	 *
	 * @param array $columns
	 * @param DevblocksSearchCriteria[] $params
	 * @param integer $limit
	 * @param integer $page
	 * @param string $sortBy
	 * @param boolean $sortAsc
	 * @param boolean $withCounts
	 * @return array
	 */
	static function search($columns, $params, $limit=10, $page=0, $sortBy=null, $sortAsc=null, $withCounts=true) {
		$db = DevblocksPlatform::services()->database();

		// Build search queries
		$query_parts = self::getSearchQueryComponents($columns,$params,$sortBy,$sortAsc);

		$select_sql = $query_parts['select'];
		$join_sql = $query_parts['join'];
		$where_sql = $query_parts['where'];
		$sort_sql = $query_parts['sort'];

		// Virtuals
		
		$args = array(
			'join_sql' => &$join_sql,
			'where_sql' => &$where_sql,
			'tables' => &$tables,
		);
		
		$sql =
			$select_sql.
			$join_sql.
			$where_sql.
			$sort_sql;
		
		if($limit > 0) {
			if(false == ($rs = $db->SelectLimit($sql,$limit,$page*$limit)))
				return false;
		} else {
			if(false == ($rs = $db->ExecuteSlave($sql)))
				return false;
			$total = mysqli_num_rows($rs);
		}

		$results = array();
		
		if(!($rs instanceof mysqli_result))
			return false;

		while($row = mysqli_fetch_assoc($rs)) {
			$object_id = intval($row[SearchFields_WorkspacePage::ID]);
			$results[$object_id] = $row;
		}

		$total = count($results);
		
		if($withCounts) {
			// We can skip counting if we have a less-than-full single page
			if(!(0 == $page && $total < $limit)) {
				$count_sql =
					"SELECT COUNT(workspace_page.id) ".
					$join_sql.
					$where_sql;
				$total = $db->GetOneSlave($count_sql);
			}
		}

		mysqli_free_result($rs);

		return array($results,$total);
	}

	public static function maint() {
		$db = DevblocksPlatform::services()->database();
		$logger = DevblocksPlatform::services()->log();

		$db->ExecuteMaster("DELETE FROM workspace_tab WHERE workspace_page_id NOT IN (SELECT id FROM workspace_page)");
		$logger->info('[Maint] Purged ' . $db->Affected_Rows() . ' workspace_tab records.');
	}

	static function clearCache() {
		$cache = DevblocksPlatform::services()->cache();
		$cache->remove(self::_CACHE_ALL);
	}
};

class SearchFields_WorkspacePage extends DevblocksSearchFields {
	const ID = 'w_id';
	const NAME = 'w_name';
	const OWNER_CONTEXT = 'w_owner_context';
	const OWNER_CONTEXT_ID = 'w_owner_context_id';
	const EXTENSION_ID = 'w_extension_id';
	const UPDATED_AT = 'w_updated_at';
	
	const VIRTUAL_CONTEXT_LINK = '*_context_link';
	const VIRTUAL_OWNER = '*_owner';
	
	static private $_fields = null;
	
	static function getPrimaryKey() {
		return 'workspace_page.id';
	}
	
	static function getCustomFieldContextKeys() {
		return array(
			CerberusContexts::CONTEXT_WORKSPACE_PAGE => new DevblocksSearchFieldContextKeys('workspace_page.id', self::ID),
		);
	}
	
	static function getWhereSQL(DevblocksSearchCriteria $param) {
		switch($param->field) {
			case self::VIRTUAL_CONTEXT_LINK:
				return self::_getWhereSQLFromContextLinksField($param, CerberusContexts::CONTEXT_WORKSPACE_PAGE, self::getPrimaryKey());
				break;
			
			case self::VIRTUAL_OWNER:
				return self::_getWhereSQLFromContextAndID($param, 'workspace_page.owner_context', 'workspace_page.owner_context_id');
				break;
				
			default:
				if('cf_' == substr($param->field, 0, 3)) {
					return self::_getWhereSQLFromCustomFields($param);
				} else {
					return $param->getWhereSQL(self::getFields(), self::getPrimaryKey());
				}
				break;
		}
	}
	
	/**
	 * @return DevblocksSearchField[]
	 */
	static function getFields() {
		if(is_null(self::$_fields))
			self::$_fields = self::_getFields();
		
		return self::$_fields;
	}
	
	/**
	 * @return DevblocksSearchField[]
	 */
	static function _getFields() {
		$translate = DevblocksPlatform::getTranslationService();
		
		$columns = array(
			self::ID => new DevblocksSearchField(self::ID, 'workspace_page', 'id', $translate->_('common.id'), null, true),
			self::NAME => new DevblocksSearchField(self::NAME, 'workspace_page', 'name', $translate->_('common.name'), Model_CustomField::TYPE_SINGLE_LINE, true),
			self::OWNER_CONTEXT => new DevblocksSearchField(self::OWNER_CONTEXT, 'workspace_page', 'owner_context', null, null, false),
			self::OWNER_CONTEXT_ID => new DevblocksSearchField(self::OWNER_CONTEXT_ID, 'workspace_page', 'owner_context_id', null, null, false),
			self::EXTENSION_ID => new DevblocksSearchField(self::EXTENSION_ID, 'workspace_page', 'extension_id', $translate->_('common.type'), null, true),
			self::UPDATED_AT => new DevblocksSearchField(self::UPDATED_AT, 'workspace_page', 'updated_at', $translate->_('common.updated'), Model_CustomField::TYPE_DATE, true),
			
			self::VIRTUAL_CONTEXT_LINK => new DevblocksSearchField(self::VIRTUAL_CONTEXT_LINK, '*', 'context_link', $translate->_('common.links'), null, false),
			self::VIRTUAL_OWNER => new DevblocksSearchField(self::VIRTUAL_OWNER, '*', 'owner', $translate->_('common.owner'), null, false),
		);
		
		// Sort by label (translation-conscious)
		DevblocksPlatform::sortObjects($columns, 'db_label');

		return $columns;
	}
};

class Model_WorkspacePage {
	public $id;
	public $name;
	public $owner_context;
	public $owner_context_id;
	public $extension_id;
	public $updated_at;
	
	function getExtension() {
		$extension = Extension_WorkspacePage::get($this->extension_id);
		return $extension;
	}
	
	/**
	 *
	 * @param Model_Worker $as_worker
	 * @return Model_WorkspaceTab[]
	 */
	function getTabs(Model_Worker $as_worker=null) {
		$tabs = DAO_WorkspaceTab::getByPage($this->id);
		
		// Order by given worker prefs
		if(!empty($as_worker)) {
			$available_tabs = $tabs;
			$tabs = array();
			
			// Do we have prefs?
			@$json = DAO_WorkerPref::get($as_worker->id, 'page_tabs_' . $this->id . '_json', null);
			$tab_ids = json_decode($json);
			
			if(!is_array($tab_ids) || empty($json))
				return $available_tabs;
			
			// Sort tabs by the worker's preferences
			foreach($tab_ids as $tab_id) {
				if(isset($available_tabs[$tab_id])) {
					$tabs[$tab_id] = $available_tabs[$tab_id];
					unset($available_tabs[$tab_id]);
				}
			}

			// Add anything left to the end that the worker didn't explicitly sort
			if(!empty($available_tabs))
				$tabs += $available_tabs;
		}
		
		return $tabs;
	}
	
	function getUsers() {
		return DAO_WorkspacePage::getUsers($this->id);
	}
};

class View_WorkspacePage extends C4_AbstractView implements IAbstractView_QuickSearch, IAbstractView_Subtotals {
	const DEFAULT_ID = 'workspace_page';

	function __construct() {
		$translate = DevblocksPlatform::getTranslationService();

		$this->id = self::DEFAULT_ID;
		$this->name = $translate->_('Pages');
		$this->renderLimit = 25;
		$this->renderSortBy = SearchFields_WorkspacePage::NAME;
		$this->renderSortAsc = true;

		$this->view_columns = array(
			SearchFields_WorkspacePage::NAME,
			SearchFields_WorkspacePage::VIRTUAL_OWNER,
			SearchFields_WorkspacePage::UPDATED_AT,
		);
		
		$this->addColumnsHidden(array(
			SearchFields_WorkspacePage::ID,
			SearchFields_WorkspacePage::OWNER_CONTEXT,
			SearchFields_WorkspacePage::OWNER_CONTEXT_ID,
			SearchFields_WorkspacePage::VIRTUAL_CONTEXT_LINK,
		));

		$this->addParamsHidden(array(
			SearchFields_WorkspacePage::ID,
			SearchFields_WorkspacePage::OWNER_CONTEXT,
			SearchFields_WorkspacePage::OWNER_CONTEXT_ID,
		));

		$this->doResetCriteria();
	}

	function getData() {
		$objects = DAO_WorkspacePage::search(
			$this->view_columns,
			$this->getParams(),
			$this->renderLimit,
			$this->renderPage,
			$this->renderSortBy,
			$this->renderSortAsc,
			$this->renderTotal
		);
		
		$this->_lazyLoadCustomFieldsIntoObjects($objects, 'SearchFields_WorkspacePage');
		
		return $objects;
	}

	function getDataSample($size) {
		return $this->_doGetDataSample('DAO_WorkspacePage', $size);
	}
	
	function getSubtotalFields() {
		$all_fields = $this->getParamsAvailable(true);
		
		$fields = array();

		if(is_array($all_fields))
		foreach($all_fields as $field_key => $field_model) {
			$pass = false;
			
			switch($field_key) {
				case SearchFields_WorkspacePage::EXTENSION_ID:
					$pass = true;
					break;
					
				// Virtuals
				case SearchFields_WorkspacePage::VIRTUAL_CONTEXT_LINK:
				case SearchFields_WorkspacePage::VIRTUAL_OWNER:
					$pass = true;
					break;
					
				// Valid custom fields
				default:
					if(DevblocksPlatform::strStartsWith($field_key, 'cf_'))
						$pass = $this->_canSubtotalCustomField($field_key);
					break;
			}
			
			if($pass)
				$fields[$field_key] = $field_model;
		}
		
		return $fields;
	}
	
	function getSubtotalCounts($column) {
		$counts = array();
		$fields = $this->getFields();
		$context = CerberusContexts::CONTEXT_WORKSPACE_PAGE;

		if(!isset($fields[$column]))
			return array();
		
		switch($column) {
			case SearchFields_WorkspacePage::EXTENSION_ID:
				$page_extensions = Extension_WorkspacePage::getAll(false);
				
				$label_map = array_map(
					function($manifest) {
						return DevblocksPlatform::translateCapitalized($manifest->params['label']);
					},
					$page_extensions
				);
				
				$counts = $this->_getSubtotalCountForStringColumn($context, $column, $label_map, '=', 'value');
				break;
				
			case SearchFields_WorkspacePage::VIRTUAL_CONTEXT_LINK:
				$counts = $this->_getSubtotalCountForContextLinkColumn($context, $column);
				break;
				
			case SearchFields_WorkspacePage::VIRTUAL_OWNER:
				$counts = $this->_getSubtotalCountForContextAndIdColumns($context, $column, DAO_WorkspacePage::OWNER_CONTEXT, DAO_WorkspacePage::OWNER_CONTEXT_ID, 'owner_context[]');
				break;
				
			default:
				// Custom fields
				if('cf_' == substr($column,0,3)) {
					$counts = $this->_getSubtotalCountForCustomColumn($context, $column);
				}
				
				break;
		}
		
		return $counts;
	}
	
	function getQuickSearchFields() {
		$search_fields = SearchFields_WorkspacePage::getFields();
		
		$fields = array(
			'text' => 
				array(
					'type' => DevblocksSearchCriteria::TYPE_TEXT,
					'options' => array('param_key' => SearchFields_WorkspacePage::NAME, 'match' => DevblocksSearchCriteria::OPTION_TEXT_PARTIAL),
				),
			'id' => 
				array(
					'type' => DevblocksSearchCriteria::TYPE_NUMBER,
					'options' => array('param_key' => SearchFields_WorkspacePage::ID),
					'examples' => [
						['type' => 'chooser', 'context' => CerberusContexts::CONTEXT_WORKSPACE_PAGE, 'q' => ''],
					]
				),
			'name' => 
				array(
					'type' => DevblocksSearchCriteria::TYPE_TEXT,
					'options' => array('param_key' => SearchFields_WorkspacePage::NAME, 'match' => DevblocksSearchCriteria::OPTION_TEXT_PARTIAL),
				),
			'type' => 
				array(
					'type' => DevblocksSearchCriteria::TYPE_TEXT,
					'options' => array('param_key' => SearchFields_WorkspacePage::EXTENSION_ID, 'match' => DevblocksSearchCriteria::OPTION_TEXT_PARTIAL),
				),
			'updated' => 
				array(
					'type' => DevblocksSearchCriteria::TYPE_DATE,
					'options' => array('param_key' => SearchFields_WorkspacePage::UPDATED_AT),
				),
		);
		
		// Add 'owner.*'
		
		$fields = self::_appendVirtualFiltersFromQuickSearchContexts('owner', $fields, 'owner');
		
		// Add searchable custom fields
		
		$fields = self::_appendFieldsFromQuickSearchContext(CerberusContexts::CONTEXT_WORKSPACE_PAGE, $fields, null);
		
		// Add is_sortable
		
		$fields = self::_setSortableQuickSearchFields($fields, $search_fields);
		
		// Sort by keys
		
		ksort($fields);
		
		return $fields;
	}
	
	function getParamFromQuickSearchFieldTokens($field, $tokens) {
		switch($field) {
			default:
				if($field == 'owner' || DevblocksPlatform::strStartsWith($field, 'owner.'))
					return DevblocksSearchCriteria::getVirtualContextParamFromTokens($field, $tokens, 'owner', SearchFields_WorkspacePage::VIRTUAL_OWNER);
				
				$search_fields = $this->getQuickSearchFields();
				return DevblocksSearchCriteria::getParamFromQueryFieldTokens($field, $tokens, $search_fields);
				break;
		}
		
		return false;
	}

	function render() {
		$this->_sanitize();

		$tpl = DevblocksPlatform::services()->template();
		$tpl->assign('id', $this->id);
		$tpl->assign('view', $this);
		
		$page_extensions = Extension_WorkspacePage::getAll(false);
		$tpl->assign('page_extensions', $page_extensions);
		
		$tpl->assign('view_template', 'devblocks:cerberusweb.core::internal/workspaces/pages/view.tpl');
		$tpl->display('devblocks:cerberusweb.core::internal/views/subtotals_and_view.tpl');
	}

	function renderCriteria($field) {
		$tpl = DevblocksPlatform::services()->template();
		$tpl->assign('id', $this->id);

		switch($field) {
			case SearchFields_WorkspacePage::EXTENSION_ID:
			case SearchFields_WorkspacePage::NAME:
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__string.tpl');
				break;

			case 'placeholder_number':
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__number.tpl');
				break;

			case 'placeholder_bool':
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__bool.tpl');
				break;

			case SearchFields_WorkspacePage::UPDATED_AT:
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__date.tpl');
				break;
				
			case SearchFields_WorkspacePage::VIRTUAL_CONTEXT_LINK:
				$contexts = Extension_DevblocksContext::getAll(false);
				$tpl->assign('contexts', $contexts);
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__context_link.tpl');
				break;
				
			case SearchFields_WorkspacePage::VIRTUAL_OWNER:
				$groups = DAO_Group::getAll();
				$tpl->assign('groups', $groups);
				
				$roles = DAO_WorkerRole::getAll();
				$tpl->assign('roles', $roles);
				
				$workers = DAO_Worker::getAll();
				$tpl->assign('workers', $workers);
				
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__context_owner.tpl');
				break;
		}
	}

	function renderVirtualCriteria($param) {
		$key = $param->field;
		
		switch($key) {
			case SearchFields_WorkspacePage::VIRTUAL_CONTEXT_LINK:
				$this->_renderVirtualContextLinks($param);
				break;
			
			case SearchFields_WorkspacePage::VIRTUAL_OWNER:
				$this->_renderVirtualContextLinks($param, 'Owner', 'Owners', 'Owner matches');
				break;
		}
	}
	
	function renderCriteriaParam($param) {
		$field = $param->field;
		$values = !is_array($param->value) ? array($param->value) : $param->value;

		switch($field) {
			case SearchFields_WorkspacePage::EXTENSION_ID:
				$page_extensions = Extension_WorkspacePage::getAll(false);
				
				$label_map = array_map(
					function($manifest) {
						return DevblocksPlatform::translateCapitalized($manifest->params['label']);
					},
					$page_extensions
				);
				parent::_renderCriteriaParamString($param, $label_map);
				break;
				
			default:
				parent::renderCriteriaParam($param);
				break;
		}
	}

	function getFields() {
		return SearchFields_WorkspacePage::getFields();
	}

	function doSetCriteria($field, $oper, $value) {
		$criteria = null;

		switch($field) {
			case SearchFields_WorkspacePage::EXTENSION_ID:
			case SearchFields_WorkspacePage::NAME:
				$criteria = $this->_doSetCriteriaString($field, $oper, $value);
				break;

			case 'placeholder_number':
				$criteria = new DevblocksSearchCriteria($field,$oper,$value);
				break;

			case SearchFields_WorkspacePage::UPDATED_AT:
				$criteria = $this->_doSetCriteriaDate($field, $oper);
				break;

			case 'placeholder_bool':
				@$bool = DevblocksPlatform::importGPC($_REQUEST['bool'],'integer',1);
				$criteria = new DevblocksSearchCriteria($field,$oper,$bool);
				break;
				
			case SearchFields_WorkspacePage::VIRTUAL_CONTEXT_LINK:
				@$context_links = DevblocksPlatform::importGPC($_REQUEST['context_link'],'array',array());
				$criteria = new DevblocksSearchCriteria($field,DevblocksSearchCriteria::OPER_IN,$context_links);
				break;
				
			case SearchFields_WorkspacePage::VIRTUAL_OWNER:
				@$owner_contexts = DevblocksPlatform::importGPC($_REQUEST['owner_context'],'array',array());
				$criteria = new DevblocksSearchCriteria($field,$oper,$owner_contexts);
				break;
		}

		if(!empty($criteria)) {
			$this->addParam($criteria, $field);
			$this->renderPage = 0;
		}
	}
};

class Context_WorkspacePage extends Extension_DevblocksContext implements IDevblocksContextPeek {
	static function isReadableByActor($models, $actor) {
		return CerberusContexts::isReadableByDelegateOwner($actor, CerberusContexts::CONTEXT_WORKSPACE_PAGE, $models);
	}
	
	static function isWriteableByActor($models, $actor) {
		return CerberusContexts::isWriteableByDelegateOwner($actor, CerberusContexts::CONTEXT_WORKSPACE_PAGE, $models);
	}
	
	function getRandom() {
		return DAO_WorkspacePage::random();
	}
	
	function getMeta($context_id) {
		$url_writer = DevblocksPlatform::services()->url();

		if(null == ($workspace_page = DAO_WorkspacePage::get($context_id)))
			return [];
		
		$url = $url_writer->write(sprintf("c=pages&id=%d",
			$workspace_page->id
		));
		
		//$url = $this->profileGetUrl($context_id);
		$friendly = DevblocksPlatform::strToPermalink($workspace_page->name);

		if(!empty($friendly))
			$url .= '-' . $friendly;
		
		return [
			'id' => $workspace_page->id,
			'name' => $workspace_page->name,
			'permalink' => $url,
			'updated' => $workspace_page->updated_at,
		];
	}
	
	function getDefaultProperties() {
		return array(
			'extension__label',
			'owner__label',
			'updated_at',
		);
	}
	
	function getContext($page, &$token_labels, &$token_values, $prefix=null) {
		if(is_null($prefix))
			$prefix = 'Workspace Page:';
		
		$translate = DevblocksPlatform::getTranslationService();
		$fields = DAO_CustomField::getByContext(CerberusContexts::CONTEXT_WORKSPACE_PAGE);
		
		// Polymorph
		if(is_numeric($page)) {
			$page = DAO_WorkspacePage::get($page);
		} elseif($page instanceof Model_WorkspacePage) {
			// It's what we want already.
		} elseif(is_array($page)) {
			$page = Cerb_ORMHelper::recastArrayToModel($page, 'Model_WorkspacePage');
		} else {
			$page = null;
		}
		
		// Token labels
		$token_labels = array(
			'_label' => $prefix,
			'name' => $prefix.$translate->_('common.name'),
			'owner__label' => $prefix.$translate->_('common.owner'),
			'extension_id' => $prefix.$translate->_('Extension ID'),
			'extension__label' => $prefix.$translate->_('common.type'),
			'record_url' => $prefix.$translate->_('common.url.record'),
			'updated_at' => $prefix.$translate->_('common.updated'),
		);
		
		// Token types
		$token_types = array(
			'_label' => 'context_url',
			'name' => Model_CustomField::TYPE_SINGLE_LINE,
			'owner__label' =>'context_url',
			'extension__label' => Model_CustomField::TYPE_SINGLE_LINE,
			'extension_id' => Model_CustomField::TYPE_SINGLE_LINE,
			'record_url' => Model_CustomField::TYPE_URL,
			'updated_at' => Model_CustomField::TYPE_DATE,
		);
		
		// Custom field/fieldset token labels
		if(false !== ($custom_field_labels = $this->_getTokenLabelsFromCustomFields($fields, $prefix)) && is_array($custom_field_labels))
			$token_labels = array_merge($token_labels, $custom_field_labels);
		
		// Custom field/fieldset token types
		if(false !== ($custom_field_types = $this->_getTokenTypesFromCustomFields($fields, $prefix)) && is_array($custom_field_types))
			$token_types = array_merge($token_types, $custom_field_types);
		
		// Token values
		$token_values = array();
		
		$token_values['_context'] = CerberusContexts::CONTEXT_WORKSPACE_PAGE;
		$token_values['_types'] = $token_types;

		// Token values
		if(null != $page) {
			$token_values['_loaded'] = true;
			$token_values['_label'] = $page->name;
			$token_values['id'] = $page->id;
			$token_values['name'] = $page->name;
			$token_values['extension_id'] = $page->extension_id;
			$token_values['updated_at'] = $page->updated_at;
			
			if(false != ($page_extension = $page->getExtension())) {
				$token_values['extension__label'] = DevblocksPlatform::translateCapitalized($page_extension->manifest->params['label']);
			}

			// Custom fields
			$token_values = $this->_importModelCustomFieldsAsValues($page, $token_values);
			
			// URL
			$url_writer = DevblocksPlatform::services()->url();
			$token_values['record_url'] = $url_writer->writeNoProxy(sprintf("c=pages&id=%d-%s",$page->id, DevblocksPlatform::strToPermalink($page->name)), true);
			
			$token_values['owner__context'] = $page->owner_context;
			$token_values['owner_id'] = $page->owner_context_id;
		}
		
		return true;
	}
	
	function getKeyToDaoFieldMap() {
		return [
			'extension_id' => DAO_WorkspacePage::EXTENSION_ID,
			'id' => DAO_WorkspacePage::ID,
			'links' => '_links',
			'name' => DAO_WorkspacePage::NAME,
			'owner__context' => DAO_WorkspacePage::OWNER_CONTEXT,
			'owner_id' => DAO_WorkspacePage::OWNER_CONTEXT_ID,
			'updated_at' => DAO_WorkspacePage::UPDATED_AT,
		];
	}
	
	function getDaoFieldsFromKeyAndValue($key, $value, &$out_fields, &$error) {
		switch(DevblocksPlatform::strLower($key)) {
			case 'links':
				$this->_getDaoFieldsLinks($value, $out_fields, $error);
				break;
		}
		
		return true;
	}
	
	function lazyLoadContextValues($token, $dictionary) {
		if(!isset($dictionary['id']))
			return;
		
		$context = CerberusContexts::CONTEXT_WORKSPACE_PAGE;
		$context_id = $dictionary['id'];
		
		@$is_loaded = $dictionary['_loaded'];
		$values = array();
		
		if(!$is_loaded) {
			$labels = array();
			CerberusContexts::getContext($context, $context_id, $labels, $values, null, true, true);
		}
		
		switch($token) {
			case 'links':
				$links = $this->_lazyLoadLinks($context, $context_id);
				$values = array_merge($values, $links);
				break;
			
			case 'tabs':
				$tabs = DAO_WorkspaceTab::getByPage($context_id);
				$values['tabs'] = array();
				
				foreach(array_keys($tabs) as $tab_id) {
					$tab_labels = array();
					$tab_values = array();
					CerberusContexts::getContext(CerberusContexts::CONTEXT_WORKSPACE_TAB, $tab_id, $tab_labels, $tab_values, null, true);
					$values['tabs'][] = $tab_values;
				}
				break;
				
			case 'widgets':
				$values = $dictionary;
				
				if(!isset($values['tabs']))
					$values = self::lazyLoadContextValues('tabs', $values);
				
				if(!is_array($values['tabs']))
					break;
				
				$context_tab = Extension_DevblocksContext::get(CerberusContexts::CONTEXT_WORKSPACE_TAB); /* @var $context_widget Context_WorkspaceTab */
				
				// Send the lazy load request to the tab itself
				foreach($values['tabs'] as $idx => $tab) {
					$values['tabs'][$idx] = $context_tab->lazyLoadContextValues('widgets', $values['tabs'][$idx]);
				}
				break;
				
			case 'worklists':
				$values = $dictionary;

				if(!isset($values['tabs']))
					$values = self::lazyLoadContextValues('tabs', $values);
				
				if(!is_array($values['tabs']))
					break;
				
				$context_tab = Extension_DevblocksContext::get(CerberusContexts::CONTEXT_WORKSPACE_TAB); /* @var $context_widget Context_WorkspaceTab */
				
				// Send the lazy load request to the tab itself
				foreach($values['tabs'] as $idx => $tab) {
					$values['tabs'][$idx] = $context_tab->lazyLoadContextValues('worklists', $values['tabs'][$idx]);
				}
				break;
			
			default:
				if(DevblocksPlatform::strStartsWith($token, 'custom_')) {
					$fields = $this->_lazyLoadCustomFields($token, $context, $context_id);
					$values = array_merge($values, $fields);
				}
				break;
		}
		
		return $values;
	}

	function getChooserView($view_id=null) {
		if(empty($view_id))
			$view_id = 'chooser_'.str_replace('.','_',$this->id).time().mt_rand(0,9999);
		
		$active_worker = CerberusApplication::getActiveWorker();
			
		// View
		$defaults = C4_AbstractViewModel::loadFromClass($this->getViewClass());
		$defaults->id = $view_id;
		$defaults->is_ephemeral = true;
		
		$view = C4_AbstractViewLoader::getView($view_id, $defaults);
		$view->name = 'Pages';
		
		$params_req = array();
		
		if($active_worker && !$active_worker->is_superuser) {
			$worker_group_ids = array_keys($active_worker->getMemberships());
			$worker_role_ids = array_keys(DAO_WorkerRole::getRolesByWorker($active_worker->id));
			
			// Restrict owners
			
			$params = $view->getParamsFromQuickSearch(sprintf('(owner.app:cerb OR owner.worker:(id:[%d]) OR owner.group:(id:[%s]) OR owner.role:(id:[%s])',
				$active_worker->id,
				implode(',', $worker_group_ids),
				implode(',', $worker_role_ids)
			));
			
			$params_req['_ownership'] = $params[0];
		}
		
		$view->addParamsRequired($params_req, true);
		
		$view->renderSortBy = SearchFields_WorkspacePage::NAME;
		$view->renderSortAsc = true;
		$view->renderLimit = 10;
		$view->renderFilters = false;
		$view->renderTemplate = 'contextlinks_chooser';
		
		return $view;
	}
	
	function getView($context=null, $context_id=null, $options=array(), $view_id=null) {
		$view_id = !empty($view_id) ? $view_id : str_replace('.','_',$this->id);
		
		$active_worker = CerberusApplication::getActiveWorker();
		
		$defaults = C4_AbstractViewModel::loadFromClass($this->getViewClass());
		$defaults->id = $view_id;

		$view = C4_AbstractViewLoader::getView($view_id, $defaults);
		$view->name = 'Pages';
		
		$params_req = [];
		
		if(!empty($context) && !empty($context_id)) {
			$params_req = [
				new DevblocksSearchCriteria(SearchFields_WorkspacePage::VIRTUAL_CONTEXT_LINK,'in',array($context.':'.$context_id)),
			];
		}
		
		if($active_worker && !$active_worker->is_superuser) {
			$worker_group_ids = array_keys($active_worker->getMemberships());
			$worker_role_ids = array_keys(DAO_WorkerRole::getRolesByWorker($active_worker->id));
			
			// Restrict owners
			
			$params = $view->getParamsFromQuickSearch(sprintf('(owner.app:cerb OR owner.worker:(id:[%d]) OR owner.group:(id:[%s]) OR owner.role:(id:[%s])',
				$active_worker->id,
				implode(',', $worker_group_ids),
				implode(',', $worker_role_ids)
			));
			
			$params_req['_ownership'] = $params[0];
		}
		
		$view->addParamsRequired($params_req, true);
		
		$view->renderTemplate = 'context';
		return $view;
	}
	
	function renderPeekPopup($context_id=0, $view_id='', $edit=false) {
		$tpl = DevblocksPlatform::services()->template();
		$tpl->assign('view_id', $view_id);
		
		$context = CerberusContexts::CONTEXT_WORKSPACE_PAGE;
		$active_worker = CerberusApplication::getActiveWorker();
		
		if(!empty($context_id)) {
			$model = DAO_WorkspacePage::get($context_id);
		}
		
		if(empty($context_id) || $edit) {
			if(isset($model))
				$tpl->assign('model', $model);
			
			// Custom fields
			$custom_fields = DAO_CustomField::getByContext($context, false);
			$tpl->assign('custom_fields', $custom_fields);
	
			$custom_field_values = DAO_CustomFieldValue::getValuesByContextIds($context, $context_id);
			if(isset($custom_field_values[$context_id]))
				$tpl->assign('custom_field_values', $custom_field_values[$context_id]);
			
			$types = Model_CustomField::getTypes();
			$tpl->assign('types', $types);
			
			// Owner
			$owners_menu = Extension_DevblocksContext::getOwnerTree();
			$tpl->assign('owners_menu', $owners_menu);
			
			// Extensions
			
			$page_extensions = Extension_WorkspacePage::getAll(false);
			
			// Sort workspaces to top
			$workspaces_extension = array('core.workspace.page.workspace' => $page_extensions['core.workspace.page.workspace']);
			unset($page_extensions['core.workspace.page.workspace']);
			$page_extensions = array_merge($workspaces_extension, $page_extensions);
			
			$tpl->assign('page_extensions', $page_extensions);
			
			// View
			$tpl->assign('id', $context_id);
			$tpl->assign('view_id', $view_id);
			$tpl->display('devblocks:cerberusweb.core::internal/workspaces/pages/peek_edit.tpl');
			
		} else {
			// Counts
			$activity_counts = array(
				'tabs' => DAO_WorkspaceTab::countByPageId($context_id),
				//'comments' => DAO_Comment::count($context, $context_id),
			);
			$tpl->assign('activity_counts', $activity_counts);
			
			// Links
			$links = array(
				$context => array(
					$context_id => 
						DAO_ContextLink::getContextLinkCounts(
							$context,
							$context_id,
							array(CerberusContexts::CONTEXT_CUSTOM_FIELDSET)
						),
				),
			);
			$tpl->assign('links', $links);
			
			// Timeline
			if($context_id) {
				$timeline_json = Page_Profiles::getTimelineJson(Extension_DevblocksContext::getTimelineComments($context, $context_id));
				$tpl->assign('timeline_json', $timeline_json);
			}

			// Context
			if(false == ($context_ext = Extension_DevblocksContext::get($context)))
				return;
			
			// Dictionary
			$labels = [];
			$values = [];
			CerberusContexts::getContext($context, $model, $labels, $values, '', true, false);
			$dict = DevblocksDictionaryDelegate::instance($values);
			$tpl->assign('dict', $dict);
			
			$properties = $context_ext->getCardProperties();
			$tpl->assign('properties', $properties);
			
			// Page users
			// [TODO] Redo this as another popup
			$page_users = $model->getUsers();
			$tpl->assign('page_users', $page_users);
			$tpl->assign('workers', DAO_Worker::getAll());
			
			$tpl->display('devblocks:cerberusweb.core::internal/workspaces/pages/peek.tpl');
		}
	}
};
