<?php

class saMSSQL {

	static $MSSQLConnection = null;
	private static $connectionObject = null;
	
	static function instance( $serverName, $userName, $password, $database )
	{
		if ( !self::$connectionObject )
			self::$connectionObject = new self( $serverName, $userName, $password, $database );
		
		return self::$connectionObject;
		
	}

	function __construct( $serverName, $userName, $password, $database )
	{
		$this->connect( $serverName, $userName, $password, $database );
	}
	
	function connect( $serverName, $userName, $password, $database )
	{
		self::$MSSQLConnection = mssql_connect( $serverName, $userName, $password );
		
		if ( !self::$MSSQLConnection )
			return false;
		
		if ( !mssql_select_db( $database, self::$MSSQLConnection ) )
			return false;

		return true;
	}

	function select( $fields, $tables, $where = '', $orderBy = '', $limit = '')
	{
		$fields = self::escapeFields( $fields );

		$fieldArray = array();

		foreach ( $fields as $value )
			$fieldsArray[] = $value;

		$query = "SELECT " . implode( ", ", $fieldsArray ) . " FROM $tables";

		if ( $where ) $query .= " WHERE $where";
		if ( $orderBy ) $query .= " ORDER BY $orderBY";
		// TODO: implement limit
		
		return $this->query( $query );
	}

	function insert( $tables, $fields )
	{
		$fields = self::escapeFields( $fields );
		
		$fieldNames = array();
		$fieldValues = array();
		
		foreach ( $fields as $key => $value )
		{
			$fieldNames[] = "$key";
			$fieldValues[] = "'$value'";
		}
					 
		$query = "INSERT INTO $tables (" . implode( ",", $fieldNames ) . ") VALUES (" . implode( ",", $fieldValues ) . ")";
		
		return $this->query( $query );
	}

	function update( $tables, $fields, $where = '', $orderBy = '' )
	{
		$fields = self::escapeFields( $fields );
		
		$fieldArray = array();
		foreach ( $fields as $key => $value )
			$fieldsArray[] = "$key = '$value'";
		 
		$query = "UPDATE $tables SET " . implode( ",", $fieldsArray);
		
		if ( $where ) $query .= " WHERE $where";
		if ( $orderBy ) $query .= " ORDER BY $orderBY";
		
		return $this->query( $query );
	}
	
	function delete( $tables, $where  )
	{
		$query = "DELETE FROM $tables WHERE $where";
		return $this->query( $query );
	}

	function query( $query )
	{
		if ( !self::$MSSQLConnection )
			return null;

//echo "$query\n";

		$result = mssql_query( $query, self::$MSSQLConnection );
		
		if ( is_bool($result) ) return $result;
		
		$rows = array();
		
		while ( $row = mssql_fetch_assoc( $result ) )
			$rows[] = $row; 

		mssql_free_result( $result );
				
		return $rows;

	}
	
	static function escapeFields( $fields )
	{
		foreach ( $fields as $key => &$value )
			$value = self::escape( $value );
		
		return $fields;		
	}

	static function escape( $value )
	{
		$value = str_replace( "'", "''", $value );
		
		return $value;
	}
}

?>