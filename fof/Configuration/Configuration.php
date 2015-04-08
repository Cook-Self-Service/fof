<?php
/**
 * @package     FOF
 * @copyright   2010-2015 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license     GNU GPL version 2 or later
 */

namespace FOF30\Configuration;

use FOF30\Container\Container;

defined('_JEXEC') or die;

/**
 * Reads and parses the fof.xml file in the back-end of a FOF-powered component,
 * provisioning the data to the rest of the FOF framework
 *
 * @since    2.1
 */
class Configuration
{
	/**
	 * The component's container
	 *
	 * @var  Container
	 */
	protected $container = null;

	/**
	 * Cache of FOF components' configuration variables
	 *
	 * @var array
	 */
	public static $configurations = array();

	function __construct(Container $c)
	{
		$this->container = $c;

		$this->parseComponent($c->componentName);
	}

	/**
	 * Returns the value of a variable. Variables use a dot notation, e.g.
	 * view.config.whatever where the first part is the domain, the rest of the
	 * parts specify the path to the variable.
	 *
	 * @param   string  $variable  The variable name
	 * @param   mixed   $default   The default value, or null if not specified
	 *
	 * @return  mixed  The value of the variable
	 */
	public function get($variable, $default = null)
	{
		static $domains = null;

		if (is_null($domains))
		{
			$domains = $this->getDomains();
		}

		list($component, $domain, $var) = explode('.', $variable, 3);

		if (!isset(self::$configurations[$component]))
		{
			$this->parseComponent($component);
		}

		if (!in_array($domain, $domains))
		{
			return $default;
		}

		$class = '\\FOF30\\Configuration\\Domain\\' . ucfirst($domain);
		/** @var   \FOF30\Configuration\Domain\DomainInterface  $o */
		$o = new $class;

		return $o->get(self::$configurations[$component], $var, $default);
	}

	/**
	 * Parses the configuration of the specified component
	 *
	 * @param   string   $component  The name of the component, e.g. com_foobar
	 * @param   boolean  $force      Force reload even if it's already parsed?
	 *
	 * @return  void
	 */
	protected function parseComponent($component, $force = false)
	{
		if (!$force && isset(self::$configurations[$component]))
		{
			return;
		}

		if ($this->container->platform->isCli())
		{
			$order = array('cli', 'backend');
		}
		elseif ($this->container->platform->isBackend())
		{
			$order = array('backend');
		}
		else
		{
			$order = array('frontend');
		}

		$order[] = 'common';

		$order = array_reverse($order);
		self::$configurations[$component] = array();

		foreach ($order as $area)
		{
			$config = $this->parseComponentArea($component, $area);
			self::$configurations[$component] = array_merge_recursive(self::$configurations[$component], $config);
		}
	}

	/**
	 * Parses the configuration options of a specific component area
	 *
	 * @param   string  $component  Which component's cionfiguration to parse
	 * @param   string  $area       Which area to parse (frontend, backend, cli)
	 *
	 * @return  array  A hash array with the configuration data
	 */
	protected function parseComponentArea($component, $area)
	{
		// Initialise the return array
		$ret = array();

		// Get the folders of the component
		$componentPaths = $this->container->platform->getComponentBaseDirs($component);
		$filesystem     = $this->container->filesystem;

		// Check that the path exists
		$path = $componentPaths['admin'];
		$path = $filesystem->pathCheck($path);

		if (!$filesystem->folderExists($path))
		{
			return $ret;
		}

		// Read the filename if it exists
		$filename = $path . '/fof.xml';

		if (!$filesystem->fileExists($filename))
		{
			return $ret;
		}

		$data = file_get_contents($filename);

		// Load the XML data in a SimpleXMLElement object
		$xml = simplexml_load_string($data);

		if (!($xml instanceof \SimpleXMLElement))
		{
			return $ret;
		}

		// Get this area's data
		$areaData = $xml->xpath('//' . $area);

		if (empty($areaData))
		{
			return $ret;
		}

		$xml = array_shift($areaData);

		// Parse individual configuration domains
		$domains = $this->getDomains();

		foreach ($domains as $dom)
		{
			$class = '\\FOF30\\Configuration\\Domain\\' . ucfirst($dom);

			if (class_exists($class, true))
			{
				/** @var   \FOF30\Configuration\Domain\DomainInterface  $o */
				$o = new $class;
				$o->parseDomain($xml, $ret);
			}
		}

		// Finally, return the result
		return $ret;
	}

	/**
	 * Gets a list of the available configuration domain adapters
	 *
	 * @return  array  A list of the available domains
	 */
	protected function getDomains()
	{
		static $domains = array();

		if (empty($domains))
		{
			$filesystem = $this->container->filesystem;

			$files = $filesystem->folderFiles(__DIR__ . '/domain', '.php');

			if (!empty($files))
			{
				foreach ($files as $file)
				{
					$domain = basename($file, '.php');

					if ($domain == 'DomainInterface')
					{
						continue;
					}

					$domain = preg_replace('/[^A-Za-z0-9]/', '', $domain);
					$domains[] = $domain;
				}

				$domains = array_unique($domains);
			}
		}

		return $domains;
	}

}