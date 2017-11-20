<?php
defined('ABSPATH') or die("Cannot access pages directly.");
/*
Plugin Name: Login with Twitch
Plugin URI: https://github.com/cmcgee93/wp-login-with-twitch
Description: Allows you to create users/login with Twitch
Version: 0.2
Author: Chris McGee
Author URI: https://github.com/cmcgee93
*/
class login_with_twitch
{
    private $clientID;
    private $clientSecret;
    private $ourTwitchName;
    private $options;
    public function __construct()
    {
        $this->hooks(); // Run WordPress Hooks
        $fields = get_option('twitch_api_options'); // Used for prepping variables.
        $this->clientID = $fields['client_id']; // Set the client ID
        $this->clientSecret = $fields['client_secret']; // Setting Client Secret
        $this->ourTwitchName = $fields['our_twitch_name'];  // Setting Twitch Name For Website

    }

    public function hooks()
    {
        add_action('register_form', array($this, 'twitchLoginButton')); // Add the "Login with Twitch" button
        add_action('login_form', array($this, 'twitchLoginButton')); // Add the "Login with Twitch" button
        add_action('login_enqueue_scripts', array($this, 'twitchEnqueueStylesLogin'), 10); // Add font awesome and custom style sheets
        add_action('admin_enqueue_scripts', array($this, 'twitchEnqueueStylesLogin'), 10); // Add font awesome and custom style sheets
        add_action('rest_api_init', array($this, 'addRegisterEndPoint')); // Add End Point
        add_action('admin_menu', array($this, 'loginWithTwitchSettings')); // Add menu page
        add_action('admin_init', array($this, 'registerTwitchSettings')); // Register plugin settings
        add_action( 'edit_user_profile', array($this, 'twitchUserProfileFields') ); // Add User Settings
    }

    /**
     * Create & Configure End Points
     */

    public function addRegisterEndPoint()
    {
        register_rest_route('login-with-twitch/v1', '/register/', array(
            'methods' => 'GET',
            'callback' => array($this, 'getUserAuth'),
        ));
    }

    public function authenticateUser($userCode)
    {
        $request = wp_remote_post("https://api.twitch.tv/api/oauth2/token?client_id=$this->clientID&client_secret=$this->clientSecret&code=$userCode&grant_type=authorization_code&redirect_uri=".$this->getRedirectUrl(true));
        if ($request['response']['code'] === 200) {
            return json_decode($request['body']);
        } elseif ($request['response']['code'] === 400) {
            wp_die('API Failure. Please check your settings are correct.');
        } else {
            wp_die('API failure, invalid response.');
        }
        die(); // We shouldn't land here. Just die.
    }

    function getUserAuth(WP_REST_Request $request)
    {
        /**
         * This function will trigger either creating a user or logging in an existing user.
         *  
         */
        if (isset($request['code']) && !empty($request['code'])) {
            if (preg_match("([a-z0-9]{30})", $request['code'])) {
                $twitchCode = $request['code'];
            }
        }
        if (isset($request['scope']) && !empty($request['scope'])) {
            $scope = $request['scope'];
        }
        if (empty($twitchCode) or empty($scope)) {
            wp_die('Unexpected API result - Please try to login again, if this does not work please ask the site owner to check their API settings.');
        }
        $userauth = $this->authenticateUser($twitchCode); // Initial authentication with Twitch to ensure the user is a real user. - Will die if there is an issue with authentication
        $userData = $this->getUserChannelData($userauth->access_token); // Get the users channel data. - Will die if it can't get channel data.
        //Determine if we should create user or login.
        $user = $this->findUser($userData->name, $userData->email, $userData->_id);
        if (is_array($user) && !empty($user)) {
            //We found a user so lets log them in.
            //User returns an array, 0 index should be our account were looking as it returns false if there is more than one user.
            wp_set_current_user($user[0]->ID, $userData->name); // Login user
            wp_set_auth_cookie($user[0]->ID, false, true); // Login user
            do_action('wp_login', $userData->name); // Login user
            wp_redirect(home_url()); // Redirect to homepage
            exit;
        } elseif($user === false) {
            //We don't have a user, setup a new one.
            $user = $this->createNewUser($userData->_id, $userData->name, $userData->display_name, $userData->email, $userauth->access_token);
            if ($user) {
                //User is just the ID in this case so we don't need to access any arrays or objects.
                wp_set_current_user($user, $userData->name); // Login user
                wp_set_auth_cookie($user, false, true); // Login user
                wp_redirect(home_url()); // Redirect to homepage
                exit;
            } else {
                wp_die('There was an issuer setting up your account. Please try again'); // User failed to create
            }
        }else{
            //This should never really happen.
            wp_die('There was an issue creating your account/logging you in.');
        }
        wp_die('There was an error with the login process.'); // This should never really happen.

    }

    /*
     * Create & Configure End Points - END
     */

    /**
     * Setup admin pages & fields
     */

    public function twitchUserProfileFields($user){
        ?>
        <!--suppress HtmlUnknownTarget -->
        <h2>Login With Twitch</h2>
        <table class="form-table">
            <tr>
                <th><label for="twitch_ID"><?php _e("Twitch ID"); ?></label></th>
                <td>
                    <input type="text" name="twitch_ID" id="twitch_ID" value="<?php echo esc_attr( get_user_meta( $user->ID, 'twitch_ID', true ) ); ?>" class="regular-text" readonly /><br />
                </td>
            </tr>
            <tr>
                <th><label for="user_follows"><?php _e("User follows our channel"); ?></label></th>
                <td>
                    <input type="text" name="user_follows" id="user_follows" value="<?php echo (esc_attr( get_user_meta( $user->ID, 'user_follows', true ) ) === 1) ? 'Yes' : 'No'; ?>" class="regular-text" readonly /><br />
                </td>
            </tr>
        </table>
        <?php
    }

    public function loginWithTwitchSettings()
    {
        add_menu_page(
            'login-with-twitch-settings', 'Login with Twitch', 'read', 'login-with-twitch-settings', array(
            $this,
            'loginWithTwitchIntroPage'
        ),
            'none', null
        );
        add_submenu_page('login-with-twitch-settings', 'API Settings', 'API Settings', 'manage_options', 'loginWithTwitchSettingsPage', array($this, 'twitch_admin_page_settings'));
    }

    public function loginWithTwitchIntroPage(){
        $info = get_plugin_data( __FILE__, $markup = true, $translate = true );
        $gitHubData = (!empty($this->getGithubData())) ? $this->getGithubData()[0] : false;
        ?>
        <div class="wrap">
            <div id="poststuff">
                <div id="post-body">
                    <div style="width: calc(50% - 2px); margin-right: 1px; float: left; ">
                        <h3>Login With Twitch</h3>
                        <p>Thanks for downloading my plugin. Feel free to leave me feedback on my <a href="https://github.com/cmcgee93/wp-login-with-twitch">GitHub Repo</a>. </p>
                        <p>
                            <strong>Install steps:</strong>
                        </p>
                            <ol>
                                <li>After installing this plugin head to API Settings on the left sidebar.</li>
                                <li>In the "Twitch Name" field add your twitch name.</li>
                                <li>Now head over to Twitch and <a href="https://dev.twitch.tv/dashboard/apps/create"> register your own Twitch App </a></li>
                                <li>Under OAuth Redirect URL insert the url from your dashboard in the API Settings.</li>
                                <li>Once registered you should receive a Client ID and a Client Secret. (Client ID is a public ID and everyone will see it. Do not allow to see your secret though.) </li>
                                <li>Insert the Client ID & Secret into your WordPress dashboard in the API settings section. </li>
                                <li>Now you can log out and test everything works. By using the "Login With Twitch" button on your admin login page.</li>
                            </ol>

                        <p>If you have any issues please feel free to raise an issue on GitHub using <a href="https://github.com/cmcgee93/wp-login-with-twitch/blob/master/ISSUE_TEMPLATE.md">this template.</a></p>
                </div>
                    <?php if($gitHubData !== false): ?>
                    <div style="width: calc(50% - 2px); margin-left: 1px; background: #efefef; float: left;">
                        <h3>Plugin Information</h3>
                        <ul>
                            <li>Current Install Version - <?php echo $info['Version']; ?></li>
                            <li>Latest Version - <?php echo $gitHubData->tag_name; ?></li>
                            <li>Build Name - <?php echo $gitHubData->name; ?></li>
                            <li><?php echo  ($gitHubData->prerelease === false) ? 'Release' : 'Pre-Release Build'; ?></li>
                            <li><a href="<?php echo $info['AuthorURI']; ?>">Github Repo</a></li>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }


    public function twitchLoginButton()
    {
        ?>
        <p>

            <a href="https://api.twitch.tv/kraken/oauth2/authorize?response_type=code&client_id=<?php echo (!empty($this->clientID)) ? $this->clientID : null; ?>&redirect_uri=<?php echo $this->getRedirectUrl(true); ?>"
               class="button button-large twitch-btn">Login with Twitch</a>

        </p>
        <?php
    }



    public function twitchEnqueueStylesLogin()
    {
        wp_enqueue_style('fontawesome', 'https://maxcdn.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css', false);
        wp_enqueue_style('twitch-form-styling', plugin_dir_url(__FILE__) . 'public/css/form.min.css', false, false);

    }


    public function twitch_admin_page_settings()
    {
        // Set class property
        $this->options = get_option('twitch_api_options');
        ?>
        <div class="wrap">
            <h1>Twitch API Settings</h1>
            <form method="post" action="options.php">
                <?php
                // This prints out all hidden setting fields
                settings_fields('twitch_api_settings');
                do_settings_sections('twitch-api-settings');
                submit_button();
                ?>
            </form>
            <p>Settings you need to insert into Twitch.</p>
            <table class="form-table">
                <tbody>
                <tr>
                    <th scope="row">
                        Twitch Return URL <br/>
                        <small> Allows Twitch to send data to your site after clicking "Login with Twitch" </small>
                    </th>
                    <td><input type="text" readonly size="150" value="<?php echo $this->getRedirectUrl(true);?>"></td>
                </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function registerTwitchSettings()
    {
        //We don't need to save redirect URL as the plugin should be the only creating routes.
        register_setting(
            'twitch_api_settings', // Option group
            'twitch_api_options'// Option name
        );

        add_settings_section(
            'twitch_api_settings_section', // ID
            'Twitch API main settings:', // Title
            array($this, 'settings_page_info'), // Callback
            'twitch-api-settings' // Page
        );

        add_settings_field(
            'twitch_name', // ID
            'Our Twitch Name', // Title
            array($this, 'our_twitch_name_callback'), // Callback
            'twitch-api-settings', // Page
            'twitch_api_settings_section' // Section
        );

        add_settings_field(
            'client_id', // ID
            'Client ID', // Title
            array($this, 'client_id_callback'), // Callback
            'twitch-api-settings', // Page
            'twitch_api_settings_section' // Section
        );

        add_settings_field(
            'client_secret',
            'Client Secret',
            array($this, 'client_secret_callback'),
            'twitch-api-settings',
            'twitch_api_settings_section'
        );

        add_settings_section('twitch-api-settings', 'API Settings', array($this, 'loginWithTwitchSettingsPage'), array($this, 'loginWithTwitchSettingsPage'));

    }

    public function settings_page_info()
    {
        print 'Please enter your Twitch API settings below:';
        ?>
        <?php
    }

    public function our_twitch_name_callback()
    {
        printf(
            '<input type="text" id="our_twitch_name" name="twitch_api_options[our_twitch_name]" value="%s" size="40" />',
            isset($this->options['our_twitch_name']) ? esc_attr($this->options['our_twitch_name']) : ''
        );
    }

    public function client_id_callback()
    {
        printf(
            '<input type="text" id="client_id" name="twitch_api_options[client_id]" value="%s" size="40" />',
            isset($this->options['client_id']) ? esc_attr($this->options['client_id']) : ''
        );
    }

    public function client_secret_callback()
    {
        printf(
            '<input type="password" id="client_secret" name="twitch_api_options[client_secret]" value="SECRET12345" size="40" />',
            isset($this->options['client_secret']) ? esc_attr($this->options['client_secret']) : ''
        );
    }

    /**
     * Setup admin pages & fields  - END
     */

    /**
     * User Functions
     */

    public function checkUserFollowers($user, $access_token){
        // There is a bunch of data we can return from this - URL: https://dev.twitch.tv/docs/v3/reference/follows#get-usersuserfollowschannelstarget
        $url = "https://api.twitch.tv/kraken/users/$user/follows/channels/$this->ourTwitchName";
        $args = array();
        $method = 'GET'; // or 'POST', 'HEAD', etc
        $headers = array(
            "Accept" => 'application/vnd.twitchtv.v5+json',
            "Client-ID" => "$this->clientID", // Public token
            "Authorization" => "OAuth $access_token" // Token of the user that is currently authenticated
        );
        $request = array(
            'headers' => $headers,
            'method' => $method,
        );

        if ($method == 'GET' && !empty($args) && is_array($args)) {
            $url = add_query_arg($args, $url);
        } else {
            $request['body'] = json_encode($args);
        }


        $response = wp_remote_request($url, $request);
        if($response['response']['code'] === 404){
            return false; // User isn't following.
        }else{
            return true;
        }
        die(); // Shouldn't land here. Just die in this case.
    }

    public function findUser($username, $email, $twitchID)
    {
        $args = array(
            'role'         => 'Subscriber',
            'role__not_in' => array('Administrator', 'Editor', 'Contributor'),
            'meta_key'     => 'twitch_ID',
            'meta_value'   => $twitchID,
            'meta_compare' => '=',
            'orderby'      => 'login',
            'order'        => 'ASC',
            'search'       => $email,
            'number'       => 1,
            'fields'       => 'all',
        );
        $result = get_users( $args );
        if(!empty($result) && is_array($result)){
            return $result;
        }else{
            //No user was found.
            return false;
        }
        wp_die('Error searching for users'); // Should never land here.
    }

    public function createNewUser($id, $username, $displayName, $email, $access_token)
    {
        $website = "https://twitch.tv/$username";
        $userdata = array(
            "user_login" => $username,
            "display_name" => $displayName,
            "nickname" => $displayName,
            "user_url" => $website,
            "user_pass" => wp_generate_password(32, true, true),
            "user_email" => $email,
            "role" => "Subscriber"
        );
        $user = wp_insert_user($userdata);
        if ($user) {
            wp_update_user(array('ID' => $user, 'role' => 'Subscriber'));
            update_user_meta($user, 'twitch_ID', $id);
            if($this->checkUserFollowers($username, $access_token) === true){
                //User does follow our channel
                update_user_meta($user, 'user_follows', true);
            }else{
                //User doesn't follow our channel
                update_user_meta($user, 'user_follows', false);
            }
            return $user;
        } else {
            wp_die('There was an error setting up your account');
        }
        return false;
    }

    public function getUserChannelData($access_token)
    {
        /**
         * Gets the users channel data
         * See reference: https://dev.twitch.tv/docs/v5/reference/channels#get-channel
         */
        $url = 'https://api.twitch.tv/kraken/channel';
        $args = array();
        $method = 'GET';
        $headers = array(
            "Accept" => 'application/vnd.twitchtv.v5+json',
            "Client-ID" => "$this->clientID", // Public token
            "Authorization" => "OAuth $access_token" // Token of the user that is currently authenticated
        );
        $request = array(
            'headers' => $headers,
            'method' => $method,
        );

        if ($method == 'GET' && !empty($args) && is_array($args)) {
            $url = add_query_arg($args, $url);
        } else {
            $request['body'] = json_encode($args);
        }


        $response = wp_remote_request($url, $request);
        if ($response['response']['code'] === 200) {
            return json_decode($response['body']);
        } else {
            wp_die('API failed to retrieve your acount. Please try again.');
        }
        return false;
    }
    /**
     * User Functions - END
     */

    /**
     * Utility Functions
     */

    public function getRedirectUrl($scope)
    {
        //Scope determines if we should return the Twitch scope or not.
        if($scope){
            return get_home_url() . '/wp-json/login-with-twitch/v1/register/' . $this->getScope();
        }else{
            return get_home_url() . '/wp-json/login-with-twitch/v1/register/';
        }

    }

    public function getScope()
    {
        // TODO: Refine Scope
        return "&scope=user_read+channel_read+openid+user_read+user_subscriptions+channel_check_subscription";
    }


    public function getGithubData(){
        /**
         * Quick way to grab the latest information on the plugin.
         */
        $args = array(
            'timeout'     => 5,
            'redirection' => 5,
            'httpversion' => '1.0',
            'user-agent'  => home_url(),
            'blocking'    => true,
            'headers'     => array(),
            'cookies'     => array(),
            'body'        => null,
            'compress'    => false,
            'decompress'  => true,
            'sslverify'   => true,
            'stream'      => false,
            'filename'    => null
        );
        $response = wp_remote_get( 'https://api.github.com/repos/cmcgee93/wp-login-with-twitch/releases', $args );
        if($response['response']['code'] === 200){
            return json_decode($response['body']);
        }else{
            return false;
        }
        return false;
    }
    /**
     * Utility Functions - END
     */
}

$helper = new login_with_twitch();