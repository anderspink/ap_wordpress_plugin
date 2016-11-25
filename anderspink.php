<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://anderspink.com
 * @since             1.0.0
 * @package           Anderspink
 *
 * @wordpress-plugin
 * Plugin Name:       Anders Pink plugin
 * Plugin URI:        https://anderspink.com/wordpress-plugin
 * Description:       Anders Pink plugin for embedding your AP briefings and boards into WordPress
 * Version:           1.0.0
 * Author:            Anders Pink
 * Author URI:        https://anderspink.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       anderspink
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

const ANDERSPINK_URL = 'https://anderspink.com/api/v1';


// Creating the widget 
class AndersPink_Widget extends WP_Widget {

    function __construct() {
        parent::__construct(
            // Base ID of your widget
            'anderspink_widget', 

            // Widget name will appear in UI
            __('Anders Pink', 'anderspink_domain'), 

            // Widget description
            array(
                'description' => __('Anders Pink widget to display your briefings and boards.', 'anderspink_domain'),
            ) 
        );
    }

    // Creating widget front-end
    // This is where the action happens
    public function widget( $args, $instance ) {
        $title = apply_filters('widget_title', $instance['title']);
        // before and after widget arguments are defined by themes
        echo $args['before_widget'];
        if (!empty($title)) {
            echo $args['before_title'] . $title . $args['after_title'];
        }
        
        $this->renderWidget($instance);
        
        echo $args['after_widget'];
    }
    
    private function renderWidget($instance) {
        
        $options = get_option('ap_settings');
        
        if (!$options || !isset($options['ap_api_key']) || strlen(trim($options['ap_api_key'])) === 0) {
            echo __('Please set your Anders Pink API key (or use the free key) in the admin settings.', 'anderspink_domain');
            return;
        }
        
        $url = null;
        $expiry = null;
        if ($instance['source'] === 'briefing') {
            $url = ANDERSPINK_URL . "/briefings/{$instance['briefing']}?limit={$instance['limit']}";
            $expiry = 60;
        } else if ($instance['source'] === 'board') {
            $url = ANDERSPINK_URL . "/boards/{$instance['board']}?limit={$instance['limit']}";
            $expiry = 15;
        }
        
        // Do we have a cached version of this?
        $key =  'ap_' . md5($url . $options['ap_api_key']);
        
        $cachedData = get_transient($key);
        $data = null;
        
        if ($cachedData) {
            $data = json_decode($cachedData, true);
        } else {
            $response = wp_remote_get($url, array(
                'timeout' => 3,
                'headers' => array(
                    'X-Api-Key' => $options['ap_api_key'],
                    'Content-Type' => 'application/json'
                )
            ));
            
            if (!is_array($response)) {
                echo __('Sorry, there was an error connecting to the Anders Pink server', 'anderspink_domain');
                return;
            }
            $data = json_decode($response['body'], true);
            if (!$data) {
                echo __('Sorry, there was an error connecting to the Anders Pink server', 'anderspink_domain');
                return;
            }
            if ($data['status'] !== 'success') {
                echo __('Error:', 'anderspink_domain') . ' ' . $data['message'];
                return;
            }
            
            // Save in the cache..
            set_transient($key, json_encode($data), $expiry);
        }
        
        // Get the html for the individual articles
        $articleHtml = array();
        foreach (array_slice($data['data']['articles'], 0, $instance['limit']) as $article) {
            $articleHtml[] = $this->renderArticle($article, $instance['image']);
        }
        
        if ($instance['column'] === '1') {
            echo implode("\n", $articleHtml);
        } else if ($instance['column'] === '2') {
            echo '<div class="ap-columns">' .
                    implode("\n", array_map(function($item) {
                        return '<div class="ap-two-column">' . $item . '</div>';
                    }, $articleHtml)) .
                '</div>';
        }
    }
    
    private function renderArticle($article, $imagePosition='side') {

        $side = $imagePosition === 'side';

        $extra = array();
        if ($article['domain']) {
            $extra[] = $article['domain'];
        }
        if ($article['date_published']) {
            $extra[] = $this->time2str($article['date_published']);
        }

        $image = "";
        if ($article['image']) {
            $image = "
                <div class='" . ($side ? "ap-article-image-container-side" : "ap-article-image-container-top") . "'>
                    <div class='" . ($side ? "ap-article-image-container-side-inner" : "ap-article-image-container-top-inner") . "' style='background-image:url({$article['image']})'>
                    </div>
                </div>
            ";
        }

        $cutoff = 75;
        $title = strlen(trim($article['title'])) > $cutoff ? substr($article['title'],0,$cutoff) . "..." : $article['title'];

        return "
            <a class='ap-article' href='{$article['url']}' title='" . htmlspecialchars($article['title'], ENT_QUOTES) . "' target='_blank'>
                {$image}
                <div class='" . (($side && $article['image']) ? 'ap-margin-right' : '') . "'>
                    <div>". htmlspecialchars($title) . "</div>
                    <div class='ap-article-text-extra'>". implode(' - ', $extra) ."</div>
                </div>
            </a>
        ";
    }
    
    private function time2str($ts) {
        if(!ctype_digit($ts)) {
            $ts = strtotime($ts);
        }
        $diff = time() - $ts;
        if($diff == 0) {
            return 'now';
        } elseif($diff > 0) {
            $day_diff = floor($diff / 86400);
            if($day_diff == 0) {
                if($diff < 60) return 'just now';
                if($diff < 120) return '1m';
                if($diff < 3600) return floor($diff / 60) . 'm';
                if($diff < 7200) return '1h';
                if($diff < 86400) return floor($diff / 3600) . 'h';
            }
            if($day_diff == 1) { return '1d'; }
            if($day_diff < 7) { return $day_diff . 'd'; }
            if($day_diff < 31) { return ceil($day_diff / 7) . 'w'; }
        }
        return date('F Y', $ts);
    } 
    		
    // Widget Backend 
    public function form( $instance ) {
        
        
        $options = get_option('ap_settings');
        
        if (!$options || !isset($options['ap_api_key']) || strlen(trim($options['ap_api_key'])) === 0) {
            echo '<p>' . __('Please set your Anders Pink API key (or use the free key) in the admin settings.', 'anderspink_domain') . '</p>';
            return;
        }
        
        // Do a curl call to get the briefings/boards...
        
        $response1 = wp_remote_get(ANDERSPINK_URL . '/briefings', array(
            'timeout' => 3,
            'headers' => array(
                'X-Api-Key' => $options['ap_api_key'],
                'Content-Type' => 'application/json'
            )
        ));
        $response2 = null;
        if ($response1) {
            // Only bother with the second if the first works..
            $response2 = wp_remote_get(ANDERSPINK_URL . '/boards', array(
                'timeout' => 3,
                'headers' => array(
                    'X-Api-Key' => $options['ap_api_key'],
                    'Content-Type' => 'application/json'
                )
            ));
        }
        
        if (!is_array($response1) || !is_array($response2)) {
            echo '<p>' . __('Sorry, there was an error connecting to the AP server', 'anderspink_domain') . '</p>';
            return;
        }
        
        $data1 = json_decode($response1['body'], true);
        $data2 = json_decode($response2['body'], true);
        if (!$data1 || !$data2) {
            echo '<p>' . __('Sorry, there was an error connecting to the AP server', 'anderspink_domain') . '</p>';
            return;
        }
        if ($data1['status'] !== 'success') {
            echo '<p>' . __('Error:', 'anderspink_domain') . ' ' . $data1['message'] . '</p>';
            return;
        }
        if ($data2['status'] !== 'success') {
            echo '<p>' . __('Error:', 'anderspink_domain') . ' ' . $data2['message'] . '</p>';
            return;
        }
        
        
        $briefings = [];
        $boards = [];
        foreach ($data1['data']['owned_briefings'] as $briefing) {
            $briefings[$briefing['id']] = $briefing['name'];
        }
        foreach ($data1['data']['subscribed_briefings'] as $briefing) {
            $briefings[$briefing['id']] = $briefing['name'];
        }
        foreach ($data2['data']['owned_boards'] as $board) {
            $boards[$board['id']] = $board['name'];
        }
        
        //var_dump($data);
        
        
        $title = isset($instance['title']) ? $instance['title'] :  __('Anders Pink', 'anderspink_domain');
        $source = isset($instance['source']) ? $instance['source'] : 'briefing';
        $briefing = isset($instance['briefing']) ? $instance['briefing'] : null;
        $board = isset($instance['board']) ? $instance['board'] : null;
        $image = isset($instance['image']) ? $instance['image'] : 'side';
        $column = isset($instance['column']) ? $instance['column'] : '1';
        $limit = isset($instance['limit']) ? $instance['limit'] : '5';
        
        // Widget admin form
        ?>
            <p>
                <label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:'); ?></label> 
                <input class="widefat" 
                       id="<?= $this->get_field_id('title'); ?>"
                       name="<?= $this->get_field_name('title'); ?>"
                       type="text"
                       value="<?php echo esc_attr( $title ); ?>" 
                />
            </p>
            <p>
                <input type="radio" name="<?= $this->get_field_name('source'); ?>" value="briefing" <?= $source === 'briefing' ? 'checked' : '' ?> /> Show a briefing &nbsp;
                <input type="radio" name="<?= $this->get_field_name('source'); ?>" value="board" <?= $source === 'board' ? 'checked' : '' ?> /> Show a board
            </p>
            <p id="<?= $this->get_field_id('briefing_section'); ?>">
                <label for="<?php echo $this->get_field_id('briefing'); ?>"><?php _e('Briefings:'); ?></label> 
                <select class="widefat" 
                       id="<?= $this->get_field_id('briefing'); ?>"
                       name="<?= $this->get_field_name('briefing'); ?>"
                >
                    <?php foreach ($briefings as $id => $name): ?>
                        <option value="<?= $id ?>" <?= $id == $briefing ? 'selected' : '' ?> ><?= $name ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p id="<?= $this->get_field_id('board_section'); ?>"
                <label for="<?php echo $this->get_field_id('board'); ?>"><?php _e('Saved boards:'); ?></label> 
                <select class="widefat" 
                       id="<?= $this->get_field_id('board'); ?>"
                       name="<?= $this->get_field_name('board'); ?>"
                >
                    <?php foreach ($boards as $id => $name): ?>
                        <option value="<?= $id ?>" <?= $id == $board ? 'selected' : '' ?>><?= $name ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p>
                <label for="<?php echo $this->get_field_id('image'); ?>"><?php _e('Article image position:'); ?></label><br />
                <input type="radio" name="<?= $this->get_field_name('image'); ?>" value="side" <?= $image === 'side' ? 'checked' : '' ?> /> On the right (small) &nbsp;
                <input type="radio" name="<?= $this->get_field_name('image'); ?>" value="top" <?= $image === 'top' ? 'checked' : '' ?> /> Above (large)
            
            </p>
            <p>
                <label for="<?php echo $this->get_field_id('column'); ?>"><?php _e('Number of columns:'); ?></label><br />
                <input type="radio" name="<?= $this->get_field_name('column'); ?>" value="1" <?= $column === '1' ? 'checked' : '' ?> /> One column &nbsp;
                <input type="radio" name="<?= $this->get_field_name('column'); ?>" value="2" <?= $column === '2' ? 'checked' : '' ?> /> Two columns
            </p>
            <p>
                <label for="<?php echo $this->get_field_id('limit'); ?>"><?php _e('Number of articles to show (min 1, max 30):'); ?></label> 
                <input class="widefat" 
                       id="<?= $this->get_field_id('limit'); ?>"
                       name="<?= $this->get_field_name('limit'); ?>"
                       type="text"
                       value="<?= $limit ?>" 
                />
            </p>
            <script type="text/javascript">
                (function($) {
                    $(function() {
                        
                        var sourceInputName = "<?= $this->get_field_name('source'); ?>";
                        var briefingSectionId = "<?= $this->get_field_id('briefing_section'); ?>";
                        var boardSectionId = "<?= $this->get_field_id('board_section'); ?>";
                        
                        function handleSourceVisibility(source) {
                            if (source === "briefing") {
                                $("#" + briefingSectionId).show();
                                $("#" + boardSectionId).hide();
                            } else {
                                $("#" + briefingSectionId).hide();
                                $("#" + boardSectionId).show();
                            }
                        }
                        
                        handleSourceVisibility($("[name='" + sourceInputName + "']:checked").val());
                        
                        $("[name='" + sourceInputName + "']").change(function(e) {
                            handleSourceVisibility(this.value);
                        });
                        
                    });
                })(jQuery);
            </script>
        <?php 
    }
    	
    // Updating widget replacing old instances with new
    public function update( $new_instance, $old_instance ) {
        $instance = array();
        $instance['title'] = ( ! empty( $new_instance['title'] ) ) ? strip_tags( $new_instance['title'] ) : '';
        $instance['source'] = ( ! empty( $new_instance['source'] ) ) ? strip_tags( $new_instance['source'] ) : '';
        $instance['briefing'] = ( ! empty( $new_instance['briefing'] ) ) ? strip_tags( $new_instance['briefing'] ) : '';
        $instance['board'] = ( ! empty( $new_instance['board'] ) ) ? strip_tags( $new_instance['board'] ) : '';
        $instance['image'] = ( ! empty( $new_instance['image'] ) ) ? strip_tags( $new_instance['image'] ) : 'side';
        $instance['column'] = ( ! empty( $new_instance['column'] ) ) ? strip_tags( $new_instance['column'] ) : '1';
        $instance['limit'] = ( ! empty( $new_instance['limit'] ) ) ? strip_tags( $new_instance['limit'] ) : '5';
        return $instance;
    }
}

// Register and load the widget
function wpb_load_widget() {
	register_widget('AndersPink_Widget');
}

add_action('widgets_init', 'wpb_load_widget');





/**
 * Admin page
 */

add_action( 'admin_menu', 'ap_add_admin_menu' );
add_action( 'admin_init', 'ap_settings_init' );

function ap_add_admin_menu() { 
	add_options_page('Anderspink', 'Anders Pink', 'manage_options', 'anderspink', 'ap_options_page');
}

function ap_settings_init() { 
	register_setting('pluginPage', 'ap_settings');
	add_settings_section(
		'ap_pluginPage_section', 
		null, 
		'ap_settings_section_callback', 
		'pluginPage'
	);
	add_settings_field( 
		'ap_api_key', 
		__('Anders Pink API key', 'anderspink'), 
		'ap_api_key_render', 
		'pluginPage', 
		'ap_pluginPage_section' 
	);
}


function ap_api_key_render() { 
	$options = get_option('ap_settings');
	?>
	<input type='text' size='44' name='ap_settings[ap_api_key]' value='<?= isset($options['ap_api_key']) ? $options['ap_api_key'] : ''; ?>'>
	<?php
}

function ap_settings_section_callback() { 
}


function ap_options_page() { 
	?>
    	<form action='options.php' method='post'>
    		<h2>Anders Pink</h2>
            <p>
                Enter the API key from your Anders Pink account or use our free account key which is <strong>WL4VDET6kcH29PDTG2RVF60Yqv76E39z</strong> to access our free briefings.
            </p>
            <p>
                To find out more about how you can create custom briefings visit <a href="https://anderspink.com">https://anderspink.com</a>.
            </p>
    		<?php
        		settings_fields( 'pluginPage' );
        		do_settings_sections( 'pluginPage' );
        		submit_button();
    		?>
    	</form>
	<?php
}


add_action('init', 'ap_register_resources');
function ap_register_resources() {
    wp_register_style('anderspink', plugins_url('style.css',__FILE__ ));
}


add_action('wp_enqueue_scripts', 'ap_enqueue_resources');
function ap_enqueue_resources(){
    wp_enqueue_style('anderspink');
}
