<?php

/**
 * Plugin Name
 * @package           PluginPackage
 * @author            qcellswat
 * @copyright         QMoney 2021
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       QMoney
 * Plugin URI:        https://qmoney.gm/
 * Description:       QMoney wordpress plugin for making online payment and transfer with your QMoney account.
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            qcellswat
 * Author URI:        https://qcell.gm
 * Text Domain:       qmoney-payment
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */
//if files is called from a browser, system should abort
defined('ABSPATH') or die('Unauthorized Access');
// define( 'WP_DEBUG', true );
// define( 'WP_DEBUG_DISPLAY', true );

if(! in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins'))))return;

add_action('plugins_loaded','qmoney_payment_init',11);

function apiValidationError(){
    
?>
    <div class="notice notice-warning is-dismissible">
        <p>merchant not found or inactive</p>
    </div>
<?php

}
function qmpreg_error(){
?>
    <div class="notice notice-warning is-dismissible">
        <p>Invalid or Empty APIKEY</p>
    </div>
<?php
}

function qmoney_payment_init(){


    if(class_exists('WC_Payment_Gateway')){    
            
        class WC_Qmoney_Gateway extends WC_Payment_Gateway {

            public function __construct()
            {
                $this -> id = 'qmoney_payment';
                $this -> icon = apply_filters('woocommerce_qmoney_icon', plugins_url(basename(__DIR__) . '/qmoney-logo.png'));
                $this->has_fields = true;
                $this -> method_title = __('QMoney','qmoney-payment');
                $this->method_description = __('QMoney merchant payment solution.','qmoney-payment');
                $this->supports = array('products');
                
                //Initialize the settings form fields metho
                $this->init_form_fields();   
                
                //Load settings method
                $this->init_settings();
                $this->description = $this->get_option('description');
                $this->instructions = $this->get_option('instructions');
                $this->enabled = $this->get_option('enabled');
                $this->apikey = $this->get_option('apikey');
                $this->validate = $this->get_option('validate');
                // $this->password = $this->get_option('password');
                // $this->token = $this->get_option('token');
                
                //save settings and get token
                if(is_admin()){

                //Action Hook to save the settings
                add_action('woocommerce_update_options_payment_gateways_'.
                $this->id, array($this,'process_admin_options') );
                }              

                //Action Hook to render woocmmerce thank you page
                add_action('woocommerce_thank_you_'.$this->id, array($this,'thank_you_page'));

                //Hook our custom JAVASCRIPT to obtain OTP code
                add_action('wp_enqueue_scripts',array($this, 'payment_scripts'));

            }
            public function init_form_fields(){
                $this->form_fields = apply_filters('woo_qmoney_fields', array(
                    'enabled' => array(
                        'title' => __('Enable/Disbale','qmoney-payment'),
                        'type' => 'checkbox',
                        'label' => __('Enable or Disable QMoney Payment','qmoney_payment'),
                        'default' => 'no'
                    ),

                    'description' => array(
                        'title' => __('Description','qmoney-payment'),
                        'type' => 'textarea',
                        'default' => __('Remit with QMoney using your mobile phone and QMoney Account','qmoney_payment'),
                        'desc_tip' => true,
                        'description' => __('Add a customized description to your qmoney account','qmoney_payment'),
                        'custom_attributes' => array('readonly' => 'readonly'),
                    ),                    
                    'apikey' => array(
                            'title' => __('Api Key','qmoney-payment'),
                            'type' => 'text',
                            'default' => __('','qmoney_payment'),
                            'desc_tip' => true,
                            'description' => __('Your QMoney merchant API Key','qmoney_payment'),    
                    ),
                    "validate" => array(
                        'title' => __('Validate','qmoney-payment'),
                        'type' => 'text',
                        'default' => __('click save to check if API KEY is valid and save API KEY','qmoney_payment'),
                        'desc_tip' => true,
                        'description' => __('API Key validation status','qmoney_payment'),
                        'custom_attributes'=>array('readonly'=>'readonly')    
                ),
                ));
            }

            public function process_admin_options(){
                $this->init_settings();
                $post_data = $this->get_post_data();

                //get apikey form fields array 
                $form_fields = $this->get_form_fields();
                $field_apikey = $form_fields["apikey"];

                //get apikey value 
                $apikey = $this->get_field_value( "apikey", $field_apikey, $post_data );
                
                //check if apikey is not empty
                if($apikey == "" || strlen($apikey) != 10 ){
                    add_action('admin_notices', 'qmpreg_error');
                    return;
                    }
                                
                //setup url and body(data) for remote post email and password to get token
                $url = "https://merchant.api.qmoney.gm/ms/merchant/wp/auth/apikey";

                $body = ["data"=>['api_key'=>$apikey]];
                $body = wp_json_encode($body);
                $args = array(
                    'method' => 'POST',
                    'timeout'=> 45,
                    'httpversion'=>'1.0',
                    'headers' =>array('Content-Type'=>'application/json', 'cache-control'=>'no-cache'),
                    'body'=>$body
                );

                //assigne response token and check if no error while posting and fetching, set it to the new token variable else output error state
                $response = wp_remote_post($url,$args);
                    if(!is_wp_error($response)){
                        $data = json_decode($response['body'], true);                      

                        if($data["valid"]){

                            foreach ( $this->get_form_fields() as $key => $field ) {
                                if ( 'title' !== $this->get_field_type( $field ) ) {
                                    if($key == "validate"){
                                        try {
                                            $this->settings[ $key ] = $data["message"];
                                        } catch ( Exception $e ) {
                                            $this->add_error( $e->getMessage() );
                                        }
                    
                                    } else {
                                        try {
                                            $this->settings[ $key ] = $this->get_field_value( $key, $field, $post_data );
                                        } catch ( Exception $e ) {
                                            $this->add_error( $e->getMessage() );
                                        }
                                            
                                    }
                
                                }
                            }

                        } else {
                            add_action('admin_notices', 'apiValidationError');
                            return;
                        }
                    } else {
                        add_action('admin_notices', 'qmplogin_error');
                        return;
                    } 

                return update_option( $this->get_option_key(), apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings ), 'yes' );            
                

            }
            public function payment_fields(){
                if($this->description){
                    // echo wpautop(wp_kses_post($this->description));
                    echo " ";
                } else {
                    echo " ";
                }

                    ?>
                    <p><span style="color:red;font-size:14px;">*</span> (required)</p>
                    <div class="form-row">
                      <label style="color:#01579b;font-size:16px;font-weight:bold;">Username&nbsp;<span class="required">*</span></label>
                      <input class="input-text" type="tel"  maxlength="7" minlength="7" name="username" id="username" style="font-size:20px;" >
                      <small>Your 7 digit QMoney number</small>
                    </div>
                    <div class="form-row">
                      <label style="color:#01579b;font-size:16px;font-weight:bold;">Password&nbsp;<span class="required">*</span></label>
                      <input class="input-text" type="password"  maxlength="20" minlength="6" name="password" id="password" style="font-size:20px;" >
                      <small>Your 6 digit QMoney passcode</small>
                      
                    </div>
                    <div class="form-row">
                      <label style="color:#01579b;font-size:16px;font-weight:bold;">Pin&nbsp;<span class="required">*</span></label>
                      <input class="input-text" type="password"  maxlength="4" minlength="4" id="pin" name="pin" id="pin" style="font-size:20px;">
                      <small>Your 4 digit QMoney pin</small>

                    </div>
                    <?php
    

            }

            public function payment_scripts(){

                //the OTP code has to be pocessed only when on checkout and cart pages
                //if(! is_cart() && ! is_checkout() && ! isset($_GET['pay_for_order']) ){
                //return;
                //}
                //if QMoney Payment is disable, do not enqueue
                if('no' === $this->enabled){
                    return;
                }
                //if merchant and token are not provided then no need to fetch from the api
                if(empty($this->apikey) || $this->apikey !== 10){
                    return;
                }
                // //checkj for ssl
                // if(!is_ssl()){
                //     return;
                // }

                //custom js
                wp_enqueue_script('woocommerce_qmoney',plugins_url('/assets/qodo.js',__FILE__));

                wp_enqueue_script( 'woocommerce_qmoney' );
            }
            public function validate_fileds(){}

            public function process_payment($order_id){
                global $woocommerce;
                $apikey = $this->apikey;

                $order = wc_get_order( $order_id );
                $order_id  = $order->get_id(); // Get the order ID
                $amount = $order->get_total();
                
                $username = sanitize_text_field($_POST['username']);    
                $password = sanitize_text_field($_POST['password']);    
                $pin = sanitize_text_field($_POST['pin']);  
            
                
                //check if only phone number is filled
                if(!empty($username) && strlen($username) == 7 && is_numeric($username) && preg_match("/^\d+$/",$username) && strlen($pin) == 4 && !empty($pin) && !empty($password) && strlen($password) > 6 ){
                
                    //check if token is available
                    if(strlen($apikey) == 0 || empty($apikey)){
                    wc_Add_notice('The merchant of this site is yet to activate this QMoney plugin, please contact for more details','error');
                    return;
                    }
                    
                    /*****  API CALL FOR TOKEN *****/
                    //setup url and body(data) for remote post email and password to get token

                    $url = "https://merchant.api.qmoney.gm/ms/merchant/wp/payment";
                    $body = ["data"=>['order_id'=>$order_id,'amount'=>$amount,'username'=>$username,'password'=>$password,'pin'=>$pin]];                            
                    $body = wp_json_encode($body);
                    $args = array(
                    'method' => 'POST',
                    'timeout'=> 45,
                    'httpversion'=>'1.0',
                    'headers' =>array('Content-Type'=>'application/json', 'cache-control'=>'no-cache','Authorization'=>'Basic '.$apikey),
                    'body'=>$body
                    );

                    //assign response token and check if no error while posting and fetching, set it to the new token variable else output error state
                    $response = wp_remote_post($url,$args);
                    
                    if(!is_wp_error($response)){
                        $body = json_decode($response['body'], true);
                    var_dump($body);
                         
                        if($body['message'] == "Success"){
                            wc_Add_notice("Payment Successful",'success');
                            $order->update_status('completed',__('QMoney '.$body['trasaction_id'],'qmoney_payment'));
                            $order->payment_complete();
                            $order->reduce_order_stock();
                            $woocommerce->cart->empty_cart();
                            return array('result' => 'success','redirect' => $this->get_return_url($order));                                    
                        
                        } else {
                            wc_Add_notice($body["message"],'error');                                    
                        }
                    } else {
                        wc_Add_notice("Request failed, please try again!",'error');
                    }

                } 
                else {
                    wc_Add_notice('valid username, password and pin are required','error');
                }

            }
            public function thank_you_page(){
                if($this->instructions){
                echo wpautop($this->instructions);
                }
            }        
        }//ends class

    }//ends if statement
}//ends function

add_filter('woocommerce_payment_gateways','add_woo_qmoney_payment_gateway');

function add_woo_qmoney_payment_gateway($gateways){
    $gateways[] = 'WC_Qmoney_Gateway';
    return $gateways;
}


