<?php

/**
 * Created by PhpStorm.
 * User: Hashashiyyin
 * Date: 17/05/16
 * Time: 13:11
 *
 * @property int id
 */
class Engines_MMT extends Engines_AbstractEngine {

    protected $_config = [
            'segment'        => null,
            'translation'    => null,
            'newsegment'     => null,
            'newtranslation' => null,
            'source'         => null,
            'target'         => null,
            'langpair'       => null,
            'email'          => null,
            'keys'           => null,
            'mt_context'     => null,
            'id_user'        => null
    ];

    /**
     * @var array
     */
    protected $_head_parameters = [];

    /**
     * @var bool
     */
    protected $_skipAnalysis = true;

    public function __construct( $engineRecord ) {

        parent::__construct( $engineRecord );

        if ( $this->engineRecord->type != "MT" ) {
            throw new Exception( "Engine {$this->engineRecord->id} is not a MT engine, found {$this->engineRecord->type} -> {$this->engineRecord->class_load}" );
        }

        $this->_head_parameters = [
                'MyMemory-License' => $this->engineRecord->extra_parameters[ 'MyMemory-License' ],
                'User_id'          => $this->engineRecord->extra_parameters[ 'User_id' ],
                'Platform_type'    => "MateCat",
                'Platform_name'    => "translated_matecat",
                'Platform_version' => INIT::MATECAT_USER_AGENT . INIT::$BUILD_NUMBER,
                'Plugin_version'   => "1.0"
        ];
    }

    protected function _setHeader(){
        if( !isset( $this->curl_additional_params[ CURLOPT_HTTPHEADER ] ) ){
            $this->_setAdditionalCurlParams( [
                            CURLOPT_HTTPHEADER     => [
                                    "PluginHeader: " . json_encode( $this->_head_parameters )
                            ],
                            CURLOPT_SSL_VERIFYPEER => false,
                    ]
            );
        }
    }

    /**
     * @param       $url
     * @param array $curl_options
     *
     * @return array|bool|null|string
     */
    public function _call( $url, Array $curl_options = [] ) {
        $this->_setHeader();
        return parent::_call( $url, $curl_options );
    }

    /**
     * MMT exception name from tag_projection call
     * @see Engines_MMT::_decode
     */
    const LanguagePairNotSupportedException = 1;

    protected static $_supportedExceptions = [
            'LanguagePairNotSupportedException' => self::LanguagePairNotSupportedException
    ];


    public function get( $_config ) {

        $parameters                 = [];
        $parameters[ 'q' ]          = $this->_preserveSpecialStrings( $_config[ 'segment' ] );
        $parameters[ 'langpair' ]   = $_config[ 'source' ] . "|" . $_config[ 'target' ];
        $parameters[ 'de' ]         = @$_config[ 'email' ];
        $parameters[ 'mt_context' ] = @$_config[ 'mt_context' ];

        if ( !empty( $_config[ 'keys' ] ) ) {
            if ( !is_array( $_config[ 'keys' ] ) ) {
                $_config[ 'keys' ] = array( $_config[ 'keys' ] );
            }
            $parameters[ 'keys' ] = implode( ",", $_config[ 'keys' ] );
        }

        $this->call( "translate_relative_url", $parameters );

        return $this->result;

    }

    public function set( $_config ) {

        $parameters               = [];
        $parameters[ 'seg' ]      = $_config[ 'segment' ];
        $parameters[ 'tra' ]      = $_config[ 'translation' ];
        $parameters[ 'langpair' ] = $_config[ 'source' ] . "|" . $_config[ 'target' ];
        $parameters[ 'de' ]       = $_config[ 'email' ];

        if ( !empty( $_config[ 'keys' ] ) ) {
            if ( !is_array( $_config[ 'keys' ] ) ) {
                $_config[ 'keys' ] = array( $_config[ 'keys' ] );
            }
            $parameters[ 'keys' ] = implode( ",", $_config[ 'keys' ] );
        }

        $this->call( "contribute_relative_url", $parameters );

        if ( $this->result->responseStatus != "200" ) {
            return false;
        }

        return true;

    }

    public function update( $_config ) {

        $parameters               = array();
        $parameters[ 'seg' ]      = $_config[ 'segment' ];
        $parameters[ 'tra' ]      = $_config[ 'translation' ];
        $parameters[ 'newseg' ]   = $_config[ 'newsegment' ];
        $parameters[ 'newtra' ]   = $_config[ 'newtranslation' ];
        $parameters[ 'langpair' ] = $_config[ 'source' ] . "|" . $_config[ 'target' ];

        if ( !empty( $_config[ 'id_user' ] ) ) {
            if ( !is_array( $_config[ 'id_user' ] ) ) {
                $_config[ 'id_user' ] = array( $_config[ 'id_user' ] );
            }
            $parameters[ 'key' ] = implode( ",", $_config[ 'id_user' ] );
        }

        $this->call( "update_relative_url", $parameters, true );

        if ( $this->result->responseStatus != "200" ) {
            return false;
        }

        return true;

    }

    public function delete( $_config ) {
        throw new DomainException( "Method " . __FUNCTION__ . " not implemented." );
    }

    /**
     * @param      $file
     * @param      $key
     * @param bool $name
     *
     * @return mixed
     */
    public function import( $file, $key, $name = false ) {

        $postFields = array(
                'tmx'  => "@" . realpath( $file ),
                'name' => $name
        );

        $postFields[ 'key' ] = trim( $key );

        if ( version_compare(PHP_VERSION, '5.5.0') >= 0 ) {
            /**
             * Added in PHP 5.5.0 with FALSE as the default value.
             * PHP 5.6.0 changes the default value to TRUE.
             */
            $options[CURLOPT_SAFE_UPLOAD] = false;
            $this->_setAdditionalCurlParams($options);
        }


        $this->call( "tmx_import_relative_url", $postFields, true );

        return $this->result;
    }

    /**
     *
     * @param $file \SplFileObject
     * @param $langPairs array
     *
     * @throws Exception
     * @return mixed
     */
    public function getContext( \SplFileObject $file, $langPairs ) {

        $fileName = $file->getRealPath();
        $file->rewind();

        $fp_out = gzopen( "$fileName.gz", 'wb9' );

        if( !$fp_out ){
            $fp_out = null;
            $file = null;
            @unlink( $fileName );
            @unlink( "$fileName.gz" );
            throw new RuntimeException( 'IOException. Unable to create temporary file.' );
        }

        while ( ! $file->eof() ) {
            gzwrite( $fp_out, $file->fgets() );
        }

        $file = null;
        gzclose( $fp_out );

        $postFields = [
                'content'             => "@" . realpath( "$fileName.gz" ),
                'content_compression' => 'gzip',
                'langpairs'           => implode( ",", $langPairs ),
        ];

        if ( version_compare( PHP_VERSION, '5.5.0' ) >= 0 ) {
            /**
             * Added in PHP 5.5.0 with FALSE as the default value.
             * PHP 5.6.0 changes the default value to TRUE.
             */
            $options[ CURLOPT_SAFE_UPLOAD ] = false;
            $this->_setAdditionalCurlParams( $options );
        }

        $this->call( "context_get", $postFields, true );

        @unlink( $fileName );
        @unlink( "$fileName.gz" );

        if( $this->result->responseStatus != 200 ){
            throw new RuntimeException( $this->result->responseDetails );
        }

        $plainContexts = array_fill_keys( array_keys( $this->result->responseData ), null );
        foreach( $this->result->responseData as $languagePair => $context ){
            foreach( $context as $contextKey => $contextValue ){
                $plainContexts[ $languagePair ] .= $contextKey . ":" . $contextValue . ",";
            }
            $plainContexts[ $languagePair ] = rtrim( $plainContexts[ $languagePair ], "," );
        }

        return $plainContexts;

    }

    /**
     * Call to check the license key validity
     * @return Engines_Results_MMT_MT
     */
    public function checkAccount(){
        $this->call( 'api_key_check_auth_url' );
        return $this->result;
    }

    /**
     * Activate the account and also update/add keys to User MMT data
     *
     * @param $keyList TmKeyManagement_MemoryKeyStruct[]
     *
     * @return mixed
     */
    public function activate( Array $keyList ){

        $_config = [];
        foreach ( $keyList as $p => $kStruct ){
            $_config[ $p ][ 'id' ] = $kStruct->tm_key->key;
            $_config[ $p ][ 'description' ] = $kStruct->tm_key->name;
        }

        $this->call( 'user_update_activate', $_config, true, true );
        return $this->result;

    }

    /**
     * @param $rawValue
     *
     * @return Engines_Results_AbstractResponse
     */
    protected function _decode( $rawValue ) {

        $args         = func_get_args();
        $functionName = $args[ 2 ];

        if ( is_string( $rawValue ) ) {
            $decoded = json_decode( $rawValue, true );
        } else {

            if ( $rawValue[ 'responseStatus' ] >= 400 ){
                $_rawValue = json_decode( $rawValue[ 'error' ][ 'response' ], true );
                foreach( self::$_supportedExceptions as $exception => $code ){
                    if( stripos( $rawValue[ 'error' ][ 'response' ], $exception ) !== false ){
                        $_rawValue[ 'error' ][ 'code' ] = @constant( 'self::' . $rawValue[ 'error' ][ 'type' ] );
                        break;
                    }
                }
                $rawValue = $_rawValue;
            }

            $decoded = $rawValue; // already decoded in case of error

        }

        $result_object = [];

        switch ( $functionName ) {
            case 'tags_projection' :
                $result_object = Engines_Results_MMT_TagProjectionResponse::getInstance( $decoded );
                break;
            case 'user_update_activate':
            case 'context_get':
            case 'contribute_relative_url':
            case 'update_relative_url':
            case 'api_key_check_auth_url':
            case 'tmx_import_relative_url':
                $result_object = Engines_Results_MMT_MT::getInstance( $decoded );
                break;
            case 'translate_relative_url':
                if( !empty( $decoded[ 'responseData' ][ 'translatedText' ] ) ){
                    $result_object = Engines_Results_MMT_MT::getInstance( $decoded );
                    $result_object = ( new Engines_Results_MyMemory_Matches(
                            $this->_resetSpecialStrings( $args[ 1 ][ 'q' ] ),
                            $result_object->translatedText,
                            100 - $this->getPenalty() . "%",
                            "MT-" . $this->getName(),
                            date( "Y-m-d" )
                    ) )->get_as_array();
                }
                break;
            default:
                //this case should not be reached
                $result_object = Engines_Results_MMT_MT::getInstance( [
                        'error' => [
                                'code'      => -1100,
                                'message'   => " Unknown Error.",
                                'response'  => " Unknown Error." // Some useful info might still be contained in the response body
                        ],
                        'responseStatus'    => 400
                ] ); //return generic error
                break;
        }

        return $result_object;

    }

    /**
     * TODO FixMe whit the url parameter and method extracted from engine record on the database
     * when MyMemory TagProjection will be public
     *
     * @param $config
     * @return Engines_Results_MMT_TagProjectionResponse
     */
    public function getTagProjection( $config ){

        $parameters           = array();
        $parameters[ 's' ]    = $config[ 'source' ];
        $parameters[ 't' ]    = $config[ 'target' ];
        $parameters[ 'hint' ] = $config[ 'suggestion' ];

        /*
         * For now override the base url and the function params
         */
        $this->engineRecord[ 'base_url' ] = 'http://149.7.212.129:10000';
        $this->engineRecord->others[ 'tags_projection' ] = 'tags-projection/' . $config[ 'source_lang' ] . "/" . $config[ 'target_lang' ] . "/";

        $this->call( 'tags_projection', $parameters );

        return $this->result;

    }

}