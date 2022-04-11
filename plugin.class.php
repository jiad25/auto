<?php
/**
 * @package Bkascript
 * @version 1.0
 * @author  http://bkascript.com/
 * email : admin@bkascript.com
 */

class SocialAutoPost {
        public $db_version='1.2';
        public $db_option_name='sap';
        public $db_prefix='sap_';
        public $pluginPath=autopost_PATH;
        public $permission=array('SocialAutoPost_config','SocialAutoPost_list_account');
        public function __construct() {
            global $wpdb;
            register_activation_hook($this->pluginPath, array(&$this, 'activation'));
            register_deactivation_hook($this->pluginPath, array(&$this, 'deactivation'));
            
            
            $this->set('dbprefix',$wpdb->prefix.$this->db_prefix);
            
            add_action( 'plugins_loaded', array(&$this, 'plugin_update'));
            
            add_action('admin_menu', array(&$this, 'admin_menu'));
            add_action('admin_enqueue_scripts',array(&$this, 'admin_js' ));
            
            if(!session_id()) {
                session_start();
            }
            if(isset($_SESSION['msg'])){
                $this->message($_SESSION['msg'],$_SESSION['type_msg']);
                unset($_SESSION['msg']);
                unset($_SESSION['type_msg']);
            }
            
            add_action('wp_enqueue_scripts',array(&$this, 'front_js' ));
            
            add_action('parse_request',array(&$this,'parse_request'));
            
            add_action( 'wp_ajax_ajax',array(&$this,'ajax_handler_request'));
            
           
            
        }

        
        public function get($property) {
		if ( isset($this->$property) ) {
			return $this->$property;
		} elseif ( isset($this->properties[$property]) ) {
			return $this->properties[$property];
		} else {
			throw new Exception(sprintf(__("Property not found: %s.", ''), $property));
		}
	}
        public function set( $property, $value ) {
		if ( isset($this->$property) ) {
			$this->$property = $value;
		} else {
			$this->properties[$property] = $value;
		}
	}
        
        public function activation() {
            $this->add_cap();
            $this->admin_db_version();
        }
        
        public function deactivation() {
            global $wpdb;
            $this->remove_cap();
            $wpdb->query("DROP TABLE IF EXISTS ".$this->get('db_prefix')."variables");
            $wpdb->query("DROP TABLE IF EXISTS ".$this->get('db_prefix')."account");
            $wpdb->query("DROP TABLE IF EXISTS ".$this->get('db_prefix')."log");
            delete_option($this->db_option_name);
        }
        
        // Add the new capability to all roles having a certain built-in capability
        private  function add_cap() {
            $sef=$this;
            $roles = get_editable_roles();
            foreach ($GLOBALS['wp_roles']->role_objects as $key => $role) {
                if(is_array($sef->get('permission'))){
                    foreach($sef->get('permission') as $cap){
                        if (isset($roles[$key]) && !$role->has_cap($cap)) {
                            $role->add_cap($cap);
                        }
                    }
                }
                
            }
        }

        public function plugin_update(){
            global $wpdb;
            
        }
        
        // Remove the plugin-specific custom capability
        private  function remove_cap() {
            $sef=$this;
            $roles = get_editable_roles();
            foreach ($GLOBALS['wp_roles']->role_objects as $key => $role) {
                if(is_array($sef->get('permission'))){
                    foreach($sef->get('permission') as $cap){
                        if (isset($roles[$key]) && $role->has_cap($cap)) {
                            $role->remove_cap($cap);
                        }
                    }
                }
                
            }
        }
        
        
        public function admin_install_db() {
            global $wpdb;
            $sqls=array();

            $sqls[] = "CREATE TABLE IF NOT EXISTS ".$this->get('db_prefix')."variables (
                    `name` varchar(100) DEFAULT '' NOT NULL,
                    `data` text NOT NULL,
                    UNIQUE KEY name (name)
            ) {$wpdb->get_charset_collate()}";
            
            $sqls[] = "CREATE TABLE IF NOT EXISTS ".$this->get('db_prefix')."account(
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `provider` varchar(255) DEFAULT '',
                    `profileURL` varchar(255) DEFAULT '',
                    `name` varchar(255) DEFAULT '',
                    `identifier` varchar(25) DEFAULT 0,
                    `start_pid` int(11) DEFAULT 0,
                    `content_type` varchar(255) DEFAULT '',
                    `format` varchar(255) DEFAULT '',
                    `categories` varchar(255) DEFAULT '',
                    `access_token` varchar(255) DEFAULT '',
                    `access_token_secret` varchar(255) DEFAULT '',
                    `refresh_token` varchar(255) DEFAULT '',
                    `type` varchar(255) DEFAULT '',
                    `created` int(11) DEFAULT 0 ,
                    `days_repost` int(11) DEFAULT 0,
                    `config` text DEFAULT '',
                    UNIQUE KEY id (id)
            ) {$wpdb->get_charset_collate()}";

            $sqls[] = "CREATE TABLE IF NOT EXISTS ".$this->get('db_prefix')."log(
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `pid` int(11) DEFAULT 0,
                    `uid` int(11) DEFAULT 0,
                    `status` int(11) DEFAULT 0,
                    `next_schedule` int(11) DEFAULT 0,
                    `posted` int(11) DEFAULT 0,
                    UNIQUE KEY id (id)
            ) {$wpdb->get_charset_collate()}";
            
            
            require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
            foreach($sqls as $sql){
                dbDelta($sql);
            }
            add_option($this->db_option_name,$this->db_version);
        }
        
        public function admin_db_version(){
            
            if (get_site_option($this->db_option_name)!= $this->db_version) {
                delete_option($this->db_option_name);
                $this->admin_install_db();
                
            }
        }
        
        public  function variable_set($name,$data){
            global $wpdb;
            if(empty($name) || empty($data)) return;
            $r=$wpdb->get_row( "SELECT * FROM ".$this->get('db_prefix')."variables where name='{$name}' limit 0,1",ARRAY_A);
            if($r){
                $wpdb->update( 
                    $this->get('db_prefix').'variables', 
                    array('data'=>  base64_encode(serialize($data))),
                    array('name'=>$r['name'])
                );
            }else{
                $wpdb->insert( 
                    $this->get('db_prefix').'variables', 
                    array('data'=>  base64_encode(serialize($data)),'name'=>$name)
                );
            }
            return $data;
        }
        
        public  function variable_get($name){
            global $wpdb;
            $args=func_get_args();
            if(empty($name)) return;
            $data=$wpdb->get_row( "SELECT * FROM ".$this->get('db_prefix')."variables where name='{$name}' limit 0,1",ARRAY_A);
            if(is_array($data)) return unserialize(base64_decode($data['data']));
            if(isset($args[1])) return func_get_arg(1);
        }
        
        public  function value(){
            $numargs = func_num_args();
            if($numargs==1) return func_get_arg(0);
            $arg_list = func_get_args();
            if(is_array($arg_list)){
                if(empty($arg_list[0])) return '';
                $arr=$arg_list[0];
                unset($arg_list[0]);
                foreach($arg_list as $k=>$v){
                    if(is_array($arr) && array_key_exists($v,$arr)){
                        $arr=$arr[$v];
                    }else if(is_object($arr) && property_exists($arr,$v)){
                        $arr=$arr->$v;
                    }
                    else{
                        return '';
                    }
                }
                return $arr;
            }
            return '';
        }
        
        public static function message($message,$err='success') {
            ?>
            <div class="notice notice-<?php echo $err;?> is-dismissible">
                <p><?php _e($message); ?></p>
            </div>
            <?php
        }
        
        public function redirect(){
            $arg_list = func_get_args();
            $url=site_url($arg_list[0]);
            if(isset($arg_list[1]) && !empty($arg_list[1])){
                if(array_key_exists('msg',$arg_list[1])){
                    $_SESSION['msg']=$arg_list[1]['msg'];
                    $_SESSION['type_msg']='error';
                    unset($arg_list[1]['msg']);
                }
                if(array_key_exists('type_msg',$arg_list[1])){
                    $_SESSION['type_msg']=$arg_list[1]['type_msg'];
                    unset($arg_list[1]['type_msg']);
                }
                $url=add_query_arg($arg_list[1], $url);
            }
            echo "<script>top.location='".$url."';</script>";
            wp_die();
        }


        public static function admin_js() {
            
            wp_enqueue_style('socialautopost_css', plugins_url('social-auto-post/assets/css/admin.css')); 
            wp_enqueue_style('socialautopost_choosen_css', plugins_url('social-auto-post/assets/js/choosen/chosen.css')); 
            wp_enqueue_script('socialautopost_choosen_js', plugins_url('social-auto-post/assets/js/choosen/chosen.jquery.min.js'),$deps = array(), $ver = false, $in_footer = true);
            wp_enqueue_script('socialautopost_script', plugins_url('social-auto-post/assets/js/admin.js'),$deps = array(), $ver = false, $in_footer = true);
	}
        
        public static function front_js() {
            
	}
        
        
        public function api(){
            $page=$_REQUEST['page'];
            if(is_file(dirname(__FILE__).'/admin/'.$page.'.php')){
                require dirname(__FILE__).'/admin/'.$page.'.php';
            }
            else if(isset($_REQUEST['file']) && is_file(dirname(__FILE__).'/admin/'.$_REQUEST['file'].'.php')){
                require dirname(__FILE__).'/admin/'.$_REQUEST['file'].'.php';
            }
        }
        
        public function parse_request() {
            global $wp;
            
            $current_url = add_query_arg( $wp->query_string, '', home_url( $wp->request ) );
            
            switch ($current_url) {
                case site_url('social-auto-post'):
                    require dirname(__FILE__).'/front/post.php';
                    exit();
                    break;
                case site_url('view/video'):
                    require dirname(__FILE__).'/front/video.php';
                    exit();
                    break;
                case site_url('social-auto-post/sign-in'):
                    if(!class_exists("Hybrid_Auth")){
                        require_once( "libs/hybridauth/Hybrid/Auth.php" );
                    }
                    require dirname(__FILE__).'/front/sign-in.php';
                    break;
                
                case site_url('social-auto-post/auth'):
                    require dirname(__FILE__).'/front/auth.php';
                    exit();
                    break;
            }

        }
        
        public function contents_get($url,$arg=array()){
                if(is_array($arg) && !empty($arg)) $url=  $url.'?'.http_build_query ($arg);
                
                $ch = curl_init();
                
                curl_setopt($ch, CURLOPT_USERAGENT, 'Firefox (WindowsXP) - Mozilla/5.0 (Windows; U; Windows NT 5.1; en-GB; rv:1.8.1.6) Gecko/20070725 Firefox/2.0.0.6');
                curl_setopt($ch, CURLOPT_URL,$url);
                curl_setopt($ch, CURLOPT_FAILONERROR, true);
                curl_setopt($ch, CURLOPT_AUTOREFERER, true);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER,true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                $content= curl_exec($ch);
                return $content;
        }

        public function contents_post($url,$args=array()){
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_HEADER, false);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                if($args)curl_setopt($ch, CURLOPT_POSTFIELDS, $args);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                $data = curl_exec($ch);
                return $data;
        }

        public function ajax_handler_request(){
            
            if(isset($_REQUEST['file'])){
                $page=$_REQUEST['file'];
                if(is_file(dirname(__FILE__).'/admin/'.$page.'.php')){
                    require dirname(__FILE__).'/admin/'.$page.'.php';
                }
            }
            wp_die();
        }
        
        public function curcle_shift($arr, $key=1) {
            foreach ($arr as $k => $v) {
                if ($k == $key) break;
                unset($arr[$k]);
                $arr[$k] = $v;
            }
            $newArr=array();
            foreach($arr as $a) $newArr[]=$a;
            return $newArr;
        }
        public function plugin_action_links($links) {
            $settings_link = "<a href='".add_query_arg(array('page'=>'smvb-settings'), site_url('wp-admin/admin.php'))."'>" . esc_html__('Settings', 'smvb') . "</a>";
            array_unshift($links, $settings_link);

            return $links;
        }
        public function admin_menu() {
           
            
            add_menu_page('Social Auto Post', 'Social Auto Post','SocialAutoPost_config', 'socialautopost',array(&$this,'api'),'dashicons-megaphone');
            add_submenu_page('socialautopost', 'Settings', 'Settings','SocialAutoPost_config', 'socialautopost' );
            add_submenu_page('socialautopost', 'List Account', 'List Account','SocialAutoPost_list_account', 'socialautopost-list-account',array(&$this,'api'));
        }
        
        public static function loadContent() {
            ob_start();
            $args = func_num_args();
            $theme=array();
            if($args==2) {
                $theme_args=func_get_args(1);
                foreach($theme_args[1] as $k=>$v){
                    $$k=$v;
                }
            }
            $arg_file_arr=func_get_args(0);
            if(is_array($arg_file_arr) && !empty($arg_file_arr) && is_file(dirname(__FILE__).'/'.$arg_file_arr[0])) require $arg_file_arr[0];
            $content= ob_get_contents(); 
            ob_end_clean();
            return $content;
	}
        
        
        public function ajax_ajaxEditor(){
            if(isset($_REQUEST['action'])){
                $page=$_REQUEST['action'];
                if(is_file(dirname(__FILE__).'/admin/'.$page.'.php')){
                    require dirname(__FILE__).'/admin/'.$page.'.php';
                }
            }
            wp_die();
        }
        
        /**
        * Get an attachment ID given a URL.
        * 
        * @param string $url
        *
        * @return int Attachment ID on success, 0 on failure
        */
        function get_attachment_id($url) {
            $attachment_id = 0;
            $dir = wp_upload_dir();
            if ( false !== strpos( $url, $dir['baseurl'] . '/' ) ) { // Is URL in uploads directory?
                    $file = basename( $url );
                    $query_args = array(
                            'post_type'   => 'attachment',
                            'post_status' => 'inherit',
                            'fields'      => 'ids',
                            'meta_query'  => array(
                                    array(
                                            'value'   => $file,
                                            'compare' => 'LIKE',
                                            'key'     => '_wp_attachment_metadata',
                                    ),
                            )
                    );

                    $query = new WP_Query( $query_args );

                    if ( $query->have_posts() ) {

                            foreach ( $query->posts as $post_id ) {

                                    $meta = wp_get_attachment_metadata( $post_id );

                                    $original_file       = basename( $meta['file'] );
                                    $cropped_image_files = wp_list_pluck( $meta['sizes'], 'file' );

                                    if ( $original_file === $file || in_array( $file, $cropped_image_files ) ) {
                                            $attachment_id = $post_id;
                                            break;
                                    }

                            }

                    }

            }
            return $attachment_id;
        }
        
        function googl_shorten($url) {
            $key=$this->variable_get('google_api_key','');
            $result = wp_remote_post( add_query_arg( 'key', apply_filters( 'googl_api_key',$key), 'https://www.googleapis.com/urlshortener/v1/url' ), array(
                    'body' => json_encode( array( 'longUrl' => esc_url_raw( $url ) ) ),
                    'headers' => array( 'Content-Type' => 'application/json' ),
            ) );
            // Return the URL if the request got an error.
            if ( is_wp_error( $result ) )
                    return $url;

            $result = json_decode( $result['body'] );
            $shortlink = $result->id;
            if ( $shortlink )
                    return $shortlink;

            return $url;
        }
}
 
global $SocialAutoPost;

$SocialAutoPost=new SocialAutoPost();
 
 
 ?>