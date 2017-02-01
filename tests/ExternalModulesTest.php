<?php
namespace ExternalModules;
require_once 'BaseTest.php';

use \Exception;

class ExternalModulesTest extends BaseTest
{
	function testInitializeSettingDefaults()
	{
		$defaultValue = rand();

		$m = $this->getInstance([
			'global-settings' => [
				[
					'key' => TEST_SETTING_KEY,
					'default' => $defaultValue
				]
			]
		]);

		$this->assertNull($this->getGlobalSetting());
		ExternalModules::initializeSettingDefaults($m);
		$this->assertEquals($defaultValue, $this->getGlobalSetting());

		// Make sure defaults do NOT overwrite any existing settings.
		$this->setGlobalSetting(rand());
		ExternalModules::initializeSettingDefaults($m);
		$this->assertNotEquals($defaultValue, $this->getGlobalSetting());
	}

	function testGetProjectSettingsAsArray_globalOnly()
	{
		$value = rand();
		$this->setGlobalSetting($value);
		$array = ExternalModules::getProjectSettingsAsArray($this->getInstance()->PREFIX, TEST_SETTING_PID);
		$this->assertEquals($value, $array[TEST_SETTING_KEY]['value']);
		$this->assertEquals($value, $array[TEST_SETTING_KEY]['global_value']);
	}

	function testGetProjectSettingsAsArray_projectOnly()
	{
		$value = rand();
		$this->setProjectSetting($value);
		$array = ExternalModules::getProjectSettingsAsArray($this->getInstance()->PREFIX, TEST_SETTING_PID);
		$this->assertEquals($value, $array[TEST_SETTING_KEY]['value']);
		$this->assertEquals(null, $array[TEST_SETTING_KEY]['global_value']);
	}

	function testGetProjectSettingsAsArray_both()
	{
		$globalValue = rand();
		$projectValue = rand();

		$this->setGlobalSetting($globalValue);
		$this->setProjectSetting($projectValue);
		$array = ExternalModules::getProjectSettingsAsArray($this->getInstance()->PREFIX, TEST_SETTING_PID);
		$this->assertEquals($projectValue, $array[TEST_SETTING_KEY]['value']);
		$this->assertEquals($globalValue, $array[TEST_SETTING_KEY]['global_value']);

		// Re-test reversing the insert order to make sure it doesn't matter.
		$this->setProjectSetting($projectValue);
		$this->setGlobalSetting($globalValue);
		$array = ExternalModules::getProjectSettingsAsArray($this->getInstance()->PREFIX, TEST_SETTING_PID);
		$this->assertEquals($projectValue, $array[TEST_SETTING_KEY]['value']);
		$this->assertEquals($globalValue, $array[TEST_SETTING_KEY]['global_value']);
	}

	function testAddReservedSettings()
	{
		$method = 'addReservedSettings';

		$this->assertThrowsException(function() use ($method){
			self::callPrivateMethod($method, array(
				'global-settings' => array(
					array('key' => ExternalModules::KEY_VERSION)
				)
			));
		}, 'reserved for internal use');

		$this->assertThrowsException(function() use ($method){
			self::callPrivateMethod($method, array(
				'project-settings' => array(
					array('key' => ExternalModules::KEY_ENABLED)
				)
			));
		}, 'reserved for internal use');

		// Make sure other settings are passed through without exception.
		$key = 'some-non-reserved-settings';
		$config = self::callPrivateMethod($method, array(
			'global-settings' => array(
				array('key' => $key)
			)
		));

		$globalSettings = $config['global-settings'];
		$this->assertEquals(2, count($globalSettings));
		$this->assertEquals(ExternalModules::KEY_ENABLED, $globalSettings[0]['key']);
		$this->assertEquals($key, $globalSettings[1]['key']);
	}

	function testCacheAllEnableData()
	{
		$m = $this->getInstance();

		$version = rand();
		$m->setGlobalSetting(ExternalModules::KEY_VERSION, $version);

		self::callPrivateMethod('cacheAllEnableData');
		$this->assertEquals($version, self::callPrivateMethod('getGloballyEnabledVersions')[TEST_MODULE_PREFIX]);

		$m->removeGlobalSetting(ExternalModules::KEY_VERSION);

		// the other values set by cacheAllEnableData() are tested via testgetEnabledModuleVersionsForProject()
	}

	function testgetEnabledModuleVersionsForProject_multiplePrefixesAndVersions()
	{
		$prefix1 = TEST_MODULE_PREFIX . '-1';
		$prefix2 = TEST_MODULE_PREFIX . '-2';

		ExternalModules::setGlobalSetting($prefix1, ExternalModules::KEY_VERSION, TEST_MODULE_VERSION);
		ExternalModules::setGlobalSetting($prefix2, ExternalModules::KEY_VERSION, TEST_MODULE_VERSION);
		ExternalModules::setGlobalSetting($prefix1, ExternalModules::KEY_ENABLED, true);
		ExternalModules::setGlobalSetting($prefix2, ExternalModules::KEY_ENABLED, true);

		$prefixes = self::getEnabledModuleVersionsForProjectIgnoreCache();
		$this->assertNotNull($prefixes[$prefix1]);
		$this->assertNotNull($prefixes[$prefix2]);

		ExternalModules::removeGlobalSetting($prefix2, ExternalModules::KEY_VERSION);
		$prefixes = self::getEnabledModuleVersionsForProjectIgnoreCache();
		$this->assertNotNull($prefixes[$prefix1]);
		$this->assertNull($prefixes[$prefix2]);

		ExternalModules::removeGlobalSetting($prefix1, ExternalModules::KEY_ENABLED);
		$prefixes = self::getEnabledModuleVersionsForProjectIgnoreCache();
		$this->assertNull($prefixes[$prefix1]);

		ExternalModules::removeGlobalSetting($prefix1, ExternalModules::KEY_VERSION);
		ExternalModules::removeGlobalSetting($prefix2, ExternalModules::KEY_ENABLED);
	}

	function testgetEnabledModuleVersionsForProject_overrides()
	{
		$m = self::getInstance();

		$m->setGlobalSetting(ExternalModules::KEY_VERSION, TEST_MODULE_VERSION);
		$prefixes = self::getEnabledModuleVersionsForProjectIgnoreCache();
		$this->assertNull($prefixes[TEST_MODULE_PREFIX]);

		$m->setGlobalSetting(ExternalModules::KEY_ENABLED, true);
		$prefixes = self::getEnabledModuleVersionsForProjectIgnoreCache();
		$this->assertNotNull($prefixes[TEST_MODULE_PREFIX]);


		$m->setProjectSetting(ExternalModules::KEY_ENABLED, true, TEST_SETTING_PID);
		$prefixes = self::getEnabledModuleVersionsForProjectIgnoreCache();
		$this->assertNotNull($prefixes[TEST_MODULE_PREFIX]);

		$m->setProjectSetting(ExternalModules::KEY_ENABLED, false, TEST_SETTING_PID);
		$prefixes = self::getEnabledModuleVersionsForProjectIgnoreCache();
		$this->assertNull($prefixes[TEST_MODULE_PREFIX]);

		$m->removeProjectSetting(ExternalModules::KEY_ENABLED, TEST_SETTING_PID);
		$prefixes = self::getEnabledModuleVersionsForProjectIgnoreCache();
		$this->assertNotNull($prefixes[TEST_MODULE_PREFIX]);

		$m->setGlobalSetting(ExternalModules::KEY_ENABLED, false);
		$prefixes = self::getEnabledModuleVersionsForProjectIgnoreCache();
		$this->assertNull($prefixes[TEST_MODULE_PREFIX]);

		$m->setProjectSetting(ExternalModules::KEY_ENABLED, true, TEST_SETTING_PID);
		$prefixes = self::getEnabledModuleVersionsForProjectIgnoreCache();
		$this->assertNotNull($prefixes[TEST_MODULE_PREFIX]);

		$m->setProjectSetting(ExternalModules::KEY_ENABLED, false, TEST_SETTING_PID);
		$prefixes = self::getEnabledModuleVersionsForProjectIgnoreCache();
		$this->assertNull($prefixes[TEST_MODULE_PREFIX]);
	}

	function testgetFileSettings() {
		$m = self::getInstance();					

		$edocIdGlobal = rand();
		$edocIdProject = rand();

                # global
		ExternalModules::setGlobalFileSetting($this->getInstance()->PREFIX, FILE_SETTING_KEY, $edocIdGlobal);

                # project
		ExternalModules::setFileSetting($this->getInstance()->PREFIX, TEST_SETTING_PID, FILE_SETTING_KEY, $edocIdProject);

		$array = ExternalModules::getProjectSettingsAsArray($this->getInstance()->PREFIX, TEST_SETTING_PID);
		$this->assertEquals($edocIdProject, $array[FILE_SETTING_KEY]['value']);
		$this->assertEquals($edocIdGlobal, $array[FILE_SETTING_KEY]['global_value']);

		ExternalModules::removeFileSetting($this->getInstance()->PREFIX, TEST_SETTING_PID, FILE_SETTING_KEY);
		ExternalModules::removeGlobalFileSetting($this->getInstance()->PREFIX, FILE_SETTING_KEY);
		$array = ExternalModules::getProjectSettingsAsArray($this->getInstance()->PREFIX, TEST_SETTING_PID);

		$this->assertNull($array[FILE_SETTING_KEY]['value']);
		$this->assertNull($array[FILE_SETTING_KEY]['global_value']);
	}

	private function getEnabledModuleVersionsForProjectIgnoreCache()
	{
		self::callPrivateMethod('cacheAllEnableData'); // Call this every time to clear/reset the cache.
		return self::callPrivateMethod('getEnabledModuleVersionsForProject', TEST_SETTING_PID);
	}

	private function callPrivateMethod($methodName)
	{
		$args = func_get_args();
		array_shift($args); // remove the method name

		$class = self::getReflectionClass();
		$method = $class->getMethod($methodName);
		$method->setAccessible(true);

		return $method->invokeArgs(null, $args);
	}

	private function getPrivateVariable($name)
	{
		$class = self::getReflectionClass();
		$property = $class->getProperty($name);
		$property->setAccessible(true);

		return $property->getValue(null);
	}

	private function getReflectionClass()
	{
		return new \ReflectionClass('ExternalModules\ExternalModules');
	}

	function testinstance()
	{
		$value1 = rand();
		$value2 = rand();
		$value3 = rand();
		$value4 = rand();
		ExternalModules::setInstance($this->getInstance()->PREFIX, TEST_SETTING_PID, TEST_SETTING_KEY, 0, $value1);
		$array = ExternalModules::getProjectSettingsAsArray($this->getInstance()->PREFIX, TEST_SETTING_PID);
		$this->assertNotNull(json_encode($array));
		$this->assertEquals($value1, $array[TEST_SETTING_KEY]['value']);

		ExternalModules::setProjectSetting($this->getInstance()->PREFIX, TEST_SETTING_PID, TEST_SETTING_KEY, $value1);
		ExternalModules::setInstance($this->getInstance()->PREFIX, TEST_SETTING_PID, TEST_SETTING_KEY, 1, $value2);
		ExternalModules::setInstance($this->getInstance()->PREFIX, TEST_SETTING_PID, TEST_SETTING_KEY, 2, $value3);
		ExternalModules::setInstance($this->getInstance()->PREFIX, TEST_SETTING_PID, TEST_SETTING_KEY, 3, $value4);
		$array = ExternalModules::getProjectSettingsAsArray($this->getInstance()->PREFIX, TEST_SETTING_PID);
		$this->assertNotNull(json_encode($array));
		$this->assertEquals($value1, $array[TEST_SETTING_KEY]['value'][0]);
		$this->assertEquals($value2, $array[TEST_SETTING_KEY]['value'][1]);
		$this->assertEquals($value3, $array[TEST_SETTING_KEY]['value'][2]);
		$this->assertEquals($value4, $array[TEST_SETTING_KEY]['value'][3]);

		ExternalModules::setProjectSetting($value1);
		ExternalModules::setInstance($this->getInstance()->PREFIX, TEST_SETTING_PID, TEST_SETTING_KEY, 1, $value2);
		ExternalModules::setInstance($this->getInstance()->PREFIX, TEST_SETTING_PID, TEST_SETTING_KEY, 2, $value3);
		ExternalModules::setInstance($this->getInstance()->PREFIX, TEST_SETTING_PID, TEST_SETTING_KEY, 3, $value4);
		$array = ExternalModules::getProjectSetting($this->getInstance()->PREFIX, TEST_SETTING_PID,  TEST_SETTING_KEY);
		$this->assertNotNull(json_encode($array));
		$this->assertEquals($value1, $array[0]);
		$this->assertEquals($value2, $array[1]);
		$this->assertEquals($value3, $array[2]);
		$this->assertEquals($value4, $array[3]);
	}

}
