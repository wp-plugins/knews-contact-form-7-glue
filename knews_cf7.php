<?php
/*
Plugin Name: Knews + Contact Form 7 Glue
Plugin URI: http://www.knewsplugin.com/knews-contact-form-7-glue/
Description: Add a Knews subscription field to your Contact Form 7 forms
Author: Carles Reverter
Author URI: http://www.knewsplugin.com/
Version: 0.9.0
License: GPLv2 or later
Domain Path: /languages
Text Domain: knews_cf7
*/

class knews_cf7 {

   function __construct() {
		
		add_action( 'init', array($this, 'add_shortcode'), 5 );
		add_action( 'wpcf7_before_send_mail', array($this, 'before_send_mail') );
		add_action( 'plugins_loaded', array ($this, 'myplugin_load_textdomain') );

		add_action( 'admin_notices', array($this, 'show_admin_messages') );
		add_action( 'admin_init', array($this, 'add_tag_generator'), 20 );

	}
	
	function add_user($email, $id_list_news, $lang='en', $lang_locale='en_US', $custom_fields=array(), $bypass_confirmation=false) {

		//Hi, guys: if you're looking for add subscription to Knews method, here the official way:
		apply_filters('knews_add_user_db', 0, $email, $id_list_news, $lang, $lang_locale, $custom_fields, $bypass_confirmation);
	}

	function myplugin_load_textdomain() {
		load_plugin_textdomain( 'knews_cf7', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' ); 
	}		

	function knews_lists() {
		global $wpdb, $Knews_plugin;
		if (!$Knews_plugin->initialized) $Knews_plugin->init();

		$query = "SELECT * FROM " . KNEWS_LISTS;

		if ((version_compare(KNEWS_VERSION, '1.6.4') >= 0 && version_compare(KNEWS_VERSION, '2.0.0') < 0) ||version_compare(KNEWS_VERSION, '2.2.6') >= 0) $query .= ' WHERE auxiliary=0';
		
		if (version_compare(KNEWS_VERSION, '1.2.3') >= 0) $query .= " ORDER BY orderlist";
		
		return $wpdb->get_results( $query );
	}

	function add_shortcode() {
		if( function_exists('wpcf7_add_shortcode') ) {
			wpcf7_add_shortcode( array( 'knewssubscription', 'knewssubscription*' ),
				array($this, 'shortcode_handler'), true );
		}
	}

	function shortcode_handler( $tag ) {

		$tag = new WPCF7_Shortcode( $tag );
	
		if ( empty( $tag->name ) ) return '';
	
		$validation_error = wpcf7_get_validation_error( $tag->name );
	
		$class = wpcf7_form_controls_class( $tag->type );
	
		if ( $validation_error ) {
			$class .= ' wpcf7-not-valid';
		}
	
		$atts = array();
	
		$atts['class'] = $tag->get_class_option( $class );
		$atts['id'] = $tag->get_option( 'id', 'id', true );
	
		// get checkbox value
		// first get all of the lists
		global $Knews_plugin;
		$lists = $this->knews_lists();
		$lang = $Knews_plugin->pageLang();

		if (is_array($lang) && isset($lang['language_code']) && count($lists) != 0) {

			$checkbox_values = array();
			foreach ($lists as $list) {
				if ( $tag->has_option( 'knews_cf7_' . $lang['language_code'] . '_' . $list->id ) ) 
					$checkbox_values[] = $list->id;
			}
		}
				
		// we still want a value for the checkbox so *some* data gets posted
		if ( ! empty ( $checkbox_values ) ) {
			// now implode them all into a comma separated string
			$atts['value'] = implode( $checkbox_values, "," );
		} else {
			// set a 0 so we know to add the user to Knews but not to any specific list
			$atts['value'] = "0";
		}
	
	
		if ( $tag->is_required() ) {
			$atts['aria-required'] = 'true';
		}
	
		// set default checked state
		$atts['checked'] = "";
		if ( $tag->has_option( 'default:on' ) ) {
			$atts['checked'] = 'checked';
		}
	
		$value = (string) reset( $tag->values );
	
		if ( '' !== $tag->content ) {
			$value = $tag->content;
		}
	
		if ( wpcf7_is_posted() && isset( $_POST[$tag->name] ) ) {
			$value = stripslashes_deep( $_POST[$tag->name] );
		}
	
		$atts['name'] = $tag->name;
		$id = $atts['id'] = $atts['name'];
	
		$atts = wpcf7_format_atts( $atts );
	
		// get the content from the tag to make the checkbox label
		$label = __( 'Subscribe me to the newsletter', 'knews_cf7' );
		$values = $tag->values;

		if( isset( $values ) && !empty ($values) ){
			$label = esc_textarea( $values[0] );
		}
	
		/*$html = sprintf(
			'<span class="wpcf7-form-control-wrap %1$s"><input type="checkbox" %2$s />&nbsp;</span><label for="%3$s">%4$s</label>&nbsp;%5$s',
			$tag->name, $atts, $id, $value, $validation_error );*/
		
		$html = sprintf(
			'<span class="wpcf7-form-control-wrap %1$s"><input type="checkbox" %2$s />&nbsp;</span><label for="%3$s">%4$s</label>&nbsp;%5$s',
			$tag->name, $atts, $id, $label, $validation_error );

		$lang = $Knews_plugin->pageLang();
		if (is_array($lang) && isset($lang['language_code']) && count($lists) != 0) {
			$html .= '<input type="hidden" name="lang_' . $id . '" value="' . $lang['language_code'] . '" />';
			$html .= '<input type="hidden" name="locale_' . $id . '" value="' . $lang['localized_code'] . '" />';
		}
		
		return $html;
	}

	/* Process the form field */
	function before_send_mail( $contactform ) {
		global $Knews_plugin;
		
		// make sure the user has Knews installed & active
		if ( !defined('KNEWS_VERSION') ) return;
	
		if (! empty( $contactform->skip_mail )) return;
	
		$posted_data = null;
		if ( class_exists( 'WPCF7_Submission' ) ) {// for Contact-Form-7 3.9 and above, http://contactform7.com/2014/07/02/contact-form-7-39-beta/
			$submission = WPCF7_Submission::get_instance();
			if ( $submission ) {
				$posted_data = $submission->get_posted_data();
			}
		} elseif ( ! empty( $contactform->posted_data ) ) {// for Contact-Form-7 older than 3.9
			$posted_data = $contactform->posted_data;
		}

		// and make sure they have something in their contact form
		if ( empty($posted_data)) return;
		
		$user_cf = array();	
		$user_email = '';
		$bypass_confirmation = false;
		$knews_extra_fields = $Knews_plugin->get_extra_fields();

		//Lets decode the tag to get field names		
		$props = $contactform->get_properties();
		$mytags = explode('[knewssubscription', $props['form']);
		if (count($mytags) < 2) return;

		$mytags = explode(']', $mytags[1]);
		if (count($mytags) < 2) return;

		$mytags = explode(' ', $mytags[0]);

		foreach ($mytags as $mytag) {
			$mytag = explode(':', $mytag);
			if (count($mytag==2)) {

				if ('knews_cf7_field_email' == $mytag[0]) $user_email = isset($posted_data[$mytag[1]]) ? trim($posted_data[$mytag[1]]) : '';
				if ('notoptin' == $mytag[0] && 'on' == $mytag[1]) $bypass_confirmation = true;

				foreach ($knews_extra_fields as $ef) {
					if ('knews_cf7_field_' . $ef->id == $mytag[0]) {
						$user_cf[$ef->id] = isset($posted_data[$mytag[1]]) ? trim($posted_data[$mytag[1]]) : '';
					}
				}
			}
		}
	
		// find all of the keys in $posted_data that belong to knews plugin
		$keys			= array_keys($posted_data);
		$knews_signups = preg_grep("/^knewssubscription.*/", $keys);

		$knews_lists   = array( );
		$lang = 'en';
		$locale = 'en_US';

		if (isset($posted_data['_wpcf7_locale']) && $posted_data['_wpcf7_locale']!='') {
			$locale = $posted_data['_wpcf7_locale'];
			$langtmp = explode('_', $locale);
			if (is_array($langtmp) && isset($langtmp[0])) $lang = $langtmp[0];
		}
				
		if (!empty($knews_signups)) {
			foreach ($knews_signups as $knews_signup_field) {

				if (isset($posted_data['lang_' . $knews_signup_field]) && $posted_data['lang_' . $knews_signup_field] != '') $lang = $posted_data['lang_' . $knews_signup_field];
				if (isset($posted_data['locale_' . $knews_signup_field]) && $posted_data['locale_' . $knews_signup_field] != '') $locale = $posted_data['locale_' . $knews_signup_field];

				$_field = trim($posted_data[$knews_signup_field]);
				if (!empty($_field)) {
					$knews_lists = array_unique(array_merge($knews_lists, explode(",", $posted_data[$knews_signup_field])));
				}
			}
		}
		
		if (empty($knews_lists)) return;
		
		//No support for multiple mailing lists support yet, added in new release
		if ((version_compare(KNEWS_VERSION, '1.7.1') <= 0 && version_compare(KNEWS_VERSION, '2.0.0') < 0) || version_compare(KNEWS_VERSION, '2.3.3') <= 0) {
			$knews_lists = $knews_lists[0];
		}
		
		if (!$Knews_plugin->initialized) $Knews_plugin->init();
		
		//Subscribe
		$this->add_user($user_email, $knews_lists, $lang, $locale, $user_cf, $bypass_confirmation);
	}


	/* Admin side */
	function show_admin_messages() {
		$html_error = '';
		if (!defined('WPCF7_VERSION')) $html_error = __('Contact Form 7 is not ready. ', 'knews_cf7' );
		if (!defined('KNEWS_VERSION')) $html_error .= __('Knews is not ready (you can use with Knews or Knews Pro).', 'knews_cf7' );
		
		if ($html_error != '') 
			echo '<div class="error"><p><strong>' . __( 'Knews + Contact Form 7 Glue:', 'knews_cf7' ) . '</strong> '
			. $html_error . '</p></div>';
	}
	
	function add_tag_generator() {
	if ( ! function_exists( 'wpcf7_add_tag_generator' ) ) return;

		wpcf7_add_tag_generator( 'knewssubscription', __( 'Knews Subscription', 'knews_cf7' ),
			'wpcf7-tg-pane-knewssubscription', array($this, 'tag_pane') );
	}

	function tag_pane( $contact_form ) {
		global $Knews_plugin;
		?>
		<div id="wpcf7-tg-pane-knewssubscription" class="hidden">
		<form action="">
			<table>
				<tr>
					<td>
						<?php echo esc_html( __( 'Name', 'knews_cf7' ) ); ?><br /><input type="text" name="name" class="tg-name oneline" />
					</td>
					<td></td>
				</tr>
			</table>
	
			<table>
				<tr>
					<td>
						<?php
						$lists = $this->knews_lists();

						if (count($lists) != 0) {

							if (KNEWS_MULTILANGUAGE) {

								$languages = $Knews_plugin->getLangs();
								foreach ($languages as $l) {
									printf( __( 'Select mailing list for %s users:', 'knews_cf7'), $l['native_name']); echo '<br />';
									foreach ($lists as $list) {
										echo '<input type="checkbox" name="knews_cf7_' . $l['language_code'] . '_' . $list->id . '" class="option knews_cf7_one">' . $list->name . '</option><br />';
									}
									echo '</br>';
								}

							} else {
								
								echo __( 'Select mailing list:', 'knews_cf7') . '<br>';
								foreach ($lists as $list) {
									echo '<input type="checkbox" name="knews_cf7_' . $list->id . '" class="option knews_cf7_one">' . $list->name . '</option>';
								}
								echo '</br>';

							}
						} else {
							echo '<span style="color:red">' . __('Create some mailing list first', 'knews_cf7' ) . '</span><br />';
						}

						?>
					</td>
					<td>
						<code><?php _e('checkbox label', 'knews_cf7'); ?></code><br />
						<textarea name="values"></textarea>
					</td>
				</tr>
				<tr>
					<td colspan="2">
						<code><?php _e('Configuration', 'knews_cf7'); ?></code><br />
						<input type="checkbox" name="required" />&nbsp;<?php echo esc_html( __( 'Required field? (will force subscription)', 'knews_cf7' ) ); ?><br>
						<input type="checkbox" name="default:on" class="option" />&nbsp;<?php echo esc_html( __( "Make subscription option checked by default", 'knews_cf7' ) ); ?><br />
						<input type="checkbox" name="notoptin:on" class="option" />&nbsp;<?php echo esc_html( __( "Do not send optin/confirmation (will breach European law)", 'knews_cf7' ) ); ?>
					</td>
				</tr>
				<tr>
					<td colspan="2">
						<br />
						<code><?php _e('Data collection', 'knews_cf7'); ?></code> <?php _e('(blank fields will not be collected)', 'knews_cf7'); ?><br />
						<?php
						echo '<input type="text" name="knews_cf7_field_email" class="oneline option" value="your-email" style="width:auto" /> -&gt; ' . __('email','knews_cf7') . '<br />';
						$extra_fields = $Knews_plugin->get_extra_fields();
						foreach ($extra_fields as $f) {
							$value = ($f->name=='name') ? 'your-name' : '';
							echo '<input type="text" name="knews_cf7_field_' . $f->id . '" class="oneline option" value="' . $value . '" style="width:auto" /> -&gt; ' . $f->name . '<br />';
						}
						?>
					</td>
				</tr>
				<tr>
					<td><code>id</code> (<?php echo esc_html( __( 'optional', 'knews_cf7' ) ); ?>)<br />
						<input type="text" name="id" class="idvalue oneline option" />
					</td>
					<td><code>class</code> (<?php echo esc_html( __( 'optional', 'knews_cf7' ) ); ?>)<br />
						<input type="text" name="class" class="classvalue oneline option" />
					</td>
				</tr>
	
			</table>
	
			<div class="tg-tag"><?php echo esc_html( __( "Copy this code and paste it into the form left.", 'knews_cf7' ) ); ?><br /><input type="text" name="knewssubscription" class="tag" readonly onfocus="this.select()" /></div>
	
			<div class="tg-mail-tag"><?php echo esc_html( __( "And, put this code into the Mail fields below.", 'knews_cf7' ) ); ?><br /><input type="text" class="mail-tag" readonly onfocus="this.select()" /></div>
		</form>
		</div>
		<?php
	}
	
}

if (!isset($knews_cf7)) $knews_cf7 = new knews_cf7();

