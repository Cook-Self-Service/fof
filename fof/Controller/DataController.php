<?php
/**
 * @package     FOF
 * @copyright   2010-2015 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license     GNU GPL version 2 or later
 */

namespace FOF30\Controller;

use FOF30\Container\Container;
use FOF30\Inflector\Inflector;
use FOF30\Model\DataModel;

defined('_JEXEC') or die;

/**
 * Database-aware Controller
 *
 * @property-read  \FOF30\Input\Input  $input  The input object (magic __get returns the Input from the Container)
 */
class DataController extends Controller
{
	/**
	 * The tasks for which caching should be enabled by default
	 *
	 * @var array
	 */
	protected $cacheableTasks = array('browse', 'read');

	/**
	 * An associative array for required ACL privileges per task. For example:
	 * array(
	 *   'edit' => 'core.edit',
	 *   'jump' => 'foobar.jump',
	 *   'alwaysallow' => 'true',
	 *   'neverallow' => 'false'
	 * );
	 *
	 * You can use the notation '@task' which means 'apply the same privileges as "task"'. If you create a reference
	 * back to yourself (e.g. 'mytask' => array('@mytask')) it will return TRUE.
	 *
	 * @var array
	 */
	protected $taskPrivileges = array(
		// Special privileges
		'*editown' => 'core.edit.own', // Privilege required to edit own record
		// Standard tasks
		'add' => 'core.create',
		'apply' => '&getACLForApplySave', // Apply task: call the getACLForApplySave method
		'archive' => 'core.edit.state',
		'cancel' => 'core.edit.state',
		'copy' => '@add', // Maps copy ACLs to the add task
		'edit' => 'core.edit',
		'loadhistory' => '@edit', // Maps loadhistory ACLs to the edit task
		'orderup' => 'core.edit.state',
		'orderdown' => 'core.edit.state',
		'publish' => 'core.edit.state',
		'remove' => 'core.delete',
		'save' => '&getACLForApplySave', // Save task: call the getACLForApplySave method
		'savenew' => 'core.create',
		'saveorder' => 'core.edit.state',
		'trash' => 'core.edit.state',
		'unpublish' => 'core.edit.state',
	);

	public function __construct(Container $container = null)
	{
		parent::__construct($container);

		// Set up a default model name if none is provided
		if (empty($this->modelName))
		{
			$this->modelName = Inflector::pluralize($this->view);
		}

		// Set up a default view name if none is provided
		if (empty($this->viewName))
		{
			$this->viewName = Inflector::pluralize($this->view);
		}
	}

	/**
	 * Executes a given controller task. The onBefore<task> and onAfter<task> methods are called automatically if they
	 * exist.
	 *
	 * If $task == 'default' we will determine the CRUD task to use based on the view name and HTTP verb in the request,
	 * overriding the routing.
	 *
	 * @param   string $task The task to execute, e.g. "browse"
	 *
	 * @return  null|bool  False on execution failure
	 *
	 * @throws  \Exception  When the task is not found
	 */
	public function execute($task)
	{
		$task = strtolower($task);

		if ($task == 'default')
		{
			$task = $this->getCrudTask();
		}

		return parent::execute($task);
	}

	/**
	 * Determines the CRUD task to use based on the view name and HTTP verb used in the request.
	 *
	 * @return  string  The CRUD task (browse, read, edit, delete)
	 */
	protected function getCrudTask()
	{
		// By default, a plural view means 'browse' and a singular view means 'edit'
		$view = $this->input->getCmd('view', null);
		$task = Inflector::isPlural($view) ? 'browse' : 'edit';

		// If the task is 'edit' but there's no logged in user switch to a 'read' task
		if (($task == 'edit') && !$this->container->platform->getUser()->id)
		{
			$task = 'read';
		}

		// Check if there is an id passed in the request
		$id = $this->input->get('id', null, 'int');

		if ($id == 0)
		{
			$ids = $this->input->get('ids', array(), 'array');

			if (!empty($ids))
			{
				$id = array_shift($ids);
			}
		}

		// Get the request HTTP verb
		$requestMethod = 'GET';

		if (isset($_SERVER['REQUEST_METHOD']))
		{
			$requestMethod = strtoupper($_SERVER['REQUEST_METHOD']);
		}

		// Alter the task based on the verb
		switch ($requestMethod)
		{
			// POST and PUT result in a record being saved, as long as there is an ID
			case 'POST':
			case 'PUT':
				if ($id)
				{
					$task = 'save';
				}
				break;

			// DELETE results in a record being deleted, as long as there is an ID
			case 'DELETE':
				if ($id)
				{
					$task = 'delete';
				}
				break;

			// GET results in browse, edit or add depending on the ID
			case 'GET':
			default:
				// If it's an edit without an ID or ID=0, it's really an add
				if (($task == 'edit') && ($id == 0))
				{
					$task = 'add';
				}
				break;
		}

		return $task;
	}

	/**
	 * Checks if the current user has enough privileges for the requested ACL area. This overridden method supports
	 * asset tracking as well.
	 *
	 * @param   string  $area  The ACL area, e.g. core.manage
	 *
	 * @return  boolean  True if the user has the ACL privilege specified
	 */
	protected function checkACL($area)
	{
		$area = $this->getACLRuleFor($area);

		$result = parent::checkACL($area);

		// Check if we're dealing with ids
		$ids = null;

		// First, check if there is an asset for this record
		/** @var DataModel $model */
		$model = $this->getModel();

		$ids = null;

		if (is_object($model) && ($model instanceof DataModel) && $model->isAssetsTracked())
		{
			$ids = $this->getIDsFromRequest($model, false);
		}

		// No IDs tracked, return parent's result
		if (empty($ids))
		{
			return $result;
		}

		// Asset tracking
		if (!is_array($ids))
		{
			$ids = array($ids);
		}

		$resource = Inflector::singularize($this->view);
		$isEditState = ($area == 'core.edit.state');

		foreach ($ids as $id)
		{
			$asset = $this->container->componentName . '.' . $resource . '.' . $id;

			// Dedicated permission found, check it!
			$platform = $this->container->platform;

			if ($platform->authorise($area, $asset) )
			{
				return true;
			}

			// Fallback on edit.own, if not edit.state. First test if the permission is available.

			$editOwn = $this->getACLRuleFor('@*editown');

			if ((!$isEditState) && ($platform->authorise($editOwn, $asset)))
			{
				$model->load($id);

				if (!$model->hasField('created_by'))
				{
					return false;
				}

				// Now test the owner is the user.
				$owner_id = (int) $model->getFieldValue('created_by', null);

				// If the owner matches 'me' then do the test.
				if ($owner_id == $platform->getUser()->id)
				{
					return true;
				}

				return false;
			}
		}

		// No result found? Not authorised.
		return false;
	}

	/**
	 * Implements a default browse task, i.e. read a bunch of records and send
	 * them to the browser.
	 *
	 * @return  void
	 */
	public function browse()
	{
		// Initialise the savestate
		$saveState = $this->input->get('savestate', -999, 'int');

		if ($saveState == -999)
		{
			$saveState = true;
		}

		$this->getModel()->savestate($saveState);

		// Apply the Form name
		$formName = 'form.default';

		if (!empty($this->layout))
		{
			$formName = 'form.' . $this->layout;
		}

		$this->getModel()->setFormName($formName);

		$this->display(in_array('browse', $this->cacheableTasks));
	}

	/**
	 * Single record read. The id set in the request is passed to the model and
	 * then the item layout is used to render the result.
	 *
	 * @return  void
	 *
	 * @throws \RuntimeException When the item is not found
	 */
	public function read()
	{
		// Load the model
		/** @var DataModel $model */
		$model = $this->getModel();

		// If there is no record loaded, try loading a record based on the id passed in the input object
		if (!$model->getId())
		{
			$ids = $this->getIDsFromRequest($model, true);

			if ($model->getId() != reset($ids))
			{
				$key = $this->container->componentName . '_ERR_' . $model->getName() . '_NOTFOUND';
				throw new \RuntimeException(\JText::_($key), 404);
			}
		}

		// Set the layout to item, if it's not set in the URL
		if (empty($this->layout))
		{
			$this->layout = 'item';
		}

		// Apply the Form name
		$formName = 'form.' . $this->layout;
		$this->getModel()->setFormName($formName);

		$this->display(in_array('read', $this->cacheableTasks));
	}

	/**
	 * Single record add. The form layout is used to present a blank page.
	 *
	 * @return  void
	 */
	public function add()
	{
		// Load and reset the model
		$model = $this->getModel();
		$model->reset();

		// Set the layout to form, if it's not set in the URL
		if (empty($this->layout))
		{
			$this->layout = 'form';
		}

		// Get temporary data from the session, set if the save failed and we're redirected back here
		$sessionKey = $this->viewName . '.savedata';
		$itemData = $this->container->session->get($sessionKey, null, $this->container->componentName);
		$this->container->session->set($sessionKey, null, $this->container->componentName);

		if (!empty($itemData))
		{
			$model->bind($itemData);
		}

		// Apply the Form name
		$formName = 'form.form';

		if (!empty($this->layout))
		{
			$formName = 'form.' . $this->layout;
		}

		$this->getModel()->setFormName($formName);

		// Display the edit form
		$this->display(in_array('add', $this->cacheableTasks));
	}

	/**
	 * Single record edit. The ID set in the request is passed to the model,
	 * then the form layout is used to edit the result.
	 *
	 * @return  void
	 */
	public function edit()
	{
		// Load the model
		/** @var DataModel $model */
		$model = $this->getModel();

		if (!$model->getId())
		{
			$this->getIDsFromRequest($model, true);
		}

		try
		{
			$model->lock();
		}
		catch (\Exception $e)
		{
			// Redirect on error
			if ($customURL = $this->input->getBase64('returnurl', ''))
			{
				$customURL = base64_decode($customURL);
			}

			$url = !empty($customURL) ? $customURL : \JRoute::_('index.php?&view=' . Inflector::pluralize($this->view) . $this->getItemidURLSuffix());
			$this->setRedirect($url, $e->getMessage(), 'error');

			return;
		}

		// Set the layout to form, if it's not set in the URL
		if (empty($this->layout))
		{
			$this->layout = 'form';
		}

		// Get temporary data from the session, set if the save failed and we're redirected back here
		$sessionKey = $this->viewName . '.savedata';
		$itemData = $this->container->session->get($sessionKey, null, $this->container->componentName);
		$this->container->session->set($sessionKey, null, $this->container->componentName);

		if (!empty($itemData))
		{
			$model->bind($itemData);
		}

		// Apply the Form name
		$formName = 'form.' . $this->layout;
		$this->getModel()->setFormName($formName);

		// Display the edit form
		$this->display(in_array('edit', $this->cacheableTasks));
	}

	/**
	 * Save the incoming data and then return to the Edit task
	 *
	 * @return  void
	 */
	public function apply()
	{
		// CSRF prevention
		$this->csrfProtection();

		// Redirect to the edit task
		if (!$this->applySave())
		{
			return;
		}

		$id = $this->input->get('id', 0, 'int');
		$textKey = $this->container->componentName . '_LBL_' . Inflector::singularize($this->view) . '_SAVED';

		if ($customURL = $this->input->getBase64('returnurl', ''))
		{
			$customURL = base64_decode($customURL);
		}

		$url = !empty($customURL) ? $customURL : \JRoute::_('index.php?option=' . $this->container->componentName . '&view=' . $this->view . '&task=edit&id=' . $id . $this->getItemidURLSuffix());
		$this->setRedirect($url, \JText::_($textKey));
	}

	/**
	 * Duplicates selected items
	 *
	 * @return  void
	 */
	public function copy()
	{
		// CSRF prevention
		$this->csrfProtection();

		$model = $this->getModel();

		$ids = $this->getIDsFromRequest($model, true);

		$error = null;

		try
		{
			$status = true;

			foreach ($ids as $id)
			{
				$model->find($id);
				$model->copy();
			}
		}
		catch (\Exception $e)
		{
			$status = false;
			$error = $e->getMessage();
		}

		// Redirect
		if ($customURL = $this->input->getBase64('returnurl', ''))
		{
			$customURL = base64_decode($customURL);
		}

		$url = !empty($customURL) ? $customURL : \JRoute::_('index.php?option=' . $this->container->componentName . '&view=' . Inflector::pluralize($this->view) . $this->getItemidURLSuffix());

		if (!$status)
		{
			$this->setRedirect($url, $error, 'error');
		}
		else
		{
			$textKey = $this->container->componentName . '_LBL_' . Inflector::singularize($this->view) . '_COPIED';
			$this->setRedirect($url, \JText::_($textKey));
		}
	}

	/**
	 * Save the incoming data and then return to the Browse task
	 *
	 * @return  void
	 */
	public function save()
	{
		// CSRF prevention
		$this->csrfProtection();

		if (!$this->applySave())
		{
			return;
		}

		$textKey = $this->container->componentName . '_LBL_' . Inflector::singularize($this->view) . '_SAVED';

		if ($customURL = $this->input->getBase64('returnurl', ''))
		{
			$customURL = base64_decode($customURL);
		}

		$url = !empty($customURL) ? $customURL : \JRoute::_('index.php?option=' . $this->container->componentName . '&view=' . Inflector::pluralize($this->view) . $this->getItemidURLSuffix());
		$this->setRedirect($url, \JText::_($textKey));
	}

	/**
	 * Save the incoming data and then return to the Add task
	 *
	 * @return  bool
	 */
	public function savenew()
	{
		// CSRF prevention
		$this->csrfProtection();

		if (!$this->applySave())
		{
			return;
		}

		$textKey = $this->container->componentName . '_LBL_' . Inflector::singularize($this->view) . '_SAVED';

		if ($customURL = $this->input->getBase64('returnurl', ''))
		{
			$customURL = base64_decode($customURL);
		}

		$url = !empty($customURL) ? $customURL : \JRoute::_('index.php?option=' . $this->container->componentName . '&view=' . Inflector::singularize($this->view) . '&task=add' . $this->getItemidURLSuffix());
		$this->setRedirect($url, \JText::_($textKey));
	}

	/**
	 * Cancel the edit, check in the record and return to the Browse task
	 *
	 * @return  void
	 */
	public function cancel()
	{
		$model = $this->getModel();

		if (!$model->getId())
		{
			$this->getIDsFromRequest($model, true);
		}

		$model->unlock();

		// Remove any saved data
		$sessionKey = $this->viewName . '.savedata';
		$this->container->session->set($sessionKey, null, $this->container->componentName);

		// Redirect to the display task
		if ($customURL = $this->input->getBase64('returnurl', ''))
		{
			$customURL = base64_decode($customURL);
		}

		$url = !empty($customURL) ? $customURL : \JRoute::_('index.php?option=' . $this->container->componentName . '&view=' . Inflector::pluralize($this->view) . $this->getItemidURLSuffix());
		$this->setRedirect($url);
	}

	/**
	 * Publish (set enabled = 1) an item.
	 *
	 * @return  void
	 */
	public function publish()
	{
		// CSRF prevention
		$this->csrfProtection();

		$model = $this->getModel();
		$ids   = $this->getIDsFromRequest($model, false);
		$error = false;

		try
		{
			$status = true;

			foreach ($ids as $id)
			{
				$model->find($id);
				$model->publish();
			}
		}
		catch (\Exception $e)
		{
			$status = false;
			$error  = $e->getMessage();
		}

		// Redirect
		if ($customURL = $this->input->getBase64('returnurl', ''))
		{
			$customURL = base64_decode($customURL);
		}

		$url = !empty($customURL) ? $customURL : \JRoute::_('index.php?option=' . $this->container->componentName . '&view=' . Inflector::pluralize($this->view) . $this->getItemidURLSuffix());

		if (!$status)
		{
			$this->setRedirect($url, $error, 'error');
		}
		else
		{
			$this->setRedirect($url);
		}
	}

	/**
	 * Unpublish (set enabled = 0) an item.
	 *
	 * @return  void
	 */
	public function unpublish()
	{
		// CSRF prevention
		$this->csrfProtection();

		$model = $this->getModel();
		$ids   = $this->getIDsFromRequest($model, false);
		$error = null;

		try
		{
			$status = true;

			foreach ($ids as $id)
			{
				$model->find($id);
				$model->unpublish();
			}
		}
		catch (\Exception $e)
		{
			$status = false;
			$error  = $e->getMessage();
		}

		// Redirect
		if ($customURL = $this->input->getBase64('returnurl', ''))
		{
			$customURL = base64_decode($customURL);
		}

		$url = !empty($customURL) ? $customURL : \JRoute::_('index.php?option=' . $this->container->componentName . '&view=' . Inflector::pluralize($this->view) . $this->getItemidURLSuffix());

		if (!$status)
		{
			$this->setRedirect($url, $error, 'error');
		}
		else
		{
			$this->setRedirect($url);
		}
	}

	/**
	 * Archive (set enabled = 2) an item.
	 *
	 * @return  void
	 */
	public function archive()
	{
		// CSRF prevention
		$this->csrfProtection();

		$model = $this->getModel();
		$ids   = $this->getIDsFromRequest($model, false);
		$error = null;

		try
		{
			$status = true;

			foreach ($ids as $id)
			{
				$model->find($id);
				$model->archive();
			}
		}
		catch (\Exception $e)
		{
			$status = false;
			$error  = $e->getMessage();
		}

		// Redirect
		if ($customURL = $this->input->getBase64('returnurl', ''))
		{
			$customURL = base64_decode($customURL);
		}

		$url = !empty($customURL) ? $customURL : \JRoute::_('index.php?option=' . $this->container->componentName . '&view=' . Inflector::pluralize($this->view) . $this->getItemidURLSuffix());

		if (!$status)
		{
			$this->setRedirect($url, $error, 'error');
		}
		else
		{
			$this->setRedirect($url);
		}
	}

	/**
	 * Trash (set enabled = -2) an item.
	 *
	 * @return  void
	 */
	public function trash()
	{
		// CSRF prevention
		$this->csrfProtection();

		$model = $this->getModel();
		$ids   = $this->getIDsFromRequest($model, false);
		$error = null;

		try
		{
			$status = true;

			foreach ($ids as $id)
			{
				$model->find($id);
				$model->trash();
			}
		}
		catch (\Exception $e)
		{
			$status = false;
			$error  = $e->getMessage();
		}

		// Redirect
		if ($customURL = $this->input->getBase64('returnurl', ''))
		{
			$customURL = base64_decode($customURL);
		}

		$url = !empty($customURL) ? $customURL : \JRoute::_('index.php?option=' . $this->container->componentName . '&view=' . Inflector::pluralize($this->view) . $this->getItemidURLSuffix());

		if (!$status)
		{
			$this->setRedirect($url, $error, 'error');
		}
		else
		{
			$this->setRedirect($url);
		}
	}

	/**
	 * Saves the order of the items
	 *
	 * @return  void
	 */
	public function saveorder()
	{
		// CSRF prevention
		$this->csrfProtection();

		$type   = null;
		$msg    = null;
		$model  = $this->getModel();
		$ids    = $this->getIDsFromRequest($model, false);
		$orders = $this->input->get('order', array(), 'array');

		// Before saving the order, I have to check I the table really supports the ordering feature
		if(!$model->hasField('ordering'))
		{
			$msg  = sprintf('%s does not support ordering.', $model->getTableName());
			$type = 'error';
		}
		else
		{
			$ordering = $model->getFieldAlias('ordering');

			// Several methods could throw exceptions, so let's wrap everything in a try-catch
			try
			{
				if ($n = count($ids))
				{
					for ($i = 0; $i < $n; $i++)
					{
						$item     = $model->find($ids[$i]);
						$neworder = (int)$orders[$i];

						if (!($item instanceof DataModel))
						{
							continue;
						}

						if ($item->getId() == $ids[$i])
						{
							$item->$ordering = $neworder;
							$model->save($item);
						}
					}
				}

				$model->reorder();
			}
			catch(\Exception $e)
			{
				$msg  = $e->getMessage();
				$type = 'error';
			}
		}

		// Redirect
		if ($customURL = $this->input->getBase64('returnurl', ''))
		{
			$customURL = base64_decode($customURL);
		}

		$url    = !empty($customURL) ? $customURL : \JRoute::_('index.php?option=' . $this->container->componentName . '&view=' . Inflector::pluralize($this->view) . $this->getItemidURLSuffix());

		$this->setRedirect($url, $msg, $type);
	}

	/**
	 * Moves selected items one position down the ordering list
	 *
	 * @return  void
	 */
	public function orderdown()
	{
		// CSRF prevention
		$this->csrfProtection();

		$model = $this->getModel();

		if (!$model->getId())
		{
			$this->getIDsFromRequest($model, true);
		}

		$error = null;

		try
		{
			$model->move(1);
			$status = true;
		}
		catch (\Exception $e)
		{
			$status = false;
			$error = $e->getMessage();
		}

		// Redirect
		if ($customURL = $this->input->getBase64('returnurl', ''))
		{
			$customURL = base64_decode($customURL);
		}

		$url = !empty($customURL) ? $customURL : \JRoute::_('index.php?option=' . $this->container->componentName . '&view=' . Inflector::pluralize($this->view) . $this->getItemidURLSuffix());

		if (!$status)
		{
			$this->setRedirect($url, $error, 'error');
		}
		else
		{
			$this->setRedirect($url);
		}
	}

	/**
	 * Moves selected items one position up the ordering list
	 *
	 * @return  void
	 */
	public function orderup()
	{
		// CSRF prevention
		$this->csrfProtection();

		$model = $this->getModel();

		if (!$model->getId())
		{
			$this->getIDsFromRequest($model, true);
		}

		$error = null;

		try
		{
			$model->move(-1);
			$status = true;
		}
		catch (\Exception $e)
		{
			$status = false;
			$error = $e->getMessage();
		}

		// Redirect
		if ($customURL = $this->input->getBase64('returnurl', ''))
		{
			$customURL = base64_decode($customURL);
		}

		$url = !empty($customURL) ? $customURL : \JRoute::_('index.php?option=' . $this->container->componentName . '&view=' . Inflector::pluralize($this->view) . $this->getItemidURLSuffix());

		if (!$status)
		{
			$this->setRedirect($url, $error, 'error');
		}
		else
		{
			$this->setRedirect($url);
		}
	}

	/**
	 * Delete selected item(s)
	 *
	 * @return  void
	 */
	public function remove()
	{
		// CSRF prevention
		$this->csrfProtection();

		$model = $this->getModel();
		$ids = $this->getIDsFromRequest($model, false);
		$error = null;

		try
		{
			$status = true;

			foreach ($ids as $id)
			{
				$model->find($id);
				$model->delete();
			}
		}
		catch (\Exception $e)
		{
			$status = false;
			$error = $e->getMessage();
		}

		// Redirect
		if ($customURL = $this->input->getBase64('returnurl', ''))
		{
			$customURL = base64_decode($customURL);
		}

		$url = !empty($customURL) ? $customURL : \JRoute::_('index.php?option=' . $this->container->componentName . '&view=' . Inflector::pluralize($this->view) . $this->getItemidURLSuffix());

		if (!$status)
		{
			$this->setRedirect($url, $error, 'error');
		}
		else
		{
			$textKey = $this->container->componentName . '_LBL_' . Inflector::singularize($this->view) . '_DELETED';
			$this->setRedirect($url, \JText::_($textKey));
		}
	}

	/**
	 * Common method to handle apply and save tasks
	 *
	 * @return  bool True on success
	 */
	protected function applySave()
	{
		// Load the model
		$model = $this->getModel();

		if (!$model->getId())
		{
			$this->getIDsFromRequest($model, true);
		}

		$id = $model->getId();

		$data = $this->input->getData();

		// Set the layout to form, if it's not set in the URL
		if (is_null($this->layout))
		{
			$this->layout = 'form';
		}

		// Apply the Form name
		$formName = 'form.' . $this->layout;
		$this->getModel()->setFormName($formName);

		// Save the data
		$status = true;
		$error = null;

		try
		{
			if (method_exists($this, 'onBeforeApplySave'))
			{
				$this->onBeforeApplySave($data);
			}

			// Save the data
			$model->save($data);

			if ($id != 0)
			{
				// Try to check-in the record if it's not a new one
				$model->unlock();
			}

			if (method_exists($this, 'onAfterApplySave'))
			{
				$this->onAfterApplySave($data);
			}

			$this->input->set('id', $model->getId());
		}
		catch (\Exception $e)
		{
			$status = false;
			$error = $e->getMessage();
		}

		if (!$status)
		{
			// Cache the item data in the session. We may need to reuse them if the save fails.
			$itemData = $model->getData();

			$sessionKey = $this->viewName . '.savedata';
			$this->container->session->set($sessionKey, $itemData, $this->container->componentName);

			// Redirect on error
			$id = $model->getId();

			if ($customURL = $this->input->getBase64('returnurl', ''))
			{
				$customURL = base64_decode($customURL);
			}

			if (!empty($customURL))
			{
				$url = $customURL;
			}
			elseif ($id != 0)
			{
				$url = \JRoute::_('index.php?option=' . $this->container->componentName . '&view=' . $this->view . '&task=edit&id=' . $id . $this->getItemidURLSuffix());
			}
			else
			{
				$url = \JRoute::_('index.php?option=' . $this->container->componentName . '&view=' . $this->view . '&task=add' . $this->getItemidURLSuffix());
			}

			$this->setRedirect($url, $error, 'error');
		}
		else
		{
			$sessionKey = $this->viewName . '.savedata';
			$this->container->session->set($sessionKey, null, $this->container->componentName);
		}

		return $status;
	}

	/**
	 * Returns a named Model object. Makes sure that the Model is a database-aware model, throwing an exception
	 * otherwise, when $name is null.
	 *
	 * @param   string $name     The Model name. If null we'll use the modelName
	 *                           variable or, if it's empty, the same name as
	 *                           the Controller
	 * @param   array  $config   Configuration parameters to the Model. If skipped
	 *                           we will use $this->config
	 *
	 * @return  DataModel  The instance of the Model known to this Controller
	 *
	 * @throws  \RuntimeException  When the model type doesn't match our expectations
	 */
	public function getModel($name = null, $config = array())
	{
		$model = parent::getModel($name, $config);

		if (is_null($name) && !($model instanceof DataModel))
		{
			throw new \RuntimeException('Model ' . get_class($model) . ' is not a database-aware Model');
		}

		return $model;
	}

	/**
	 * Gets the list of IDs from the request data
	 *
	 * @param DataModel $model      The model where the record will be loaded
	 * @param bool      $loadRecord When true, the record matching the *first* ID found will be loaded into $model
	 *
	 * @return array
	 */
	public function getIDsFromRequest(DataModel &$model, $loadRecord = true)
	{
		// Get the ID or list of IDs from the request or the configuration
		$cid = $this->input->get('cid', array(), 'array');
		$id = $this->input->getInt('id', 0);
		$kid = $this->input->getInt($model->getIdFieldName(), 0);

		$ids = array();

		if (is_array($cid) && !empty($cid))
		{
			$ids = $cid;
		}
		else
		{
			if (empty($id))
			{
				if(!empty($kid))
				{
					$ids = array($kid);
				}
			}
			else
			{
				$ids = array($id);
			}
		}

		if ($loadRecord && !empty($ids))
		{
			$id = reset($ids);
			$model->find(array('id' => $id));
		}

		return $ids;
	}

	/**
	 * Method to load a row from version history
	 *
	 * @return   boolean  True if the content history is reverted, false otherwise
	 *
	 * @since   2.2
	 */
	public function loadhistory()
	{
		$model = $this->getModel();
		$historyId = $this->input->get('version_id', null, 'integer');
		$model->lock();
		$alias = $this->container->componentName . '.' . $this->view;

		try
		{
			$model->loadhistory($historyId, $alias);
		}
		catch (\Exception $e)
		{
			$this->setMessage($e->getMessage(), 'error');

			$url = !empty($customURL) ? $customURL : 'index.php?option=' . $this->container->componentName . '&view=' . Inflector::pluralize($this->view) . $this->getItemidURLSuffix();
			$this->setRedirect($url);

			return false;
		}

		// Access check.
		if (!$this->checkACL('@loadhistory'))
		{
			$this->setMessage(\JText::_('JLIB_APPLICATION_ERROR_EDIT_NOT_PERMITTED'), 'error');

			$url = !empty($customURL) ? $customURL : 'index.php?option=' . $this->container->componentName . '&view=' . Inflector::pluralize($this->view) . $this->getItemidURLSuffix();
			$this->setRedirect($url);
			$model->unlock();

			return false;
		}

		$model->store();
		$url = !empty($customURL) ? $customURL : 'index.php?option=' . $this->container->componentName . '&view=' . Inflector::pluralize($this->view) . $this->getItemidURLSuffix();
		$this->setRedirect($url);

		$this->setMessage(\JText::sprintf('JLIB_APPLICATION_SUCCESS_LOAD_HISTORY', $model->getState('save_date'), $model->getState('version_note')));

		return true;
	}

	/**
	 * Gets a URL suffix with the Itemid parameter. If it's not the front-end of the site, or if
	 * there is no Itemid set it returns an empty string.
	 *
	 * @return  string  The &Itemid=123 URL suffix, or an empty string if Itemid is not applicable
	 */
	public function getItemidURLSuffix()
	{
		if ($this->container->platform->isFrontend() && ($this->input->getCmd('Itemid', 0) != 0))
		{
			return '&Itemid=' . $this->input->getInt('Itemid', 0);
		}
		else
		{
			return '';
		}
	}

	/**
	 * Gets the applicable ACL privilege for the apply and save tasks. The value returned is:
	 * - @add if the record's ID is empty / record doesn't exist
	 * - True if the ACL privilege of the edit task (@edit) is allowed
	 * - @editown if the owner of the record (field user_id, userid or user) is the same as the logged in user
	 * - False if the record is not owned by the logged in user and the user doesn't have the @edit privilege
	 *
	 * @return bool|string
	 * @throws \Exception
	 */
	protected function getACLForApplySave()
	{
		$model = $this->getModel();

		if (!$model->getId())
		{
			$this->getIDsFromRequest($model, true);
		}

		$id = $model->getId();

		if (!$id)
		{
			return '@add';
		}

		if ($this->checkACL('@edit'))
		{
			return true;
		}

		$user = $this->container->platform->getUser();
		$uid = 0;

		if ($model->hasField('user_id'))
		{
			$uid = $model->getFieldValue('user_id');
		}
		elseif ($model->hasField('userid'))
		{
			$uid = $model->getFieldValue('userid');
		}
		elseif ($model->hasField('user'))
		{
			$uid = $model->getFieldValue('user');
		}

		if (!empty($uid) && !$user->guest && ($user->id == $uid))
		{
			return '@editown';
		}

		return false;
	}
}