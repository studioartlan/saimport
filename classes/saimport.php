<?php

require_once('autoload.php');

class saImport
{

	const DEFAULT_INI_NAME = 'saimport.ini';


	static $cli = false;
	static $script = false;

	static $importININame = self::DEFAULT_INI_NAME;
	static $displayTime = true;
		
	static $importINI = false;
	
	static $lowercaseComparison = true;

	const DEBUG_LEVEL_NONE = -1;
	const DEBUG_LEVEL_STANDARD = 0;
	const DEBUG_LEVEL_VERBOSE = 10;
	const DEBUG_LEVEL_ALL = 20;

	const DEFAULT_REGEX_DELIMITER = '|';

	private static $_DebugLevel = self::DEBUG_LEVEL_STANDARD;
			
	static $DefaultContentClass = false;
	static $DefaultParentNode = false;
	static $DefaultCreator = false;

	static $saImportID = "";	
	static $ImagesFolder = "";
	static $FilesFolder = "";

	static $lastOutput = '';

	static $moduleOperationList = array();

	static $simpleHTMLReplacements = array(
#                        '<p>' => '<paragraph>',
#                        '</p>' => '</paragraph>',
                        '<i>' => '<emphasize>',
                        '</i>' => '</emphasize>',
                        '<b>' => '<strong>',
                        '</b>' => '</strong>'
	);

	static function freeNodesMemory( $nodes )
	{
		return self::freeObjectsMemory( $nodes, true );
	}

	static function freeObjectsMemory( $objects, $isNodes = false )
	{
		if ( !$objects )
			return false;
		
		if ( !is_array( $objects ) )
			$objects = array( $objects );

		foreach ( $objects as $object )
		{
			if ( $isNodes )
				$object = $object->attribute( 'object' );
			else
				$object = $item;

			$objectID = $object->attribute( 'id' );

			$object->resetDataMap();
			eZContentObject::clearCache( $objectID );
		}

		return true;
	}
	
	static function ImportFile($filename, $importClass, $importMethod, $skipFirstRows = 0)
	{

		$file = @fopen($filename, "r");
		
		if (!$file)
			self::breakScript("Could not open import file '$filename'.");
		
		$importHandler = "$importClass::$importMethod";
		
		if (!method_exists($importClass, $importMethod))
			self::breakScript("Import handler '$importHandler' doesn't exist'.");		

		self::output("Parsing file: '$filename'...");
	
		$filesize = filesize($filename);

		$lineNumber = 0; $importedLines = 0; $readBytes = 0;
		
		while ($line = fgets($file))
		{
			$lineNumber++;
			$length = strlen($line);
			$mb_length = mb_strlen($line);
			$readBytes += $length;
			$percent = $readBytes / $filesize * 100;

			self::$saImportID = '<no id>';
			
			if ($lineNumber <= $skipFirstRows)
				self::output("Skipping line $lineNumber.");
			else
			{
				self::output("Importing line $lineNumber, read $readBytes/$filesize bytes ($percent %).");
				if ( call_user_func($importHandler, $line) )
					$importedLines++;
			}
			
		}
		
		@fclose($file);	
		self::output("Import DONE. Imported lines: $importedLines/$lineNumber.");

	}

	static function findFirstNode( &$params )
	{
		$nodes = self::FindNodes( $params );

		if ( $nodes )
			$result = array_shift( $nodes );
		else
			$result = null;

		//saImport::freeNodesMemory( $nodes );
		
		return $result;

	}
	
	static function FindNodes( $params )
	{
		if (!isset($params['class'])  || !isset($params['parent_node']))
			return false;
		
		if ( !is_array($params['class']) )
		{
			$params['class'] = array( $params['class'] );
		}

		$classFilter = array();
		
		foreach ( $params['class'] as $class)
		{
			if ( is_string($class) || is_numeric($class) )
			{
				$classFilter[] = $class;
			}
			else
			{
				$classFilter[] = $class->attribute( 'identifier' );
			}
		}
		
		$fetchHash = array(
				'ClassFilterType' => 'include',
				'ClassFilterArray' => $classFilter
			);

		if (isset($params['attribute_filter']))
			$fetchHash['AttributeFilter'] = $params['attribute_filter'];

		if (isset($params['ignore_visibility']))
			$fetchHash['IgnoreVisibility'] = $params['ignore_visibility'];

		$result = $params['parent_node']->subTree($fetchHash);
	
		return $result;
	}
	

	private static function _findMainLocation( $locations )
	{
		foreach ($locations as $locationData)
		{
			if ( isset( $locationData['is_main'] ) && $locationData['is_main'] )
				return $locationData;
		}

		return false;

	}

	static function addLocations($object, $additionalLocations)
	{

		$result = true;

		$parentNodes = $object->parentNodeIDArray();
		
		foreach ($additionalLocations as $locationID)
		{
			if (!in_array($locationID, $parentNodes))
			{
				$assignedNodes = $object->assignedNodes();
				$alreadyAssigned = false;

				foreach ($assignedNodes as $assignedNode)
				{
					if ($assignedNode->attribute('parent_node_id') == $locationID)
					{
						$alreadyAssigned = true;
						break;
					}
				}

				if (!$alreadyAssigned)
					$result = $result && $object->AddLocation($locationID);
			}
		}

		return $result;

	}

	static function easyImport( &$importData, $searchAllLocations = false)
	{

		$mainLocation = self::_findMainLocation( $importData['locations'] );

		if ( $mainLocation && isset( $mainLocation['node'] ) )
			$searchNode = $mainLocation['node'];
		else 
			$searchNode = null;

		if ( !$searchNode )
		{
			self::output('No main location defined');
			return false;
		}

		if ( is_object( $importData['class'] ) )
			$class = $importData['class'];
		else
			$class = eZContentClass::fetchByIdentifier( $importData['class'] );

		if ( !$class )
		{
			self::output('No content type (class) defined');
			return false;
		}

		$attributeFilter = array();
		if ( isset( $importData['attribute_match'] ) )
		{
			foreach ( $importData['attribute_match'] as $attributeName => $matchValue )
			{
				if ( self::$lowercaseComparison ) $matchValue = strtolower( $matchValue );
				$attributeFilter[] = array( $class->attribute('identifier') . '/' . $attributeName, '=', $matchValue );
			}
				

		}

		if ( isset( $importData['attribute_filter'] ) )
			$attributeFilter = array_merge( $attributeFilter, $importData['attribute_filter'] );


		$existingFilter = array(
			'parent_node' => $searchNode,
			'class' => $importData['class'],
		);

		if ( $attributeFilter )
		{
			array_unshift(  $attributeFilter, 'and' );
			$existingFilter['attribute_filter'] = $attributeFilter;
		}

		if ( isset( $importData['ignore_visibility'] ) )
		{
			$existingFilter['ignore_visibility'] = $importData['ignore_visibility'];
		}

//TODO: manage case when $searchAllLocations == true
		if ( !isset( $importData['merge_match_attributes'] ) || $importData['merge_match_attributes'] )
		{
			foreach ( $importData['attribute_match'] as $attributeName => $attributeValue )
			{
				$importData['attributes'][$attributeName] = array( 'from_string' => $attributeValue );	
			}
			
		}
			
//var_dump($importData['attributes']);exit;
		$importData['existing_filter'] = $existingFilter;

		return self::Import( $importData );

	}

	static function Import(&$import_data)
	{
		// Retreiving saimport_id
		if (isset($import_data['saimport_id']))
			self::$saImportID = $import_data['saimport_id'];

		// Retreiving object class
		if (isset($import_data['class']) && $import_data['class'])
			$import_class = $import_data['class'];
		elseif (self::$DefaultContentClass)
			$import_class = array(self::$DefaultContentClass);
		else
		{
			self::output("No class defined.");
			return false;
		}

		// Retreiving object creator
		if (isset($import_data['creator']['user']) && $import_data['creator']['user'])
			$creatorUser = $import_data['creator']['user'];
		elseif (self::$DefaultCreator)
			$creatorUser = self::$DefaultCreator;
		else
		{
			$creatorUser = eZUser::currentUser();
			self::output("No creator user defined, using current user");
		}

		// Retreiving object locations
		if (isset($import_data['locations']) && is_array($import_data['locations']) && $import_data['locations'])
			$import_locations = $import_data['locations'];
		elseif (self::$DefaultParentNode)
			$import_locations = array(self::$DefaultParentNode);
		else
		{
			self::output("No locations defined.");
			return false;
		}
		
		// Checking for object attributes
		if (isset($import_data['attributes']) && is_array($import_data['attributes']) && $import_data['attributes'] )
			$import_attributes = $import_data['attributes'];
		else
		{
			self::output("No attibutes defined.");
			return false;
		}
		
		// Setting object parameters
		$parameters = array();
		$parameters['class_identifier'] = $import_class->attribute('identifier');
		$parameters['creator_id'] = $creatorUser->attribute('contentobject_id');

		if (isset($import_data['published']) && $import_data['published'])
				$parameters['published'] = self::_ImportDate($import_data['published']);
				
		// Parsing and setting locations
		$parentNodeId = false;
		$additionalLocations = array();

		foreach ($import_locations as $location_import_data)
		{		
			if (isset($location_import_data['node']) && $location_import_data['node'])
			{
				if (is_numeric($location_import_data['node']))
					$locationID = $location_import_data['node'];
				else
				{
					$location_node = $location_import_data['node'];
					$locationID = $location_node->NodeID;
				}

				if (isset($location_import_data['is_main']) && $location_import_data['is_main'])
					$parentNodeId = $locationID;

				$additionalLocations[] = $locationID;
			}
			else
				self::output("A missing parent node occurred.");
		}

		if ($parentNodeId)
			$parameters['parent_node_id'] = $parentNodeId;
		else
		{
			self::output("No main node defined.");
			return false;
		}


		// setting attributes
		$attributesData = array();
		
		foreach ($import_attributes as $attributeIdentifier => $attribute_import_data)
		{
			$attribute_data = self::_ImportAttributeData($import_class, $attributeIdentifier, $attribute_import_data);
			if ( $attribute_data !== NULL )
				$attributesData[$attributeIdentifier] = $attribute_data;
		}

		$parameters['attributes'] = $attributesData;

//		print_r($parameters); return false;


		if ( !empty( $import_data['existing_node'] ) )
			$existingNodes = array( $import_data['existing_node'] );
		else
		{
			// Checking for exsisting objects
			self::output("Looking for existing nodes...");
	
			if (isset($import_data['existing_filter']))
					$existingNodes = self::FindNodes($import_data['existing_filter']);
			else
				$existingNodes = false;			
		}

		// If more than one node was found, we check if it's the same object under all nodes
		if ( count($existingNodes) > 1)
		{
					
			$existingNodesIDs =array();
			$existingNodesNames =array();
			
			$existingObjectID = $existingNodes[0]->attribute('object')->attribute('id');
			
			foreach ($existingNodes as $existingNode)
			{
				if ($existingNode->attribute('object')->attribute('id') != $existingObjectID)
				{
					$existingNodesIDs[] = $existingNode->attribute('node_id');
					$existingNodesNames[] = $existingNode->attribute('name');
				}
			}
			
			if ($existingNodesIDs)
			{
				// If found more than one object we don't import
				self::output( "More than one existing node found." );
				self::output( "Nodes IDs: " . implode(',', $existingNodesIDs) );
				self::output( "Nodes names: " . implode(',', $existingNodesNames) );
				return false;
			}
			else
			{
				$node = $existingNodes[0];
				self::output("More than one existing node found, but all belong to the same object, continuing with update...");
			}
		}

		if (!$existingNodes)
		{
			// If no existing nodes were found create and publish new object and set it for update
			self::output("No existing nodes found, creating new.");
//var_dump($parameters);
			$object = eZContentFunctions::createAndPublishObject( $parameters );
			$node = $object->attribute('main_node');
		}
		elseif ( !(isset($import_data['overwrite']) && $import_data['overwrite']) )
		{
			// If one object was found and overwrite is not enabled we don't import, but we return the found node
			self::output("Existing nodes found but overwrite not enabled.");
			return $existingNodes[0];
		}
		else
		{
			// If one object was found and overwrite is enabled we will set this node for update
			$node = $existingNodes[0];
			$object = $node->attribute('object');
			self::output("Existing node found (" . $node->attribute('name') ."), updating...");
		}

		$version = $object->currentVersion();
		$version->setAttribute( 'status', eZContentObjectVersion::STATUS_DRAFT );

		// Set creator
		if (isset($import_data['creator']['overwrite']) && $import_data['creator']['overwrite'])
		{
			$object->setAttribute( 'creator_id', $parameters['creator_id'] );
		}

		// Set published date
		if ( isset($parameters['published']) && $parameters['published'])
		{
			self::output("Setting published time to: " . $parameters['published'], self::DEBUG_LEVEL_ALL);
			$object->setAttribute( 'published', $parameters['published']);
//			$object->setAttribute( 'created', $parameters['published']);

//			$version->setAttribute( 'published', $parameters['published']); 
//			$version->setAttribute( 'created', $parameters['published']);
		}

		$version->store();
		$object->store();
		
		// Set attributes
		$dataMap = $object->attribute( 'data_map' );
		$paramAttributes = $parameters['attributes'];

		foreach ( $import_attributes as $attributeIdentifier => $attributeImportData )
		{

//ezDebug::writeError("Processing attribute $attributeIdentifier");

			// Checking if owerwrite is allowed
			if ( !empty( $attributeImportData['overwrite'] ) )
			{
			
				$attribute = $dataMap[$attributeIdentifier];

				if ( $attribute )
				{
					// Wether we have attribute data
					if ( isset( $paramAttributes[$attributeIdentifier] ) )
					{
	//ezDebug::writeError("Overwriting attribute $attributeIdentifier");
						self::output( "Overwriting attribute $attributeIdentifier" );
						// Set the attribute data using fromString() method
						$attribute->fromString( $paramAttributes[$attributeIdentifier] );
					}
					elseif ( !empty( $attributeImportData['allow_delete'] ) && $attribute->attribute( 'has_content' ) ) 
					{
						//If there's no attribute data, and allow_delete is set and attribute has content
						// we remove the attribute
	//ezDebug::writeError("Deleting attribute value for $attributeIdentifier");
						self::output( "Deleting attribute value for $attributeIdentifier" );
// xmak debug: ako ne podesimo verziju, onda ne bi trebao javiti debug notice za image aliase
// no u tom slučaju briše SVE aliase za tu sliku i njene prijašnje verzije - treba vidjeti dal je to OK 
//						$attribute->removeThis( $attribute->attribute( 'id' ), $version->attribute( 'version' ) );
						$attribute->removeThis( $attribute->attribute( 'id' ) );
					}
	//ezDebug::writeError("Storing attribute $attributeIdentifier");
					self::output( "Storing attribute $attributeIdentifier", self::DEBUG_LEVEL_ALL );
					$attribute->store();
				}
				else
					self::output("Attribute '$attributeIdentifier' doesn't exist.", self::DEBUG_LEVEL_STANDARD);
			}
			
		}

//ezDebug::writeError("Publishing object: " . $object->attribute( 'name' ));
		self::output("Publishing object: " . $object->attribute( 'name' ), self::DEBUG_LEVEL_VERBOSE);
		$operationResult = eZOperationHandler::execute(
			'content', 'publish',
			array(
				'object_id' => $object->attribute( 'id' ),
				'version' => $version->attribute( 'version' )
			)
		);
		
		if ($operationResult && isset($operationResult['status']) && $operationResult['status'])
			self::output("Object published: " . $object->attribute( 'name' ), self::DEBUG_LEVEL_VERBOSE);
		else
			self::output("Object publishing failed: " . $object->attribute( 'name' ), self::DEBUG_LEVEL_VERBOSE);
		
		
		saImport::freeNodesMemory( $existingNodes );

		unset($version);
		unset($existingNodes);

		if ($node)
		{
			if ($additionalLocations)
				self::addLocations($node->attribute('object'), $additionalLocations);
				
			return $node;
		}
		else
			return false;
	
	}

	private static function _ImportAttributeData(&$class, &$attributeIdentifier, &$attribute_data)
	{

		$classAttribute = $class->fetchAttributeByIdentifier($attributeIdentifier);
		if ($classAttribute)
		{
			if ( isset( $attribute_data['from_string'] ) || $attribute_data['from_string'] === NULL )
			{
				return $attribute_data['from_string'];
			}
			else
			{
				$dataTypeString = $classAttribute->attribute('data_type_string');
				
				switch ($dataTypeString)
				{
					case 'ezstring':
					case 'eztext':
					case 'ezinteger':
					case 'ezboolean':
						return $attribute_data['value'];
					break;
					case 'ezselection':
					
						$classContent = $classAttribute->attribute( 'content' );
//print_r($classContent);
						foreach ( $classContent['options'] as $option )
						{
							if ( $option['id'] == $attribute_data['value'] )
								return $option['name'];
						}
						
						return false;
						
					break;
					case 'ezdatetime':
						return self::_ImportDate($attribute_data);
					break;
					case 'ezimage':
						if (isset($attribute_data['file']))
							$image_file = $attribute_data['file'];
						else
							$image_file = '';
							 
						if ($image_file)
						{
							if (self::$ImagesFolder)
								$image_file = self::$ImagesFolder . '/' . $image_file;
							return $image_file;
						}
						else
						{
							self::output("No image file for attribute $attributeIdentifier (" . $dataTypeString . ").");
							return false;
						}
					break;
					
					default:
						//print_r($attribute_data);
						self::output("Import attribute $attributeIdentifier (" . $dataTypeString . ") not supported.");
						return false;
					break;
				}
			}
			
		}
		else
		{
			self::output("No attribute '$attributeIdentifier'.");
			return false;
		}
	}

	static function HTML2OE( $text, $replacements = '', $delimiter = self::DEFAULT_REGEX_DELIMITER )
	{
		if ( !$replacements )
			$replacements = self::$simpleHTMLReplacements;

		$text = self::replacePatterns($text, $replacements, $delimiter);
                $text = trim($text);

// TODO: detect wether to use OE parser or not
//                $parser = new eZOEInputParser();

        $parser = new eZSimplifiedXMLInputParser(NULL);
        $parser->setParseLineBreaks( true );
        $document = $parser->process( $text );

		if ($document)
			$text = eZXMLTextType::domString( $document );
		else
			saImport::output("Could not parse XML text $text");

        return $text;

	}


	static function replacePatterns( $text, $replacements, $delimiter = self::DEFAULT_REGEX_DELIMITER )
	{

		foreach ( $replacements as $key => $value )
		{
			$pattern = "$delimiter$key$delimiter";
			$replacement = $value;
			$text = preg_replace($pattern, $replacement, $text);
		}

		return $text;
        }
	
	private static function _ImportDate(&$import_date)
	{
		if (isset($import_date['timestamp']))
			return $import_date['timestamp'];
		else
			return strtotime($import_date['value']);
	}


	static function setDebugLevel($debugLevel = 0)
	{
		self::$_DebugLevel = $debugLevel;
	}

	static function getDebugLevel()
	{
		return self::$_DebugLevel;
	}


	static function parseParams($requiredParams, $optionalParams = array())
	{
		
		foreach (array_keys($requiredParams) as $key)
		{
			$requiredParams[$key] = $requiredParams[$key] . " (required)";
		}
	
	
		$paramsString = "";
		$params = array();
		
		foreach (array_merge($requiredParams, $optionalParams) as $key => $value)
		{
			$paramsString .= '[' . $key . ']';
			$newKey = str_replace( ';', '', str_replace(':', '', $key) );
			$params[$newKey] = $value;
		}
	
		$options = self::$script->getOptions(
			$paramsString,
			"",
			$params
		);
	
		foreach (array_keys($requiredParams) as $key)
		{
			$newKey = str_replace( ';', '', str_replace(':', '', $key) );
	
			if ($options[$newKey] == "")
			{
				return false;
			}
		}
	
		return $options;
	}

	static function generateAttributesArray( $simpleAttributes, $overwrite = true )
	{
		
		$attributes = array();
		foreach ( $simpleAttributes as $attributeName => $attributeValue )
		{
			$attributes[$attributeName] = array( 'from_string' => $attributeValue, 'overwrite' => $overwrite );
		}
		return $attributes;
	}

	static function getImportININode( $setting, $break = true)
	{
		return self::getININode( 'ImportSettings', $setting, $break );
	}
	
	static function getININode($group, $setting, $break = true)
	{

		self::getImportINI($group, $setting, $node_id);
		$node = eZContentObjectTreeNode::fetch($node_id);
		if (!$node)
		{
			$msg = "There's no node with ID '" . $node_id . "' for setting $group -> $setting.";
			if ($break)
				self::breakScript($msg);
			else
				self::output($msg);						
		}
		else
		{
			$settingName = is_array( $setting ) ? $setting = $setting[0] . '['  . $setting[1] . ']' : $setting;
			self::output("Fetched node $group -> $settingName: ". $node->attribute('name') . ' - ('. $node->attribute('node_id') . ')');			
		}
		
		return $node;
	}


	static function getImportINIClass( $setting, $break = true)
	{
		return self::getINIClass( 'ImportSettings', $setting, $break );
	}
	
	static function getINIClass( $group, $setting, $break = true )
	{

		$classIdentifier = null;
		
		self::getImportINI( $group, $setting, $classIdentifier );
		
		$class = eZContentClass::fetchByIdentifier( $classIdentifier );

		if (!$class)
		{
			$msg = "There's no class with identifier '" . $classIdentifier . "' for setting $group -> $setting.";
			if ($break)
				self::breakScript( $msg );
			else
				self::output( $msg );
		}
		else
		{
			$settingName = is_array( $setting ) ? $setting = $setting[0] . '['  . $setting[1] . ']' : $setting;
			self::output("Fetched class $group -> $settingName: ". $class->attribute('name') . ' - ('. $class->attribute('identifier') . ')');
		}
			
			
		return $class;
	}

	static function getImportINI( $group, $setting, &$var )
	{
		if ( !self::$importINI )
		{
			self::$importINI = eZINI::instance( self::$importININame );
			self::$importINI->load();
		}

		return self::getINI( self::$importINI, $group, $setting, $var );
	}
	
	static function getINI( $inifile, $group, $setting, &$var )
	{
	
		if ( is_array( $setting ) )
		{
			$settingName = $setting[0];
			$settingKey = $setting[1]; 
		}
		else
		{
			$settingName = $setting;
			$settingKey = null;
		}
			
		$result = self::_getINI($inifile, $group, $settingName, $var );

		if ( $result && $settingKey )
			$var = $var[$settingKey];
		
		return $result;
	}
	
	private static function _getINI( $inifile, $group, $setting, &$var )
	{
		
		if ( $inifile->hasvariable( $group, $setting ) )
		{
			$var = self::trimRecursive( $inifile->variable( $group, $setting ) );
			return true;
		}
		else
			return false;
	}
	
	static function breakScript($message = '')
	{
		if (self::$script)
			self::$script->shutdown( 1, $message );
		else
		{
			self::output($message);
			exit;
		}
	}
	
	static function output( $message, $debugLevel = 0 )
	{
		if ($debugLevel <= self::$_DebugLevel)
		{
			if (self::$saImportID)
				$context = "saImport, import object " . self::$saImportID;
			else
				$context = "saImport";

			self::$lastOutput = $message;
			
			if ( self::$displayTime )
			{
				$microtime = microtime( true );
				$time = floor( $microtime );
				$miliseconds = $microtime - $time;  
				$context .= date( ' d.m.Y H:i:s.', $time ) . round( $miliseconds * 1000 );
			}
			
			if ( self::$cli )
				self::$cli->output( "$context: $message" );
			else
				ezDebug::writeError( $message, $context );

		}
	}
	
	static function trimRecursive($var)
	{
		if (!is_array($var))
			return trim($var);
		else
			return array_map('self::trimRecursive', $var);
	}
	

	static function importStandardDisablePublishOperation()
	{
		self::disablePublishOperation( 'send-to-publishing-queue' );
		self::disablePublishOperation( 'pre_publish' );
		self::disablePublishOperation( 'publish-objectextension-handler' );
		
		self::disablePublishOperation( 'clear-object-view-cache' );
		self::disablePublishOperation( 'generate-object-view-cache' );
		self::disablePublishOperation( 'register-search-object' );
		self::disablePublishOperation( 'remove-temporary-drafts' );

		self::disablePublishOperation( 'create-notification' );
		self::disablePublishOperation( 'post_publish' );
	}

	static function disablePublishOperation( $operationPartName )
	{
		self::disableModuleOperation('content', 'publish', $operationPartName);
	}

	static function enablePublishOperation( $operationPartName )
	{
		self::enableModuleOperation('content', 'publish', $operationPartName);
	}
	
	static function disableModuleOperation( $moduleName, $operationName, $operationPartName )
	{
		if( !isset( $GLOBALS['eZGlobalModuleOperationList'][$moduleName] ) )
		{
			$moduleOperationInfo = new eZModuleOperationInfo( $moduleName, false );
			$moduleOperationInfo->loadDefinition();
	 
			$GLOBALS['eZGlobalModuleOperationList'][$moduleName] = $moduleOperationInfo;
	        }
	
		if ( !isset( $GLOBALS['eZGlobalModuleOperationList'][$moduleName]->OperationList[$operationName] ) )
			return;
	
		$index = self::findModuelOperation( $moduleName, $operationName, $operationPartName );
		
		if ($index >= 0)
		{
			self::$moduleOperationList[self::getModuleOperationKey( $moduleName, $operationName, $operationPartName, $index )] = $GLOBALS['eZGlobalModuleOperationList'][$moduleName]->OperationList[$operationName]['body'][$index];
			unset( $GLOBALS['eZGlobalModuleOperationList'][$moduleName]->OperationList[$operationName]['body'][$index] ); 
		}
	}

	static function enableModuleOperation( $moduleName, $operationName, $operationPartName )
	{
		if( !isset( $GLOBALS['eZGlobalModuleOperationList'][$moduleName] ) )
		{
			$moduleOperationInfo = new eZModuleOperationInfo( $moduleName, false );
			$moduleOperationInfo->loadDefinition();
	 
			$GLOBALS['eZGlobalModuleOperationList'][$moduleName] = $moduleOperationInfo;
	        }
	
		if ( !isset( $GLOBALS['eZGlobalModuleOperationList'][$moduleName]->OperationList[$operationName] ) )
			return;
	
		$index = self::findModuelOperation( $moduleName, $operationName, $operationPartName );
		
		if ($index >= 0)
		{
			$body =  self::$moduleOperationList[self::getModuleOperationKey( $moduleName, $operationName, $operationPartName, $index )];
			array_splice( $GLOBALS['eZGlobalModuleOperationList'][$moduleName]->OperationList[$operationName]['body'], $index, 0, $body );
#			$GLOBALS['eZGlobalModuleOperationList'][$moduleName]->OperationList[$operationName]['body'][$index] = $body;
		}
	}

	private static function getModuleOperationKey( $moduleName, $operationName, $operationPartName, $index )
	{
		return "$moduleName|$operationName|$operationPartName|$index";
	}

	static function findModuelOperation( $moduleName, $operationName, $operationPartName )
	{

		foreach ($GLOBALS['eZGlobalModuleOperationList'][$moduleName]->OperationList[$operationName]['body'] as $key => $operationPart)
		{
			if ($operationPart['name'] == $operationPartName)
			{
				return $key;
			}
		}
	
		return -1;

	}
	
	static function explodeFields($line, $separator, $fieldCount, $fieldMappings, $nullString)
	{
		$fields = explode($separator, $line);
			
		if (count($fields) < $fieldCount)
			return false;
		else
		{
			$resultFields = array();
			
			foreach ($fieldMappings as $index => $fieldName)
			{
				$resultFields[$fieldName] = trim($fields[$index]);
				if ( $nullString && ($resultFields[$fieldName] == $nullString) )
					$resultFields[$fieldName] = '';
			}
			return $resultFields;
		}
		
	}

}

?>
