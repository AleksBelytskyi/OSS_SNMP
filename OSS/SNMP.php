<?php

/*
    Copyright (c) 2012, Open Source Solutions Limited, Dublin, Ireland
    All rights reserved.

    Contact: Barry O'Donovan - barry (at) opensolutions (dot) ie
             http://www.opensolutions.ie/

    This file is part of the OSS_SNMP package.

    Redistribution and use in source and binary forms, with or without
    modification, are permitted provided that the following conditions are met:

        * Redistributions of source code must retain the above copyright
          notice, this list of conditions and the following disclaimer.
        * Redistributions in binary form must reproduce the above copyright
          notice, this list of conditions and the following disclaimer in the
          documentation and/or other materials provided with the distribution.
        * Neither the name of Open Source Solutions Limited nor the
          names of its contributors may be used to endorse or promote products
          derived from this software without specific prior written permission.

    THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
    ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
    WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
    DISCLAIMED. IN NO EVENT SHALL <COPYRIGHT HOLDER> BE LIABLE FOR ANY
    DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
    (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
    LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
    ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
    (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
    SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/

namespace OSS;


spl_autoload_register( function( $class ) {
    if( substr( $class, 0, 4 ) == 'OSS\\' )
    {
        $class = str_replace( '\\', '/', $class );
        require( dirname( __FILE__ ) . '/../' . $class . '.php' );
    }
});


/**
 * A class for performing SNMP V2 queries and processing the results.
 *
 * @copyright Copyright (c) 2012, Open Source Solutions Limited, Dublin, Ireland
 * @author Barry O'Donovan <barry@opensolutions.ie>
 */
class SNMP
{
    /** @type string The SNMP community to use when polling SNMP services. Defaults to 'public' by the constructor. */
    protected $_community;

    /**
     * The SNMP host to query. Defaults to '127.0.0.1'
     * @var string The SNMP host to query. Defaults to '127.0.0.1' by the constructor.
     */
    protected $_host;

    /**
     * The SNMP query timeout value (microseconds). Default: 1000000
     * @var int The SNMP query timeout value (microseconds). Default: 1000000
     */
    protected $_timeout = 1000000;

    /**
     * The SNMP query retry count. Default: 5
     * @var int The SNMP query retry count. Default: 5
     */
    protected $_retry = 5;


    /**
     * A variable to hold the last unaltered result of an SNMP query
     * @var mixed The last unaltered result of an SNMP query
     */
    protected $_lastResult = null;


    /**
     * An array to store processed results - a temporary cache
     * @var array An array to store processed results - a temporary cache
     */
    protected $_resultCache;

    /**
     * Set to true to disable local cache lookup and force SNMP queries
     *
     * Results are still stored. If you need to force a SNMP query, you can:
     *
     * $snmp = new OSS\SNMP( ... )'
     * ...
     * $snmp->disableCache();
     * $snmp->get( ... );
     * $snmp->enableCache();
     */
    protected $_disableCache = false;
    /*
     * SNMP output constants to mirror those of PHP
     */
    const OID_OUTPUT_FULL    = SNMP_OID_OUTPUT_FULL;
    const OID_OUTPUT_NUMERIC = SNMP_OID_OUTPUT_NUMERIC;


    /**
     * Definition of an SNMP return type 'TruthValue'
     */
    const SNMP_TRUTHVALUE_TRUE  = 1;

    /**
     * Definition of an SNMP return type 'TruthValue'
     */
    const SNMP_TRUTHVALUE_FALSE = 2;

    /**
     * PHP equivalents of SNMP return type TruthValue
     *
     * @var array PHP equivalents of SNMP return type TruthValue
     */
    public static $SNMP_TRUTHVALUES = array(
        self::SNMP_TRUTHVALUE_TRUE  => true,
        self::SNMP_TRUTHVALUE_FALSE => false
    );

    /**
     * The constructor.
     *
     * @param string $host The target host for SNMP queries.
     * @param string $community The community to use for SNMP queries.
     * @return OSS_SNMP An instance of $this (for fluent interfaces)
     */
    public function __construct( $host = '127.0.0.1', $community = 'public' )
    {
        $this->_resultCache = array();

        return $this->setHost( $host )
                    ->setCommunity( $community )
                    ->setOidOutputFormat( self::OID_OUTPUT_NUMERIC );
    }


    /**
     * Proxy to the snmp2_real_walk command
     *
     * @param string $oid The OID to walk
     * @return array The results of the walk
     */
    public function realWalk( $oid )
    {
        return $this->_lastResult = snmp2_real_walk( $this->getHost(), $this->getCommunity(), $oid, $this->getTimeout(), $this->getRetry() );
    }


    /**
     * Get a single SNMP value
     *
     * @param string $oid The OID to get
     * @return mixed The resultant value
     */
    public function get( $oid )
    {
        if( $this->cache() && isset( $this->_resultCache[$oid] ) )
            return $this->_resultCache[$oid];

        $this->_lastResult = snmp2_get( $this->getHost(), $this->getCommunity(), $oid, $this->getTimeout(), $this->getRetry() );

        return $this->_resultCache[$oid] = $this->parseSnmpValue( $this->_lastResult );
    }

    /**
     * Get indexed SNMP values (first degree)
     *
     * Walks the SNMP tree returning an array of key => value pairs.
     *
     * This is a first degree walk and it will throw an exception if there is more that one degree of values.
     *
     * I.e. the following query with sample results:
     *
     * walk1d( '.1.0.8802.1.1.2.1.3.7.1.4' )
     *
     *       .1.0.8802.1.1.2.1.3.7.1.4.1 = STRING: "GigabitEthernet1/0/1"
     *       .1.0.8802.1.1.2.1.3.7.1.4.2 = STRING: "GigabitEthernet1/0/2"
     *       .1.0.8802.1.1.2.1.3.7.1.4.3 = STRING: "GigabitEthernet1/0/3"
     *       .....
     *
     * would yield an array:
     *
     *      1 => GigabitEthernet1/0/1
     *      2 => GigabitEthernet1/0/2
     *      3 => GigabitEthernet1/0/3
     *
     * @param string $oid The OID to walk
     * @return array The resultant values
     */
    public function walk1d( $oid )
    {
        if( $this->cache() && isset( $this->_resultCache[$oid] ) )
            return $this->_resultCache[$oid];

        $this->_lastResult = snmp2_real_walk( $this->getHost(), $this->getCommunity(), $oid, $this->getTimeout(), $this->getRetry() );

        $result = array();

        $oidPrefix = null;
        foreach( $this->_lastResult as $_oid => $value )
        {
            if( $oidPrefix !== null && $oidPrefix != substr( $_oid, 0, strrpos( $_oid, '.' ) ) )
                throw new Exception( 'Requested OID tree is not a first degree indexed SNMP value' );
            else
                $oidPrefix = substr( $_oid, 0, strrpos( $_oid, '.' ) );

            $result[ substr( $_oid, strrpos( $_oid, '.' ) + 1 ) ] = $this->parseSnmpValue( $value );
        }

        return $this->_resultCache[$oid] = $result;
    }

    /**
     * Get indexed SNMP values where the array key is the given position of the OID
     *
     * I.e. the following query with sample results:
     *
     * subOidWalk( '.1.3.6.1.4.1.9.9.23.1.2.1.1.9', 15 )
     *
     *
     *       .1.3.6.1.4.1.9.9.23.1.2.1.1.9.10101.5 = Hex-STRING: 00 00 00 01
     *       .1.3.6.1.4.1.9.9.23.1.2.1.1.9.10105.2 = Hex-STRING: 00 00 00 01
     *       .1.3.6.1.4.1.9.9.23.1.2.1.1.9.10108.4 = Hex-STRING: 00 00 00 01
     *
     * would yield an array:
     *
     *      10101 => Hex-STRING: 00 00 00 01
     *      10105 => Hex-STRING: 00 00 00 01
     *      10108 => Hex-STRING: 00 00 00 01
     *
     * @param string $oid The OID to walk
     * @param int $position The position of the OID to use as the key
     * @return array The resultant values
     */
    public function subOidWalk( $oid, $position )
    {
        if( $this->cache() && isset( $this->_resultCache[$oid] ) )
            return $this->_resultCache[$oid];

        $this->_lastResult = snmp2_real_walk( $this->getHost(), $this->getCommunity(), $oid, $this->getTimeout(), $this->getRetry() );

        $result = array();

        foreach( $this->_lastResult as $_oid => $value )
        {
            $oids = explode( '.', $_oid );

            $result[ $oids[ $position] ] = $this->parseSnmpValue( $value );
        }

        return $this->_resultCache[$oid] = $result;
    }


    /**
     * Parse the result of an SNMP query into a PHP type
     *
     * For example, [STRING: "blah"] is parsed to a PHP string containing: blah
     *
     * @param string $v The value to parse
     * @return mixed The parsed value
     * @throws Exception
     */
    public function parseSnmpValue( $v )
    {
        $type = substr( $v, 0, strpos( $v, ':' ) );
        $value = trim( substr( $v, strpos( $v, ':' ) + 1 ) );

        switch( $type )
        {
            case 'STRING':
                if( substr( $value, 0, 1 ) == '"' )
                    $rtn = (string)substr( substr( $value, 1 ), 0, -1 );
                else
                    $rtn = (string)$value;
                break;

            case 'INTEGER':
                if( !is_numeric( $value ) )
                    $rtn = (int)substr( substr( $value, strpos( $value, '(' ) + 1 ), 0, -1 );
                else
                    $rtn = (int)$value;
                break;

            case 'Gauge32':
                $rtn = (int)$value;
                break;

            case 'Hex-STRING':
                $rtn = (string)implode( '', explode( ' ', $value ) );
                break;

            default:
                throw new Exception( "ERR: Unhandled SNMP return type: $type\n" );
        }

        return $rtn;
    }

    /**
     * Utility function to convert TruthValue SNMP responses to true / false
     *
     * @param integer $value The TruthValue ( 1 => true, 2 => false) to convert
     * @return boolean
     */
    public static function ppTruthValue( $value )
    {
        if( is_array( $value ) )
            foreach( $value as $k => $v )
                $value[$k] = self::$SNMP_TRUTHVALUES[ $v ];
        else
            $value = self::$SNMP_TRUTHVALUES[ $value ];

        return $value;
    }

    /**
     * Utility function to translate one value(s) to another via an associated array
     *
     * I.e. all elements '$value' will be replaced with $translator( $value ) where
     * $translator is an associated array.
     *
     * Huh? Just read the code below!
     *
     * @param mixed $values A scalar or array or values to translate
     * @param array $translator An associated array to use to translate the values
     * @return mixed The translated scalar or array
     */
    public static function translate( $values, $translator )
    {
        if( !is_array( $values ) )
            return $translator[ $values ];

        foreach( $values as $k => $v )
            $values[$k] = $translator[ $v ];

        return $values;
    }

    /**
     * Sets the output format for SNMP queries.
     *
     * Should be one of the class OID_OUTPUT_* constants
     *
     * @param int $f The fomat to use
     * @return OSS_SNMP An instance of $this (for fluent interfaces)
     */
    public function setOidOutputFormat( $f )
    {
        snmp_set_oid_output_format( $f );
        return $this;
    }


    /**
     * Sets the target host for SNMP queries.
     *
     * @param string $h The target host for SNMP queries.
     * @return OSS_SNMP An instance of $this (for fluent interfaces)
     */
    public function setHost( $h )
    {
        $this->_host = $h;

        // clear the temporary result cache and last result
        $this->_lastResult = null;
        unset( $this->_resultCache );
        $this->_resultCache = array();

        return $this;
    }

    /**
     * Returns the target host as currently configured for SNMP queries
     *
     * @return string The target host as currently configured for SNMP queries
     */
    public function getHost()
    {
        return $this->_host;
    }

    /**
     * Sets the community string to use for SNMP queries.
     *
     * @param string $c The community to use for SNMP queries.
     * @return OSS_SNMP An instance of $this (for fluent interfaces)
     */
    public function setCommunity( $c )
    {
        $this->_community = $c;
        return $this;
    }

    /**
     * Returns the community string currently in use.
     *
     * @return string The community string currently in use.
     */
    public function getCommunity()
    {
        return $this->_community;
    }

    /**
     * Sets the timeout to use for SNMP queries (microseconds).
     *
     * @param int $t The timeout to use for SNMP queries (microseconds).
     * @return OSS_SNMP An instance of $this (for fluent interfaces)
     */
    public function setTimeout( $t )
    {
        $this->_timeout = $t;
        return $this;
    }

    /**
     * Returns the SNMP query timeout (microseconds).
     *
     * @return int The the SNMP query timeout (microseconds)
     */
    public function getTimeout()
    {
        return $this->_timeout;
    }

    /**
     * Sets the SNMP query retry count.
     *
     * @param int $r The SNMP query retry count
     * @return OSS_SNMP An instance of $this (for fluent interfaces)
     */
    public function setRetry( $r )
    {
        $this->_retry = $r;
        return $this;
    }

    /**
     * Returns the SNMP query retry count
     *
     * @return string The SNMP query retry count
     */
    public function getRetry()
    {
        return $this->_retry;
    }

    /**
     * Returns the unaltered original last SNMP result
     *
     * @return mixed The unaltered original last SNMP result
     */
    public function getLastResult()
    {
        return $this->_lastResult;
    }

    /**
     * Returns the internal result cache
     *
     * @return array The internal result cache
     */
    public function getResultCache()
    {
        return $this->_resultCache;
    }


    /**
     * Disable lookups of the local cache
     *
     * @return SNMP An instance of this for fluent interfaces
     */
    public function disableCache()
    {
        $this->_disableCache = true;
        return $this;
    }


    /**
     * Enable lookups of the local cache
     *
     * @return SNMP An instance of this for fluent interfaces
     */
    public function enableCache()
    {
        $this->_disableCache = false;
        return $this;
    }

    /**
     * Query whether we are using the cache or not
     *
     * @return boolean True of the local lookup cache is enabled. Otherwise false.
     */
    public function cache()
    {
        return !$this->_disableCache;
    }


    /**
     * Magic method for generic function calls
     *
     * @param string $method
     * @param array $args
     * @throws Exception
     */
    public function __call( $method, $args )
    {
        if( substr( $method, 0, 3 ) == 'use' )
            return $this->useExtension( substr( $method, 3 ), $args );

        throw new Exception( "ERR: Unknown method requested in magic __call(): $method\n" );
    }


    /**
     * This is the MIB Extension magic
     *
     * Calling $this->useXXX_YYY_ZZZ()->fn() will instantiate
     * an extension MIB class is the given name and this $this SNMP
     * instance and then call fn().
     *
     * See the examples for more information.
     *
     * @param string $mib The extension class to use
     * @param array $args
     * @return \OSS\SNMP\MIBS
     */
    public function useExtension( $mib, $args )
    {
        $mib = '\\OSS\\SNMP\\MIBS\\' . str_replace( '_', '\\', $mib );
        $m = new $mib();
        $m->setSNMP( $this );
        return $m;
    }

}


