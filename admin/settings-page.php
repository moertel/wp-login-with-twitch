<?php
defined('ABSPATH') or die("Cannot access pages directly.");
$fields = !empty($this->getSettingsFields()) ? json_decode($this->getSettingsFields()) : null;
?>
    <!--suppress ALL -->
    <style>
        .submit {
            float: right;
        }
    </style>
    <div class="wrap">
        <h2>Login With Twitch Settings</h2>
        <div class="half">
            <form name="form1" method="post" action="options.php">
                <?php settings_fields('login_with_twitch_options'); ?>
            </form>
        </div>
    </div>
<?php /*