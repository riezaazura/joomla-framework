<?php
/**
 * @package     Joomla.Platform
 * @subpackage  Table
 *
 * @copyright   Copyright (C) 2005 - 2012 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Joomla\Table;

defined('JPATH_PLATFORM') or die;

use Joomla\Filesystem\Path;
use Joomla\Database\Driver;
use Joomla\Database\Query;
use Joomla\Language\Text;
use Joomla\Factory;
use Joomla\Log\Log;
use UnexpectedValueException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Abstract Table class
 *
 * Parent class to all tables.
 *
 * @package     Joomla.Platform
 * @subpackage  Table
 * @link        http://docs.joomla.org/JTable
 * @since       11.1
 * @tutorial    Joomla.Platform/jtable.cls
 */

abstract class Table
{
	/**
	 * Include paths for searching for JTable classes.
	 *
	 * @var    array
	 * @since  12.1
	 */
	private static $_includePaths = array();

	/**
	 * Name of the database table to model.
	 *
	 * @var    string
	 * @since  11.1
	 */
	protected $_tbl = '';

	/**
	 * Name of the primary key field in the table.
	 *
	 * @var    string
	 * @since  11.1
	 */
	protected $_tbl_key = '';

	/**
	 * Name of the primary key fields in the table.
	 *
	 * @var    array
	 * @since  12.2
	 */
	protected $_tbl_keys = array();

	/**
	 * Driver object.
	 *
	 * @var    Driver
	 * @since  11.1
	 */
	protected $_db;

	/**
	 * The rules associated with this record.
	 *
	 * @var    Rules  A Rules object.
	 * @since  11.1
	 */
	protected $_rules;

	/**
	 * Indicator that the tables have been locked.
	 *
	 * @var    boolean
	 * @since  11.1
	 */
	protected $_locked = false;

	/**
	 * Indicates that the primary keys autoincrement.
	 *
	 * @var    boolean
	 * @since  12.3
	 */
	protected $_autoincrement = true;

	/**
	 * An array of error messages or Exception objects.
	 *
	 * @var              array
	 * @since            11.1
	 */
	protected $_errors = array();

	/**
	 * Object constructor to set table and key fields.  In most cases this will
	 * be overridden by child classes to explicitly set the table and key fields
	 * for a particular database table.
	 *
	 * @param   string  $table  Name of the table to model.
	 * @param   mixed   $key    Name of the primary key field in the table or array of field names that compose the primary key.
	 * @param   Driver  $db     Driver object.
	 *
	 * @since   11.1
	 */
	public function __construct($table, $key, Driver $db)
	{
		// Set internal variables.
		$this->_tbl = $table;

		// Set the key to be an array.
		if (is_string($key))
		{
			$key = array($key);
		}
		elseif (is_object($key))
		{
			$key = (array) $key;
		}

		$this->_tbl_keys = $key;

		if (count($key) == 1)
		{
			$this->_autoincrement = true;
		}
		else
		{
			$this->_autoincrement = false;
		}

		// Set the singular table key for backwards compatibility.
		$this->_tbl_key = $this->getKeyName();

		$this->_db = $db;

		// Initialise the table properties.
		$fields = $this->getFields();

		if ($fields)
		{
			foreach ($fields as $name => $v)
			{
				// Add the field if it is not already present.
				if (!property_exists($this, $name))
				{
					$this->$name = null;
				}
			}
		}

		// If the access property exists, set the default.
		if (property_exists($this, 'access'))
		{
			$this->access = (int) Factory::getConfig()->get('access');
		}
	}

	/**
	 * Get the columns from database table.
	 *
	 * @return  mixed  An array of the field names, or false if an error occurs.
	 *
	 * @since   11.1
	 * @throws  UnexpectedValueException
	 */
	public function getFields()
	{
		static $cache = null;

		if ($cache === null)
		{
			// Lookup the fields for this table only once.
			$name   = $this->_tbl;
			$fields = $this->_db->getTableColumns($name, false);

			if (empty($fields))
			{
				throw new UnexpectedValueException(sprintf('No columns found for %s table', $name));
			}
			$cache = $fields;
		}

		return $cache;
	}

	/**
	 * Static method to get an instance of a JTable class if it can be found in
	 * the table include paths.  To add include paths for searching for JTable
	 * classes @see JTable::addIncludePath().
	 *
	 * @param   string  $type    The type (name) of the JTable class to get an instance of.
	 * @param   string  $prefix  An optional prefix for the table class name.
	 * @param   array   $config  An optional array of configuration values for the JTable object.
	 *
	 * @return  mixed    A JTable object if found or boolean false if one could not be found.
	 *
	 * @link    http://docs.joomla.org/JTable/getInstance
	 * @since   11.1
	 */
	public static function getInstance($type, $prefix = 'JTable', $config = array())
	{
		// Sanitize and prepare the table class name.
		$type       = preg_replace('/[^A-Z0-9_\.-]/i', '', $type);
		$tableClass = $prefix . ucfirst($type);

		// Only try to load the class if it doesn't already exist.
		if (!class_exists($tableClass))
		{
			// Search for the class file in the JTable include paths.
			$path = Path::find(self::addIncludePath(), strtolower($type) . '.php');

			if ($path)
			{
				// Import the class file.
				include_once $path;

				// If we were unable to load the proper class, raise a warning and return false.
				if (!class_exists($tableClass))
				{
					Log::add(Text::sprintf('JLIB_DATABASE_ERROR_CLASS_NOT_FOUND_IN_FILE', $tableClass), Log::WARNING, 'jerror');

					return false;
				}
			}
			else
			{
				// If we were unable to find the class file in the JTable include paths, raise a warning and return false.
				Log::add(Text::sprintf('JLIB_DATABASE_ERROR_NOT_SUPPORTED_FILE_NOT_FOUND', $type), Log::WARNING, 'jerror');

				return false;
			}
		}

		// If a database object was passed in the configuration array use it, otherwise get the global one from JFactory.
		$db = isset($config['dbo']) ? $config['dbo'] : Factory::getDbo();

		// Instantiate a new table class and return it.
		return new $tableClass($db);
	}

	/**
	 * Add a filesystem path where JTable should search for table class files.
	 * You may either pass a string or an array of paths.
	 *
	 * @param   mixed  $path  A filesystem path or array of filesystem paths to add.
	 *
	 * @return  array  An array of filesystem paths to find JTable classes in.
	 *
	 * @link    http://docs.joomla.org/JTable/addIncludePath
	 * @since   11.1
	 */
	public static function addIncludePath($path = null)
	{
		// If the internal paths have not been initialised, do so with the base table path.
		if (empty(self::$_includePaths))
		{
			self::$_includePaths = array(__DIR__);
		}

		// Convert the passed path(s) to add to an array.
		settype($path, 'array');

		// If we have new paths to add, do so.
		if (!empty($path))
		{
			// Check and add each individual new path.
			foreach ($path as $dir)
			{
				// Sanitize path.
				$dir = trim($dir);

				// Add to the front of the list so that custom paths are searched first.
				if (!in_array($dir, self::$_includePaths))
				{
					array_unshift(self::$_includePaths, $dir);
				}
			}
		}

		return self::$_includePaths;
	}

	/**
	 * Method to append the primary keys for this table to a query.
	 *
	 * @param   Query  $query  A query object to append.
	 * @param   mixed  $pk     Optional primary key parameter.
	 *
	 * @return  void
	 *
	 * @since   12.3
	 */
	public function appendPrimaryKeys($query, $pk = null)
	{
		if (is_null($pk))
		{
			foreach ($this->_tbl_keys as $k)
			{
				$query->where($this->_db->quoteName($k) . ' = ' . $this->_db->quote($this->$k));
			}
		}
		else
		{
			if (is_string($pk))
			{
				$pk = array($this->_tbl_key => $pk);
			}

			$pk = (object) $pk;

			foreach ($this->_tbl_keys AS $k)
			{
				$query->where($this->_db->quoteName($k) . ' = ' . $this->_db->quote($pk->$k));
			}
		}
	}

	/**
	 * Method to get the database table name for the class.
	 *
	 * @return  string  The name of the database table being modeled.
	 *
	 * @since   11.1
	 *
	 * @link    http://docs.joomla.org/JTable/getTableName
	 */
	public function getTableName()
	{
		return $this->_tbl;
	}

	/**
	 * Method to get the primary key field name for the table.
	 *
	 * @param   boolean  $multiple  True to return all primary keys (as an array) or false to return just the first one (as a string).
	 *
	 * @return  mixed  Array of primary key field names or string containing the first primary key field.
	 *
	 * @link    http://docs.joomla.org/JTable/getKeyName
	 * @since   11.1
	 */
	public function getKeyName($multiple = false)
	{
		// Count the number of keys
		if (count($this->_tbl_keys))
		{
			if ($multiple)
			{
				// If we want multiple keys, return the raw array.
				return $this->_tbl_keys;
			}
			else
			{
				// If we want the standard method, just return the first key.
				return $this->_tbl_keys[0];
			}
		}

		return '';
	}

	/**
	 * Method to get the Driver object.
	 *
	 * @return  Driver  The internal database driver object.
	 *
	 * @link    http://docs.joomla.org/JTable/getDBO
	 * @since   11.1
	 */
	public function getDbo()
	{
		return $this->_db;
	}

	/**
	 * Method to set the Driver object.
	 *
	 * @param   Driver  $db  A Driver object to be used by the table object.
	 *
	 * @return  boolean  True on success.
	 *
	 * @link    http://docs.joomla.org/JTable/setDBO
	 * @since   11.1
	 */
	public function setDBO(Driver $db)
	{
		$this->_db = $db;

		return true;
	}

	/**
	 * Method to set rules for the record.
	 *
	 * @param   mixed  $input  A Rules object, JSON string, or array.
	 *
	 * @return  void
	 *
	 * @since   11.1
	 */
	public function setRules($input)
	{
		if ($input instanceof Rules)
		{
			$this->_rules = $input;
		}
		else
		{
			$this->_rules = new Rules($input);
		}
	}

	/**
	 * Method to get the rules for the record.
	 *
	 * @return  Rules object
	 *
	 * @since   11.1
	 */
	public function getRules()
	{
		return $this->_rules;
	}

	/**
	 * Method to reset class properties to the defaults set in the class
	 * definition. It will ignore the primary key as well as any private class
	 * properties.
	 *
	 * @return  void
	 *
	 * @link    http://docs.joomla.org/JTable/reset
	 * @since   11.1
	 */
	public function reset()
	{
		// Get the default values for the class from the table.
		foreach ($this->getFields() as $k => $v)
		{
			// If the property is not the primary key or private, reset it.
			if (!in_array($k, $this->_tbl_keys) && (strpos($k, '_') !== 0))
			{
				$this->$k = $v->Default;
			}
		}
	}

	/**
	 * Method to bind an associative array or object to the JTable instance.This
	 * method only binds properties that are publicly accessible and optionally
	 * takes an array of properties to ignore when binding.
	 *
	 * @param   mixed  $src     An associative array or object to bind to the JTable instance.
	 * @param   mixed  $ignore  An optional array or space separated list of properties to ignore while binding.
	 *
	 * @return  boolean  True on success.
	 *
	 * @link    http://docs.joomla.org/JTable/bind
	 * @since   11.1
	 * @throws  UnexpectedValueException
	 */
	public function bind($src, $ignore = array())
	{
		// If the source value is not an array or object return false.
		if (!is_object($src) && !is_array($src))
		{
			throw new InvalidArgumentException(sprintf('%s::bind(*%s*)', get_class($this), gettype($src)));
		}

		// If the source value is an object, get its accessible properties.
		if (is_object($src))
		{
			$src = get_object_vars($src);
		}

		// If the ignore value is a string, explode it over spaces.
		if (!is_array($ignore))
		{
			$ignore = explode(' ', $ignore);
		}

		// Bind the source value, excluding the ignored fields.
		foreach ($this->getProperties() as $k => $v)
		{
			// Only process fields not in the ignore array.
			if (!in_array($k, $ignore))
			{
				if (isset($src[$k]))
				{
					$this->$k = $src[$k];
				}
			}
		}

		return true;
	}

	/**
	 * Method to load a row from the database by primary key and bind the fields
	 * to the JTable instance properties.
	 *
	 * @param   mixed    $keys   An optional primary key value to load the row by, or an array of fields to match.  If not
	 *                           set the instance property value is used.
	 * @param   boolean  $reset  True to reset the default values before loading the new row.
	 *
	 * @return  boolean  True if successful. False if row not found.
	 *
	 * @link    http://docs.joomla.org/JTable/load
	 * @since   11.1
	 * @throws  RuntimeException
	 * @throws  UnexpectedValueException
	 */
	public function load($keys = null, $reset = true)
	{
		if (empty($keys))
		{
			$empty = true;
			$keys  = array();

			// If empty, use the value of the current key
			foreach ($this->_tbl_keys as $key)
			{
				$empty      = $empty && empty($this->$key);
				$keys[$key] = $this->$key;
			}

			// If empty primary key there's is no need to load anything
			if ($empty)
			{
				return true;
			}
		}
		elseif (!is_array($keys))
		{
			// Load by primary key.
			$keyCount = count($this->_tbl_keys);

			if ($keyCount)
			{
				if ($keyCount > 1)
				{
					throw new InvalidArgumentException('Table has multiple primary keys specified, only one primary key value provided.');
				}
				$keys = array($this->getKeyName() => $keys);
			}
			else
			{
				throw new RuntimeException('No table keys defined.');
			}
		}

		if ($reset)
		{
			$this->reset();
		}

		// Initialise the query.
		$query = $this->_db->getQuery(true);
		$query->select('*');
		$query->from($this->_tbl);
		$fields = array_keys($this->getProperties());

		foreach ($keys as $field => $value)
		{
			// Check that $field is in the table.
			if (!in_array($field, $fields))
			{
				throw new UnexpectedValueException(sprintf('Missing field in database: %s &#160; %s.', get_class($this), $field));
			}
			// Add the search tuple to the query.
			$query->where($this->_db->quoteName($field) . ' = ' . $this->_db->quote($value));
		}

		$this->_db->setQuery($query);

		$row = $this->_db->loadAssoc();

		// Check that we have a result.
		if (empty($row))
		{
			return false;
		}

		// Bind the object with the row and return.
		return $this->bind($row);
	}

	/**
	 * Method to perform sanity checks on the JTable instance properties to ensure
	 * they are safe to store in the database.  Child classes should override this
	 * method to make sure the data they are storing in the database is safe and
	 * as expected before storage.
	 *
	 * @return  boolean  True if the instance is sane and able to be stored in the database.
	 *
	 * @link    http://docs.joomla.org/JTable/check
	 * @since   11.1
	 */
	public function check()
	{
		return true;
	}

	/**
	 * Method to store a row in the database from the JTable instance properties.
	 * If a primary key value is set the row with that primary key value will be
	 * updated with the instance property values.  If no primary key value is set
	 * a new row will be inserted into the database with the properties from the
	 * JTable instance.
	 *
	 * @param   boolean  $updateNulls  True to update fields even if they are null.
	 *
	 * @return  boolean  True on success.
	 *
	 * @link    http://docs.joomla.org/JTable/store
	 * @since   11.1
	 */
	public function store($updateNulls = false)
	{
		$k = $this->_tbl_keys;

		// If a primary key exists update the object, otherwise insert it.
		if ($this->hasPrimaryKey())
		{
			$this->_db->updateObject($this->_tbl, $this, $this->_tbl_keys, $updateNulls);
		}
		else
		{
			$this->_db->insertObject($this->_tbl, $this, $this->_tbl_keys);
		}

		if ($this->_locked)
		{
			$this->_unlock();
		}

		return true;
	}

	/**
	 * Method to provide a shortcut to binding, checking and storing a JTable
	 * instance to the database table.  The method will check a row in once the
	 * data has been stored and if an ordering filter is present will attempt to
	 * reorder the table rows based on the filter.  The ordering filter is an instance
	 * property name.  The rows that will be reordered are those whose value matches
	 * the JTable instance for the property specified.
	 *
	 * @param   mixed   $src             An associative array or object to bind to the JTable instance.
	 * @param   string  $orderingFilter  Filter for the order updating
	 * @param   mixed   $ignore          An optional array or space separated list of properties
	 *                                   to ignore while binding.
	 *
	 * @return  boolean  True on success.
	 *
	 * @link    http://docs.joomla.org/JTable/save
	 * @since   11.1
	 */
	public function save($src, $orderingFilter = '', $ignore = '')
	{
		// Attempt to bind the source to the instance.
		if (!$this->bind($src, $ignore))
		{
			return false;
		}

		// Run any sanity checks on the instance and verify that it is ready for storage.
		if (!$this->check())
		{
			return false;
		}

		// Attempt to store the properties to the database table.
		if (!$this->store())
		{
			return false;
		}

		// Attempt to check the row in, just in case it was checked out.
		if (!$this->checkin())
		{
			return false;
		}

		// If an ordering filter is set, attempt reorder the rows in the table based on the filter and value.
		if ($orderingFilter)
		{
			$filterValue = $this->$orderingFilter;
			$this->reorder($orderingFilter ? $this->_db->quoteName($orderingFilter) . ' = ' . $this->_db->Quote($filterValue) : '');
		}

		// Set the error to empty and return true.
		$this->setError('');

		return true;
	}

	/**
	 * Method to delete a row from the database table by primary key value.
	 *
	 * @param   mixed  $pk  An optional primary key value to delete.  If not set the instance property value is used.
	 *
	 * @return  boolean  True on success.
	 *
	 * @link    http://docs.joomla.org/JTable/delete
	 * @since   11.1
	 * @throws  UnexpectedValueException
	 */
	public function delete($pk = null)
	{
		if (is_null($pk))
		{
			$pk = array();

			foreach ($this->_tbl_keys AS $key)
			{
				$pk[$key] = $this->$key;
			}
		}
		elseif (!is_array($pk))
		{
			$pk = array($this->_tbl_key => $pk);
		}

		foreach ($this->_tbl_keys AS $key)
		{
			$pk[$key] = is_null($pk[$key]) ? $this->$key : $pk[$key];

			if ($pk[$key] === null)
			{
				throw new UnexpectedValueException('Null primary key not allowed.');
			}
			$this->$key = $pk[$key];
		}

		// Delete the row by primary key.
		$query = $this->_db->getQuery(true);
		$query->delete();
		$query->from($this->_tbl);
		$this->appendPrimaryKeys($query, $pk);

		$this->_db->setQuery($query);

		// Check for a database error.
		$this->_db->execute();

		return true;
	}

	/**
	 * Method to check a row out if the necessary properties/fields exist.  To
	 * prevent race conditions while editing rows in a database, a row can be
	 * checked out if the fields 'checked_out' and 'checked_out_time' are available.
	 * While a row is checked out, any attempt to store the row by a user other
	 * than the one who checked the row out should be held until the row is checked
	 * in again.
	 *
	 * @param   integer  $userId  The Id of the user checking out the row.
	 * @param   mixed    $pk      An optional primary key value to check out.  If not set
	 *                            the instance property value is used.
	 *
	 * @return  boolean  True on success.
	 *
	 * @link    http://docs.joomla.org/JTable/checkOut
	 * @since   11.1
	 */
	public function checkOut($userId, $pk = null)
	{
		// If there is no checked_out or checked_out_time field, just return true.
		if (!property_exists($this, 'checked_out') || !property_exists($this, 'checked_out_time'))
		{
			return true;
		}

		if (is_null($pk))
		{
			$pk = array();

			foreach ($this->_tbl_keys AS $key)
			{
				$pk[$key] = $this->$key;
			}
		}
		elseif (!is_array($pk))
		{
			$pk = array($this->_tbl_key => $pk);
		}

		foreach ($this->_tbl_keys AS $key)
		{
			$pk[$key] = is_null($pk[$key]) ? $this->$key : $pk[$key];

			if ($pk[$key] === null)
			{
				throw new UnexpectedValueException('Null primary key not allowed.');
			}
		}

		// Get the current time in MySQL format.
		$time = Factory::getDate()->toSql();

		// Check the row out by primary key.
		$query = $this->_db->getQuery(true);
		$query->update($this->_tbl);
		$query->set($this->_db->quoteName('checked_out') . ' = ' . (int) $userId);
		$query->set($this->_db->quoteName('checked_out_time') . ' = ' . $this->_db->quote($time));
		$this->appendPrimaryKeys($query, $pk);
		$this->_db->setQuery($query);
		$this->_db->execute();

		// Set table values in the object.
		$this->checked_out      = (int) $userId;
		$this->checked_out_time = $time;

		return true;
	}

	/**
	 * Method to check a row in if the necessary properties/fields exist.  Checking
	 * a row in will allow other users the ability to edit the row.
	 *
	 * @param   mixed  $pk  An optional primary key value to check out.  If not set the instance property value is used.
	 *
	 * @return  boolean  True on success.
	 *
	 * @link    http://docs.joomla.org/JTable/checkIn
	 * @since   11.1
	 */
	public function checkIn($pk = null)
	{
		// If there is no checked_out or checked_out_time field, just return true.
		if (!property_exists($this, 'checked_out') || !property_exists($this, 'checked_out_time'))
		{
			return true;
		}

		if (is_null($pk))
		{
			$pk = array();

			foreach ($this->_tbl_keys AS $key)
			{
				$pk[$this->$key] = $this->$key;
			}
		}
		elseif (!is_array($pk))
		{
			$pk = array($this->_tbl_key => $pk);
		}

		foreach ($this->_tbl_keys AS $key)
		{
			$pk[$key] = empty($pk[$key]) ? $this->$key : $pk[$key];

			if ($pk[$key] === null)
			{
				throw new UnexpectedValueException('Null primary key not allowed.');
			}
		}

		// Check the row in by primary key.
		$query = $this->_db->getQuery(true);
		$query->update($this->_tbl);
		$query->set($this->_db->quoteName('checked_out') . ' = 0');
		$query->set($this->_db->quoteName('checked_out_time') . ' = ' . $this->_db->quote($this->_db->getNullDate()));
		$this->appendPrimaryKeys($query, $pk);
		$this->_db->setQuery($query);

		// Check for a database error.
		$this->_db->execute();

		// Set table values in the object.
		$this->checked_out      = 0;
		$this->checked_out_time = '';

		return true;
	}

	/**
	 * Validate that the primary key has been set.
	 *
	 * @return  boolean  True if the primary key(s) have been set.
	 *
	 * @since   12.3
	 */
	public function hasPrimaryKey()
	{
		if ($this->_autoincrement)
		{
			$empty = true;

			foreach ($this->_tbl_keys as $key)
			{
				$empty = $empty && empty($this->$key);
			}
		}
		else
		{
			$query = $this->_db->getQuery(true);
			$query->select('COUNT(*)');
			$query->from($this->_tbl);
			$this->appendPrimaryKeys($query);

			$this->_db->setQuery($query);
			$count = $this->_db->loadResult();

			if ($count == 1)
			{
				$empty = false;
			}
			else
			{
				$empty = true;
			}
		}

		return !$empty;
	}

	/**
	 * Method to increment the hits for a row if the necessary property/field exists.
	 *
	 * @param   mixed  $pk  An optional primary key value to increment. If not set the instance property value is used.
	 *
	 * @return  boolean  True on success.
	 *
	 * @link    http://docs.joomla.org/JTable/hit
	 * @since   11.1
	 */
	public function hit($pk = null)
	{
		// If there is no hits field, just return true.
		if (!property_exists($this, 'hits'))
		{
			return true;
		}

		if (is_null($pk))
		{
			$pk = array();

			foreach ($this->_tbl_keys AS $key)
			{
				$pk[$key] = $this->$key;
			}
		}
		elseif (!is_array($pk))
		{
			$pk = array($this->_tbl_key => $pk);
		}

		foreach ($this->_tbl_keys AS $key)
		{
			$pk[$key] = is_null($pk[$key]) ? $this->$key : $pk[$key];

			if ($pk[$key] === null)
			{
				throw new UnexpectedValueException('Null primary key not allowed.');
			}
		}

		// Check the row in by primary key.
		$query = $this->_db->getQuery(true);
		$query->update($this->_tbl);
		$query->set($this->_db->quoteName('hits') . ' = (' . $this->_db->quoteName('hits') . ' + 1)');
		$this->appendPrimaryKeys($query, $pk);
		$this->_db->setQuery($query);
		$this->_db->execute();

		// Set table values in the object.
		$this->hits++;

		return true;
	}

	/**
	 * Method to determine if a row is checked out and therefore uneditable by
	 * a user. If the row is checked out by the same user, then it is considered
	 * not checked out -- as the user can still edit it.
	 *
	 * @param   integer  $with     The userid to preform the match with, if an item is checked
	 *                             out by this user the function will return false.
	 * @param   integer  $against  The userid to perform the match against when the function
	 *                             is used as a static function.
	 *
	 * @return  boolean  True if checked out.
	 *
	 * @link    http://docs.joomla.org/JTable/isCheckedOut
	 * @since   11.1
	 */
	public function isCheckedOut($with = 0, $against = null)
	{
		// Handle the non-static case.
		if (isset($this) && ($this instanceof Table) && is_null($against))
		{
			$against = $this->get('checked_out');
		}

		// The item is not checked out or is checked out by the same user.
		if (!$against || ($against == $with))
		{
			return false;
		}

		$db = Factory::getDBO();
		$db->setQuery('SELECT COUNT(userid)' . ' FROM ' . $db->quoteName('#__session') . ' WHERE ' . $db->quoteName('userid') . ' = ' . (int) $against);
		$checkedOut = (boolean) $db->loadResult();

		// If a session exists for the user then it is checked out.
		return $checkedOut;
	}

	/**
	 * Method to get the next ordering value for a group of rows defined by an SQL WHERE clause.
	 * This is useful for placing a new item last in a group of items in the table.
	 *
	 * @param   string  $where  WHERE clause to use for selecting the MAX(ordering) for the table.
	 *
	 * @return  mixed  Boolean false an failure or the next ordering value as an integer.
	 *
	 * @link    http://docs.joomla.org/JTable/getNextOrder
	 * @since   11.1
	 */
	public function getNextOrder($where = '')
	{
		// If there is no ordering field set an error and return false.
		if (!property_exists($this, 'ordering'))
		{
			throw new UnexpectedValueException(sprintf('%s does not support ordering.', get_class($this)));
		}

		// Get the largest ordering value for a given where clause.
		$query = $this->_db->getQuery(true);
		$query->select('MAX(ordering)');
		$query->from($this->_tbl);

		if ($where)
		{
			$query->where($where);
		}

		$this->_db->setQuery($query);
		$max = (int) $this->_db->loadResult();

		// Return the largest ordering value + 1.
		return ($max + 1);
	}

	/**
	 * Get the primary key values for this table using passed in values as a default.
	 *
	 * @param   array  $keys  Optional primary key values to use.
	 *
	 * @return  array  An array of primary key names and values.
	 *
	 * @since   12.3
	 */
	public function getPrimaryKey(array $keys = array())
	{
		foreach ($this->_tbl_keys as $key)
		{
			if (!isset($keys[$key]))
			{
				if (!empty($this->$key))
				{
					$keys[$key] = $this->$key;
				}
			}
		}

		return $keys;
	}

	/**
	 * Method to compact the ordering values of rows in a group of rows
	 * defined by an SQL WHERE clause.
	 *
	 * @param   string  $where  WHERE clause to use for limiting the selection of rows to compact the ordering values.
	 *
	 * @return  mixed  Boolean  True on success.
	 *
	 * @link    http://docs.joomla.org/JTable/reorder
	 * @since   11.1
	 */
	public function reorder($where = '')
	{
		// If there is no ordering field set an error and return false.
		if (!property_exists($this, 'ordering'))
		{
			throw new UnexpectedValueException(sprintf('%s does not support ordering.', get_class($this)));
		}

		$k = $this->_tbl_key;

		// Get the primary keys and ordering values for the selection.
		$query = $this->_db->getQuery(true);
		$query->select(implode(',', $this->_tbl_keys) . ', ordering');
		$query->from($this->_tbl);
		$query->where('ordering >= 0');
		$query->order('ordering');

		// Setup the extra where and ordering clause data.
		if ($where)
		{
			$query->where($where);
		}

		$this->_db->setQuery($query);
		$rows = $this->_db->loadObjectList();

		// Compact the ordering values.
		foreach ($rows as $i => $row)
		{
			// Make sure the ordering is a positive integer.
			if ($row->ordering >= 0)
			{
				// Only update rows that are necessary.
				if ($row->ordering != $i + 1)
				{
					// Update the row ordering field.
					$query = $this->_db->getQuery(true);
					$query->update($this->_tbl);
					$query->set('ordering = ' . ($i + 1));
					$this->appendPrimaryKeys($query, $row);
					$this->_db->setQuery($query);
					$this->_db->execute();
				}
			}
		}

		return true;
	}

	/**
	 * Method to move a row in the ordering sequence of a group of rows defined by an SQL WHERE clause.
	 * Negative numbers move the row up in the sequence and positive numbers move it down.
	 *
	 * @param   integer  $delta  The direction and magnitude to move the row in the ordering sequence.
	 * @param   string   $where  WHERE clause to use for limiting the selection of rows to compact the
	 *                           ordering values.
	 *
	 * @return  mixed    Boolean  True on success.
	 *
	 * @link    http://docs.joomla.org/JTable/move
	 * @since   11.1
	 * @throws  UnexpectedValueException
	 */
	public function move($delta, $where = '')
	{
		// If there is no ordering field set an error and return false.
		if (!property_exists($this, 'ordering'))
		{
			throw new UnexpectedValueException(sprintf('%s does not support ordering.', get_class($this)));
		}

		// If the change is none, do nothing.
		if (empty($delta))
		{
			return true;
		}

		$k     = $this->_tbl_key;
		$row   = null;
		$query = $this->_db->getQuery(true);

		// Select the primary key and ordering values from the table.
		$query->select(implode(',', $this->_tbl_keys) . ', ordering');
		$query->from($this->_tbl);

		// If the movement delta is negative move the row up.
		if ($delta < 0)
		{
			$query->where('ordering < ' . (int) $this->ordering);
			$query->order('ordering DESC');
		}
		// If the movement delta is positive move the row down.
		elseif ($delta > 0)
		{
			$query->where('ordering > ' . (int) $this->ordering);
			$query->order('ordering ASC');
		}

		// Add the custom WHERE clause if set.
		if ($where)
		{
			$query->where($where);
		}

		// Select the first row with the criteria.
		$this->_db->setQuery($query, 0, 1);
		$row = $this->_db->loadObject();

		// If a row is found, move the item.
		if (!empty($row))
		{
			// Update the ordering field for this instance to the row's ordering value.
			$query = $this->_db->getQuery(true);
			$query->update($this->_tbl);
			$query->set('ordering = ' . (int) $row->ordering);
			$this->appendPrimaryKeys($query);
			$this->_db->setQuery($query);
			$this->_db->execute();

			// Update the ordering field for the row to this instance's ordering value.
			$query = $this->_db->getQuery(true);
			$query->update($this->_tbl);
			$query->set('ordering = ' . (int) $this->ordering);
			$this->appendPrimaryKeys($query, $row);
			$this->_db->setQuery($query);
			$this->_db->execute();

			// Update the instance value.
			$this->ordering = $row->ordering;
		}
		else
		{
			// Update the ordering field for this instance.
			$query = $this->_db->getQuery(true);
			$query->update($this->_tbl);
			$query->set('ordering = ' . (int) $this->ordering);
			$this->appendPrimaryKeys($query);
			$this->_db->setQuery($query);
			$this->_db->execute();
		}

		return true;
	}

	/**
	 * Method to set the publishing state for a row or list of rows in the database
	 * table.  The method respects checked out rows by other users and will attempt
	 * to checkin rows that it can after adjustments are made.
	 *
	 * @param   mixed    $pks     An optional array of primary key values to update.
	 *                            If not set the instance property value is used.
	 * @param   integer  $state   The publishing state. eg. [0 = unpublished, 1 = published]
	 * @param   integer  $userId  The user id of the user performing the operation.
	 *
	 * @return  boolean  True on success; false if $pks is empty.
	 *
	 * @link    http://docs.joomla.org/JTable/publish
	 * @since   11.1
	 */
	public function publish($pks = null, $state = 1, $userId = 0)
	{
		$k = $this->_tbl_keys;

		if (!is_null($pks))
		{
			foreach ($pks AS $key => $pk)
			{
				if (!is_array($pk))
				{
					$pks[$key] = array($this->_tbl_key => $pk);
				}
			}
		}

		$userId = (int) $userId;
		$state  = (int) $state;

		// If there are no primary keys set check to see if the instance key is set.
		if (empty($pks))
		{
			$pk = array();

			foreach ($this->_tbl_keys AS $key)
			{
				if ($this->$key)
				{
					$pk[$this->$key] = $this->$key;
				}
				// We don't have a full primary key - return false
				else
				{
					return false;
				}
			}

			$pks = array($pk);
		}

		foreach ($pks AS $pk)
		{
			// Update the publishing state for rows with the given primary keys.
			$query = $this->_db->getQuery(true);
			$query->update($this->_tbl);
			$query->set('published = ' . (int) $state);

			// Determine if there is checkin support for the table.
			if (property_exists($this, 'checked_out') || property_exists($this, 'checked_out_time'))
			{
				$query->where('(checked_out = 0 OR checked_out = ' . (int) $userId . ')');
				$checkin = true;
			}
			else
			{
				$checkin = false;
			}

			// Build the WHERE clause for the primary keys.
			$this->appendPrimaryKeys($query, $pk);

			$this->_db->setQuery($query);
			$this->_db->execute();

			// If checkin is supported and all rows were adjusted, check them in.
			if ($checkin && (count($pks) == $this->_db->getAffectedRows()))
			{
				$this->checkin($pk);
			}

			$ours = true;

			foreach ($this->_tbl_keys AS $key)
			{
				if ($this->$key != $pk[$key])
				{
					$ours = false;
				}
			}

			if ($ours)
			{
				$this->published = $state;
			}
		}

		$this->setError('');

		return true;
	}

	/**
	 * Method to lock the database table for writing.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   11.1
	 * @throws  RuntimeException
	 */
	protected function _lock()
	{
		$this->_db->lockTable($this->_tbl);
		$this->_locked = true;

		return true;
	}

	/**
	 * Method to unlock the database table for writing.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   11.1
	 */
	protected function _unlock()
	{
		$this->_db->unlockTables();
		$this->_locked = false;

		return true;
	}

	// Method's copied from JObject

	/**
	 * Returns a property of the object or the default value if the property is not set.
	 *
	 * @param   string  $property  The name of the property.
	 * @param   mixed   $default   The default value.
	 *
	 * @return  mixed    The value of the property.
	 *
	 * @since   11.1
	 *
	 * @see     getProperties()
	 */
	public function get($property, $default = null)
	{
		if (isset($this->$property))
		{
			return $this->$property;
		}
		return $default;
	}

	/**
	 * Returns an associative array of object properties.
	 *
	 * @param   boolean  $public  If true, returns only the public properties.
	 *
	 * @return  array
	 *
	 * @since   11.1
	 *
	 * @see     get()
	 */
	public function getProperties($public = true)
	{
		$vars = get_object_vars($this);

		if ($public)
		{
			foreach ($vars as $key => $value)
			{
				if ('_' == substr($key, 0, 1))
				{
					unset($vars[$key]);
				}
			}
		}

		return $vars;
	}

	/**
	 * Get the most recent error message.
	 *
	 * @param   integer  $i         Option error index.
	 * @param   boolean  $toString  Indicates if JError objects should return their error message.
	 *
	 * @return  string   Error message
	 *
	 * @since       11.1
	 * @see         JError
	 * @deprecated  13.3
	 */
	public function getError($i = null, $toString = true)
	{
		// Find the error
		if ($i === null)
		{
			// Default, return the last message
			$error = end($this->_errors);
		}
		elseif (!array_key_exists($i, $this->_errors))
		{
			// If $i has been specified but does not exist, return false
			return false;
		}
		else
		{
			$error = $this->_errors[$i];
		}

		// Check if only the string is requested
		if ($error instanceof Exception && $toString)
		{
			return (string) $error;
		}

		return $error;
	}

	/**
	 * Add an error message.
	 *
	 * @param   string  $error  Error message.
	 *
	 * @return  void
	 *
	 * @since       11.1
	 */
	public function setError($error)
	{
		array_push($this->_errors, $error);
	}
}
