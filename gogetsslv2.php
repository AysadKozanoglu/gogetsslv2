<?php
/**
 * GoGetSsl Extended Module
 *
 * @package blesta
 * @subpackage blesta.components.modules.gogetsslv2
 * @author Phillips Data, Inc. 
 * @author Full Ambit Networks (orginal)
 * @author Luke Hardiman, Koha Technologies LTD
 * @copyright Copyright (c) 2015, Luke Hardiman
 * @license https://raw.githubusercontent.com/lukesUbuntu/gogetsslv2/master/LICENSE
 * @link http://kohatech.co.nz
 */

/*
 * @todo Still have language file to update
 * @todo Clean up some of the template files
 * @todo Generate CSR download key options need to be linked in.
 * @todo Fix required install field Title,International Phone number
 * @todo Display CSR,PKEY on client install page as download option
 * @todo Remember form filled content when swapping between CSR Generating to install client tab
 * @todo Fix when submitting install to show blesta loading.
 * @todo final code clean up
 * @todo Add Administration re-issuing of certificate options.
 * @todo Add option to send out email after installation has been completed of cert
 * @todo when loading re-issue when loading email need to show loading blesta screen
 * @todo if order is still pending, then re-issue tab should possible be disabled?\
 * @todo on stable release complete finish logging & parsing of api response
 */
class Gogetsslv2 extends Module
{

    /**
     * @var string The version of this module
     */
    private static $version = "1.1.0";
    /**
     * @var string The authors of this module
     */
    private static $authors = array(
        array('name' => "Modified : Luke Hardiman", 'url' => "http://kohatech.co.nz"),
        array('name' => "Phillips Data, Inc.", 'url' => "http://www.blesta.com")
    );

    /**
     * Initializes the module
     */
    public function __construct()
    {
        // Load components required by this module
        Loader::loadComponents($this, array("Input"));

        // Load the language required by this module
        Language::loadLang("gogetsslv2", null, dirname(__FILE__) . DS . "language" . DS);

        //load our config file
        Configure::load("gogetsslv2", dirname(__FILE__) . DS . "config" . DS);
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
    }

    /**
     * Initializes my little library helper that ive been using with blesta modules
     *
     * @return my_module_lib instance
     */
    private $my_module_lib = false;

    public function getLib()
    {
        if (!$this->my_module_lib) {
            Loader::load(dirname(__FILE__) . DS . "libs" . DS . "my_module_lib.php");
            $this->my_module_lib = new my_module_lib();
        }

        return $this->my_module_lib;
    }
    /**
     * Initializes my little ajax handler
     *
     * @return my_module_lib instance
     */
    //private $gogetssl_ajax_calls = false;

    /**
     * Returns the name of this module
     *
     * @return string The common name of this module
     */
    public function getName()
    {
        return Language::_("GoGetSSLv2.name", true);
    }

    /**
     * Returns the version of this module
     *
     * @return string The current version of this module
     */
    public function getVersion()
    {
        return self::$version;
    }

    /**
     * Returns the name and URL for the authors of this module
     *
     * @return array A numerically indexed array that contains an array with key/value pairs for 'name' and 'url', representing the name and URL of the authors of this module
     */
    public function getAuthors()
    {
        return self::$authors;
    }

    /**
     * Returns the value used to identify a particular service
     *
     * @param stdClass $service A stdClass object representing the service
     * @return string A value used to identify this service amongst other similar services
     */
    public function getServiceName($service)
    {
        foreach ($service->fields as $field) {
            if ($field->key == "gogetssl_fqdn")
                return $field->value;
        }
        return "New";
    }

    /**
     * Returns a noun used to refer to a module row (e.g. "Server", "VPS", "Reseller Account", etc.)
     *
     * @return string The noun used to refer to a module row
     */
    public function moduleRowName()
    {
        return Language::_("GoGetSSLv2.module_row", true);
    }

    /**
     * Returns a noun used to refer to a module row in plural form (e.g. "Servers", "VPSs", "Reseller Accounts", etc.)
     *
     * @return string The noun used to refer to a module row in plural form
     */
    public function moduleRowNamePlural()
    {
        return Language::_("GoGetSSLv2.module_row_plural", true);
    }

    /**
     * Returns a noun used to refer to a module group (e.g. "Server Group", "Cloud", etc.)
     *
     * @return string The noun used to refer to a module group
     */
    public function moduleGroupName()
    {
        return null;
    }

    /**
     * Returns the key used to identify the primary field from the set of module row meta fields.
     * This value can be any of the module row meta fields.
     *
     * @return string The key used to identify the primary field from the set of module row meta fields
     */
    public function moduleRowMetaKey()
    {
        return "gogetssl_name";
    }

    /**
     * Returns the value used to identify a particular package service which has
     * not yet been made into a service. This may be used to uniquely identify
     * an uncreated service of the same package (i.e. in an order form checkout)
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param array $vars An array of user supplied info to satisfy the request
     * @return string The value used to identify this package service
     * @see Module::getServiceName()
     */
    public function getPackageServiceName($packages, array $vars = null)
    {
        if (isset($vars['gogetssl_name']))
            return $vars['gogetssl_name'];
        return null;
    }

    /*
     * @debug mode
     * Debug mode, during testing this will capture some API calls and also help debug calls as i want to capture first instance of the value
     * It will store these into session and will use these instead of requesting new api call to server
     */
    private $debug_mode = true;
    //keeps storage of our responses for debug/inspection
    private function debug($service_fields,$key,$value = false){
        $domain = trim($service_fields->gogetssl_fqdn);
        $key = trim($key);
        if (empty($key))die("key empty debug_mode");

        if (!isset($_SESSION[$key][$domain]) || empty($_SESSION[$key][$domain]))
            $_SESSION[$key][$domain] = $value;

        return  $_SESSION[$key][$domain];
    }
    private function clearDebug($service_fields,$key){
        $domain = trim($service_fields->gogetssl_fqdn);
        $key = trim($key);
        if (empty($key))die("key empty debug_mode");

        unset( $_SESSION[$key][$domain]);
    }
    /**
     * Attempts to validate service info. This is the top-level error checking method. Sets Input errors on failure.
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param array $vars An array of user supplied info to satisfy the request
     * @param boolean False if we are adding a service by default we need to unset some rules
     * @return boolean True if the service validates, false otherwise. Sets Input errors when false.
     */
    public function validateService($package, array $vars = null, $editService = false)
    {


        // Set rules
        $rules = array(
			'gogetssl_approver_email' => array(
				'format' => array(
					'rule' => "isEmail",
					'message' => Language::_("GoGetSSLv2.!error.gogetssl_approver_email.format", true)
				)
			),
			'gogetssl_csr' => array(
				'format' => array(
					'rule' => "isEmpty",
					'negate' => true,
					'message' => Language::_("GoGetSSLv2.!error.gogetssl_csr.format", true)
				)
			),
			'gogetssl_webserver_type' => array(
				'format' => array(
					'rule' => "isEmpty",
					'negate' => true,
					'message' => Language::_("GoGetSSLv2.!error.gogetssl_webserver_type.format", true)
				)
			),
            'gogetssl_fqdn' => array(
                'format' => array(
                    'rule' => "isEmpty",
                    'negate' => true,
                    'message' => Language::_("GoGetSSLv2.!error.gogetssl_fqdn.format", true)
                )
            )
        );
        //if we are adding service we can unset some of our rules but not if we are editing service
        if (!$editService) {
            //as we are NOT editing we are going to unset some rules
            /*
            $rules['gogetssl_fqdn'] = array(
                'format' => array(
                    'rule' => "isEmpty",
                    'negate' => true,
                    'message' => Language::_("GoGetSSLv2.!error.gogetssl_fqdn.format", true)
                )
            );*/
            unset($rules['gogetssl_approver_email']);
            unset($rules['gogetssl_csr']);
            unset($rules['gogetssl_webserver_type']);

        }
        //if we are not validating by email unset email rule
        if (isset($vars['gogetssl_approver_type']) && $vars['gogetssl_approver_type'] != "email")
            unset($rules['gogetssl_approver_email']);

        $this->Input->setRules($rules);
        return $this->Input->validates($vars);
    }


    /**
     * @description Passes a postrequest and returns valid entries for our META row
     * @param $service_fields
     * @param $postRequest
     * @return array
     */
    private function getVarPost($service_fields , $postRequest ){
        
        return array(
                "gogetssl_approver_email"   => $postRequest["gogetssl_approver_email"],
                "gogetssl_fqdn"             => $service_fields->gogetssl_fqdn,
                "gogetssl_webserver_type"   => $postRequest["gogetssl_webserver_type"],
                "gogetssl_csr"              => $postRequest["gogetssl_csr"],
                "gogetssl_approver_type"    => $postRequest["gogetssl_approver_type"],
                "gogetssl_title"            => $postRequest["gogetssl_title"],
                "gogetssl_firstname"        => $postRequest["gogetssl_firstname"],
                "gogetssl_lastname"         => $postRequest["gogetssl_lastname"],
                "gogetssl_address1"         => $postRequest["gogetssl_address1"],
                "gogetssl_address2"         => $postRequest["gogetssl_address2"],
                "gogetssl_city"             => $postRequest["gogetssl_city"],
                "gogetssl_zip"              => $postRequest["gogetssl_zip"],
                "gogetssl_state"            => $postRequest["gogetssl_state"],
                "gogetssl_country"          => $postRequest["gogetssl_country"],
                "gogetssl_email"            => $postRequest["gogetssl_email"],
                "gogetssl_number"           => $postRequest["gogetssl_number"],
                "gogetssl_fax"              => $postRequest["gogetssl_fax"],
                "gogetssl_organization"     => $postRequest["gogetssl_organization"],
                "gogetssl_organization_unit"=> $postRequest["gogetssl_organization_unit"]

        );
    }
    /**
     * Fills SSL data for order API calls from given vars
     *
     * @param stdClass $package The package
     * @param integer $client_id The ID of the client
     * @param mixed $vars Array or object representing user input
     * @param mixed $vars Array or object representing user input
     * @return mixed $postData data from POSTdata
     */
    private function fillSSLDataFrom($package, $client_id, $vars) {
        $vars = (object)$vars;

        $period = 12;
        foreach($package->pricing as $pricing) {

            if ($pricing->id == $vars->pricing_id) {
                if($pricing->period == 'month')
                    $period = $pricing->term;
                elseif($pricing->period == 'year')
                    $period = $pricing->term * 12;
                break;
            }
        }

        $data = array(
            'product_id' => $package->meta->gogetssl_product,
            'csr' => $vars->gogetssl_csr,
            'server_count' => "-1",
            'period' => $period,
            //approval settings
            'approver_email' => $vars->gogetssl_approver_email,
            'webserver_type' => $vars->gogetssl_webserver_type,
            'dcv_method'     => $vars->gogetssl_approver_type,

            'admin_firstname' => $vars->gogetssl_firstname,
            'admin_lastname' => $vars->gogetssl_lastname,
            'admin_phone' => $vars->gogetssl_number,
            'admin_title' => $vars->gogetssl_title,
            'admin_email' => $vars->gogetssl_email,
            'admin_city' => $vars->gogetssl_city,
            'admin_country' => $vars->gogetssl_country,
            'admin_organization' => $vars->gogetssl_organization,
            'admin_fax' => $vars->gogetssl_fax,

            'tech_firstname' => $vars->gogetssl_firstname,
            'tech_lastname' => $vars->gogetssl_lastname,
            'tech_phone' => $vars->gogetssl_number,
            'tech_title' => $vars->gogetssl_title,
            'tech_email' => $vars->gogetssl_email,
            'tech_city' => $vars->gogetssl_city,
            'tech_country' => $vars->gogetssl_country,
            'tech_organization' => $vars->gogetssl_organization,
            'tech_fax' => $vars->gogetssl_fax,

            'org_name' => $vars->gogetssl_organization,
            'org_division' => $vars->gogetssl_organization_unit,
            'org_addressline1' => $vars->gogetssl_address1,
            'org_addressline2' => $vars->gogetssl_address2,
            'org_city' => $vars->gogetssl_city,
            'org_country' => $vars->gogetssl_country,
            'org_phone' => $vars->gogetssl_number,
            'org_postalcode' => $vars->gogetssl_zip,
            'org_region' => $vars->gogetssl_state
        );

        return $data;
    }

    /**
     * Adds the service to the remote server. Sets Input errors on failure,
     * preventing the service from being added.
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param array $vars An array of user supplied info to satisfy the request
     * @param stdClass $parent_package A stdClass object representing the parent service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent service of the service being added (if the current service is an addon service and parent service has already been provisioned)
     * @param string $status The status of the service being added. These include:
     *    - active
     *    - canceled
     *    - pending
     *    - suspended
     * @return array A numerically indexed array of meta fields to be stored for this service containing:
     *    - key The key for this meta field
     *    - value The value for this key
     *    - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function addService($package, array $vars = null, $parent_package = null, $parent_service = null, $status = "pending")
    {

        $this->validateService($package, $vars);
        //we are going to pass the package type as comodo can only do other authentication (dns/http)
        $row = $this->getModuleRow($package->module_row);
        $api = $this->api($row);
        //get the cert type
        $cert_type = $this->isComodoCert($api, $package) ? '1' : '2';


        //Preset our record data for row
        return array(
            //approval rows
            array(
                'key' => "gogetssl_approver_email",
                'value' => '',
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_approver_type",
                'value' => '',
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_fqdn",
                'value' => $vars["gogetssl_fqdn"],
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_webserver_type",
                'value' => '',
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_csr",
                'value' => '',
                'encrypted' => 1
            ),

            array(
                'key' => "gogetssl_orderid",
                'value' => '',
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_title",
                'value' => '',
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_firstname",
                'value' => '',
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_lastname",
                'value' => '',
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_address1",
                'value' => '',
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_address2",
                'value' => '',
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_city",
                'value' => '',
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_zip",
                'value' => '',
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_state",
                'value' => '',
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_country",
                'value' => '',
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_email",
                'value' => '',
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_number",
                'value' => '',
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_fax",
                'value' => '',
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_organization",
                'value' => '',
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_organization_unit",
                'value' => '',
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_issed",
                'value' => false,
                'encrypted' => 0
            ),
            array(
                'key' => "cert_type",
                'value' => $cert_type,
                'encrypted' => 0
            )
        );
    }

    /**
     * Edits the service on the remote server. Sets Input errors on failure,
     * preventing the service from being edited.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $vars An array of user supplied info to satisfy the request
     * @param stdClass $parent_package A stdClass object representing the parent service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent service of the service being edited (if the current service is an addon service)
     * @return array A numerically indexed array of meta fields to be stored for this service containing:
     *    - key The key for this meta field
     *    - value The value for this key
     *    - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function editService($package, $service, array $vars = array(), $parent_package = null, $parent_service = null)
    {
        //define as default installed


        // Validate the service-specific fields
        $this->validateService($package, $vars , true);

        if ($this->Input->errors())
            return;

        $row = $this->getModuleRow($package->module_row);

        $api = $this->api($row);

        $service_fields = $this->serviceFieldsToObject($service->fields);




        //check if we have installed cert yet
        if ($service_fields->gogetssl_issed == false && $vars["use_module"] == "true")
        {
            //check install_cert from gogetssl
            //install cert
            $data = $this->fillSSLDataFrom($package, (isset($vars['client_id']) ? $vars['client_id'] : ""), $vars);

            //force dcv_method for email validation for non comodo certs
            if ($service_fields->cert_type == 2)
                $data['dcv_method'] = "email";

            //if we are not using email auth we need to unset approver email to force transaction
            if ($data['dcv_method'] != "email")
                unset($data['approver_email']);

            //print_r($data);
            //exit;
            $this->log($row->meta->api_username . "|ssl-new-order", serialize($data), "input", true);

            //make the call
            //for testing
            if ($this->debug_mode == false){
                $response = $api->addSSLOrder($data);
            }else{


                if(!isset($_SESSION['addSSLOrder']))
                $_SESSION['addSSLOrder'] =  $api->addSSLOrder($data);;

                $response =  $_SESSION['addSSLOrder'];


                $response = $this->debug($service_fields,'addSSLOrder' ,$response );
            }

            $this->log($row->meta->api_username . "|ssl-new-order-response", serialize($response), "input", true);

                //$api->addSSLOrder($data);


            //$response = $api->addSSLOrder($data);
            $result = $this->parseResponse($response, $row);

            if ($row->meta->sandbox == true && $this->Input->errors()){
                if(preg_match('/Such certificate already exists \(Order ID: (\d+)/',$response['description'], $matches)){
                    $result['order_id'] = $matches[1];
                    $this->Input->setErrors(array());
                }

                //check for errors

            }else{
                //check for errors
                if ($this->Input->errors())
                    return;
            }



            if(empty($result)) {
                return;
            }

            if(isset($result['order_id'])) {
                $order_id = $result['order_id'];
                $this->log($row->meta->api_username . "|ssl-activate", serialize($order_id), "input", true);
                //$this->parseResponse($api->activateSSLOrder($order_id), $row); //depreicated GetOrderStatus

                //Update that we have installed the cert
               $service_fields->gogetssl_issed = true;

            }

        }else  if ($vars["use_module"] == "true" && $service_fields->gogetssl_issed == true) {
            $order_id = $service_fields->gogetssl_orderid;

            $data = array(
                'csr'               => $vars["gogetssl_csr"],
                'approver_email'    => $vars["gogetssl_approver_email"],
                'webserver_type'    => $vars["gogetssl_webserver_type"],
                'dcv_method'        => $vars["gogetssl_approver_type"]
            );

            $this->log($row->meta->api_username . "|ssl-reissue", serialize($data), "input", true);
            $response = $api->reIssueOrder($service_fields->gogetssl_orderid, $data);

            $result = $this->parseResponse($response, $row);
            //this could be due to cert not actually installed or domain/auth method has not been done
            //    [description] => Order can't be processed with reissue procedure!

            if ($this->Input->errors())
                return;

            if (empty($result)) {
                return;
            }

            if (isset($result['order_id'])) {
                $order_id = $result['order_id'];
                $this->log($row->meta->api_username . "|ssl-activate", serialize($order_id), "input", true);
                //$this->parseResponse($api->activateSSLOrder($order_id), $row);
            }
        }


        return $this->ourServiceFields($service_fields, $order_id);
    }

    /**
     * Cancels the service on the remote server. Sets Input errors on failure,
     * preventing the service from being canceled.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param stdClass $parent_package A stdClass object representing the parent service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent service of the service being canceled (if the current service is an addon service)
     * @return mixed null to maintain the existing meta fields or a numerically indexed array of meta fields to be stored for this service containing:
     *    - key The key for this meta field
     *    - value The value for this key
     *    - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function cancelService($package, $service, $parent_package = null, $parent_service = null)
    {
        return null;
    }

    /**
     * Suspends the service on the remote server. Sets Input errors on failure,
     * preventing the service from being suspended.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param stdClass $parent_package A stdClass object representing the parent service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent service of the service being suspended (if the current service is an addon service)
     * @return mixed null to maintain the existing meta fields or a numerically indexed array of meta fields to be stored for this service containing:
     *    - key The key for this meta field
     *    - value The value for this key
     *    - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function suspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        return null;
    }

    /**
     * Unsuspends the service on the remote server. Sets Input errors on failure,
     * preventing the service from being unsuspended.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param stdClass $parent_package A stdClass object representing the parent service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent service of the service being unsuspended (if the current service is an addon service)
     * @return mixed null to maintain the existing meta fields or a numerically indexed array of meta fields to be stored for this service containing:
     *    - key The key for this meta field
     *    - value The value for this key
     *    - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function unsuspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        return null;
    }

    /**
     * Allows the module to perform an action when the service is ready to renew.
     * Sets Input errors on failure, preventing the service from renewing.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param stdClass $parent_package A stdClass object representing the parent service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent service of the service being renewed (if the current service is an addon service)
     * @return mixed null to maintain the existing meta fields or a numerically indexed array of meta fields to be stored for this service containing:
     *    - key The key for this meta field
     *    - value The value for this key
     *    - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function renewService($package, $service, $parent_package = null, $parent_service = null)
    {
        //@todo finish of renew service
        $row = $this->getModuleRow($package->module_row);
        $api = $this->api($row);


        $order_id = '';

        $vars = (isset($vars)) ? $vars : null;

        $service_fields = $this->serviceFieldsToObject($service->fields);

        if ($vars["use_module"] == "true") {
            $data = $this->fillSSLDataFrom($package, $service->client_id, $service_fields);

            $this->log($row->meta->api_username . "|ssl-renew-order", serialize($data), "input", true);
            $result = $this->parseResponse($api->addSSLRenewOrder($data), $row);

            if (empty($result)) {
                return;
            }

            if (isset($result['order_id'])) {
                $order_id = $result['order_id'];
                $this->log($row->meta->api_username . "|ssl-activate", serialize($order_id), "input", true);
                //$this->parseResponse($api->activateSSLOrder($order_id), $row); deprecated use GetOrderStatus
            }
        }

        // Return service fields

    }

    /**
     * Updates the package for the service on the remote server. Sets Input
     * errors on failure, preventing the service's package from being changed.
     *
     * @param stdClass $package_from A stdClass object representing the current package
     * @param stdClass $package_to A stdClass object representing the new package
     * @param stdClass $service A stdClass object representing the current service
     * @param stdClass $parent_package A stdClass object representing the parent service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent service of the service being changed (if the current service is an addon service)
     * @return mixed null to maintain the existing meta fields or a numerically indexed array of meta fields to be stored for this service containing:
     *    - key The key for this meta field
     *    - value The value for this key
     *    - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function changeServicePackage($package_from, $package_to, $service, $parent_package = null, $parent_service = null)
    {
        return null;
    }

    /**
     * Validates input data when attempting to add a package, returns the meta
     * data to save when adding a package. Performs any action required to add
     * the package on the remote server. Sets Input errors on failure,
     * preventing the package from being added.
     *
     * @param array An array of key/value pairs used to add the package
     * @return array A numerically indexed array of meta fields to be stored for this package containing:
     *    - key The key for this meta field
     *    - value The value for this key
     *    - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function addPackage(array $vars = null)
    {

        $this->Input->setRules($this->getPackageRules($vars));

        $meta = array();
        if ($this->Input->validates($vars)) {
            // Return all package meta fields
            foreach ($vars['meta'] as $key => $value) {
                $meta[] = array(
                    'key' => $key,
                    'value' => $value,
                    'encrypted' => 0
                );
            }
        }

        return $meta;
    }

    /**
     * Validates input data when attempting to edit a package, returns the meta
     * data to save when editing a package. Performs any action required to edit
     * the package on the remote server. Sets Input errors on failure,
     * preventing the package from being edited.
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param array An array of key/value pairs used to edit the package
     * @return array A numerically indexed array of meta fields to be stored for this package containing:
     *    - key The key for this meta field
     *    - value The value for this key
     *    - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function editPackage($package, array $vars = null)
    {
        $this->Input->setRules($this->getPackageRules($vars));

        $meta = array();
        if ($this->Input->validates($vars)) {
            // Return all package meta fields
            foreach ($vars['meta'] as $key => $value) {
                $meta[] = array(
                    'key' => $key,
                    'value' => $value,
                    'encrypted' => 0
                );
            }
        }

        return $meta;
    }

    /**
     * Deletes the package on the remote server. Sets Input errors on failure,
     * preventing the package from being deleted.
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function deletePackage($package)
    {
        // Nothing to do
        return null;
    }

    /**
     * Returns the rendered view of the manage module page
     *
     * @param mixed $module A stdClass object representing the module and its rows
     * @param array $vars An array of post data submitted to or on the manage module page (used to repopulate fields after an error)
     * @return string HTML content containing information to display when viewing the manager module page
     */
    public function manageModule($module, array &$vars)
    {
        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View("manage", "default");
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView("components" . DS . "modules" . DS . "gogetsslv2" . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, array("Form", "Html", "Widget"));

        $this->view->set("module", $module);

        return $this->view->fetch();
    }

    /**
     * Returns the rendered view of the add module row page
     *
     * @param array $vars An array of post data submitted to or on the add module row page (used to repopulate fields after an error)
     * @return string HTML content containing information to display when viewing the add module row page
     */
    public function manageAddRow(array &$vars)
    {
        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View("add_row", "default");
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView("components" . DS . "modules" . DS . "gogetsslv2" . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, array("Form", "Html", "Widget"));

        // Set unspecified checkboxes
        if (!empty($vars)) {
            if (empty($vars['sandbox']))
                $vars['sandbox'] = "false";
        }

        $this->view->set("vars", (object)$vars);
        return $this->view->fetch();
    }

    /**
     * Returns the rendered view of the edit module row page
     *
     * @param stdClass $module_row The stdClass representation of the existing module row
     * @param array $vars An array of post data submitted to or on the edit module row page (used to repopulate fields after an error)
     * @return string HTML content containing information to display when viewing the edit module row page
     */
    public function manageEditRow($module_row, array &$vars)
    {
        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View("edit_row", "default");
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView("components" . DS . "modules" . DS . "gogetsslv2" . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, array("Form", "Html", "Widget"));

        if (empty($vars))
            $vars = $module_row->meta;
        else {
            // Set unspecified checkboxes
            if (empty($vars['sandbox']))
                $vars['sandbox'] = "false";
        }

        $this->view->set("vars", (object)$vars);
        return $this->view->fetch();
    }

    /**
     * Adds the module row on the remote server. Sets Input errors on failure,
     * preventing the row from being added.
     *
     * @param array $vars An array of module info to add
     * @return array A numerically indexed array of meta fields for the module row containing:
     *    - key The key for this meta field
     *    - value The value for this key
     *    - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     */
    public function addModuleRow(array &$vars)
    {
        $meta_fields = array("gogetssl_name", "api_username", "api_password", "sandbox");
        $encrypted_fields = array("api_username", "api_password");

        // Set unspecified checkboxes
        if (empty($vars['sandbox']))
            $vars['sandbox'] = "false";

        $this->Input->setRules($this->getRowRules($vars));

        // Validate module row
        if ($this->Input->validates($vars)) {
            // Build the meta data for this row
            $meta = array();
            foreach ($vars as $key => $value) {

                if (in_array($key, $meta_fields)) {
                    $meta[] = array(
                        'key' => $key,
                        'value' => $value,
                        'encrypted' => in_array($key, $encrypted_fields) ? 1 : 0
                    );
                }
            }

            return $meta;
        }
    }

    /**
     * Edits the module row on the remote server. Sets Input errors on failure,
     * preventing the row from being updated.
     *
     * @param stdClass $module_row The stdClass representation of the existing module row
     * @param array $vars An array of module info to update
     * @return array A numerically indexed array of meta fields for the module row containing:
     *    - key The key for this meta field
     *    - value The value for this key
     *    - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     */
    public function editModuleRow($module_row, array &$vars)
    {
        // Same as adding
        return $this->addModuleRow($vars);
    }

    /**
     * Deletes the module row on the remote server. Sets Input errors on failure,
     * preventing the row from being deleted.
     *
     * @param stdClass $module_row The stdClass representation of the existing module row
     */
    public function deleteModuleRow($module_row)
    {
        return null; // Nothing to do
    }

    /**
     * Returns all fields used when adding/editing a package, including any
     * javascript to execute when the page is rendered with these fields.
     *
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object, containg the fields to render as well as any additional HTML markup to include
     */
    public function getPackageFields($vars = null)
    {
        Loader::loadHelpers($this, array("Form", "Html"));

        $fields = new ModuleFields();

        $row = null;
        if (isset($vars->module_group) && $vars->module_group == "") {
            if (isset($vars->module_row) && $vars->module_row > 0) {
                $row = $this->getModuleRow($vars->module_row);
            } else {
                $rows = $this->getModuleRows();
                if (isset($rows[0]))
                    $row = $rows[0];
                unset($rows);
            }
        } else {
            // Fetch the 1st server from the list of servers in the selected group
            $rows = $this->getModuleRows($vars->module_group);

            if (isset($rows[0]))
                $row = $rows[0];
            unset($rows);
        }

        if ($row) {

            $api = $this->api($row);

            $products = $this->getProducts($api, $row);
        } else {
            $products = array();
        }

        // Show nodes, and set javascript field toggles
        $this->Form->setOutput(true);

        // Set the product as a selectable option
        $gogetssl_products = array('' => Language::_("GoGetSSLv2.please_select", true)) + $products;
        $gogetssl_product = $fields->label(Language::_("GoGetSSLv2.package_fields.product", true), "gogetssl_product");
        $gogetssl_product->attach($fields->fieldSelect("meta[gogetssl_product]", $gogetssl_products,
            $this->Html->ifSet($vars->meta['gogetssl_product']), array('id' => "gogetssl_product")));
        $fields->setField($gogetssl_product);
        unset($gogetssl_product);

        return $fields;
    }

    /**
     * Returns an array of key values for fields stored for a module, package,
     * and service under this module, used to substitute those keys with their
     * actual module, package, or service meta values in related emails.
     *
     * @return array A multi-dimensional array of key/value pairs where each key is one of 'module', 'package', or 'service' and each value is a numerically indexed array of key values that match meta fields under that category.
     * @see Modules::addModuleRow()
     * @see Modules::editModuleRow()
     * @see Modules::addPackage()
     * @see Modules::editPackage()
     * @see Modules::addService()
     * @see Modules::editService()
     */
    public function getEmailTags()
    {
        return array(
            'module' => array(),
            'package' => array("gogetssl_product"),
            'service' => array("gogetssl_fqdn")
            /*
             @todo resolve email on after added cert
            'service' => array("gogetssl_approver_email", "gogetssl_fqdn", "gogetssl_webserver_type", "gogetssl_csr",
                "gogetssl_orderid", "gogetssl_title", "gogetssl_firstname", "gogetssl_lastname",
                "gogetssl_address1", "gogetssl_address2", "gogetssl_city", "gogetssl_zip", "gogetssl_state",
                "gogetssl_country", "gogetssl_email", "gogetssl_number", "gogetssl_fax", "gogetssl_organization",
                "gogetssl_organization_unit"
            )*/
        );
    }

    /**
     * Returns array of valid approver E-Mails for domain
     *
     * @param GoGetSslApi $api the API to use
     * @param stdClass $package The package
     * @param string $domain The domain
     * @return array E-Mails that are valid approvers for the domain
     */
    private function getApproverEmails($api, $package, $domain)
    {
        if (empty($domain))
            return array();

        $row = $this->getModuleRow($package->module_row);
        $this->log($row->meta->api_username . "|ssl-domain-emails", serialize($domain), "input", true);

        $gogetssl_approver_emails = array();
        try {
            $gogetssl_approver_emails = $this->parseResponse($api->getDomainEmails($domain), $row);
        } catch (Exception $e) {
            // Error, invalid authorization
            $this->Input->setErrors(array('api' => array('internal' => Language::_("GoGetSSLv2.!error.api.internal", true))));
        }

        $emails = array();
        if ($this->isComodoCert($api, $package) && isset($gogetssl_approver_emails['ComodoApprovalEmails']))
            $emails = $gogetssl_approver_emails['ComodoApprovalEmails'];
        elseif (isset($gogetssl_approver_emails['GeotrustApprovalEmails']))
            $emails = $gogetssl_approver_emails['GeotrustApprovalEmails'];

        $formatted_emails = array();
        foreach ($emails as $email)
            $formatted_emails[$email] = $email;

        return $formatted_emails;
    }

    /**
     * Returns ModuleFields for adding a package
     *
     * @param stdClass $package The package
     * @param stdClass $vars Passed vars
     * @return ModuleFields Fields to display
     */
    private function makeAddFields($package, $vars)
    {
        //return "Sorry this has not been implemented yet. This can be installed via order form will be completed soon.";

        Loader::loadHelpers($this, array("Form", "Html"));

        // Load the API
        //$row = $this->getModuleRow($package->module_row);
       // $api = $this->api($row);

        $fields = new ModuleFields();


        $fields->setHtml("
			<script type=\"text/javascript\">
                $(document).ready(function() {
                    $('#gogetssl_fqdn').change(function() {
						var form = $(this).closest('form');
						$(form).append('<input type=\"hidden\" name=\"refresh_fields\" value=\"true\">');
						$(form).submit();
					});
                });
			</script>
		");

        $gogetssl_fqdn = $fields->label(Language::_("GoGetSSLv2.service_field.gogetssl_fqdn", true), "gogetssl_fqdn");
        $gogetssl_fqdn->attach($fields->fieldText("gogetssl_fqdn", $this->Html->ifSet($vars->gogetssl_fqdn), array('id' => "gogetssl_fqdn")));
        $fields->setField($gogetssl_fqdn);
        unset($gogetssl_fqdn);
        return $fields;

    }

    /**
     * Returns all fields to display to an admin attempting to add a service with the module
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object, containg the fields to render as well as any additional HTML markup to include
     */
    public function getAdminAddFields($package, $vars = null)
    {

        return $this->makeAddFields($package, $vars);
    }

    /**
     * Returns all fields to display to a client attempting to add a service with the module
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object, containg the fields to render as well as any additional HTML markup to include
     */
    public function getClientAddFields($package, $vars = null)
    {
        //return $this->makeAddFields($package, $vars);
        Loader::loadHelpers($this, array("Form", "Html"));
        //We are just going to get domain name we want SSL for
        $fields = new ModuleFields();

        $fields->setHtml("
			<script type=\"text/javascript\">
                $(document).ready(function() {
                    $('#gogetssl_fqdn').change(function() {
						var form = $(this).closest('form');
						$(form).append('<input type=\"hidden\" name=\"refresh_fields\" value=\"true\">');
						$(form).submit();
					});
                });
			</script>
		");

        $gogetssl_fqdn = $fields->label(Language::_("GoGetSSLv2.service_field.gogetssl_fqdn", true), "gogetssl_fqdn");
        $gogetssl_fqdn->attach($fields->fieldText("gogetssl_fqdn", $this->Html->ifSet($vars->gogetssl_fqdn), array('id' => "gogetssl_fqdn")));
        $fields->setField($gogetssl_fqdn);
        unset($gogetssl_fqdn);

        return $fields;
    }

    /**
     * Returns all fields to display to an admin attempting to edit a service with the module
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object, containg the fields to render as well as any additional HTML markup to include
     */
    public function getAdminEditFields($package, $vars = null)
    {
        return new ModuleFields();
    }

    /**
     * Returns all tabs to display to an admin when managing a service whose
     * package uses this module
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @return array An array of tabs in the format of method => title. Example: array('methodName' => "Title", 'methodName2' => "Title2")
     */
    public function getAdminTabs($package)
    {
        return array(
            'tabClientReissueAdmin' => Language::_("GoGetSSLv2.tab_reissue", true),
            'tabClientImport' =>  "Import Order",
        );
    }



    /**
     * Returns all tabs to display to a client when managing a service whose
     * package uses this module
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @return array An array of tabs in the format of method => title. Example: array('methodName' => "Title", 'methodName2' => "Title2")
     */
    public function getClientTabs($package)
    {

        return array(
            'tabClientInstall' => array('name' => Language::_("GoGetSSLv2.tab_install", true), 'icon' => "fa fa-chain"),
            'tabClientReissue' => array('name' => Language::_("GoGetSSLv2.tab_reissue", true), 'icon' => "fa fa-chain-broken"),
            'tabClientGenerateCSR' =>  array('name' => Language::_("GoGetSSLv2.tab_csr_generator", true), 'icon' => "fa fa-file-text-o"),

        );

        //if we can generate CSR add tab    @todo move this as a optional setting for main module
        /*@moved to gogetssl api generate csr
        if (extension_loaded('openssl'))
            $tabs['tabClientCSR'] = array('name' => Language::_("GoGetSSLv2.tab_csr_generator", true), 'icon' => "fa fa-file-text-o");
        return $tabs;
        */
    }
    /**
     * Reissue tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientReissueAdmin($package, $service, array $getRequest = null, array $postRequest = null, array $files = null)
    {
        return "Sorry this is still to be implemented";
    }
    /**
     * Reissue tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientReissue($package, $service, array $getRequest = null, array $postRequest = null, array $files = null)
    {

        if ($service->status == "pending"){
            return "Service still pending, has not been activated yet.";
        }

        $this->view = new View("tab_client_reissue", "default");
        //get our external library helper
        $lib = $this->getLib();

        $this->view->base_uri = $this->base_uri;
        // Load the helpers required for this view
        Loader::loadHelpers($this, array("Form", "Html"));

        // Get the service fields
        $service_fields = $this->serviceFieldsToObject($service->fields);
        $row = $this->getModuleRow($package->module_row);

        //POST CHECK CERT INSTALL
        if (!$service_fields->gogetssl_issed){

            $link = $this->base_uri . "services/manage/" . $this->Html->ifSet($service->id) . "/tabClientInstall/";
            return "Certificate has not been installed yet, please go to <a href=\"$link\">Install Certificate</a>";

        }

        //if ($service_fields->gogetssl_issed) {

            //$cert_install_check = $this->certIsPending($service, $row);
            if ($this->certIsPending($service, $row) == true) {
                //return $cert_install_check;
                return $this->view->fetch();
            }
        //}
        //gogetssl_issed

        $csr_data = $service_fields->gogetssl_csr;

        //render the csr from get install
        /*@removed this is now been generated by gogetssl api
        if ($lib->isGetRequest($getRequest,array('install_csr')) != false){
            $csr_data = $lib->getRequest($getRequest,'csr_data');

            if($csr_data != false)
                $csr_data = base64_decode($csr_data);

        }*/
        //$module_row = $this->getModuleRow($package->module_row);


        //set our variables in template
        $approver_other = array(
            "http"  => Language::_("GoGetSSLv2.tab_install.other_installs.http_select", true),
            "dns"   => Language::_("GoGetSSLv2.tab_install.other_installs.dns_select", true),
            "email" => Language::_("GoGetSSLv2.tab_install.other_installs.email_select", true)
        );
        $this->view->set("gogetssl_approver_type", $approver_other);

        //removed api call
        //set our webserver types
        $this->view->set("gogetssl_webserver_types", $this->getWebserverTypes($service_fields->cert_type));
        //can we install with other methods
        $this->view->set("cert_type", $service_fields->cert_type);

        $this->view->set("client_id", $service->client_id);
        $this->view->set("service_id", $service->id);
        $this->view->set("gogetssl_csr_fqdn",$service_fields->gogetssl_fqdn);
        $this->view->set("action_url",	$this->base_uri . "services/manage/" . $service->id . "/tabClientInstall/");
        $this->view->set("csr_install",	$this->base_uri . "services/manage/" . $service->id . "/tabClientGenerateCSR/?tab=tabClientReissue");
        $this->view->set("csr_data",	$csr_data);

        $this->view->set("post_back",   json_encode($postRequest));

        //add our custom javascript
        ///components/modules/gogetssl/views/default/
        $this->view->set("js_script",  "js/" . ($row->meta->sandbox? "tab_client_install.js" : "tab_client_install.min.js"));

        //$this->view->set("gogetssl_approver_emails", $this->getApproverEmails($api, $package, $service_fields->gogetssl_fqdn));

        //@todo pass install action
        if(isset($postRequest["gogetssl_csr"])) {

            Loader::loadModels($this, array("Services"));

            $vars = array(
                "gogetssl_fqdn"             => $service_fields->gogetssl_fqdn,
                "use_module"                 => true,
                "pricing_id"                 => $service->pricing_id,
                "gogetssl_webserver_type"   =>  $postRequest["gogetssl_webserver_type"],
                "gogetssl_csr"              =>  $postRequest["gogetssl_csr"],
                "gogetssl_approver_type"    =>  $postRequest["gogetssl_approver_type"],
                "gogetssl_approver_email"    => $postRequest["gogetssl_approver_email"],
            );

            $vars['client_id'] = $service->client_id;
            $vars['action'] = 'tabClientReissue';

            //need to look why in fill data we are checking pricing id maybe multiple products?
            $vars['pricing_id'] = $service->pricing_id;
            $res = $this->editService($package, $service, $vars);


            if (!$this->Input->errors())
                $this->Services->setFields($service->id, $res);


        }

        $this->view->set("view", $this->view->view);
        $this->view->setDefaultView("components" . DS . "modules" . DS . "gogetsslv2" . DS);

        return $this->view->fetch();

    }

    /**
     * Client Reissue tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientGenerateCSR($package, $service, array $getRequest = null, array $postRequest = null, array $files = null)
    {


        $this->view = new View("tab_csr_generator", "default");
        //views/default/tab_csr_generator.pdt
        if ($service->status == "pending"){
            return "Service still pending, has not been activated yet.";
        }
        $lib = $this->getLib();
        //define any ajax calls to this tab
        $allowedRequests = array("generateCSR");

        //process any ajax request first before page render
        if ($lib->isAjaxRequest()){

            $dataRequest    = $lib->dataRequest($getRequest,$postRequest);
            $packageRequest = $lib->packageRequest($package,$service);

            $lib->processAjax($this,$allowedRequests,$dataRequest,$packageRequest);
        }

        //grab the tab we want to pass the data to

        //Set our install redirect
        $tab = $lib->getRequest($getRequest,'tab');
        $install_tab = $this->base_uri . "services/manage/" . $service->id . "/" .(($tab != false)?$tab: "tabClientInstall") . "/";


        /*
        $dataRequest    = $lib->dataRequest($getRequest,$postRequest);
        $packageRequest = $lib->packageRequest($package,$service);
        //while testing
        $response = $this->generateCSR($dataRequest,$packageRequest);
        var_dump($response);exit;
        */


        $this->view->base_uri = $this->base_uri;

        $this->view = new View("tab_csr_generator", "default");
        Loader::loadHelpers($this, array("Form", "Html"));

        $this->view->setDefaultView("components" . DS . "modules" . DS . "gogetsslv2" . DS);

        if (!isset($this->Clients) || !isset($this->Countries))
            Loader::loadModels($this, array("Clients","Countries"));

        $service_fields = $this->serviceFieldsToObject($service->fields);

        $row = $this->getModuleRow($package->module_row);

        //retrieve client info
        $client_info = $this->Clients->get($service->client_id,false);
        $countries = $this->Countries->getList();

        $this->view->set("view", $this->view->view);
        $this->view->set("gogetssl_country_codes",$countries);
        $this->view->set("gogetssl_country_default",$client_info->country);
        $this->view->set("gogetssl_csr_state",$client_info->state);

        $this->view->set("gogetssl_csr_fqdn",$service_fields->gogetssl_fqdn);
        $this->view->set("gogetssl_csr_email",$client_info->email);
        $this->view->set("action_url",	$this->base_uri . "services/manage/" . $service->id . "/tabClientGenerateCSR/");

        $this->view->set("install_csr_url", $install_tab);




        return $this->view->fetch();
    }



    /**
     * Client Install tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientInstall($package, $service, array $getRequest=null, array $postRequest=null, array $files=null) {

        if ($service->status == "pending"){
            return "Service still pending, has not been activated yet.";
        }

        // Get the service fields & row
        $service_fields = $this->serviceFieldsToObject($service->fields);



        //get our external library helper
        $lib = $this->getLib();

        //predefine ajax calls allowed to this tab
        $allowedRequests = array("authAlternatives","emailAuthorisation");



        //process any ajax request first before page renders
        //*******************************HANDLE AJAX REQUEST START***********************************/
        if ($lib->isAjaxRequest()){

            $dataRequest    = $lib->dataRequest($getRequest,$postRequest);
            $packageRequest = $lib->packageRequest($package,$service);

            $lib->processAjax($this,$allowedRequests,$dataRequest,$packageRequest);
        }
        //*******************************HANDLE AJAX REQUEST END***********************************/

        //non ajax calls
        /**Download http file before render @todo move to global request **/
        if ($lib->isGetRequest($getRequest,array('http_download')) != false){
            $contents = $lib->getRequest($getRequest,'contents');
            $file_name = $lib->getRequest($getRequest,'file_name');
            header("Content-Disposition: attachment; filename=$file_name");
            header("Content-type: text/plain");
            echo $contents;
            die;
        }



        //*******************************HANDLE POST DATA START***********************************/
        //action=install
        if(isset($postRequest["gogetssl_csr"]) ) {


            Loader::loadModels($this, array("Services"));
            $vars = $this->getVarPost($service_fields,$postRequest);
            $vars['use_module'] = true;
            $vars['client_id'] = $service->client_id;
            $vars['action'] = 'install';
            //@todo need to look why we need to fill pricing id maybe multiple products?
            $vars['pricing_id'] = $service->pricing_id;
            $results = $this->editService($package, $service, $vars);


            if (!$this->Input->errors()){
                $this->Services->setFields($service->id, $results);
                $tmp_service =  $this->serviceFieldsToObject($results);
                //for security we just going to pass order_id and is issued to our service_fields
                $service_fields->gogetssl_issed     = $tmp_service->gogetssl_issed;
                $service_fields->gogetssl_orderid   = $tmp_service->gogetssl_orderid;
            }




        }


        //*******************************HANDLE POST DATA END***********************************/

        //RENDER PAGE
        $this->view = new View("tab_client_install", "default");
        $this->view->base_uri = $this->base_uri;

        //load client info to help form out
        if (!isset($this->Clients))
            Loader::loadModels($this, array("Clients"));

        //retrieve client info
        $client_info = $this->Clients->get($service->client_id,false);





        // Load the helpers required for this view
        Loader::loadHelpers($this, array("Form", "Html"));

        //int our csr data pass false if empty
        $csr_data = $lib->ifSet($service_fields->gogetssl_csr,false);
        //load our row
        $row = $this->getModuleRow($package->module_row);


        //small patch for cert_type that was missing should have done as upgrade function this will be removed later
        if (!isset($service_fields->cert_type)){
            if (!isset($this->Services))
                Loader::loadModels($this, array("Services"));

            $api = $this->api($row);
            $service_fields->cert_type = $this->isComodoCert($api, $package) ? '1' : '2';

            //update our csr code
            $service_fields_update = $this->ourServiceFields(
                $lib->serviceFieldMerge($service_fields,    array("cert_type" => $service_fields->cert_type)) ,
                $service_fields->gogetssl_orderid
            );
            $this->Services->setFields($service->id, $service_fields_update);
        }

        //POST CHECK CERT INSTALL
        if ($service_fields->gogetssl_issed) {
          
            /*
            $cert_install_check = $this->certIsPending($service, $row);
            if ($cert_install_check !== true) {
                return $cert_install_check;
            }*/
            //@todo bug $service i not passing service_fields->gogetssl_order id
            //$cert_install_check = $this->certIsPending($service, $row);
            if ($this->certIsPending($service, $row) == true) {
                //return $cert_install_check;
                return $this->view->fetch();
            }
        }

        //***************************************CERTIFICATE HAS NOT BEEN CREATED*******************************************

        //default passing to view
        $approver_other = array(
                "http"  => Language::_("GoGetSSLv2.tab_install.other_installs.http_select", true),
                "dns"   => Language::_("GoGetSSLv2.tab_install.other_installs.dns_select", true),
                "email" => Language::_("GoGetSSLv2.tab_install.other_installs.email_select", true),
        );
        $this->view->set("gogetssl_approver_type", $approver_other);

        //pass webserver types
        $this->view->set("gogetssl_webserver_types", $this->getWebserverTypes($service_fields->cert_type));
        //pass the cert type
        $this->view->set("cert_type", $service_fields->cert_type);
       // $this->view->set("gogetssl_fqdn", $service_fields->gogetssl_fqdn);
        //$this->view->set("gogetssl_approver_emails", $this->getApproverEmails($api, $package, $service_fields->gogetssl_fqdn));
        //$this->view->set("vars", $vars);

        $this->view->set("client_id", $service->client_id);
        $this->view->set("service_id", $service->id);
        $this->view->set("gogetssl_csr_fqdn",$service_fields->gogetssl_fqdn);

        $this->view->set("client",$client_info);
        $this->view->set("action_url",	$this->base_uri . "services/manage/" . $service->id . "/tabClientInstall/");
        $this->view->set("csr_install",	$this->base_uri . "services/manage/" . $service->id . "/tabClientGenerateCSR/?tab=tabClientInstall");
        $this->view->set("csr_data",	$csr_data);
        $this->view->set("post_back",   json_encode($postRequest));

        //add our custom javascript
        ///components/modules/gogetssl/views/default/
        $this->view->set("js_script",  "js/" . ($row->meta->sandbox? "tab_client_install.js" : "tab_client_install.min.js"));

        $this->view->set("view", $this->view->view);
        $this->view->setDefaultView("components" . DS . "modules" . DS . "gogetsslv2" . DS);

        return $this->view->fetch();
    }


    private function certIsPending($service,$row)
    {
        //Set as default pending
        $isPending = true;
        //print_r($this->view);exit;

        //return $this->view->fetch();
        //$tab = ($this->view->file == "tab_client_install") ? "tabClientInstall" : "tabClientReissue";


        //pre-define cert as not installed

        $service_fields = $this->serviceFieldsToObject($service->fields);


         // Get the service fields & row
        $api = $this->api($row);
        //caching results for testing
        $api->cacheResults();

        $response = $this->parseResponse($api->getOrderStatus($service_fields->gogetssl_orderid) , $row);

            //for testing purposes
        /*
            if ($this->debug_mode == false){



            }else{

                $response = $this->debug($service_fields,'getOrderStatus');

                //$this->clearDebug($service_fields,'getOrderStatus');

                if ($response == false)
                $response = $this->parseResponse(
                    $this->debug($service_fields,'getOrderStatus' , $api->getOrderStatus($service_fields->gogetssl_orderid))
                    ,$row);
            }
            */

            //check status of cert
            if ($response['success'] != true)die("Failed to load response certIsPending ");

            $result = (object) $response;

            //grab the status
            $status = (isset($result->status)) ? $result->status : false;

            //certificate is active don't continue
            if ($status == "active") return false;

            $this->view = new View("tab_client_pending", "default");
            $this->view->base_uri = $this->base_uri;
            $this->view->set("view", $this->view->view);
            $this->view->set("gogetssl_csr_fqdn", $service_fields->gogetssl_fqdn);
            $this->view->set("dcv_method", $result->dcv_method);
            $this->view->set("result", $result);

            $this->view->setDefaultView("components" . DS . "modules" . DS . "gogetsslv2" . DS);


        /*
                    if ($status != false && $status == "active") return true;

                    if(!$status) return "Failed to get status";

                    $message = '';

                    //switch between auth types
                    switch($result->dcv_method){
                        case "email":
                            $cert_installed = sprintf("<p>Certificate details have been sent to %s </p>",          $result->approver_email);
                            break;
                        case "http":
                            $http_details = $result->approver_method->http;
                            $cert_installed = sprintf('<p>1) You need to create a file on the server named : <pre>%s</pre></p>
                                <p>2) with the following contents : <pre>%s</pre></p>
                                <p>3) This should now link <a href="%s" target="_blank">%3$s</a>',
                                $http_details->filename,
                                $http_details->content,
                                $http_details->link
                            );
                            break;
                        case "dns":
                            $dns_details = $result->approver_method->dns;
                            $record = explode("CNAME", $dns_details->record);
                            $our_dns_record = $record[0];
                            $comodo_dns = $record[1];
                            $cert_installed = sprintf('<p>1) To Authorise create a CNAME record <pre>%s</pre></p>
                                <p>2) Point the record to <pre>%s</pre></p>',
                                $our_dns_record,
                                $comodo_dns
                            );
                            break;
                    }
                    //$install_method = $result['dcv_method'];


                    if ($result->dcv_method == 'http'){
                        $http_details = $result['approver_method']['http'];
                        $file_name      = $http_details['filename'];
                        $file_content   = $http_details['content'];
                        $http_link = $http_details['link'];
                        $message = "<p>1) You need to create file on server named : <pre>$file_name</pre></p><p>2) with the following contents : <pre>$file_content</pre></p> <p>3) This should now link <a href='$http_link' target='_blank'>$http_link</a>";


                    }
                    if ($result->dcv_method == 'dns'){
                        $http_details = $result['approver_method']['dns'];
                        //split has been deprecated from 5.3
                        //$record      = split('CNAME',$http_details['record']);
                        $record = explode("CNAME", $http_details['record']);
                        $our_dns_record = $record[0];
                        $comodo_dns = $record[1];
                        $message = "<p>1) To Authorise create a CNAME record <pre>$our_dns_record</pre><p>2) Point the record to <pre>$comodo_dns</pre></p>";
                    }

                    $link = $this->base_uri . "services/manage/" . $this->Html->ifSet($service->id) . "/".$tab."/";

                    $start_message = "Certificate has already been processed, please go to <a href=\"$link\">re-issue Certificate</a>";

                    if ($status == "processing" || $status == "pending" || $status == "new_order" )
                        $start_message = "Order is still processing, please check your install. <h3>".ucfirst($result->dcv_method)." Install Method</h3><p>$message</p>";


                    if ($status == "active"){

                        $csr_code = (isset($result['csr_code']))?$result['csr_code'] : '';
                        $crt_code = (isset($result['crt_code']))?$result['crt_code'] : '';
                        $ca_code = (isset($result['ca_code']))?$result['ca_code'] : '';
                        $start_message = "Certificate has already been processed, please go to <a href=\"$link\">re-issue Certificate</a><p><span>CSR CODE</span><pre>$csr_code</pre><p><span>CRT CODE</span></p><pre>$crt_code</pre><p><span>CA CODE</span></p><pre>$ca_code</pre></p>";
                    }
                    //$start_message.= "<h3>".ucfirst($install_method)." Install Method</h3><p>$message</p>";
                    */

            // print_r($result);




        //echo $start_message;exit;
        return true;
    }
	
	/**
	 * Retrieves a list of products
	 *
	 * @param GoGetSslApi $api the API to use
	 * @param stdClass $module_row A stdClass object representing a single reseller (optional, required when Module::getModuleRow() is unavailable)
	 * @return array A list of products
	 */
	private function getProducts($api, $module_row) {
		$this->log($module_row->meta->api_username . "|ssl-products", '', "input", true);
		$res = $this->parseResponse($api->getAllProducts(), $module_row);

		$out = array(); 
		  
		foreach($res['products'] AS $value) { 
			$out[$value['id']] = $value['name']; 
		}
		
		return $out;
	}

    /**
     * Initializes the API and returns a Singleton instance of that object for api calls
     *
     * @param stdClass $module_row A stdClass object representing a single reseller (optional, required when Module::getModuleRow() is unavailable)
     * @return GoGetSSLApi The GoGetSSLApi instance
     */
    private $_api = false;
    private function api($module_row = false){
        if ($this->_api == false){

            //if module_row was not passed will try retrieve
            if ($module_row == false || !isset($module_row))
                $module_row = $this->getModuleRow();

            if (!isset($module_row)){
                die ("failed to load api (module row issue)");
            }

            Loader::load(dirname(__FILE__) . DS . "apis" . DS . "GoGetSSLApi.php");

            $this->_api = new GoGetSSLApi($module_row->meta->sandbox == "true");



            $this->parseResponse($this->_api->auth(
                $module_row->meta->api_username,
                $module_row->meta->api_password
            ),
            $module_row);

        }


        return $this->_api;
    }
	/**
	 * Retrieves a list of webserver types from the config file
	 *
	 * @param stdClass $package The package
	 * @return array A list of products
     */
    public function getWebserverTypes($cert_type = 1)
    {

        //webserver types
        return Configure::get("gogetsslv2.web_server_types.$cert_type");
	}
	
	/**
	 * Returns if package's certificate vendor is a COMODO cert or not
	 *
	 * @param GoGetSslApi $api the API to use
	 * @param stdClass $package The package
	 * @return boolean If it is COMODO
	 */
	public function isComodoCert($api, $package) {
        //@todo once we have purchased a cert we should store some details into config for so many days not recall api
		$row = $this->getModuleRow($package->module_row);
	
		$this->log($row->meta->api_username . "|ssl-is-comodo-cert", serialize($package->meta->gogetssl_product), "input", true);
		try {

			$product = $this->parseResponse($api->getProductDetails($package->meta->gogetssl_product), $row);
			return $product['product_brand'] == 'comodo';
        } catch (Exception $e) {
			// Error, invalid authorization
			$this->Input->setErrors(array('api' => array('internal' => Language::_("GoGetSSLv2.!error.api.internal", true))));
		}
		return false;
	}
	
	/**
	 * Retrieves a list of rules for validating adding/editing a module row
	 *
	 * @param array $vars A list of input vars
	 * @return array A list of rules
	 */
	private function getRowRules(array &$vars) {
		return array(
			'api_username' => array(
				'empty' => array(
					'rule' => "isEmpty",
					'negate' => true,
					'message' => Language::_("GoGetSSLv2.!error.api_username.empty", true)
				),
				'valid' => array(
					'rule' => array(array($this, "validateConnection"), $vars),
					'message' => Language::_("GoGetSSLv2.!error.api_username.valid", true)
				)
			),
			'api_password' => array(
				'empty' => array(
					'rule' => "isEmpty",
					'negate' => true,
					'message' => Language::_("GoGetSSLv2.!error.api_password.empty", true)
				)
			),
			'gogetssl_name' => array(
				'empty' => array(
					'rule' => "isEmpty",
					'negate' => true,
					'message' => Language::_("GoGetSSLv2.!error.gogetssl_name.empty", true)
				)
			),
			'sandbox' => array(
			)
		);
	}
	
	/**
	 * Retrieves a list of rules for validating adding/editing a package
	 *
	 * @param array $vars A list of input vars
	 * @return array A list of rules
	 */
	private function getPackageRules(array $vars = null) {
		$rules = array(
			'meta[gogetssl_product]' => array(
				'empty' => array(
					'rule' => "isEmpty",
					'negate' => true,
					'message' => Language::_("GoGetSSLv2.!error.meta[gogetssl_product].valid", true)
				)
			)
		);
		
		return $rules;
	}
	
	/**
	 * Validates whether or not the connection details are valid by attempting to fetch
	 * the number of accounts that currently reside on the server
	 *
	 * @param string $api_username The reseller API username
	 * @param array $vars A list of other module row fields including:
	 * 	- api_password The reseller password
	 * 	- sandbox "true" or "false" as to whether sandbox is enabled
	 * @return boolean True if the connection is valid, false otherwise
	 */
	public function validateConnection($api_username, $vars) {
		try {
			$api_password = (isset($vars['api_password']) ? $vars['api_password'] : "");
			$sandbox = (isset($vars['sandbox']) && $vars['sandbox'] == "true" ? "true" : "false");
			$module_row = (object)array('meta' => (object)$vars);

			$this->api($module_row);

			if (!$this->Input->errors())
				return true;
			
			// Remove the errors set
			$this->Input->setErrors(array());
        } catch (Exception $e) {
			// Trap any errors encountered, could not validate connection
		}
		return false;
	}
	
	/**
	 * Parses the response from GoGetSsl into an stdClass object
	 *
	 * @param mixed $response The response from the API
	 * @param stdClass $module_row A stdClass object representing a single reseller (optional, required when Module::getModuleRow() is unavailable)
	 * @param boolean $ignore_error Ignores any response error and returns the response anyway; useful when a response is expected to fail (e.g. check client exists) (optional, default false)
	 * @return stdClass A stdClass object representing the response, void if the response was an error
	 */
	public function parseResponse($response, $module_row = null, $ignore_error = false) {
		Loader::loadHelpers($this, array("Html"));
		
		// Set the module row
		if (!$module_row)
			$module_row = $this->getModuleRow();
		
		$success = true;

		if(empty($response) || !empty($response['error'])) {
            $success = false;
            $error = (isset($response['description'])) ? $response['description'] : Language::_("GoGetSSLv2.!error.api.internal", true);


            if (!$ignore_error)
                $this->Input->setErrors(
                    array('api' =>
                        array('internal' =>
                            $error
                        )
                    )
                );
                //$this->Input->setErrors(array('errors' => $error));


            //$this->Input->setErrors(array('api' => array('internal' => $error)));

        }

		$this->log($module_row->meta->api_username, serialize($response), "output", $success);
		
		if (!$success && !$ignore_error)
			return;
		
		return $response;
	}


    private function getJSPath(){
        return DS . "views" . DS . "default" . DS . "js" . DS ;
    }

    private function ourServiceFields($service_fields,  $order_id){
        Loader::loadHelpers($this, array("Html"));

        return array(
            array(
                'key' => "gogetssl_approver_email",
                'value' => $this->Html->ifSet($service_fields->gogetssl_approver_email),
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_fqdn",
                'value' => $service_fields->gogetssl_fqdn,
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_webserver_type",
                'value' => $this->Html->ifSet($service_fields->gogetssl_webserver_type),
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_csr",
                'value' => $this->Html->ifSet($service_fields->gogetssl_csr),
                'encrypted' => 1
            ),
            array(
                'key' => "gogetssl_orderid",
                'value' => $this->Html->ifSet($order_id),
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_title",
                'value' => $this->Html->ifSet($service_fields->gogetssl_title),
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_firstname",
                'value' => $this->Html->ifSet($service_fields->gogetssl_firstname),
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_lastname",
                'value' => $this->Html->ifSet($service_fields->gogetssl_lastname),
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_address1",
                'value' => $this->Html->ifSet($service_fields->gogetssl_address1),

                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_address2",
                'value' => $this->Html->ifSet($service_fields->gogetssl_address2),
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_city",
                'value' => $this->Html->ifSet($service_fields->gogetssl_city),
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_zip",
                'value' => $this->Html->ifSet($service_fields->gogetssl_zip),
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_state",
                'value' => $this->Html->ifSet($service_fields->gogetssl_state),
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_country",
                'value' => $this->Html->ifSet($service_fields->gogetssl_country),
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_email",
                'value' => $this->Html->ifSet($service_fields->gogetssl_email),
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_number",
                'value' => $this->Html->ifSet($service_fields->gogetssl_number),
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_fax",
                'value' => $this->Html->ifSet($service_fields->gogetssl_fax),
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_organization",
                'value' => $this->Html->ifSet($service_fields->gogetssl_organization),
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_organization_unit",
                'value' => $this->Html->ifSet($service_fields->gogetssl_organization_unit),
                'encrypted' => 0
            ),
            array(
                'key' => "gogetssl_issed",
                'value' => $this->Html->ifSet($service_fields->gogetssl_issed),
                'encrypted' => 0
            ),
            array(
                'key' => "cert_type",
                'value' => $service_fields->cert_type,
                'encrypted' => 0
            )
        );
    }

    //****************************************AJAX CALLS BELOW HERE*********************************************//

    /**
     * @param $request                  This contains the GET & POST requests as an Array
     * @param $dataRequest              This contains the package & service requests as an Array
     * @throws GoGetSSLAuthException
     * @return                          JSON of valid server installs
     *
     * public function webServerTypes($request,$dataRequest){
     * //parse our request
     * $postRequest    = $request['postRequest'];
     * $getRequest     = $request['getRequest'];
     *
     * //get service & packages
     * $package        = $dataRequest['package'];
     * $service        = $dataRequest['service'];
     *
     * $lib = $this->getLib();
     *
     *
     * $row = $this->getModuleRow($package->module_row);
     * $api = $this->api($row);
     *
     * $cert_type = $this->isComodoCert($api, $package) ? '1' : '2';
     * //$this->log($row->meta->api_username . "|ssl-webservers", serialize($cert_type), "input", true);
     *
     * $response = array('webservers' => array());
     * try {
     * $response = $this->parseResponse($api->getWebservers($cert_type), $row);
     * }
     * catch (Exception $e) {
     * // Error, invalid authorization
     * $this->Input->setErrors(array('api' => array('internal' => Language::_("GoGetSSLv2.!error.api.internal", true))));
     * $lib->sendAjax($e." error ->".print_r($api->getWebservers($cert_type),true),false);
     * }
     *
     * $out = array();
     *
     * foreach($response['webservers'] AS $value) {
     * $out[$value['id']] = $value['software'];
     * }
     *
     * $lib->sendAjax($out);
     * }
     */

    /**
     * @param $request                  This contains the GET & POST requests as an Array
     * @param $dataRequest              This contains the package & service requests as an Array
     * @throws GoGetSSLAuthException
     * @return                          JSON of other alternative authorisation methods
     */
    public function authAlternatives($request,$dataRequest){
        //parse our request
        $postRequest    = $request['postRequest'];
        $getRequest     = $request['getRequest'];

        $lib = $this->getLib();

        //get CSR request
        $csr_data = $lib->getRequest($getRequest,'csr_data');

        
        if(!$csr_data)
        $lib->sendAjax("Failed to get CSR data",false);


        //get service & packages
        $package        = $dataRequest['package'];
        $service        = $dataRequest['service'];

        $service_fields = $this->serviceFieldsToObject($service->fields);
        $domain = $service_fields->gogetssl_fqdn;

        if ($this->debug_mode == true)
            if (isset($_SESSION[$domain]['other_auth']) && !empty($_SESSION[$domain]['other_auth'])){
                $lib->sendAjax($_SESSION[$domain]['other_auth']);
            }





        $row = $this->getModuleRow($package->module_row);
        $api = $this->api($row);



        //$cert_type = $this->isComodoCert($api, $package) ? '1' : '2';

        //$response = array('webservers' => array());

        try {

            $response = $this->parseResponse($api->getDomainAlternative($csr_data), $row , true);
            //$response = $api->getDomainAlternative($CSR);
        } catch (Exception $e) {
            // Error, invalid authorization
            $this->Input->setErrors(array('api' => array('internal' => Language::_("GoGetSSLv2.!error.api.internal", true))));
            //$lib->sendAjax($e." error ->".var_dump($response),false);
        }


        //Check for errors
         if ($lib->getRequest($response,'error') == true){
             $description = $lib->getRequest($response,'description');
             $lib->sendAjax($description,false);
         }

        //@todo put this in a proper cache
        if ($this->debug_mode == true)
        $_SESSION[$domain]['other_auth'] = $response;

        $lib->sendAjax($response);
        //$lib->sendAjax(var_dump($response));

    }
    /**
     * @param $request                  This contains the GET & POST requests as an Array
     * @param $dataRequest              This contains the package & service requests as an Array
     * @throws GoGetSSLAuthException
     * @return                          JSON of email Authorisation
     */

    public function emailAuthorisation($request,$dataRequest){
        //parse our request
        $postRequest    = $request['postRequest'];
        $getRequest     = $request['getRequest'];

        //get service & packages
        $package        = $dataRequest['package'];
        $service        = $dataRequest['service'];

        $service_fields = $this->serviceFieldsToObject($service->fields);
        $domain = $service_fields->gogetssl_fqdn;

        $lib = $this->getLib();

        //@todo only want to store email_auth during swapping between CSR Generation & domain renew will save as services
        //@disabled for now due to https://github.com/lukesUbuntu/gogetsslv2/issues/1

        if (isset($_SESSION[$domain]['email_auth']) && !empty($_SESSION[$domain]['email_auth'])){
            $lib->sendAjax($_SESSION[$domain]['email_auth']);
        }

        if (empty($domain))$lib->sendAjax("domain failed empty",false);



        $row = $this->getModuleRow($package->module_row);
        $api = $this->api($row);


        $this->log($row->meta->api_username . "|ssl-domain-emails", serialize($domain), "input", true);

        $gogetssl_approver_emails = array();
        try {

            $response = $api->getDomainEmails($domain);

            $gogetssl_approver_emails = $this->parseResponse($response, $row);
        } catch (Exception $e) {
            // Error, invalid authorization
            $this->Input->setErrors(array('api' => array('internal' => Language::_("GoGetSSLv2.!error.api.internal", true))));
        }
        //error checking response
        if ($this->Input->errors())
            $lib->sendAjax($response,false);

        $emails = array();
        if($this->isComodoCert($api, $package) && isset($gogetssl_approver_emails['ComodoApprovalEmails']))
            $emails = $gogetssl_approver_emails['ComodoApprovalEmails'];
        elseif (isset($gogetssl_approver_emails['GeotrustApprovalEmails']))
            $emails = $gogetssl_approver_emails['GeotrustApprovalEmails'];

        $formatted_emails = array();
        foreach ($emails as $email)
            $formatted_emails[$email] = $email;

        $_SESSION[$domain]['email_auth'] = $formatted_emails;

        $lib->sendAjax($formatted_emails);
    }

    /**
     * @name  generateCSR               Generates a CSR request and passes back as json
     * @param $request                  This contains the GET & POST requests as an Array
     * @param $dataRequest              This contains the package & service requests as an Array
     * @throws GoGetSSLAuthException
     * @return                          JSON of email Authorisation
     */
    public function generateCSR($request,$dataRequest){

        //load our helpers
        Loader::loadHelpers($this, array("Html"));
        //load models
        Loader::loadModels($this, array("Services"));
        //load our lib
        $lib = $this->getLib();

        //pass our requests
        $postRequest    = $request['postRequest'];
        $getRequest     = $request['getRequest'];

        //set our packages and services
        $package        = $dataRequest['package'];
        $service        = $dataRequest['service'];

        //get service_fields
        $service_fields = $this->serviceFieldsToObject($service->fields);


        $requires = array(
            "gogetssl_csr_fqdn",
            "gogetssl_csr_country",
            "gogetssl_csr_state",
            "gogetssl_csr_locality",
            "gogetssl_csr_organization",
            "gogetssl_csr_organization_unit",
            "gogetssl_csr_email"
        );
        $cert_details = $lib->getRequests($getRequest,$requires);


        //if any of our fields failed check if they are manditory fields
        /*
        if ($cert_details['failed'] != false){
            $response['fields'] = $cert_details['failed'];
            $response['message'] = "missing or empty fields";
            $lib->sendAjax($response,false);
        }
        */



        //we can use generate ourselfs or use API
        $row = $this->getModuleRow($package->module_row);
        $api = $this->api($row);

        //@issue gossl sandbox does not support SHA2 for some reason
        $SHA  = ($row->meta->sandbox == "true" ? "SHA1" : "SHA2");


        $data = array(
            "csr_commonname"            => $cert_details['gogetssl_csr_fqdn'],
            "csr_organization"          => $lib->ifSet($cert_details['gogetssl_csr_organization'],        "NA"),
            "csr_department"            => $lib->ifSet($cert_details['gogetssl_csr_organization_unit'],   "NA"),
            "csr_city"                  => $lib->ifSet($cert_details['gogetssl_csr_locality'],            "NA"),
            "csr_state"                 => $lib->ifSet($cert_details['gogetssl_csr_state'],               "NA"),
            "csr_country"               => $lib->ifSet($cert_details['gogetssl_csr_country'],             "NA"),
            "csr_email"                 => $cert_details['gogetssl_csr_email'],
            "signature_hash"            => "SHA1"
        );

        //generate CSR
        $response = $api->generateCSR($data);

        //pass hash used
        $response['hash'] = $SHA;


        //catch the error as we may have already generated CSR with gogetssl
        if  (isset($response['error']) && $response['error']== true){
            //if we have already generated CSR details we will retrieve this
            if ($response['message'] == 'CSR Exist'){
                if(preg_match('/CSR ID is: .(\d+)./',$response['description'], $matches)){
                    $csr_id = $matches[1];
                    $response = $api->getCSR($csr_id,$cert_details['gogetssl_csr_fqdn']);
                }
            }else{
                //we have a different error lets pass back
                $lib->sendAjax($response,false);
            }
        }
        //we will end up here with a valid response->csr_code

        //Update our gogetssl_csr records.
        $csr_update = array("gogetssl_csr" => $this->Html->ifSet($response['csr_code']));

        //update our csr code
        $service_fields_update = $this->ourServiceFields(
            $lib->serviceFieldMerge($service_fields,$csr_update) ,
            $service_fields->gogetssl_orderid
        );
        $this->Services->setFields($service->id, $service_fields_update);

        $lib->sendAjax($response);

    }


}


?>
