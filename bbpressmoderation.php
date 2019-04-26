<?php
/*
Plugin Name: bbPress Moderation
Description: Moderate bbPress topics and replies
Author: Ian Stanley
Version: 1.9.2
Author URI: http://codeincubator.co.uk


 Copyright: 		Ian Stanley, 2013- (email:iandstanley@gmail.com)
 Maintainer:		Ian Stanley, 2013-  (email iandstanley@gmail.com)
 Original Design by Ian Haycox, 2011-2013 (email : ian.haycox@gmail.com)
 Changes in version 1.9+ by Florian Höch (florian <dot> hoech <at> gmx <dot> net)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details. 

 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

class bbPressModeration {
	
	const TD = 'bbpressmoderation'; 

	/**
	 * Construct
	 * 
	 * Setup filters and actions for bbPress
	 */
	function __construct() {
		add_filter('bbp_new_topic_pre_insert', array($this, 'pre_insert'));
		add_filter('bbp_new_reply_pre_insert', array($this, 'pre_insert'));

		add_filter('bbp_new_topic_redirect_to', array($this, 'redirect_to'), 10 , 2);
		
		add_filter('bbp_has_topics_query', array($this, 'query'));
		add_filter('bbp_has_replies_query', array($this, 'query'));
		
		//add_filter('bbp_get_topic', array($this, 'topic'), 10, 3);
		add_filter('bbp_get_topic_permalink', array($this, 'permalink'), 10, 2);
		//add_filter('bbp_get_reply', array($this, 'reply'), 10, 3);
		add_filter('bbp_get_reply_permalink', array($this, 'permalink'), 10, 2);

		add_filter('bbp_get_topic_title', array($this, 'title'), 10, 2);

		add_filter('bbp_get_reply_content', array($this, 'content'), 10, 2);

		add_filter('bbp_current_user_can_publish_replies', array($this, 'can_reply'));
		
		add_action('bbp_new_topic', array($this, 'new_topic'), 10, 4);
		add_action('bbp_new_reply', array($this, 'new_reply'), 10, 5);
		
		add_action('wp_enqueue_scripts', array($this, 'wp_enqueue_scripts'));
		
		load_plugin_textdomain(self::TD, false, dirname( plugin_basename( __FILE__ ) ) . '/languages');
		
		if (is_admin()) {
			// Activation/deactivation functions
			register_activation_hook(__FILE__, array(&$this, 'activate'));
			register_deactivation_hook(__FILE__, array(&$this, 'deactivate'));
		
			// Register an uninstall hook to automatically remove options
			register_uninstall_hook(__FILE__, array('bbPressModeration', 'deinstall') );
			
			add_action('admin_init', array($this, 'admin_init' ));
			add_action('admin_menu', array($this, 'admin_menu' ));
			add_filter('plugin_action_links_' . plugin_basename(__FILE__), array(&$this, 'plugin_action_links'));
			add_filter('post_row_actions', array($this, 'post_row_actions'), 20, 2);
		
			add_action( 'pending_to_publish', array($this, 'pending_to_publish'), 10, 1 );
		
		}

		//add_filter('posts_results', array($this, 'posts_results'), 10, 2);
		add_action('init', array($this, 'approve'));
		add_filter('bbp_get_topic_admin_links', array($this, 'bbp_get_topic_admin_links'), 10, 3);
		add_filter('bbp_get_reply_admin_links', array($this, 'bbp_get_reply_admin_links'), 10, 3);
	}


	/**
	 * Approve topic or reply
	 */
	function approve() {
		if ( isset( $_GET['bbpmoderation_action'] ) ) {
			switch ( $_GET['bbpmoderation_action'] ) {
				case 'bbp_approve_topic' :
					if ( ! empty( $_GET['topic_id'] ) && check_admin_referer( 'approve-topic_' . $_GET['topic_id'] ) ) $this->approve_topic( $_GET['topic_id'] );
					break;

				case 'bbp_approve_reply' :
					if ( ! empty( $_GET['reply_id'] ) && check_admin_referer( 'approve-reply_' . $_GET['reply_id'] ) ) $this->approve_reply( $_GET['reply_id'] );
					break;
			}
		}
	}
	
	/**
	 * Activate
	 * @return boolean
	 */
	function activate() {
		// Notify admin 
		add_option(self::TD . 'always_display', 1);
		add_option(self::TD . 'widget_display', 1);
		add_option(self::TD . 'notify', 1);
		add_option(self::TD . 'always_approve_topics', 1);
		add_option(self::TD . 'always_approve_replies', 1);
		add_option(self::TD . 'previously_approved', 1);
		
		return true;
	}
	
	/**
	 * Deactivate
	 * @return boolean
	 */
	function deactivate() {
		return true;
	}
	
	/**
	 * Tidy up deleted plugin by removing options
	 */
	static function deinstall() {
		delete_option(self::TD . 'always_display');
		delete_option(self::TD . 'widget_display');
		delete_option(self::TD . 'notify');
		delete_option(self::TD . 'always_approve_topics');
		delete_option(self::TD . 'always_approve_replies');
		delete_option(self::TD . 'previously_approved');
		
		return true;
	}
	
	/**
	 * Before inserting a new topic/reply mark
	 * this as 'pending' depending on settings
	 * 
	 * @param array $data - new topic/reply data
	 */
	function pre_insert($data) {
		global $wpdb;
		
		if (@$data['post_status']=='spam') return $data; // fix for 1.8.2  hide spam 
		
		// Pointless moderating a post that the current user can approve
		if (current_user_can('moderate')) return $data;
		
		if ($data['post_author'] == 0) {
			// Anon user - check if need to moderate
			
			if ( ( 'topic' == $data['post_type'] && get_option(self::TD . 'always_approve_topics') ) || ( 'reply' == $data['post_type'] && get_option(self::TD . 'always_approve_replies') ) ) {
				// fix for v.1.8.3 separate settings for anonymous posting
				$data['post_status'] = 'pending';
			}
		} else {
			// Registered user
			if (get_option(self::TD . 'previously_approved')) {
				// Check if user already posted 
				$sql = $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d AND post_type IN ('topic','reply') AND post_status = 'publish'", $data['post_author']);
				$count = $wpdb->get_var($sql);
				if (!$count) {
					// Check if user has approved comment
					$user_info = get_userdata( $data['post_author'] );
					$sql = $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_author_email = %s AND comment_approved = 1", $user_info->user_email);
					$count = $wpdb->get_var($sql);
					if (!$count) {
						// User never posted or commented so mark as pending.
						$data['post_status'] = 'pending';
					}
				}
			} else {
				$data['post_status'] = 'pending';
			}
		}
		return $data;
	}

	/**
	 * Include in the return topic/reply list any pending posts
	 * 
	 * @param unknown_type $where
	 */
	function posts_where($where = '', $query = null) {
	
		/*
		 * Typical $where is:
		 * 
		 * " AND wp_posts.post_parent = 490 AND wp_posts.post_type IN ('topic', 'reply') AND 
		 * (wp_posts.post_status = 'publish' OR wp_posts.post_status = 'closed' OR wp_posts.post_author = 1 
		 * AND wp_posts.post_status = 'private' OR wp_posts.post_author = 1 AND wp_posts.post_status = 'hidden') 
		 * 
		 */
		
		// Real UGLY hack to add 'pending' post statuses to the
		// list of returned posts. Changes to whitespace are gonna shoot us
		// in the foot - regex anyone ?
		//
		// Might possibly mess up the query for posts without publish status !!!!
		//
		// fix to allow DB prefixes
		global $wpdb;

		// We assume it's the main query also if the 'no_found_rows' query var is empty.
		$is_main_query = $query->is_main_query() || empty( $query->query_vars['no_found_rows'] );

		$always_display = get_option( self::TD . 'always_display' );
		$widget_display = get_option( self::TD . 'widget_display' );

		if ( ! ( $is_main_query ? $always_display : $widget_display ) ) return $where;

		$where = str_ireplace($wpdb->prefix."posts.post_status = 'publish'", "(".$wpdb->prefix."posts.post_status = 'publish' OR (".$wpdb->prefix."posts.post_status = 'pending'".(current_user_can('moderate') ? "" : " AND ".$wpdb->prefix."posts.post_author = '".get_current_user_id()."'")."))", $where);				
		
		return $where;
	}

	/**
	 * When querying for topics/replies include those
	 * marked as pending otherwise we never see them
	 * 
	 * @param array $bbp topic/reply query data
	 */
	function query($bbp) {

		if (get_option(self::TD . 'always_display') || get_option(self::TD . 'widget_display')) {

				add_filter('posts_where', array($this, 'posts_where'), 10, 2);
			
			}
			else 
			{
				
				// remove_filter('posts_where', array($this, 'posts_where'));
			}
			
	
		return $bbp;
	}

	/**
	 * Trash the permalink for pending topics/replies
	 * Would be nice if we could remove the link entirely
	 * but the filter is a bit too late
	 * 
	 * @param string $permalink - topic or reply permalink
	 * @param int $topic_id - topic or reply ID
	 */
	function permalink($permalink, $topic_id) {
		global $post;
		
		if (!current_user_can('moderate') && $post && $post->post_status == 'pending' && $post->post_author != get_current_user_id()) {
			return '#';  // Fudge url to prevent viewing
		}
		
		return $permalink;
	}

	/**
	 * Alter pending topic title to indicate it
	 * is awaiting moderation
	 * 
	 * @param string $title - the title
	 * @param int $post_id - the post ID
	 * @return string New title
	 */
	function title($title, $post_id) {
		
		$post = get_post( $post_id );
		if ($post && $post->post_status == 'pending') {
			return $title . ' ' . __('(Awaiting moderation)', self::TD);
		}

		return $title;
	}

	/**
	 * Hide content for pending replies
	 * 
	 * TODO We could drop a cookie in pre_insert so the original poster can see
	 * the content but still hide it from other users. 
	 * 
	 * @param string $content - the content
	 * @param int $post_id - the post ID
	 * @return string - New content
	 */
	function content($content, $post_id) {
		$post = get_post( $post_id );
		if ($post && $post->post_status == 'pending') {
			if (current_user_can('moderate') || $post->post_author == get_current_user_id()) {
				// Admin can see body
				return __('(Awaiting moderation)', self::TD) . '<br />' . $content;
			} else {
				return __('(Awaiting moderation)', self::TD);
			}
		}

		return $content;
	}

	/**
	 * Check if newly created topic is published and
	 * disable replies until it is.
	 * 
	 * @param boolean $retval - Indicator if user can reply
	 * @return boolean - true can reply
	 */
	function can_reply($retval) {
		if (!$retval) return $retval;

		$topic_id = bbp_get_topic_id();
		
		return ('publish' == bbp_get_topic_status($topic_id));
	}

	/**
	 * Notify admin of new reply with pending status
	 * 
	 * @param int $reply_id
	 * @param int $topic_id
	 * @param int $forum_id
	 * @param boolean $anonymous_data
	 * @param int $reply_author
	 */
	function new_reply($reply_id = 0, $topic_id = 0, $forum_id = 0, $anonymous_data = false, $reply_author = 0) {
		$reply_id = bbp_get_reply_id( $reply_id );
		
		$status = bbp_get_reply_status($reply_id);
		
		if ($status == 'pending') {
			$this->notify_admin($reply_id, true);
		}
	}
	
	/**
	 * Notify admin of new topic with pending status
	 * 
	 * @param int $topic_id
	 * @param int $forum_id
	 * @param boolean $anonymous_data
	 * @param int $reply_author
	 */
	function new_topic($topic_id = 0, $forum_id = 0, $anonymous_data = false, $topic_author = 0) {
		$topic_id = bbp_get_topic_id( $topic_id );
		
		$status = bbp_get_topic_status($topic_id);
		
		if ($status == 'pending') {
			$this->notify_admin($topic_id);
		}
	}
	
	/**
	 * Alert admin of pending topic/reply
	 */
	function notify_admin($post_id, $is_reply = false) {
		
		if (get_option(self::TD . 'notify')) {
			
			$blogname = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
			$blogurl = get_option('home');
			$message  = sprintf(__('New %s awaiting moderation on your site %s: %s', self::TD), $is_reply ? 'reply' : 'topic', $blogname, $blogurl) . "\r\n\r\n";

			/* Add body of topic/reply to email */
			$post = get_post($post_id);
			$title = '';
			
			if ($post) {
				$title = bbp_get_reply_title_fallback( $post->post_title, $post_id );
				$message .= get_permalink($post->ID) . "\r\n\r\n";
				$author = get_userdata($post->post_author);
				$message .= "The following content was posted\r\n";
				if ($author !== false) {
					$name = $author->user_firstname . " " . $author->user_lastname;
					$name = trim($name);
					if (empty($name)) {
						$name = $author->display_name;
					}
					if ($name == $author->user_login) {
						$name = '';
					} else {
						$name = ' (' . $name . ')';
					}
					$message .= "by " . $author->user_login .  $name . "\r\n\r\n";
				} else {
					$message .= "by Anonymous\r\n\r\n";
				}
				$message .= $post->post_content . "\r\n\r\n";
			}
			
			@wp_mail(get_option('admin_email'), sprintf(__('[%s] bbPress Moderation - %s', self::TD), $blogname, $title), $message);
		}
	}
	
	/**
	 * Show pending counts for topics/replies and
	 * add plugin options page to Settings
	 */
	function admin_menu() {
		global $menu;
		global $wpdb;
		
		add_options_page(__('bbPress Moderation', self::TD), __('bbPress Moderation', self::TD), 
								'manage_options', self::TD, array($this, 'options'));
		
		/*
		 * Are there any pending items ?
		 */
		$sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'topic' AND post_status = 'pending'";
		$topic_count = $wpdb->get_var($sql);
		$sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'reply' AND post_status = 'pending'";
		$reply_count = $wpdb->get_var($sql);
		
		if ($reply_count || $topic_count) {
			/*
			 * Have a quick butchers at the menu structure
			 * looking for topics and replies and tack
			 * on the pending bubble count. The 5th item seems
			 * to be the class name
			 * 
			 * Bit hacky but seems to work 
			 */
			foreach ($menu as $key=>$item) {
				if ($topic_count && isset($item[5]) && $item[5] == 'menu-posts-topic') {
					$bubble = '<span class="awaiting-mod count-'.$topic_count.'"><span class="pending-count">'.number_format_i18n($topic_count) .'</span></span>';
					$menu[$key][0] .= $bubble;
				}
				if ($reply_count && isset($item[5]) && $item[5] == 'menu-posts-reply') {
					$bubble = '<span class="awaiting-mod count-'.$reply_count.'"><span class="pending-count">'.number_format_i18n($reply_count) .'</span></span>';
					$menu[$key][0] .= $bubble;
				}
			}
		}
	}
	
	/**
	 * Check if we need to redirect this pending topic
	 * back to the forum list of topics
	 * 
	 * @param string $redirect_url
	 * @param string $redirect_to
	 * @return string
	 */
	function redirect_to($redirect_url, $redirect_to) {
		
		if (!empty($redirect_url)) {
			$query = parse_url($redirect_url, PHP_URL_QUERY);
			$args = wp_parse_args($query);
			
			if (isset($args['p'])) {
				$topic_id = bbp_get_topic_id($args['p']);
				
				if ('pending' == bbp_get_topic_status($topic_id)) {
					return $_SERVER['HTTP_REFERER'];
				}
			}
		}
		
		return $redirect_url;
	}
	
	/**
	 * Add settings link to plugin admin page
	 * 
	 * @param array $links - plugin links
	 * @return array of links
	 */
	function plugin_action_links($links) {
		$settings_link = '<a href="admin.php?page=bbpressmoderation">'.__('Settings', self::TD).'</a>';
		$links[] = $settings_link;
		return $links;
	}
	
	/**
	 * Register our options
	 */
	function admin_init() {
		register_setting( self::TD.'option-group', self::TD.'always_display');
		register_setting( self::TD.'option-group', self::TD.'widget_display');
		register_setting( self::TD.'option-group', self::TD.'notify');
		register_setting( self::TD.'option-group', self::TD.'always_approve_topics');
		register_setting( self::TD.'option-group', self::TD.'always_approve_replies');
		register_setting( self::TD.'option-group', self::TD.'previously_approved');
		
	}
	
	/**
	 * Plugin settings page for various options
	 */
	function options() {
		?>
		
		<div class="wrap">
		
		<div id="icon-options-general" class="icon32"><br/></div>
		<h2><?php _e('bbPress Moderation settings', self::TD); ?></h2>
		
		<form method="post" action="options.php">
		
		<?php settings_fields( self::TD.'option-group' );?>
		
		<table class="form-table">
			
		<tr valign="top">
		<th scope="row"><?php _e('Display pending posts on forums', self::TD); ?></th>
		<td>
			<input type="checkbox" id="<?php echo self::TD; ?>always_display" name="<?php echo self::TD; ?>always_display" value="1" <?php echo (get_option(self::TD.'always_display', '') ? ' checked ' : ''); ?> />
			<label for="<?php echo self::TD; ?>always_display"><?php _e('Display for moderators and original author', self::TD); ?></label>
		</td>
		</tr>
			
		<tr valign="top">
		<th scope="row"><?php _e('Display pending posts on widgets', self::TD); ?></th>
		<td>
			<input type="checkbox" id="<?php echo self::TD; ?>widget_display" name="<?php echo self::TD; ?>widget_display" value="1" <?php echo (get_option(self::TD.'widget_display', '') ? ' checked ' : ''); ?> />
			<label for="<?php echo self::TD; ?>widget_display"><?php _e('Display for moderators and original author', self::TD); ?></label>
		</td>
		</tr>
			
		<tr valign="top">
		<th scope="row"><?php _e('Email me Whenever', self::TD); ?></th>
		<td>
			<input type="checkbox" id="<?php echo self::TD; ?>notify" name="<?php echo self::TD; ?>notify" value="1" <?php echo (get_option(self::TD.'notify', '') ? ' checked ' : ''); ?> />
			<label for="<?php echo self::TD; ?>notify"><?php _e('A topic or reply is held for moderation', self::TD); ?></label>
		</td>
		</tr>
		
		<tr valign="top">
		<th scope="row"><?php _e('Do not moderate', self::TD); ?></th>
		<td>
			<input type="checkbox" id="<?php echo self::TD; ?>previously_approved" name="<?php echo self::TD; ?>previously_approved" value="1" <?php echo (get_option(self::TD.'previously_approved', '') ? ' checked ' : ''); ?> />
			<label for="<?php echo self::TD; ?>previously_approved"><?php _e('A topic or reply by a previously approved author', self::TD); ?></label>
		</td>
		</tr>

		<tr valign="top">
		<th scope="row"><?php _e('Anonymous topics and replies', self::TD); ?></th>
		<td>
			<input type="checkbox" id="<?php echo self::TD; ?>Always_Approve_Topics" name="<?php echo self::TD; ?>always_approve_topics" value="1" <?php echo (get_option(self::TD.'always_approve_topics', '') ? ' checked ' : ''); ?> />
			<label for="<?php echo self::TD; ?>always_approve_topics"><?php _e('Always moderate topics', self::TD); ?></label>
		</td>
	</tr>
	
	<tr>
				<th scope="row"><?php _e('', self::TD); ?></th>
		<td>
			<input type="checkbox" id="<?php echo self::TD; ?>Always_Approve_Replies" name="<?php echo self::TD; ?>always_approve_replies" value="1" <?php echo (get_option(self::TD.'always_approve_replies', '') ? ' checked ' : ''); ?> />
			<label for="<?php echo self::TD; ?>always_approve_replies"><?php _e('Always moderate replies', self::TD); ?></label>
		</td>
		</tr>
		
		</table>
		
		
		<p class="submit">
		<input type="submit" class="button" value="<?php _e('Save Changes', self::TD); ?>" />
		</p>
		</form>
		
		<p>
		<?php _e('If you have any problems, please read the <a href="http://wordpress.org/extend/plugins/bbpressmoderation/faq/">FAQ</a> and if necessary contact me through the support forum on the plugin homepage.', self::TD); ?>
		</p>
		<p>
		<?php _e('If you like this plugin please consider <a href="http://codeincubator.co.uk">donating</a> to fund future development. Thank you.', self::TD); ?>
		</p>
		
		
		</div>

<?php 
	}
	
	/**
	 * Add a Spam row action
	 * 
	 * @param unknown_type $actions
	 * @param unknown_type $post
	 */
	function post_row_actions($actions, $post){
	
		global $wpdb;
		$the_id = $post->ID;
	
		// For replies:
		if ( $post->post_type =='reply' && $post->post_status == 'pending' ) {
			if ( !array_key_exists('spam', $actions) ) {
				// Mark posts as spam
				$spam_uri  = esc_url( wp_nonce_url( add_query_arg( array( 'reply_id' => $the_id, 'action' => 'bbp_toggle_reply_spam' ), remove_query_arg( array( 'bbp_reply_toggle_notice', 'reply_id', 'failed', 'super' ) ) ), 'spam-reply_'  . $the_id ) );
				if ( bbp_is_reply_spam( $the_id ) ) {
					$actions['spam'] = '<a href="' . $spam_uri . '" title="' . esc_attr__( 'Mark the reply as not spam', 'bbpress' ) . '">' . __( 'Not spam', 'bbpress' ) . '</a>';
				} else {
					$actions['spam'] = '<a href="' . $spam_uri . '" title="' . esc_attr__( 'Mark this reply as spam',    'bbpress' ) . '">' . __( 'Spam',     'bbpress' ) . '</a>';
				}
			}
			$actions['approve_reply'] = '<a href="' . esc_url( wp_nonce_url( add_query_arg( array( 'reply_id' => $the_id, 'bbpmoderation_action' => 'bbp_approve_reply' ), remove_query_arg( array( 'bbp_reply_toggle_notice', 'reply_id', 'failed', 'super' ) ) ), 'approve-reply_'  . $the_id ) ) . '" title="' . esc_attr__( 'Approve this reply',    'bbpress' ) . '">' . __( 'Approve', 'bbpress' ) . '</a>';
		}
	
		// For Topics:
		if ( $post->post_type =='topic' && $post->post_status == 'pending' ) {
			if ( !array_key_exists('spam', $actions) ) {
				// Mark posts as spam
				$spam_uri  = esc_url( wp_nonce_url( add_query_arg( array( 'topic_id' => $the_id, 'action' => 'bbp_toggle_topic_spam' ), remove_query_arg( array( 'bbp_topic_toggle_notice', 'topic_id', 'failed', 'super' ) ) ), 'spam-topic_'  . $the_id ) );
				if ( bbp_is_topic_spam( $the_id ) ) {
					$actions['spam'] = '<a href="' . $spam_uri . '" title="' . esc_attr__( 'Mark the topic as not spam', 'bbpress' ) . '">' . __( 'Not spam', 'bbpress' ) . '</a>';
				} else {
					$actions['spam'] = '<a href="' . $spam_uri . '" title="' . esc_attr__( 'Mark this topic as spam',    'bbpress' ) . '">' . __( 'Spam',     'bbpress' ) . '</a>';
				}
			}
			$actions['approve_topic'] = '<a href="' . esc_url( wp_nonce_url( add_query_arg( array( 'topic_id' => $the_id, 'bbpmoderation_action' => 'bbp_approve_topic' ), remove_query_arg( array( 'bbp_topic_toggle_notice', 'topic_id', 'failed', 'super' ) ) ), 'approve-topic_'  . $the_id ) ) . '" title="' . esc_attr__( 'Approve this topic',    'bbpress' ) . '">' . __( 'Approve',  'bbpress' ) . '</a>';
		}
	
		return $actions;
	}
	
	/**
	 * Stylesheet
	 */
	function wp_enqueue_scripts() {
		wp_enqueue_style(self::TD.'style', plugins_url('style.css', __FILE__));
	}
	
	/**
	* Detect when a pending moderated post is promoted to
	* publish, i.e. Moderator has approved post.
	* 
	* Fixes bug - http://wordpress.org/support/topic/email-notifications-for-subscribed-topics-not-working
	*
	* @param unknown_type $post
	*/
	function pending_to_publish($post) {
	
		if (!$post) return;
	
		// Only replies to topics need to notify parent
		if ($post->post_type == bbp_get_reply_post_type()) {
				
			$reply_id = bbp_get_reply_id( $post->ID);
			if ($reply_id) {
				// Get ancestors
				$ancestors = get_post_ancestors( $reply_id );
	
				if ($ancestors && count($ancestors) > 1) {
					$topic_id = $ancestors[0];
					$forum_id = $ancestors[count($ancestors)-1];
					bbp_notify_subscribers($reply_id, $topic_id, $forum_id);
				}
			}
		}
	}

	/**
	 * Approves a topic
	 *
	 * @param int $topic_id Topic id
	 * @uses bbp_get_topic() To get the topic
	 * @uses wp_update_post() To update the topic with the new status
	 * @return mixed False or {@link WP_Error} on failure, topic id on success
	 */
	function approve_topic( $topic_id = 0 ) {

		// Get topic
		$topic = bbp_get_topic( $topic_id );
		if ( empty( $topic ) )
			return $topic;

		// Bail if already approved
		if ( 'pending' !== $topic->post_status )
			return false;

		// Execute pre open code
		do_action( 'bbp_open_topic', $topic_id );

		// Set public status
		$topic->post_status = bbp_get_public_status_id();

		// Keep the goddamn date
		$topic->edit_date = $topic->post_date_gmt = get_gmt_from_date( $topic->post_date, 'Y-m-d H:i:s' );

		// No revisions
		remove_action( 'pre_post_update', 'wp_save_post_revision' );

		// Update topic
		$topic_id = wp_update_post( $topic );

		// Execute post open code
		do_action( 'bbp_opened_topic', $topic_id );

		// Return topic_id
		return $topic_id;
	}

	/**
	 * Approves a reply
	 *
	 * @param int $reply_id Reply id
	 * @uses bbp_get_reply() To get the reply
	 * @uses wp_update_post() To update the reply with the new status
	 * @return mixed False or {@link WP_Error} on failure, reply id on success
	 */
	function approve_reply( $reply_id = 0 ) {

		// Get reply
		$reply = bbp_get_reply( $reply_id );
		if ( empty( $reply ) )
			return $reply;

		// Bail if already approved
		if ( 'pending' !== $reply->post_status )
			return false;

		// Set public status
		$reply->post_status = bbp_get_public_status_id();

		// Keep the goddamn date
		$reply->edit_date = $reply->post_date_gmt = get_gmt_from_date( $reply->post_date, 'Y-m-d H:i:s' );

		// No revisions
		remove_action( 'pre_post_update', 'wp_save_post_revision' );

		// Update reply
		$reply_id = wp_update_post( $reply );

		// Return reply_id
		return $reply_id;
	}

	/**
	 * Filter admin links for topic
	 *
	 * @param string $retval Topic admin links
	 * @param array $r Parsed arguments
	 * @param array $args Raw arguments
	 * @uses bbp_get_topic_approve_link() To get the topic approval link
	 * @return string Topic admin links
	 */
	function bbp_get_topic_admin_links( $retval,  $r,  $args ) {
		if ( bbp_get_topic_status( $r['id'] ) == 'pending' ) {
			$links = array();
			unset( $r['links']['edit'] );
			unset( $r['links']['merge'] );
			foreach ( $r['links'] as $action => $link ) {
				$links[$action] = $link;
				if ( $action == 'spam' )
					$links['approve'] = $this->bbp_get_topic_approve_link( $r );
			}
			$r['links'] = $links;
		}

		// Process the admin links
		$links  = implode( $r['sep'], array_filter( $r['links'] ) );
		$retval = $r['before'] . $links . $r['after'];

		return $retval;
	}

	/**
	 * Filter admin links for reply
	 *
	 * @param string $retval Reply admin links
	 * @param array $r Parsed arguments
	 * @param array $args Raw arguments
	 * @uses bbp_get_reply_approve_link() To get the reply approval link
	 * @return string Reply admin links
	 */
	function bbp_get_reply_admin_links( $retval,  $r,  $args ) {
		if ( bbp_get_reply_status( $r['id'] ) == 'pending' ) {
			$links = array();
			unset( $r['links']['edit'] );
			unset( $r['links']['move'] );
			foreach ( $r['links'] as $action => $link ) {
				$links[$action] = $link;
				if ( $action == 'spam' )
					$links['approve'] = $this->bbp_get_reply_approve_link( $r );
			}
			$r['links'] = $links;
		}

		// Process the admin links
		$links  = implode( $r['sep'], array_filter( $r['links'] ) );
		$retval = $r['before'] . $links . $r['after'];

		return $retval;
	}

	/**
	 * Get approval link for topic
	 *
	 * @param array $args Parsed arguments
	 * @uses bbp_get_topic() To get the topic
	 * @uses bbp_get_topic_id() To get the topic ID
	 * @uses current_user_can() To check if the current user can moderate
	 *                           the topic
	 * @uses apply_filters() Calls 'bbp_get_topic_approve_link' with the
	 *                        topic approval link and args
	 * @return string Topic approval link
	 */
	function bbp_get_topic_approve_link( $args = '' ) {

		// Parse arguments against default values
		$r = bbp_parse_args( $args, array(
			'id'           => 0,
			'link_before'  => '',
			'link_after'   => '',
			'sep'          => ' | ',
			'approve_text'    => esc_html__( 'Approve',   'bbpress' )
		), 'get_topic_approve_link' );

		$topic = bbp_get_topic( bbp_get_topic_id( (int) $r['id'] ) );

		if ( empty( $topic ) || !current_user_can( 'moderate', $topic->ID ) )
			return;

		$display = $r['approve_text'];
		$uri     = add_query_arg( array( 'bbpmoderation_action' => 'bbp_approve_topic', 'topic_id' => $topic->ID ) );
		$uri     = wp_nonce_url( $uri, 'approve-topic_' . $topic->ID );
		$retval  = $r['link_before'] . '<a href="' . esc_url( $uri ) . '" class="bbp-topic-approve-link">' . $display . '</a>' . $r['link_after'];

		return apply_filters( 'bbp_get_topic_approve_link', $retval, $r );
	}

	/**
	 * Get approval link for reply
	 *
	 * @param array $args Parsed arguments
	 * @uses bbp_get_reply() To get the reply
	 * @uses bbp_get_reply_id() To get the reply ID
	 * @uses current_user_can() To check if the current user can moderate
	 *                           the reply
	 * @uses apply_filters() Calls 'bbp_get_reply_approve_link' with the
	 *                        reply approval link and args
	 * @return string Reply approval link
	 */
	function bbp_get_reply_approve_link( $args = '' ) {

		// Parse arguments against default values
		$r = bbp_parse_args( $args, array(
			'id'           => 0,
			'link_before'  => '',
			'link_after'   => '',
			'approve_text'    => esc_html__( 'Approve',   'bbpress' )
		), 'get_reply_approve_link' );

		$reply = bbp_get_reply( bbp_get_reply_id( (int) $r['id'] ) );

		if ( empty( $reply ) || !current_user_can( 'moderate', $reply->ID ) )
			return;

		$display = $r['approve_text'];
		$uri     = add_query_arg( array( 'bbpmoderation_action' => 'bbp_approve_reply', 'reply_id' => $reply->ID ) );
		$uri     = wp_nonce_url( $uri, 'approve-reply_' . $reply->ID );
		$retval  = $r['link_before'] . '<a href="' . esc_url( $uri ) . '" class="bbp-reply-approve-link">' . $display . '</a>' . $r['link_after'];

		return apply_filters( 'bbp_get_reply_approve_link', $retval, $r );
	}

	/**
	 * Filter topic if pending
	 *
	 * @param int|object $topic Topic id or topic object
	 * @param string $output Optional. OBJECT, ARRAY_A, or ARRAY_N. Default = OBJECT
	 * @param string $filter Optional Sanitation filter. See {@link sanitize_post()}
	 * @uses current_user_can() To check if the current user can moderate
	 *                           the topic
	 * @return mixed Null if error or topic (in specified form) if success
	 */
	function topic( $topic, $output, $filter ) {
		if (!current_user_can('moderate') && $topic && $topic->post_status == 'pending' && $topic->post_author != get_current_user_id())
			return null;

		return $topic;
	}

	/**
	 * Filter reply if pending
	 *
	 * @param int|object $reply Reply id or reply object
	 * @param string $output Optional. OBJECT, ARRAY_A, or ARRAY_N. Default = OBJECT
	 * @param string $filter Optional Sanitation filter. See {@link sanitize_post()}
	 * @uses current_user_can() To check if the current user can moderate
	 *                           the reply
	 * @return mixed Null if error or reply (in specified form) if success
	 */
	function reply( $reply, $output, $filter ) {
		if (!current_user_can('moderate') && $reply && $reply->post_status == 'pending' && $reply->post_author != get_current_user_id())
			return null;

		return $reply;
	}

	/**
	 * Filters pending posts from the raw post results array, prior to status checks
	 *
	 * @param array $posts  The post results array
	 * @param WP_Query The WP_Query instance (passed by reference)
	 * @uses current_user_can() To check if the current user can moderate
	 *                           the post
	 * @return array Filtered posts
	 */
	function posts_results( $posts, $query ) {
		if ( ! isset( $query->query['post_type'] ) || ! in_array( $query->query['post_type'], array( 'topic', 'reply' ) ) ) return $posts;

		$is_admin = is_admin();

		// We assume it's the main query also if the 'no_found_rows' query var is empty.
		$is_main_query = $query->is_main_query() || empty( $query->query_vars['no_found_rows'] );

		$always_display = get_option( self::TD . 'always_display' );
		$widget_display = get_option( self::TD . 'widget_display' );

		$can_moderate = current_user_can( 'moderate' );

		$filtered_posts = array();

		foreach ( $posts as $post ) {
			if ( $is_admin || $post->post_status != 'pending' || ( ( $is_main_query ? $always_display : $widget_display ) && ( $can_moderate || $post->post_author == get_current_user_id() ) ) )
				$filtered_posts[] = $post;
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'administrator' ) )
			file_put_contents( __DIR__ . '/posts_results_debug.log', print_r( $query, true ), $query->is_main_query() ? 0 : FILE_APPEND );

		return $filtered_posts;
	}
}

$bbpressmoderation = new bbPressModeration();