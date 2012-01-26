<?php

class saWebImportServer {

	const HTTP_METHOD_POST = 'post';
	const HTTP_METHOD_FILE = 'file';
	const HTTP_METHOD_GET = 'get';
	const HTTP_DEFAULT_METHOD = self::HTTP_METHOD_POST;

	const STATUS_ERROR = false;
	const STATUS_SUCCESS = true;
	const STATUS_STRING_ERROR = 'error';
	const STATUS_STRING_SUCCESS = 'success';

	const UPLOAD_FILE_SUBDIR = 'saimport/upload';


	function __construct( $contentType )
	{

		$typeMappings = $this->typeMappings();

		if ( !isset( $typeMappings[$contentType] ) )
			return null;

		$this->externalContentTypeID = $contentType;
		
		$classIdentifier = $typeMappings[$contentType];

		if ( is_int( $classIdentifier ) )
			$class = eZContentClass::fetch( $classIdentifier );
		else
			$class = eZContentClass::fetchByIdentifier( $classIdentifier );
			
		if ( !$class )
			return null;

		$this->contentClass = $class;

		return $this;

	}

	function createObjectFromHTTPParameters()
	{
		return $this->createObject( $this->getHTTPParameters() );
	}
	
	function createObject( $inputParameters )
	{
		$this->parameters = $inputParameters;

		$importIDs = array();
		$importIDParameters = $this->importIDParameters();
		foreach ( $importIDParameters as $parameterName )
		{
			if ( isset( $this->parameters[$parameterName] ) )
				$importIDs[$parameterName] = $this->parameters[$parameterName];
		}

		$resultData = array();
		$resultData['import_identifiers'] = $importIDs;


		if ( !$this->contentClass )
			return self::returnError( 'Invalid content type', $resultData );


		$attributes = array();
		$failedAttributes = array();

		$this->_parseParameters();

		if ( !$this->passLogin() )
			return self::returnError( 'Could not authenticate user' );

		if ( !$this->isMainLocationDefined )
		{
			$firstLocationIndex = $this->_findNodeLocation( $this->firstLocationNode );
			if ( $firstLocationIndex !== false )
				$this->parsedLocations[$firstLocationIndex]['is_main'] = true;
		}

		$generatedLocations = $this->generateLocations();

		if ( isset( $generatedLocations['locations'] ) )
		{
			$aditionalLocations = $generatedLocations['locations'];
			if ( $generatedLocations['override_locations'] )
				 $this->parsedLocations = $aditionalLocations;
			else
			{
				foreach ( $additionalLocations as $location )
				{
					$this->_addLocation( $location );
				}
			}

		}

		if ( !$this->_checkRequiredAttributes( null, $failedAttributes ) )
		{
			$msg = 'The following parameters are required: ' . implode ( ', ', $failedAttributes );
			return self::returnError( $msg );
		}

		$importData = array(
			'class' => $this->contentClass,
			'locations' => $this->parsedLocations,
			'ignore_visibility' => false,
			'overwrite' => true,
			'attributes' => $this->parsedAttributes,
			'attribute_match' => array( 'webservice_content_id1' => 3)
		);



		// TODO: add possibility to define creator
		if ( $this->user )
			$importData['creator'] = array( 'user' => $this->user);

		$node = saImport::easyImport( $importData );

		if ( $node )
		{
			$this->_cleanUploadedFiles();
			$resultData['node'] = $node;
			return self::returnSuccess( 'Data was successfully imported, thank you', $resultData );
		}
		else
		{
			$msg = saImport::$lastOutput ? saImport::$lastOutput : 'Could not import data';
			return self::returnError( $msg, $resultData );
		}

	}


	function getAvailableLocations( $parameters = array() )
	{

		if ( $parameters )
			$this->parameters = $parameters;
		else
			$this->parameters = $this->getHTTPParameters();

		$this->_parseParameters();

		if ( !$this->passLogin() )
			return self::returnError( 'Could not authenticate user' );

		$availableLocations = $this->availableLocations( $this->externalContentTypeID );

		$resultLocations = array();

		foreach ( $availableLocations as $nodeID)
		{
			$node = eZContentObjectTreeNode::fetch( $nodeID );
			if ( $node )
				$resultLocations[$nodeID] = $node;
			else
			{
				// TODO: debug output
			}

		}

		return self::returnSuccess( 'Categories list retreived', $resultLocations );
	}


	protected function passLogin()
	{
		if ($this->user)
			return $this->user;

		if ( !$this->parameters)
			$this->parameters = $this->getHTTPParameters();

		$this->_parseParameters();

		$this->user = null;
		if ( $this->requireLogin() )
		{
			$this->user = $this->_tryLogin();
			if ( $this->user )
				return true;
			else
				return false;
		}
		else return true;

	}

	private function _cleanUploadedFiles()
	{
		foreach ( $this->uploadedFiles as $fileName => $filePath )
		{
			unlink( $filePath );
		}
	}

	private function _tryLogin()
	{

		$user = $this->loginUser();

		if ( $user )
			return $user;

		if ( $this->username && $this->password )
		{
			$user = eZUser::loginUser( $this->username, $this->password );
			if ( $user )
				return $user;
		}
	}

	private function _checkRequiredAttributes( $attributes, &$failedAttributes )
	{

		if ( $attributes === null )
			$attributes = $this->parsedAttributes;

		$result = true;

		$parameterMappings = $this->parameterMappings();

		foreach	( $parameterMappings as $parameterName => $parameterMapping )
		{
			if ( isset( $parameterMapping['attribute'] ) && isset( $parameterMapping['required'] ) && $attributeMapping['required'] && !isset( $attributes[$attributeMapping['attribute']] ) )
			{
				$failedAttributes[] = $parameterName;
				$result = false;
			}
		}

		return $result;

	}

	private function _parseParameters( $force = false )
	{
		if ( !$this->parametersParsed || $force )
		{
			foreach ( $this->parameters as $parameterName => $parameterValue )
			{
				$this->_parseParameter( $parameterName, $parameterValue );
			}

			$this->parametersParsed = true;
		}

	}

	private function _parseParameter( $parameterName, $parameterValue = null)
	{
		if ( $parameterValue === null )
			$parameterValue = $this->parameters[$parameterName];

		$parameterMappings = $this->parameterMappings();

		if ( !isset( $parameterMappings[$parameterName] ) )
		{
			// TODO: debug output
			return false;
		}

		if ( $parameterValue === null )
		{
			// TODO: debug output
			return false;
		}

		$resultAttributes = $this->_parseAttributeParameter( $parameterName, $parameterValue );

		if ( $resultAttributes )
		{
			$this->parsedAttributes = array_merge( $this->parsedAttributes, $resultAttributes );
			return true;
		}

		if ( $this->_parseLocationParameter( $parameterName, $parameterValue ) )
			return true;

		if ( $this->_parseUserParameter( $parameterName, $parameterValue ) )
			return true;

		return false;
	}


	private function _parseUserParameter( $parameterName, $parameterValue )
	{

		$parameterMappings = $this->parameterMappings();

		if ( isset( $parameterMappings[$parameterName]['username'] ) )
		{
			$this->username = $parameterValue;
			return;
		}

		if ( isset( $parameterMappings[$parameterName]['password'] ) )
		{
			$this->password = $parameterValue;
			return;
		}

	}

	private function _parseLocationParameter( $parameterName, $parameterValue )
	{

		$parameterMappings = $this->parameterMappings();

		if ( !isset( $parameterMappings[$parameterName]['locations'] ) )
		{
			// TODO: debug output
			return array();
		}

		$locationList = array();

		if ( isset( $parameterMappings[$parameterName]['is_main'] ) && $parameterMappings[$parameterName]['is_main'] )
		{
			$locationValues = array( $parameterValue );
			$isMainLocation = true;
		}
		else
		{
			$locationValues = array_unique( $parameterValue );
			$isMainLocation = false;
		}

		foreach ( $locationValues as $locationID )
		{
			
			if ( isset( $parameterMappings[$parameterName]['locations'][$locationID] ) )
				$locationNodeID = $parameterMappings[$parameterName]['locations'][$locationID];
			else
				$locationNodeID = $locationID;

			$node = eZContentObjectTreeNode::fetch( $locationNodeID );

			if ( $node )
			{
				$this->_addNodeLocation( $node, $isMainLocation );
			}
			else
			{
				// TODO: debug output
			}
		
		}

		return $locationList;
		
	}

	private function _addNodeLocation( $node, $isMainLocation = false )
	{
		$this->_addLocation( array( 'node' => $node, 'is_main' => $isMainLocation ) );

	}

	private function _addLocation( $location )
	{

		if ( $location['is_main'] )
			$this->isMainLocationDefined = true;

		$existingLocation = $this->_findNodeLOcation( $location['node'] );

		if ( $existingLocation !== false )
		{
			$this->parsedLocations[$existingLocation] = $location;
		}
		else
			$this->parsedLocations[] = $location;

		if ( !$this->firstLocationNode )
			$this->firstLocationNode = $location['node'];
	}

	private function _findNodeLocation( &$node )
	{
		foreach ( $this->parsedLocations as $key => $location )
		{
			if ( isset( $location['node'] ) && ($location['node']->attribute( 'node_id' ) == $node->attribute( 'node_id' )) )
				return $key;
		}

		return false;

	}

	private function _parseAttributeParameter( $parameterName, $parameterValue )
	{

		$parameterMappings = $this->parameterMappings();


		if ( !isset( $parameterMappings[$parameterName]['attribute'] ) )
		{
			// TODO: debug output
			return array();
		}

		$attributeIdentifier = $parameterMappings[$parameterName]['attribute'];

		$attribute = $this->contentClass->fetchAttributeByIdentifier( $attributeIdentifier );

		if ( !$attribute )
		{
			// TODO: debug output
			return array();
		}

		$attributeData = array();

		switch ( $attribute->attribute( 'data_type_string' ) )
		{
			case 'ezstring':
			case 'ezinteger':
				$attributeData = array( 'from_string' => $parameterValue );
			break;
			case 'ezkeyword':
				$attributeData = array( 'from_string' => implode( ',', $parameterValue ) );
			break;
			case 'ezxmltext':
				$attributeData = array( 'from_string' => $this->parseText( $parameterValue ) );
//print_r($attributeData);
			break;
			case 'ezobjectrelationlist':
				$allowedRelations = $this->getAllowedRelations( $parameterName, $parameterValue );

				if ( $allowedRelations )
				{
					$resultRelations = array();
	
					foreach ( $parameterValue as $objectID )
					{
						if ( array_contains( $objectID, $allowedRelations ) )
						{
							$resultRelations[] = $objectID;
						}
					}
				}
				elseif ( $allowedRelations === NULL )
					 $resultRelations = $parameterValue;

				$attributeData = array( 'from_string' => implode('-', $resultRelations ) );
			break;
			case 'ezimage':

				$httpFile = $parameterValue;
				
				if ( $httpFile )
				{
					$subDir = self::UPLOAD_FILE_SUBDIR;

					if ( $httpFile->store( $subDir ) )
					{
						$filePath = $httpFile->Filename;
						$this->uploadedFiles[$httpFile->Filename] = $filePath;
						$attributeData = array( 'from_string' => $filePath );
					}
				}
			break;
			case 'ezgmaplocation':

				$longitude = null; $latitude = null;
				list( $longitude, $latitude ) = explode( ',', $parameterValue );
				
				if ( ( $longitude != null ) && ( $latitude != null ) )
				{
						$attributeData = array( 'from_string' => "1|#$longitude|#$latitude" );
				}

			break;
		}

		if ( $attributeData )
			return array( $attributeIdentifier => $attributeData );
		else
			return array();
	}


	function setHTTPMethod( $method )
	{
		if ( ($method == self::HTTP_METHOD_POST) || ($method == self::HTTP_METHOD_GET) )
			$this->httpMethod = $method;
		else
			$this->httpMethod = self::HTTP_DEFAULT_METHOD;
	}

	function getHTTPParameters( $method = null )
	{

		if ( !$method ) $method = $this->httpMethod;

		$parameters = array();

		foreach ( $this->parameterMappings() as $parameterName => $parameterMapping )
		{
			if (
				( $method == self::HTTP_METHOD_POST )
				&& isset( $parameterMapping['type'] )
				&& ( $parameterMapping['type'] == 'file')
			)
				$parameterValue = self::getHTTPParameter( $parameterName, self::HTTP_METHOD_FILE );
			else
				$parameterValue = self::getHTTPParameter( $parameterName, $method );

			if ( $parameterValue !== null)
				$parameters[$parameterName] = $parameterValue;
		}

		return $parameters;

	}


	function parseText( $text )
	{
		return saImport::HTML2OE( $text );
	}

	function parameterMappings()
	{
		return array();
	}

	function typeMappings()
	{
		return array();
	}

	function existingAttributeFilter()
	{
		return array();
	}


	function generateLocations()
	{
		return array();
	}
	
	function importIDParameters()
	{
		return array();
	}

	function availableLocations( $externalContentTypeID )
	{
		return array();
	}

	function getAllowedRelations( $parameterName, $parameterValue )
	{
		return array();
	}


	function loginUser()
	{
		return null;
	}

	function requireLogin()
	{
		return false;
	}

	
	static function getHTTPParameter( $parameter, $method = self::HTTP_METHOD_POST )
	{

		$http = eZHTTPTool::instance();

		switch ($method)
		{
			case self::HTTP_METHOD_POST:
				if ( $http->hasPostVariable( $parameter ) )
					return $http->postVariable( $parameter );
				else return null;
			break;
			case self::HTTP_METHOD_FILE:
				if ( eZHTTPFile::canFetch( $parameter ) )
					return eZHTTPFile::fetch( $parameter );
				else return null;
			break;
			case self::HTTP_METHOD_GET:
				if ( $http->hasGetVariable( $parameter ) )
					return $http->getVariable( $parameter );
				else return null;
			break;
			default:
				return null;
			break;
		}
	}


	static function returnError( $message, $data = array() )
	{
		return array(
			'status' => self::STATUS_ERROR,
			'status_string' => self::STATUS_STRING_ERROR,
			'message' => $message,
			'data' => $data
		);
	}

	static function returnSuccess( $message, $data = array() )
	{
		return array(
			'status' => self::STATUS_SUCCESS,
			'status_string' => self::STATUS_STRING_SUCCESS,
			'message' => $message,
			'data' => $data
		);
	}

	var $httpMethod = self::HTTP_DEFAULT_METHOD;

	var $parameters = array();
	var $parametersParsed = false;
	var $externalContentTypeID = null;

	var $uploadedFiles = array();

	var $isMainLocationDefined = false;
	var $firstLocationNode = null;

	var $contentClass = null;
	var $contentObject = null;
	var $parsedAttributes = array();
	var $parsedLocations = array();

	var $username = '';
	var $password = '';
	var $user = null;
}

?>
