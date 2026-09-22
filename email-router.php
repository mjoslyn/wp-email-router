<?php
/**
 * Email Router: intercept outgoing wp_mail and rewrite its recipients.
 *
 * Extracted from the hello-elementor-child theme (inc/email-interceptor.php), which
 * stored its rules under email_interceptor_replacer_settings. This plugin uses a
 * different option key, so a site coming from the theme version starts with an empty
 * rule set — see README.md to carry the old rules across.
 *
 * @package Email_Router
 *
 * @wordpress-plugin
 * Plugin Name: Email Router
 * Description: Intercepts outgoing emails and routes/replaces recipients based on target addresses, subject patterns, and a blacklist. Adds a Tools > Email Router admin page.
 * Version: 1.4.0
 * Author: Mike Joslyn
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: email-router
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * Email Router is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 2 of the License, or (at your option) any
 * later version. See the bundled LICENSE file for the full text.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rewrites the recipients of outgoing wp_mail and renders the admin UI.
 */
class EmailRouter {

	/**
	 * Singleton instance.
	 *
	 * @var EmailRouter|null
	 */
	private static $instance = null;

	/**
	 * Name of the option holding every routing rule.
	 *
	 * @var string
	 */
	private $option_name = 'email_router_settings';

	/**
	 * Registers the mail filters and admin hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'settings_init' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'wp_ajax_email_router_usage', array( $this, 'ajax_usage' ) );
		add_action( 'admin_post_email_router_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_email_router_system_emails_export', array( $this, 'handle_system_emails_export' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
		// Priority 20: runs on the recipients replace_by_subject() leaves behind.
		add_filter( 'wp_mail', array( $this, 'replace_emails' ), 20 );
		add_filter( 'wp_mail', array( $this, 'replace_by_subject' ), 10 );
	}

	/**
	 * Enqueues the admin JS and inline CSS on this plugin's screen only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( 'tools_page_email_router' !== $hook ) {
			return;
		}

		wp_enqueue_script( 'jquery-ui-autocomplete' );

		wp_add_inline_style(
			'wp-admin',
			'
      /* Email Router Styling */
      .email-router-section {
        background: #fff;
        border: 1px solid #ccd0d4;
        border-radius: 4px;
        margin: 20px 0;
        box-shadow: 0 1px 1px rgba(0,0,0,.04);
      }
      
      .email-router-section h2 {
        background: #f1f1f1;
        border-bottom: 1px solid #ccd0d4;
        padding: 15px 20px;
        margin: 0;
        font-size: 14px;
        font-weight: 600;
      }
      
      .email-pairs-table, .subject-pairs-table, .blacklist-table {
        margin: 0;
        border: none;
        table-layout: fixed;
      }
      
      .email-pairs-table .column-title {
        width: 180px;
        padding-right: 20px;
      }

      .email-pairs-table .column-target {
        width: 320px;
        padding-right: 20px;
      }
      
      .email-pairs-table .column-replacement {
        width: auto;
        padding-left: 20px;
        padding-right: 20px;
      }
      
      .email-pairs-table .column-actions {
        width: 100px;
        padding-left: 20px;
      }
      
      .subject-pairs-table .column-pattern {
        width: 200px;
        padding-right: 20px;
      }
      
      .subject-pairs-table .column-recipients {
        width: auto;
        padding-left: 20px;
        padding-right: 20px;
      }
      
      .subject-pairs-table .column-actions {
        width: 100px;
        padding-left: 20px;
      }
      
      .blacklist-table .column-email {
        width: auto;
      }
      
      .blacklist-table .column-status {
        width: 120px;
      }
      
      .blacklist-table .column-actions {
        width: 100px;
      }
      
      /* Constrain form fields to their columns */
      .email-pairs-table input,
      .subject-pairs-table input,
      .blacklist-table input {
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
      }
      
      .email-pairs-table .column-target input {
      }
      
      .subject-pairs-table .column-pattern input {
      }
      
      .blacklist-table .column-email input {
        max-width: 100%;
      }
      
      .tablenav.top {
        padding: 15px 8px;
      }
      
      .rule-status {
        padding: 2px 8px;
        border-radius: 3px;
        font-size: 11px;
        font-weight: bold;
      }
      
      .rule-status.active {
        background: #d4edda;
        color: #155724;
      }
      
      .rule-status.blocked {
        background: #f8d7da;
        color: #721c24;
      }
      
      .nav-tab .dashicons {
        vertical-align: middle;
        margin-top: -2px;
      }
      
      .button-group {
        white-space: nowrap;
        display: flex;
        flex-direction: column;
        gap: 4px;
        align-items: stretch;
      }
      
      .button-group .button {
        margin: 0;
      }

      /* Icon action buttons (replacement + subject rows) */
      .email-pairs-table .column-actions .button-group,
      .subject-pairs-table .column-actions .button-group {
        flex-direction: row;
        gap: 4px;
        align-items: center;
      }
      .email-pairs-table .column-actions .button,
      .subject-pairs-table .column-actions .button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 30px;
        padding: 0;
      }
      .email-pairs-table .column-actions .button .dashicons,
      .subject-pairs-table .column-actions .button .dashicons {
        font-size: 16px;
        width: 16px;
        height: 16px;
        line-height: 16px;
      }
      .duplicate-email-pair:hover,
      .duplicate-subject-pair:hover {
        color: #2271b1;
        border-color: #2271b1;
      }
      .remove-email-pair:hover,
      .remove-subject-pair:hover {
        color: #d63638;
        border-color: #d63638;
      }

      .recipient-preview {
        font-family: monospace;
        background: #f6f7f7;
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 11px;
        color: #50575e;
        margin-right: 4px;
        white-space: nowrap;
        display: inline-block;
      }
      
      .recipient-preview-list {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 4px;
        margin-top: 5px;
      }
      
      .copy-emails-btn {
        background: #0073aa !important;
        color: white !important;
        border-color: #0073aa !important;
        margin-right: 5px !important;
      }
      
      .copy-emails-btn:hover {
        background: #005a87 !important;
        border-color: #005a87 !important;
        color: white !important;
      }
      
      .copy-emails-btn:active {
        background: #004c75 !important;
        border-color: #004c75 !important;
      }
      
      .copy-success {
        color: #46b450;
        font-size: 11px;
        margin-left: 5px;
        opacity: 1;
        transition: opacity 0.3s ease;
      }
      
      .copy-success.fade-out {
        opacity: 0;
      }
      
      .pattern-preview {
        font-family: monospace;
        background: #fff8e1;
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 11px;
        color: #5d4e1b;
      }
      
      @media screen and (max-width: 782px) {
        .tablenav .alignright {
          margin-top: 10px;
          clear: both;
        }
      }

      /* Recipient tag input */
      .email-tags-field .email-tag-input {
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        margin-bottom: 8px;
      }
      .email-tags-field .email-tag-input-error {
        border-color: #d63638;
        box-shadow: 0 0 0 1px #d63638;
      }
      .email-tags-list {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
      }
      .email-tag {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #f0f6fc;
        border: 1px solid #c5d9ed;
        color: #1d2327;
        border-radius: 12px;
        padding: 3px 6px 3px 10px;
        font-size: 12px;
        font-family: monospace;
      }
      .email-tag-remove {
        background: #c5d9ed;
        color: #1d2327;
        border: none;
        border-radius: 50%;
        width: 16px;
        height: 16px;
        line-height: 16px;
        text-align: center;
        cursor: pointer;
        font-size: 13px;
        padding: 0;
      }
      .email-tag-remove:hover {
        background: #d63638;
        color: #fff;
      }

      /* Autocomplete menu (jQuery UI) */
      .ui-autocomplete.ui-menu {
        position: absolute;
        z-index: 100001;
        max-height: 240px;
        overflow-y: auto;
        overflow-x: hidden;
        background: #fff;
        border: 1px solid #c3c4c7;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.12);
        padding: 0;
        margin: 0;
        list-style: none;
      }
      .ui-autocomplete.ui-menu .ui-menu-item {
        margin: 0;
        border: none;
      }
      .ui-autocomplete.ui-menu .ui-menu-item-wrapper {
        display: block;
        padding: 6px 12px;
        cursor: pointer;
        font-size: 13px;
        font-family: monospace;
        color: #1d2327;
      }
      .ui-autocomplete.ui-menu .ui-menu-item-wrapper.ui-state-active {
        background: #2271b1;
        color: #fff;
        border: none;
        margin: 0;
      }

      /* Usage modal */
      .email-router-modal {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 100000;
      }
      .email-router-modal-backdrop {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
      }
      .email-router-modal-box {
        position: relative;
        max-width: 820px;
        margin: 60px auto;
        background: #fff;
        border-radius: 4px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        max-height: 80vh;
        display: flex;
        flex-direction: column;
      }
      .email-router-modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 20px;
        border-bottom: 1px solid #ddd;
      }
      .email-router-modal-header h2 {
        margin: 0;
        padding: 0;
        background: none;
        border: none;
        font-size: 16px;
      }
      .email-router-modal-close {
        background: none;
        border: none;
        font-size: 26px;
        line-height: 1;
        cursor: pointer;
        color: #666;
        padding: 0 4px;
      }
      .email-router-modal-close:hover {
        color: #000;
      }
      .email-router-modal-body {
        overflow-y: auto;
      }
      .email-router-modal-body table {
        border: none;
        margin: 0;
      }
      .email-router-usage-btn .dashicons {
        margin-right: 2px;
      }
    '
		);

		wp_add_inline_script(
			'jquery',
			'
      // Global function to copy email lists to clipboard
      function copyEmailList(button) {
        const emailString = button.getAttribute("data-emails") || "";
        
        if (!emailString) {
          console.error("No email data found");
          return;
        }
        
        // Use modern clipboard API if available
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(emailString).then(function() {
            showCopySuccess(button);
          }).catch(function(err) {
            fallbackCopyTextToClipboard(emailString, button);
          });
        } else {
          fallbackCopyTextToClipboard(emailString, button);
        }
      }
      
      // Fallback for older browsers
      function fallbackCopyTextToClipboard(text, button) {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.position = "fixed";
        textArea.style.left = "-999999px";
        textArea.style.top = "-999999px";
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        try {
          const successful = document.execCommand("copy");
          if (successful) {
            showCopySuccess(button);
          }
        } catch (err) {
          console.error("Failed to copy text: ", err);
        }
        
        document.body.removeChild(textArea);
      }
      
      // Show success message
      function showCopySuccess(button) {
        const originalText = button.textContent;
        button.textContent = "Copied!";
        button.style.background = "#46b450";

        setTimeout(function() {
          button.textContent = originalText;
          button.style.background = "#0073aa";
        }, 1500);
      }

      // Attach a true (filter-as-you-type) autocomplete to an input.
      // source: array of strings. onSelect(value, $input) optional.
      (function($) {
        window.emailRouterAttachAutocomplete = function($input, source, onSelect) {
          if (!$input || !$input.length || $input.data("acInit") || !$.fn.autocomplete) {
            return;
          }
          $input.data("acInit", true);
          $input.autocomplete({
            minLength: 0,
            source: function(request, response) {
              var term = (request.term || "").toLowerCase();
              var matches = (source || []).filter(function(item) {
                return item.toLowerCase().indexOf(term) !== -1;
              });
              response(matches.slice(0, 20));
            },
            select: function(event, ui) {
              if (typeof onSelect === "function") {
                onSelect(ui.item.value, $input);
                return false;
              }
            }
          });
        };
      })(jQuery);
    '
		);
	}

	/**
	 * Renders one replacement rule row.
	 *
	 * @param int   $index Row index within the option array.
	 * @param array $pair  Rule data: title, target, replacement.
	 */
	private function render_email_pair_row( $index, $pair ) {
		echo '<tr class="email-pair-row">';
		echo '<td class="column-title">';
		echo '<input type="text" name="' . esc_attr( $this->option_name ) . '[email_replacement_pairs][' . esc_attr( $index ) . '][title]" ';
		echo 'value="' . esc_attr( $pair['title'] ?? '' ) . '" placeholder="Rule title" class="regular-text">';
		echo '</td>';
		echo '<td class="column-target">';
		echo '<input type="email" name="' . esc_attr( $this->option_name ) . '[email_replacement_pairs][' . esc_attr( $index ) . '][target]" ';
		echo 'value="' . esc_attr( $pair['target'] ?? '' ) . '" placeholder="user@example.com" class="regular-text" required>';
		if ( ! empty( $pair['target'] ) ) {
			echo '<div style="margin-top:5px;">';
			echo '<button type="button" class="button button-small email-router-usage-btn" data-email="' . esc_attr( $pair['target'] ) . '">';
			echo '<span class="dashicons dashicons-search" style="vertical-align:text-top;font-size:16px;height:16px;width:16px;"></span> Where used?';
			echo '</button>';
			echo '</div>';
		}
		echo '</td>';
		echo '<td class="column-replacement">';
		$this->render_email_tags_field(
			$this->option_name . '[email_replacement_pairs][' . esc_attr( $index ) . '][replacement]',
			$pair['replacement'] ?? ''
		);
		echo '</td>';
		echo '<td class="column-actions">';
		echo '<div class="button-group">';
		echo '<button type="button" class="button button-small duplicate-email-pair" title="Duplicate rule" aria-label="Duplicate rule"><span class="dashicons dashicons-admin-page"></span></button>';
		echo '<button type="button" class="button button-small remove-email-pair" title="Remove rule" aria-label="Remove rule"><span class="dashicons dashicons-trash"></span></button>';
		echo '</div>';
		echo '</td>';
		echo '</tr>';
	}

	/**
	 * Render a tag/token input for a comma-separated list of email addresses.
	 *
	 * The canonical value is stored in a hidden input under $name (still a
	 * comma-separated string, so the existing save/sanitize logic is unchanged).
	 * The visible input adds recipients as removable tags; JavaScript keeps the
	 * hidden value in sync.
	 *
	 * @param string $name  The form field name for the hidden value input.
	 * @param string $value Current comma-separated list of addresses.
	 */
	private function render_email_tags_field( $name, $value ) {
		echo '<div class="email-tags-field">';
		echo '<input type="hidden" class="email-tags-value" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		echo '<input type="email" class="email-tag-input regular-text" placeholder="Add a recipient and press Enter" autocomplete="off">';
		echo '<div class="email-tags-list"></div>';
		echo '</div>';
	}

	/**
	 * Renders one subject-pattern row.
	 *
	 * @param int   $index Row index within the option array.
	 * @param array $pair  Rule data: pattern, recipients.
	 */
	private function render_subject_pair_row( $index, $pair ) {
		echo '<tr class="subject-pair-row">';
		echo '<td class="column-pattern">';
		echo '<input type="text" name="' . esc_attr( $this->option_name ) . '[subject_pattern_pairs][' . esc_attr( $index ) . '][pattern]" ';
		echo 'value="' . esc_attr( $pair['pattern'] ?? '' ) . '" placeholder="*Order* or Payment* or *Invoice*" class="regular-text" required>';
		if ( ! empty( $pair['pattern'] ) ) {
			echo '<div style="margin-top: 5px;"><span class="pattern-preview">' . esc_html( $pair['pattern'] ) . '</span></div>';
		}
		echo '</td>';
		echo '<td class="column-recipients">';
		$this->render_email_tags_field(
			$this->option_name . '[subject_pattern_pairs][' . esc_attr( $index ) . '][recipients]',
			$pair['recipients'] ?? ''
		);
		echo '</td>';
		echo '<td class="column-actions">';
		echo '<div class="button-group">';
		echo '<button type="button" class="button button-small duplicate-subject-pair" title="Duplicate rule" aria-label="Duplicate rule"><span class="dashicons dashicons-admin-page"></span></button>';
		echo '<button type="button" class="button button-small remove-subject-pair" title="Remove rule" aria-label="Remove rule"><span class="dashicons dashicons-trash"></span></button>';
		echo '</div>';
		echo '</td>';
		echo '</tr>';
	}

	/**
	 * Renders one blacklist row.
	 *
	 * @param int    $index Row index within the option array.
	 * @param string $email Blacklisted address.
	 */
	private function render_blacklist_row( $index, $email ) {
		echo '<tr class="blacklist-row">';
		echo '<td class="column-email">';
		echo '<input type="email" name="' . esc_attr( $this->option_name ) . '[email_blacklist][' . esc_attr( $index ) . ']" ';
		echo 'value="' . esc_attr( $email ) . '" placeholder="blocked@example.com" class="regular-text" required>';
		echo '</td>';
		echo '<td class="column-status">';
		echo '<span class="rule-status blocked">🚫 Blocked</span>';
		echo '</td>';
		echo '<td class="column-actions">';
		echo '<button type="button" class="button button-small remove-blacklist-email">Remove</button>';
		echo '</td>';
		echo '</tr>';
	}

	/**
	 * Returns the singleton, creating it on first call.
	 *
	 * @return EmailRouter
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new EmailRouter();
		}

		return self::$instance;
	}

	/**
	 * Load translations for the email-router text domain.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'email-router', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Registers the Tools > Email Router page.
	 */
	public function add_admin_menu() {
		add_management_page(
			'Email Router',
			'Email Router',
			'manage_options',
			'email_router',
			array( $this, 'options_page' )
		);
	}

	/**
	 * Registers the setting, its sections, and its fields.
	 */
	public function settings_init() {
		register_setting(
			'emailRouter',
			$this->option_name,
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);

		add_settings_section(
			'email_router_section',
			__( 'Recipient Replacement Settings', 'email-router' ),
			array( $this, 'settings_section_callback' ),
			'emailRouter'
		);

		add_settings_section(
			'subject_pattern_section',
			__( 'Subject Pattern Settings', 'email-router' ),
			array( $this, 'subject_section_callback' ),
			'emailRouter'
		);

		add_settings_section(
			'email_blacklist_section',
			__( 'Email Blacklist', 'email-router' ),
			array( $this, 'blacklist_section_callback' ),
			'emailRouter'
		);

		add_settings_field(
			'email_replacement_pairs',
			__( 'Target and Replacement Email Pairs', 'email-router' ),
			array( $this, 'email_pairs_render' ),
			'emailRouter',
			'email_router_section'
		);

		add_settings_field(
			'subject_pattern_pairs',
			__( 'Subject Pattern and Recipients', 'email-router' ),
			array( $this, 'subject_pairs_render' ),
			'emailRouter',
			'subject_pattern_section'
		);

		add_settings_field(
			'email_blacklist',
			__( 'Blacklisted Email Addresses', 'email-router' ),
			array( $this, 'blacklist_render' ),
			'emailRouter',
			'email_blacklist_section'
		);
	}

	/**
	 * Sanitizes the whole option on save.
	 *
	 * Each tab posts only its own fields, so the incoming values are merged over the
	 * stored option rather than replacing it — otherwise saving one tab would wipe the
	 * others. Rules with an empty target/pattern are dropped.
	 *
	 * @param array $input Raw values from the Settings API.
	 * @return array Sanitized option value.
	 */
	public function sanitize_settings( $input ) {
		// Get existing options to preserve data from other tabs.
		$existing_options = get_option( $this->option_name, array() );

		// Merge new input with existing options.
		$sanitized = array_merge( $existing_options, $input );

		// Sanitize email pairs.
		if ( isset( $sanitized['email_replacement_pairs'] ) ) {
			$sanitized['email_replacement_pairs'] = array_filter(
				array_map(
					function ( $pair ) {
						return array(
							'title'       => sanitize_text_field( $pair['title'] ?? '' ),
							'target'      => sanitize_email( $pair['target'] ?? '' ),
							'replacement' => sanitize_text_field( $pair['replacement'] ?? '' ),
						);
					},
					$sanitized['email_replacement_pairs']
				),
				function ( $pair ) {
					return ! empty( $pair['target'] );
				}
			);
		}

		// Sanitize subject pattern pairs.
		if ( isset( $sanitized['subject_pattern_pairs'] ) ) {
			$sanitized['subject_pattern_pairs'] = array_filter(
				array_map(
					function ( $pair ) {
						return array(
							'pattern'    => sanitize_text_field( $pair['pattern'] ?? '' ),
							'recipients' => sanitize_text_field( $pair['recipients'] ?? '' ),
						);
					},
					$sanitized['subject_pattern_pairs']
				),
				function ( $pair ) {
					return ! empty( $pair['pattern'] );
				}
			);
		}

		// Sanitize blacklist.
		if ( isset( $sanitized['email_blacklist'] ) ) {
			$sanitized['email_blacklist'] = array_filter(
				array_map( 'sanitize_email', $sanitized['email_blacklist'] ),
				function ( $email ) {
					return ! empty( $email ) && is_email( $email );
				}
			);
		}

		return $sanitized;
	}

	/**
	 * Describes the replacements section.
	 */
	public function settings_section_callback() {
		echo esc_html__( 'Set up pairs of target email addresses and the replacement email addresses that should replace them.', 'email-router' );
	}

	/**
	 * Describes the subject-patterns section.
	 */
	public function subject_section_callback() {
		echo esc_html__( 'Set up pairs of subject patterns and the email addresses that should receive matching emails. Use * for wildcards.', 'email-router' );
	}

	/**
	 * Describes the blacklist section.
	 */
	public function blacklist_section_callback() {
		echo esc_html__( 'Enter email addresses that should be completely blocked from receiving any emails. These addresses will be removed from all outgoing emails.', 'email-router' );
	}

	/**
	 * Renders the settings screen for the current tab.
	 */
	public function options_page() {
		// A tab switch is navigation, not a state change, so there is nothing to nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'replacements';
		if ( ! in_array( $tab, array( 'replacements', 'subjects', 'blacklist', 'tools' ), true ) ) {
			$tab = 'replacements';
		}

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Email Router Management', 'email-router' ) . '</h1>';
		echo '<hr class="wp-header-end">';

		$this->render_tabs( $tab );
		$this->render_tab_description( $tab );

		// The Tools tab manages its own forms (bulk actions + lookups), not the
		// Settings API options form.
		if ( 'tools' === $tab ) {
			$this->render_tools_section();
			echo '</div>';
			return;
		}

		echo '<form action="options.php" method="post">';
		settings_fields( 'emailRouter' );

		switch ( $tab ) {
			case 'subjects':
				$this->render_subject_patterns_section();
				break;
			case 'blacklist':
				$this->render_blacklist_section();
				break;
			default:
				$this->render_replacements_section();
				break;
		}

		submit_button( 'Save Email Router Settings' );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Renders the tab bar.
	 *
	 * @param string $current_tab Tab id currently being viewed.
	 */
	private function render_tabs( $current_tab ) {
		$tabs = array(
			'replacements' => array(
				'name' => __( 'Email Replacements', 'email-router' ),
				'icon' => 'dashicons-email-alt',
			),
			'subjects'     => array(
				'name' => __( 'Subject Patterns', 'email-router' ),
				'icon' => 'dashicons-filter',
			),
			'blacklist'    => array(
				'name' => __( 'Blacklist', 'email-router' ),
				'icon' => 'dashicons-dismiss',
			),
			'tools'        => array(
				'name' => __( 'Tools', 'email-router' ),
				'icon' => 'dashicons-admin-tools',
			),
		);

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $tab_id => $tab_data ) {
			$class = ( $tab_id === $current_tab ) ? 'nav-tab nav-tab-active' : 'nav-tab';
			$url   = admin_url( 'tools.php?page=email_router&tab=' . $tab_id );
			echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">';
			echo '<span class="dashicons ' . esc_attr( $tab_data['icon'] ) . '" style="margin-right: 5px; vertical-align: middle; margin-top: -2px;"></span>';
			echo esc_html( $tab_data['name'] );
			echo '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Renders the blurb under the tab bar.
	 *
	 * @param string $current_tab Tab id currently being viewed.
	 */
	private function render_tab_description( $current_tab ) {
		$descriptions = array(
			'replacements' => array(
				'title'       => 'Email Address Replacements',
				'description' => 'Configure specific email addresses to be replaced with different recipients. Useful for redirecting emails from specific addresses during development or testing.',
			),
			'subjects'     => array(
				'title'       => 'Subject Pattern Routing',
				'description' => 'Route emails to specific recipients based on subject line patterns. Use wildcards (*) to match partial subjects and automatically route emails to the appropriate team members.',
			),
			'blacklist'    => array(
				'title'       => 'Email Blacklist',
				'description' => 'Block specific email addresses from receiving any emails. Blacklisted addresses will be completely removed from all outgoing email recipients.',
			),
			'tools'        => array(
				'title'       => 'Tools',
				'description' => 'Bulk actions, lookups, and reports: remove an address from every replacement rule at once, find everywhere an address is used, or list every system email the site sends and who receives it.',
			),
		);

		if ( isset( $descriptions[ $current_tab ] ) ) {
			$desc = $descriptions[ $current_tab ];
			echo '<div class="notice notice-info" style="margin: 20px 0; padding: 15px;">';
			echo '<h3 style="margin: 0 0 10px 0;">' . esc_html( $desc['title'] ) . '</h3>';
			echo '<p style="margin: 0;">' . esc_html( $desc['description'] ) . '</p>';
			echo '</div>';
		}
	}

	/**
	 * Render the Tools tab: bulk "remove from all replacements" plus a find-usage lookup.
	 */
	private function render_tools_section() {
		// Handle the "remove from all replacements" submission.
		$remove_notice      = '';
		$remove_notice_type = 'success';
		if ( isset( $_POST['email_router_remove_email'] ) ) {
			check_admin_referer( 'email_router_remove_email' );
			$email = sanitize_email( wp_unslash( $_POST['email_router_remove_email'] ) );
			if ( $email && is_email( $email ) ) {
				$count         = $this->remove_email_from_replacements( $email );
				$remove_notice = sprintf(
					'Removed %s from %d replacement rule%s.',
					$email,
					$count,
					1 === $count ? '' : 's'
				);
			} else {
				$remove_notice      = 'Please enter a valid email address.';
				$remove_notice_type = 'error';
			}
		}

		// Handle the settings import submission.
		$import_notice      = '';
		$import_notice_type = 'success';
		if ( isset( $_POST['email_router_import_submit'] ) ) {
			check_admin_referer( 'email_router_import' );
			$upload = isset( $_FILES['email_router_import_file']['tmp_name'] )
				? sanitize_text_field( wp_unslash( $_FILES['email_router_import_file']['tmp_name'] ) )
				: '';
			if ( '' !== $upload && is_uploaded_file( $upload ) ) {
				// Reading a local PHP upload, not a remote URL, so wp_remote_get() does not apply.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$raw  = file_get_contents( $upload );
				$data = json_decode( $raw, true );
				if ( json_last_error() === JSON_ERROR_NONE && is_array( $data ) ) {
					update_option( $this->option_name, $this->sanitize_imported_settings( $data ) );
					$import_notice = 'Settings imported successfully.';
				} else {
					$import_notice      = 'Could not read the uploaded file as valid JSON.';
					$import_notice_type = 'error';
				}
			} else {
				$import_notice      = 'Please choose a file to import.';
				$import_notice_type = 'error';
			}
		}

		// Build a shared autocomplete list of every address used in the replacement rules.
		$options = get_option( $this->option_name, array() );
		$emails  = array();
		foreach ( ( $options['email_replacement_pairs'] ?? array() ) as $pair ) {
			if ( ! empty( $pair['target'] ) ) {
				$emails[ strtolower( $pair['target'] ) ] = $pair['target'];
			}
			foreach ( array_map( 'trim', explode( ',', $pair['replacement'] ?? '' ) ) as $recipient ) {
				if ( '' !== $recipient ) {
					$emails[ strtolower( $recipient ) ] = $recipient;
				}
			}
		}
		ksort( $emails );

		echo '<script type="text/javascript">window.emailRouterToolsEmails = ' . wp_json_encode( array_values( $emails ) ) . ';</script>';

		// --- Tool 1: remove an email from all replacement rules ---
		echo '<div class="email-router-section">';
		echo '<h2>Remove an Email From All Replacements</h2>';
		echo '<div style="padding: 15px 20px;">';
		if ( '' !== $remove_notice ) {
			echo '<div class="notice notice-' . esc_attr( $remove_notice_type ) . ' inline" style="margin: 0 0 12px;"><p>' . esc_html( $remove_notice ) . '</p></div>';
		}
		echo '<p style="margin-top: 0; color: #666;">Removes the address from the recipient list of every replacement rule. Target addresses are not changed.</p>';
		echo '<form method="post" id="email-router-remove-form">';
		wp_nonce_field( 'email_router_remove_email' );
		echo '<input type="email" name="email_router_remove_email" class="regular-text email-router-ac-tools" placeholder="address@example.com" required autocomplete="off" style="margin-right: 8px;">';
		submit_button( 'Remove from all rules', 'delete', '', false );
		echo '</form>';
		echo '</div>';
		echo '</div>';

		// --- Tool 2: find where an email is used ---
		$find_email = isset( $_GET['find_email'] ) ? sanitize_email( wp_unslash( $_GET['find_email'] ) ) : '';

		echo '<div class="email-router-section">';
		echo '<h2>Find Where an Email Is Used</h2>';
		echo '<div style="padding: 15px 20px;">';
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="email_router">';
		echo '<input type="hidden" name="tab" value="tools">';
		echo '<input type="email" name="find_email" value="' . esc_attr( $find_email ) . '" class="regular-text email-router-ac-tools" placeholder="address@example.com" required autocomplete="off" style="margin-right: 8px;">';
		submit_button( 'Find usage', 'primary', '', false );
		echo '</form>';
		echo '</div>';

		// Wire true autocomplete onto both tool inputs.
		echo '<script type="text/javascript">
      (function($) {
        $(function() {
          $(".email-router-ac-tools").each(function() {
            if (window.emailRouterAttachAutocomplete) {
              window.emailRouterAttachAutocomplete($(this), window.emailRouterToolsEmails || []);
            }
          });
          $("#email-router-remove-form").on("submit", function(e) {
            var email = $(this).find("input[name=\'email_router_remove_email\']").val() || "this address";
            if (!window.confirm("Remove " + email + " from the recipient list of every replacement rule? This saves immediately and cannot be undone.")) {
              e.preventDefault();
            }
          });
        });
      })(jQuery);
    </script>';

		if ( $find_email && is_email( $find_email ) ) {
			$results = $this->find_email_usage( $find_email );
			if ( empty( $results ) ) {
				echo '<div style="padding: 0 20px 20px; color: #666;">No usages found for ' . esc_html( $find_email ) . '.</div>';
			} else {
				echo '<table class="wp-list-table widefat fixed striped" style="margin-top: 0;">';
				echo '<thead><tr>';
				echo '<th scope="col" style="width: 150px;">Source</th>';
				echo '<th scope="col">Location</th>';
				echo '<th scope="col">Detail</th>';
				echo '<th scope="col" style="width: 80px;">Link</th>';
				echo '</tr></thead><tbody>';
				foreach ( $results as $row ) {
					echo '<tr>';
					echo '<td>' . esc_html( $row['source'] ) . '</td>';
					echo '<td>' . esc_html( $row['location'] ) . '</td>';
					echo '<td>' . esc_html( $row['detail'] ) . '</td>';
					echo '<td>' . ( ! empty( $row['link'] ) ? '<a href="' . esc_url( $row['link'] ) . '" target="_blank" rel="noopener">View</a>' : '&mdash;' ) . '</td>';
					echo '</tr>';
				}
				echo '</tbody></table>';
			}
		}
		echo '</div>';

		// --- Tool 3: report every system email and who receives it ---
		$this->render_system_emails_section();

		// --- Tool 4: export / import settings ---
		echo '<div class="email-router-section">';
		echo '<h2>Export / Import Settings</h2>';
		echo '<div style="padding: 15px 20px;">';
		if ( '' !== $import_notice ) {
			echo '<div class="notice notice-' . esc_attr( $import_notice_type ) . ' inline" style="margin: 0 0 12px;"><p>' . esc_html( $import_notice ) . '</p></div>';
		}
		echo '<p style="margin-top: 0; color: #666;">Download all router settings (replacements, subject patterns, and blacklist) as a JSON file, or restore them from a previously exported file. Importing replaces all current settings.</p>';

		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=email_router_export' ), 'email_router_export' );
		echo '<p><a href="' . esc_url( $export_url ) . '" class="button button-secondary"><span class="dashicons dashicons-download" style="vertical-align: text-top;"></span> Export settings</a></p>';

		echo '<form method="post" enctype="multipart/form-data" id="email-router-import-form" style="margin-top: 12px;">';
		wp_nonce_field( 'email_router_import' );
		echo '<input type="hidden" name="email_router_import_submit" value="1">';
		echo '<input type="file" name="email_router_import_file" id="email-router-import-file" accept="application/json,.json" style="display: none;">';
		echo '<button type="button" class="button button-secondary" id="email-router-import-btn"><span class="dashicons dashicons-upload" style="vertical-align: text-top;"></span> Import settings</button>';
		echo '</form>';

		// Import replaces the whole option, so the prompt names what is at stake.
		$current         = (array) get_option( $this->option_name, array() );
		$rule_count      = count( isset( $current['email_replacement_pairs'] ) ? (array) $current['email_replacement_pairs'] : array() );
		$pattern_count   = count( isset( $current['subject_pattern_pairs'] ) ? (array) $current['subject_pattern_pairs'] : array() );
		$blocked_count   = count( isset( $current['email_blacklist'] ) ? (array) $current['email_blacklist'] : array() );
		$current_summary = sprintf(
			'%1$d replacement %2$s, %3$d subject %4$s, %5$d blacklisted %6$s',
			$rule_count,
			1 === $rule_count ? 'rule' : 'rules',
			$pattern_count,
			1 === $pattern_count ? 'pattern' : 'patterns',
			$blocked_count,
			1 === $blocked_count ? 'address' : 'addresses'
		);

		echo '<script type="text/javascript">
      (function($) {
        var currentSummary = "' . esc_js( $current_summary ) . '";
        $(function() {
          $("#email-router-import-btn").on("click", function() {
            $("#email-router-import-file").trigger("click");
          });
          $("#email-router-import-file").on("change", function() {
            if (this.files && this.files.length) {
              var name = this.files[0].name;
              if (!window.confirm("Import " + name + "?\n\nThis replaces everything currently saved (" + currentSummary + ") and cannot be undone. Export first if you want a copy.")) {
                $(this).val("");
                return;
              }
              $("#email-router-import-form").trigger("submit");
            }
          });
        });
      })(jQuery);
    </script>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Stream the current settings to the browser as a downloadable JSON file.
	 */
	public function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied.' );
		}
		check_admin_referer( 'email_router_export' );

		$settings = get_option( $this->option_name, array() );
		$filename = 'email-router-settings-' . gmdate( 'Ymd-His' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		echo wp_json_encode( $settings, JSON_PRETTY_PRINT );
		exit;
	}

	/**
	 * Validate and sanitize a decoded settings payload from an import file.
	 * Returns a clean settings array suitable for a full replace.
	 *
	 * @param array $data Decoded JSON payload.
	 * @return array
	 */
	private function sanitize_imported_settings( $data ) {
		$clean = array();

		if ( ! empty( $data['email_replacement_pairs'] ) && is_array( $data['email_replacement_pairs'] ) ) {
			foreach ( $data['email_replacement_pairs'] as $pair ) {
				$target = sanitize_email( $pair['target'] ?? '' );
				if ( ! $target ) {
					continue;
				}
				$clean['email_replacement_pairs'][] = array(
					'title'       => sanitize_text_field( $pair['title'] ?? '' ),
					'target'      => $target,
					'replacement' => sanitize_text_field( $pair['replacement'] ?? '' ),
				);
			}
		}

		if ( ! empty( $data['subject_pattern_pairs'] ) && is_array( $data['subject_pattern_pairs'] ) ) {
			foreach ( $data['subject_pattern_pairs'] as $pair ) {
				$pattern = sanitize_text_field( $pair['pattern'] ?? '' );
				if ( '' === $pattern ) {
					continue;
				}
				$clean['subject_pattern_pairs'][] = array(
					'pattern'    => $pattern,
					'recipients' => sanitize_text_field( $pair['recipients'] ?? '' ),
				);
			}
		}

		if ( ! empty( $data['email_blacklist'] ) && is_array( $data['email_blacklist'] ) ) {
			foreach ( $data['email_blacklist'] as $email ) {
				$email = sanitize_email( $email );
				if ( $email && is_email( $email ) ) {
					$clean['email_blacklist'][] = $email;
				}
			}
		}

		return $clean;
	}

	/**
	 * Remove an email address from the recipient list of every replacement rule.
	 *
	 * @param string $email Address to strip from all replacement recipient lists.
	 * @return int Number of rules that were changed.
	 */
	private function remove_email_from_replacements( $email ) {
		$email   = strtolower( trim( $email ) );
		$options = get_option( $this->option_name, array() );

		if ( empty( $options['email_replacement_pairs'] ) ) {
			return 0;
		}

		$affected = 0;
		foreach ( $options['email_replacement_pairs'] as $i => $pair ) {
			$recipients = array_filter(
				array_map( 'trim', explode( ',', $pair['replacement'] ?? '' ) ),
				'strlen'
			);
			$kept       = array_values(
				array_filter(
					$recipients,
					function ( $recipient ) use ( $email ) {
						return strtolower( $recipient ) !== $email;
					}
				)
			);

			if ( count( $kept ) !== count( $recipients ) ) {
				++$affected;
				$options['email_replacement_pairs'][ $i ]['replacement'] = implode( ',', $kept );
			}
		}

		if ( $affected > 0 ) {
			update_option( $this->option_name, $options );
		}

		return $affected;
	}

	/**
	 * Normalise a row's CC and BCC recipients into address/label pairs.
	 *
	 * Callers may pass a plain address or an array with address and label keys,
	 * so a collector that knows an address came from CC can say so while a
	 * simpler one can just hand over the address.
	 *
	 * @param mixed $copies Raw copies value from a report row.
	 * @return array<int, array{address: string, label: string}>
	 */
	private function normalize_copies( $copies ) {
		$clean = array();

		foreach ( (array) $copies as $entry ) {
			if ( is_array( $entry ) ) {
				$address = isset( $entry['address'] ) ? trim( (string) $entry['address'] ) : '';
				$label   = isset( $entry['label'] ) ? trim( (string) $entry['label'] ) : '';
			} else {
				$address = trim( (string) $entry );
				$label   = '';
			}

			if ( '' === $address ) {
				continue;
			}

			$clean[] = array(
				'address' => $address,
				'label'   => $label,
			);
		}

		return $clean;
	}

	/**
	 * Run normalised copies through the same rules route_copy_headers() applies.
	 *
	 * Each routed address keeps the label of the entry it came from.
	 *
	 * @param array $copies Normalised copies.
	 * @return array<int, array{address: string, label: string}>
	 */
	private function route_copies( $copies ) {
		$routed = array();

		foreach ( $copies as $entry ) {
			foreach ( $this->route_copy_addresses( $entry['address'] ) as $address ) {
				$routed[] = array(
					'address' => $address,
					'label'   => $entry['label'],
				);
			}
		}

		return $routed;
	}

	/**
	 * Render a copy entry as "address (LABEL)" for display.
	 *
	 * @param array $entry Normalised copy entry.
	 * @return string
	 */
	private function format_copy( $entry ) {
		return '' === $entry['label']
			? $entry['address']
			: $entry['address'] . ' (' . $entry['label'] . ')';
	}

	/**
	 * Build the system email report.
	 *
	 * Collects every email the site is configured to send — WordPress core,
	 * WooCommerce, Gravity Forms, and other mail-sending plugins — together with
	 * the recipients each one is configured with, then runs those recipients
	 * through this plugin's own routing rules so the report shows where the mail
	 * actually lands.
	 *
	 * Recipients that are computed at send time (the customer on an order, a form
	 * field, the user resetting their password) cannot be resolved here; they are
	 * reported as dynamic recipients instead of literal addresses.
	 *
	 * @return array<int, array<string, mixed>> Report rows.
	 */
	private function get_system_email_report() {
		$rows = array_merge(
			$this->collect_wordpress_emails(),
			$this->collect_woocommerce_emails(),
			$this->collect_gravity_forms_emails(),
			$this->collect_other_plugin_emails()
		);

		/**
		 * Filters the rows of the system email report.
		 *
		 * Lets a plugin whose emails this report does not know about add its own.
		 * Each row is an array with the keys source, name, subject, status,
		 * recipients (array of literal To addresses), copies (array of literal CC
		 * and BCC addresses, each either an address or an array with address and
		 * label keys; `unrouted` is accepted as an older name for it), dynamic
		 * (array of descriptions of send-time recipients), and link.
		 *
		 * @param array $rows Report rows collected so far.
		 */
		$rows = apply_filters( 'email_router_system_emails', $rows );

		$defaults = array(
			'source'     => '',
			'name'       => '',
			'subject'    => '',
			'status'     => '',
			'recipients' => array(),
			'copies'     => array(),
			'dynamic'    => array(),
			'link'       => '',
		);

		$report = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			// Collectors written against 1.3 report CC and BCC under `unrouted`.
			if ( isset( $row['unrouted'] ) ) {
				$row['copies'] = array_merge( (array) ( $row['copies'] ?? array() ), (array) $row['unrouted'] );
				unset( $row['unrouted'] );
			}
			$row                  = array_merge( $defaults, $row );
			$row['recipients']    = array_values( array_filter( array_map( 'trim', (array) $row['recipients'] ), 'strlen' ) );
			$row['copies']        = $this->normalize_copies( $row['copies'] );
			$row['dynamic']       = array_values( array_filter( array_map( 'trim', (array) $row['dynamic'] ), 'strlen' ) );
			$row['routed']        = $this->route_recipients( $row['recipients'], $row['subject'] );
			$row['routed_copies'] = $this->route_copies( $row['copies'] );

			$to_rerouted     = array_map( 'strtolower', $row['recipients'] ) !== array_map( 'strtolower', $row['routed'] );
			$row['rerouted'] = $to_rerouted || $row['copies'] !== $row['routed_copies'];
			$report[]        = $row;
		}

		return $report;
	}

	/**
	 * Run a set of recipients through this plugin's routing rules.
	 *
	 * Reuses the live wp_mail filters in their hooked order (subject routing at
	 * priority 10, then address replacement at 20) so the report cannot drift
	 * from what actually happens at send time. Subject routing is only simulated
	 * when the email's subject is known, since an empty subject would match
	 * patterns it never matches in practice.
	 *
	 * @param array  $recipients Literal recipient addresses.
	 * @param string $subject    Subject line of the email, if known.
	 * @return array Addresses the mail is delivered to after routing.
	 */
	private function route_recipients( $recipients, $subject = '' ) {
		$recipients = array_values( array_filter( array_map( 'trim', (array) $recipients ), 'strlen' ) );

		if ( empty( $recipients ) ) {
			return array();
		}

		$args = array(
			'to'      => implode( ',', $recipients ),
			'subject' => (string) $subject,
		);

		if ( '' !== $args['subject'] ) {
			$args = $this->replace_by_subject( $args );
		}
		$args = $this->replace_emails( $args );

		$routed = is_array( $args['to'] ) ? $args['to'] : explode( ',', (string) $args['to'] );

		return array_values( array_unique( array_filter( array_map( 'trim', $routed ), 'strlen' ) ) );
	}

	/**
	 * Split a configured recipient string into literal addresses and everything else.
	 *
	 * Merge tags ({admin_email}, [your-email], {field_id="3"}) are not addresses and
	 * cannot be routed, so they are returned separately as dynamic recipients.
	 *
	 * @param string|array $recipient_list Comma-separated recipient list, or an array of entries.
	 * @return array{0: array, 1: array} Literal addresses, then dynamic entries.
	 */
	private function split_recipient_list( $recipient_list ) {
		$entries = is_array( $recipient_list ) ? $recipient_list : explode( ',', (string) $recipient_list );
		$literal = array();
		$dynamic = array();

		foreach ( $entries as $entry ) {
			$entry = trim( (string) $entry );
			if ( '' === $entry ) {
				continue;
			}

			// Accept the "Name <address@example.com>" form as a literal address.
			$address = $entry;
			if ( preg_match( '/<([^>]+)>/', $entry, $matches ) ) {
				$address = trim( $matches[1] );
			}

			if ( is_email( $address ) ) {
				$literal[] = $address;
			} else {
				$dynamic[] = $entry;
			}
		}

		return array( $literal, $dynamic );
	}

	/**
	 * Collect the emails WordPress core itself sends.
	 *
	 * @return array<int, array<string, mixed>> Report rows.
	 */
	private function collect_wordpress_emails() {
		$admin_email = (string) get_option( 'admin_email' );
		$blogname    = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$general     = admin_url( 'options-general.php' );
		$discussion  = admin_url( 'options-discussion.php' );

		$rows = array(
			array(
				'source'     => 'WordPress',
				'name'       => 'New user registration (admin copy)',
				'subject'    => '[' . $blogname . '] New User Registration',
				'status'     => get_option( 'users_can_register' ) ? 'Open registration' : 'Registration closed',
				'recipients' => array( $admin_email ),
				'link'       => $general,
			),
			array(
				'source'  => 'WordPress',
				'name'    => 'New user welcome (user copy)',
				'subject' => '[' . $blogname . '] Login Details',
				'status'  => 'Always',
				'dynamic' => array( 'The new user' ),
				'link'    => $general,
			),
			array(
				'source'  => 'WordPress',
				'name'    => 'Password reset',
				'subject' => '[' . $blogname . '] Password Reset',
				'status'  => 'Always',
				'dynamic' => array( 'The user requesting the reset' ),
				'link'    => admin_url( 'users.php' ),
			),
			array(
				'source'     => 'WordPress',
				'name'       => 'Comment awaiting moderation',
				'subject'    => '[' . $blogname . '] Please moderate',
				'status'     => get_option( 'moderation_notify' ) ? 'Enabled' : 'Disabled',
				'recipients' => array( $admin_email ),
				'dynamic'    => array( 'Post author (when not the admin)' ),
				'link'       => $discussion,
			),
			array(
				'source'  => 'WordPress',
				'name'    => 'New comment published',
				'subject' => '[' . $blogname . '] Comment',
				'status'  => get_option( 'comments_notify' ) ? 'Enabled' : 'Disabled',
				'dynamic' => array( 'Post author' ),
				'link'    => $discussion,
			),
			array(
				'source'     => 'WordPress',
				'name'       => 'Automatic update results',
				'subject'    => '[' . $blogname . '] Some updates were applied',
				'status'     => 'Always',
				'recipients' => array( $admin_email ),
				'link'       => admin_url( 'update-core.php' ),
			),
			array(
				'source'     => 'WordPress',
				'name'       => 'Site health / fatal error recovery',
				'subject'    => '[' . $blogname . '] Your site is experiencing a technical issue',
				'status'     => 'On fatal error',
				'recipients' => array( $admin_email ),
				'link'       => admin_url( 'site-health.php' ),
			),
			array(
				'source'     => 'WordPress',
				'name'       => 'Administration email change confirmation',
				'subject'    => '[' . $blogname . '] New Admin Email Address',
				'status'     => 'On change',
				'recipients' => array( $admin_email ),
				'link'       => $general,
			),
			array(
				'source'     => 'WordPress',
				'name'       => 'Personal data request (confirmation to admin)',
				'subject'    => '[' . $blogname . '] Confirmed action',
				'status'     => 'On request',
				'recipients' => array( $admin_email ),
				'link'       => admin_url( 'export-personal-data.php' ),
			),
		);

		return $rows;
	}

	/**
	 * Read a WC_Email's subject without letting a broken one take down the page.
	 *
	 * A WC_Email may build its subject from $this->object, which is null on the
	 * bare instances WC()->mailer()->get_emails() returns.
	 * WC_Email_Customer_Invoice dereferences it unconditionally, so asking it for
	 * a subject outside a real send raises an Error. Report such an email without
	 * a subject instead; the report already treats an unknown subject as "do not
	 * simulate subject routing for this row".
	 *
	 * @param object $wc_email A WC_Email instance.
	 * @return string The subject, or '' when it cannot be determined.
	 */
	private function email_subject( $wc_email ) {
		if ( ! method_exists( $wc_email, 'get_subject' ) ) {
			return '';
		}

		try {
			return (string) $wc_email->get_subject();
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Collect the WooCommerce transactional emails and their recipients.
	 *
	 * Customer-facing emails are addressed at send time from the order, so they
	 * are reported as dynamic rather than as literal recipients.
	 *
	 * @return array<int, array<string, mixed>> Report rows.
	 */
	private function collect_woocommerce_emails() {
		$rows = array();

		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
			return $rows;
		}

		$mailer = WC()->mailer();
		if ( $mailer && method_exists( $mailer, 'get_emails' ) ) {
			foreach ( $mailer->get_emails() as $wc_email ) {
				$recipient = isset( $wc_email->recipient ) ? $wc_email->recipient : '';
				$customer  = ! empty( $wc_email->customer_email );
				$enabled   = method_exists( $wc_email, 'is_enabled' ) ? $wc_email->is_enabled() : true;
				$subject   = $this->email_subject( $wc_email );
				$title     = method_exists( $wc_email, 'get_title' ) ? $wc_email->get_title() : get_class( $wc_email );

				list( $literal, $dynamic ) = $this->split_recipient_list( $recipient );

				if ( $customer ) {
					$dynamic[] = 'Customer (order billing address)';
				}

				$rows[] = array(
					'source'     => 'WooCommerce',
					'name'       => $title,
					'subject'    => $subject,
					'status'     => $enabled ? 'Enabled' : 'Disabled',
					'recipients' => $literal,
					'dynamic'    => $dynamic,
					'link'       => admin_url( 'admin.php?page=wc-settings&tab=email&section=' . strtolower( get_class( $wc_email ) ) ),
				);
			}
		}

		$stock_recipient = get_option( 'woocommerce_stock_email_recipient' );
		if ( $stock_recipient ) {
			list( $literal, $dynamic ) = $this->split_recipient_list( $stock_recipient );

			$low_stock = 'yes' === get_option( 'woocommerce_notify_low_stock', 'yes' );
			$no_stock  = 'yes' === get_option( 'woocommerce_notify_no_stock', 'yes' );

			$rows[] = array(
				'source'     => 'WooCommerce',
				'name'       => 'Stock notifications',
				'subject'    => 'Product low in stock',
				'status'     => ( $low_stock || $no_stock ) ? 'Enabled' : 'Disabled',
				'recipients' => $literal,
				'dynamic'    => $dynamic,
				'link'       => admin_url( 'admin.php?page=wc-settings&tab=products&section=inventory' ),
			);
		}

		$from_address = get_option( 'woocommerce_email_from_address' );
		if ( $from_address ) {
			$rows[] = array(
				'source'  => 'WooCommerce',
				'name'    => '"From" address (sender, not a recipient)',
				'status'  => 'Sender',
				'dynamic' => array( $from_address ),
				'link'    => admin_url( 'admin.php?page=wc-settings&tab=email' ),
			);
		}

		return $rows;
	}

	/**
	 * Collect every Gravity Forms notification and its recipients.
	 *
	 * Notifications can address a field on the form or a set of routing rules
	 * instead of a fixed address; both are reported as dynamic recipients, with
	 * the literal addresses inside routing rules pulled out where they exist.
	 *
	 * @return array<int, array<string, mixed>> Report rows.
	 */
	private function collect_gravity_forms_emails() {
		$rows = array();

		if ( ! class_exists( 'GFAPI' ) ) {
			return $rows;
		}

		// Null asks for active and inactive forms alike; a report should show both.
		$forms = GFAPI::get_forms( null );
		if ( ! is_array( $forms ) ) {
			return $rows;
		}

		foreach ( $forms as $form ) {
			if ( empty( $form['notifications'] ) ) {
				continue;
			}

			foreach ( $form['notifications'] as $notification ) {
				$literal = array();
				$dynamic = array();
				$to_type = $notification['toType'] ?? 'email';

				if ( 'routing' === $to_type && ! empty( $notification['routing'] ) ) {
					foreach ( $notification['routing'] as $route ) {
						list( $route_literal, $route_dynamic ) = $this->split_recipient_list( $route['email'] ?? '' );
						$literal                               = array_merge( $literal, $route_literal );
						$dynamic                               = array_merge( $dynamic, $route_dynamic );
					}
					$dynamic[] = 'Conditional routing (' . count( (array) $notification['routing'] ) . ' rule(s))';
				} elseif ( 'field' === $to_type ) {
					$dynamic[] = 'Form field (id ' . ( $notification['toField'] ?? '?' ) . ')';
				} else {
					list( $literal, $dynamic ) = $this->split_recipient_list( $notification['to'] ?? '' );
				}

				$copies = array();
				foreach ( array( 'cc', 'bcc' ) as $extra ) {
					if ( empty( $notification[ $extra ] ) || ! is_string( $notification[ $extra ] ) ) {
						continue;
					}
					list( $extra_literal, $extra_dynamic ) = $this->split_recipient_list( $notification[ $extra ] );
					foreach ( $extra_literal as $address ) {
						$copies[] = array(
							'address' => $address,
							'label'   => strtoupper( $extra ),
						);
					}
					foreach ( $extra_dynamic as $address ) {
						$dynamic[] = strtoupper( $extra ) . ': ' . $address;
					}
				}

				$active = ! isset( $notification['isActive'] ) || $notification['isActive'];

				$rows[] = array(
					'source'     => 'Gravity Forms',
					'name'       => $form['title'] . ' → ' . ( $notification['name'] ?? 'Untitled notification' ),
					'subject'    => $notification['subject'] ?? '',
					'status'     => $active ? 'Enabled' : 'Disabled',
					'recipients' => $literal,
					'copies'     => $copies,
					'dynamic'    => $dynamic,
					'link'       => admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=notification&id=' . $form['id'] . '&nid=' . ( $notification['id'] ?? '' ) ),
				);
			}
		}

		return $rows;
	}

	/**
	 * Collect emails from the other mail-sending plugins this report understands.
	 *
	 * Currently Contact Form 7 and WPForms. Anything else can add its own rows
	 * through the email_router_system_emails filter.
	 *
	 * @return array<int, array<string, mixed>> Report rows.
	 */
	private function collect_other_plugin_emails() {
		$rows = array();

		// --- Contact Form 7 ---
		if ( class_exists( 'WPCF7_ContactForm' ) ) {
			$forms = WPCF7_ContactForm::find( array( 'posts_per_page' => -1 ) );
			foreach ( (array) $forms as $form ) {
				$templates = array(
					'mail'   => 'Mail',
					'mail_2' => 'Mail (2)',
				);
				foreach ( $templates as $prop => $label ) {
					$mail = $form->prop( $prop );
					if ( empty( $mail['recipient'] ) ) {
						continue;
					}
					if ( 'mail_2' === $prop && empty( $mail['active'] ) ) {
						continue;
					}

					list( $literal, $dynamic ) = $this->split_recipient_list( $mail['recipient'] );

					$rows[] = array(
						'source'     => 'Contact Form 7',
						'name'       => $form->title() . ' → ' . $label,
						'subject'    => $mail['subject'] ?? '',
						'status'     => 'Enabled',
						'recipients' => $literal,
						'dynamic'    => $dynamic,
						'link'       => admin_url( 'admin.php?page=wpcf7&post=' . $form->id() . '&active-tab=1' ),
					);
				}
			}
		}

		// --- WPForms ---
		$wpforms_forms = $this->get_wpforms_forms();
		foreach ( $wpforms_forms as $form ) {
			$data          = json_decode( $form->post_content, true );
			$notifications = $data['settings']['notifications'] ?? array();
			if ( ! is_array( $notifications ) ) {
				continue;
			}

			foreach ( $notifications as $notification ) {
				if ( empty( $notification['email'] ) ) {
					continue;
				}

				list( $literal, $dynamic ) = $this->split_recipient_list( $notification['email'] );

				foreach ( array(
					'carboncopy' => 'CC',
					'replyto'    => 'Reply-To',
				) as $field => $field_label ) {
					if ( ! empty( $notification[ $field ] ) ) {
						$dynamic[] = $field_label . ': ' . $notification[ $field ];
					}
				}

				$rows[] = array(
					'source'     => 'WPForms',
					'name'       => $form->post_title . ' → ' . ( $notification['notification_name'] ?? 'Notification' ),
					'subject'    => $notification['subject'] ?? '',
					'status'     => ( isset( $notification['enable'] ) && ! $notification['enable'] ) ? 'Disabled' : 'Enabled',
					'recipients' => $literal,
					'dynamic'    => $dynamic,
					'link'       => admin_url( 'admin.php?page=wpforms-builder&view=settings&form_id=' . $form->ID ),
				);
			}
		}

		return $rows;
	}

	/**
	 * Fetch the WPForms form posts, across the two accessor styles WPForms has shipped.
	 *
	 * @return array<int, WP_Post> Form posts, or an empty array when WPForms is absent.
	 */
	private function get_wpforms_forms() {
		if ( ! function_exists( 'wpforms' ) ) {
			return array();
		}

		$wpforms = wpforms();
		if ( ! is_object( $wpforms ) ) {
			return array();
		}

		$handler = null;
		if ( method_exists( $wpforms, 'get' ) ) {
			$handler = $wpforms->get( 'form' );
		}
		if ( ! is_object( $handler ) && isset( $wpforms->form ) ) {
			$handler = $wpforms->form;
		}
		if ( ! is_object( $handler ) || ! method_exists( $handler, 'get' ) ) {
			return array();
		}

		$forms = $handler->get( '' );

		return is_array( $forms ) ? $forms : array();
	}

	/**
	 * Render the Tools tab's system email report.
	 */
	private function render_system_emails_section() {
		$report = $this->get_system_email_report();

		$sources  = array_values( array_unique( wp_list_pluck( $report, 'source' ) ) );
		$rerouted = count(
			array_filter(
				$report,
				static function ( $row ) {
					return ! empty( $row['rerouted'] );
				}
			)
		);

		echo '<div class="email-router-section">';
		echo '<h2>System Email Report</h2>';
		echo '<div style="padding: 15px 20px;">';
		echo '<p style="margin-top: 0; color: #666;">Every email this site is configured to send and who receives it, with the recipients this router actually delivers to. Recipients that are worked out at send time (a customer, a form field, the user resetting a password) cannot be routed in advance and are listed as dynamic. CC and BCC addresses go through the same replacement rules and blacklist as the main recipients, but not subject routing.</p>';

		$summary = sprintf(
			'%d email%s across %d source%s (%s). %d %s rerouted by this plugin.',
			count( $report ),
			1 === count( $report ) ? '' : 's',
			count( $sources ),
			1 === count( $sources ) ? '' : 's',
			implode( ', ', $sources ),
			$rerouted,
			1 === $rerouted ? 'is' : 'are'
		);
		echo '<p style="margin: 0 0 12px; color: #666;">' . esc_html( $summary ) . '</p>';

		$csv_url = wp_nonce_url( admin_url( 'admin-post.php?action=email_router_system_emails_export' ), 'email_router_system_emails_export' );
		echo '<p><a href="' . esc_url( $csv_url ) . '" class="button button-secondary"><span class="dashicons dashicons-download" style="vertical-align: text-top;"></span> Download report (CSV)</a></p>';
		echo '</div>';

		if ( empty( $report ) ) {
			echo '<div style="padding: 0 20px 20px; color: #666;">No system emails were found.</div>';
			echo '</div>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped" style="margin-top: 0;">';
		echo '<thead><tr>';
		echo '<th scope="col" style="width: 120px;">Source</th>';
		echo '<th scope="col" style="width: 22%;">Email</th>';
		echo '<th scope="col" style="width: 110px;">Status</th>';
		echo '<th scope="col">Configured recipients</th>';
		echo '<th scope="col">Delivered to</th>';
		echo '<th scope="col" style="width: 70px;">Link</th>';
		echo '</tr></thead><tbody>';

		foreach ( $report as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['source'] ) . '</td>';
			echo '<td><strong>' . esc_html( $row['name'] ) . '</strong>';
			if ( '' !== $row['subject'] ) {
				echo '<br><span style="color: #666; font-size: 11px;">' . esc_html( $row['subject'] ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( $row['status'] ) . '</td>';

			echo '<td>';
			if ( empty( $row['recipients'] ) && empty( $row['copies'] ) && empty( $row['dynamic'] ) ) {
				echo '<span style="color: #666;">&mdash; none configured &mdash;</span>';
			}
			if ( ! empty( $row['recipients'] ) ) {
				echo esc_html( implode( ', ', $row['recipients'] ) );
			}
			foreach ( $row['copies'] as $entry ) {
				echo '<div>' . esc_html( $this->format_copy( $entry ) ) . '</div>';
			}
			foreach ( $row['dynamic'] as $dynamic ) {
				echo '<div style="color: #666; font-style: italic;">' . esc_html( $dynamic ) . '</div>';
			}
			echo '</td>';

			echo '<td>';
			if ( empty( $row['recipients'] ) && empty( $row['copies'] ) ) {
				echo '<span style="color: #666;">&mdash;</span>';
			} elseif ( empty( $row['routed'] ) && empty( $row['routed_copies'] ) ) {
				echo '<span style="color: #b32d2e;">Blocked (all recipients blacklisted)</span>';
			} else {
				if ( ! empty( $row['recipients'] ) && empty( $row['routed'] ) ) {
					// The To list is blocked, but a CC or BCC still receives.
					echo '<span style="color: #b32d2e;">To blocked (all blacklisted)</span>';
				} elseif ( ! empty( $row['routed'] ) ) {
					echo esc_html( implode( ', ', $row['routed'] ) );
				}
				foreach ( $row['routed_copies'] as $entry ) {
					echo '<div>' . esc_html( $this->format_copy( $entry ) ) . '</div>';
				}
				if ( ! empty( $row['rerouted'] ) ) {
					echo ' <span class="dashicons dashicons-randomize" style="color: #2271b1;" title="Rerouted by Email Router"></span>';
				}
			}
			echo '</td>';

			echo '<td>' . ( '' !== $row['link'] ? '<a href="' . esc_url( $row['link'] ) . '" target="_blank" rel="noopener">View</a>' : '&mdash;' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Stream the system email report to the browser as a downloadable CSV file.
	 */
	public function handle_system_emails_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied.' );
		}
		check_admin_referer( 'email_router_system_emails_export' );

		$report   = $this->get_system_email_report();
		$filename = 'email-router-system-emails-' . gmdate( 'Ymd-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$lines = array(
			$this->csv_row( array( 'Source', 'Email', 'Subject', 'Status', 'Configured recipients', 'CC/BCC recipients', 'Dynamic recipients', 'Delivered to', 'Rerouted', 'Link' ) ),
		);

		foreach ( $report as $row ) {
			$lines[] = $this->csv_row(
				array(
					$row['source'],
					$row['name'],
					$row['subject'],
					$row['status'],
					implode( ', ', $row['recipients'] ),
					implode( ', ', array_map( array( $this, 'format_copy' ), $row['copies'] ) ),
					implode( ', ', $row['dynamic'] ),
					implode( ', ', array_merge( $row['routed'], array_map( array( $this, 'format_copy' ), $row['routed_copies'] ) ) ),
					$row['rerouted'] ? 'yes' : 'no',
					$row['link'],
				)
			);
		}

		// A CSV download, not markup: escaping for HTML would corrupt the file.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo implode( "\r\n", $lines ) . "\r\n";
		exit;
	}

	/**
	 * Format one CSV record, quoting every field.
	 *
	 * @param array $fields Field values.
	 * @return string The record, without its line ending.
	 */
	private function csv_row( $fields ) {
		$quoted = array_map(
			static function ( $field ) {
				return '"' . str_replace( '"', '""', (string) $field ) . '"';
			},
			$fields
		);

		return implode( ',', $quoted );
	}

	/**
	 * Render email replacements section
	 */
	private function render_replacements_section() {
		$options = get_option( $this->option_name );
		$pairs   = isset( $options['email_replacement_pairs'] ) ? $options['email_replacement_pairs'] : array();

		echo '<div class="email-router-section">';
		echo '<h2>Email Address Replacements</h2>';

		echo '<div class="tablenav top">';
		echo '<div class="alignleft actions">';
		echo '<span class="displaying-num">' . count( $pairs ) . ' replacement rule' . ( count( $pairs ) !== 1 ? 's' : '' ) . '</span>';
		echo '</div>';
		echo '<div class="alignright actions">';
		echo '<button type="button" id="add-email-pair" class="button button-primary">Add New Replacement</button>';
		echo '</div>';
		echo '</div>';

		echo '<table class="wp-list-table widefat fixed striped email-pairs-table">';
		echo '<thead>';
		echo '<tr>';
		echo '<th scope="col" class="manage-column column-title">Title</th>';
		echo '<th scope="col" class="manage-column column-target">Target Email Address</th>';
		echo '<th scope="col" class="manage-column column-replacement">Replacement Recipients</th>';
		echo '<th scope="col" class="manage-column column-actions">Actions</th>';
		echo '</tr>';
		echo '</thead>';
		echo '<tbody id="email-pairs-tbody">';

		if ( empty( $pairs ) ) {
			echo '<tr class="no-items"><td colspan="4" style="text-align: center; padding: 20px; color: #999;">No email replacements configured. Click "Add New Replacement" to get started.</td></tr>';
		} else {
			foreach ( $pairs as $index => $pair ) {
				$this->render_email_pair_row( $index, $pair );
			}
		}

		echo '</tbody>';
		echo '</table>';
		echo '</div>';

		$this->render_recipients_source();
		$this->add_email_pairs_javascript( count( $pairs ) );
		$this->add_email_tags_javascript();
		$this->render_usage_modal();
	}

	/**
	 * Expose every recipient address already used across the replacement rules as a
	 * JS array (window.emailRouterRecipients) so the tag inputs can autocomplete
	 * from prior entries.
	 */
	private function render_recipients_source() {
		$options    = get_option( $this->option_name, array() );
		$recipients = array();

		foreach ( ( $options['email_replacement_pairs'] ?? array() ) as $pair ) {
			foreach ( array_map( 'trim', explode( ',', $pair['replacement'] ?? '' ) ) as $email ) {
				if ( '' !== $email ) {
					$recipients[ strtolower( $email ) ] = $email;
				}
			}
		}
		foreach ( ( $options['subject_pattern_pairs'] ?? array() ) as $pair ) {
			foreach ( array_map( 'trim', explode( ',', $pair['recipients'] ?? '' ) ) as $email ) {
				if ( '' !== $email ) {
					$recipients[ strtolower( $email ) ] = $email;
				}
			}
		}
		ksort( $recipients );

		echo '<script type="text/javascript">window.emailRouterRecipients = ' . wp_json_encode( array_values( $recipients ) ) . ';</script>';
	}

	/**
	 * JavaScript that turns each .email-tags-field into a tag/token input:
	 * recipients are added from the visible input (Enter, comma, or blur) and
	 * shown as removable tags, while the hidden input is kept in sync as a
	 * comma-separated list. Removing a tag asks for confirmation first.
	 */
	private function add_email_tags_javascript() {
		echo <<<'JS'
    <script type="text/javascript">
    (function($) {
      function isEmail(value) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
      }

      function getEmails($field) {
        return ($field.find('.email-tags-value').val() || '')
          .split(',')
          .map(function(s) { return s.trim(); })
          .filter(function(s) { return s.length; });
      }

      function setEmails($field, emails) {
        var seen = {}, unique = [];
        emails.forEach(function(email) {
          var key = email.toLowerCase();
          if (email && !seen[key]) { seen[key] = true; unique.push(email); }
        });
        $field.find('.email-tags-value').val(unique.join(','));
        renderTags($field, unique);
      }

      function renderTags($field, emails) {
        var $list = $field.find('.email-tags-list').empty();
        emails.forEach(function(email) {
          var $tag = $('<span class="email-tag"></span>');
          $tag.append($('<span class="email-tag-label"></span>').text(email));
          $tag.append(
            $('<button type="button" class="email-tag-remove" aria-label="Remove recipient">&times;</button>')
              .attr('data-email', email)
          );
          $list.append($tag);
        });
      }

      function addFromInput($input) {
        var $field = $input.closest('.email-tags-field');
        var value = ($input.val() || '').replace(/,$/, '').trim();
        if (!value) { return; }
        if (!isEmail(value)) { $input.addClass('email-tag-input-error'); return; }
        $input.removeClass('email-tag-input-error');
        var emails = getEmails($field);
        emails.push(value);
        setEmails($field, emails);
        $input.val('');
      }

      function initAll(context) {
        $('.email-tags-field', context || document).each(function() {
          var $field = $(this);
          if ($field.data('tagsInit')) { return; }
          $field.data('tagsInit', true);
          renderTags($field, getEmails($field));
          if (window.emailRouterAttachAutocomplete) {
            window.emailRouterAttachAutocomplete(
              $field.find('.email-tag-input'),
              window.emailRouterRecipients || [],
              function(value, $input) {
                $input.val(value);
                addFromInput($input);
              }
            );
          }
        });
      }

      $(document).on('keydown', '.email-tag-input', function(e) {
        if (e.key === 'Enter' || e.key === ',') {
          // When a suggestion is highlighted, let the autocomplete's select handle it.
          var ac = $(this).data('ui-autocomplete');
          if (e.key === 'Enter' && ac && ac.menu && ac.menu.active) { return; }
          e.preventDefault();
          addFromInput($(this));
        }
      });
      $(document).on('blur', '.email-tag-input', function() {
        addFromInput($(this));
      });
      $(document).on('input', '.email-tag-input', function() {
        $(this).removeClass('email-tag-input-error');
      });

      $(document).on('click', '.email-tag-remove', function(e) {
        e.preventDefault();
        var email = $(this).attr('data-email');
        if (!window.confirm('Remove ' + email + ' from the recipients?')) { return; }
        var $field = $(this).closest('.email-tags-field');
        var remaining = getEmails($field).filter(function(item) {
          return item.toLowerCase() !== String(email).toLowerCase();
        });
        setEmails($field, remaining);
      });

      $(document).ready(function() { initAll(); });
      window.emailRouterInitTags = initAll;
    })(jQuery);
    </script>
JS;
	}

	/**
	 * Render the "Where used?" modal markup and its AJAX-driven behaviour. The
	 * modal is opened by the per-target buttons in the replacements table.
	 */
	private function render_usage_modal() {
		$nonce = wp_create_nonce( 'email_router_usage' );
		?>
	<div id="email-router-usage-modal" class="email-router-modal" style="display:none;">
		<div class="email-router-modal-backdrop"></div>
		<div class="email-router-modal-box">
		<div class="email-router-modal-header">
			<h2 id="email-router-modal-title">Where is this email used?</h2>
			<button type="button" class="email-router-modal-close" aria-label="Close">&times;</button>
		</div>
		<div class="email-router-modal-body" id="email-router-modal-body"></div>
		</div>
	</div>

	<script type="text/javascript">
		(function($) {
		var nonce = <?php echo wp_json_encode( $nonce ); ?>;
		var $modal = $('#email-router-usage-modal');
		var $title = $('#email-router-modal-title');
		var $body = $('#email-router-modal-body');

		function esc(value) {
			return $('<div>').text(value == null ? '' : value).html();
		}

		function closeModal() {
			$modal.hide();
		}

		function renderResults(email, results) {
			if (!results || !results.length) {
			$body.html('<p style="padding:20px;color:#666;">No usages found in WordPress, WooCommerce, Gravity Forms, or the router rules. <br><span style="font-size:12px;">(Only literal addresses are matched — merge tags and dynamic recipients are not resolved.)</span></p>');
			return;
			}
			var html = '<table class="wp-list-table widefat fixed striped">' +
			'<thead><tr>' +
			'<th scope="col" style="width:150px;">Source</th>' +
			'<th scope="col">Location</th>' +
			'<th scope="col">Detail</th>' +
			'<th scope="col" style="width:70px;">Link</th>' +
			'</tr></thead><tbody>';
			results.forEach(function(r) {
			html += '<tr>' +
				'<td>' + esc(r.source) + '</td>' +
				'<td>' + esc(r.location) + '</td>' +
				'<td>' + esc(r.detail) + '</td>' +
				'<td>' + (r.link ? '<a href="' + esc(r.link) + '" target="_blank" rel="noopener">View</a>' : '&mdash;') + '</td>' +
				'</tr>';
			});
			html += '</tbody></table>';
			$body.html(html);
		}

		function openModal(email) {
			$title.text('Where is ' + email + ' used?');
			$body.html('<p style="padding:20px;color:#666;">Scanning…</p>');
			$modal.show();

			$.post(ajaxurl, {
			action: 'email_router_usage',
			nonce: nonce,
			email: email
			}).done(function(resp) {
			if (!resp || !resp.success) {
				var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Something went wrong.';
				$body.html('<p style="padding:20px;color:#b32d2e;">' + esc(msg) + '</p>');
				return;
			}
			renderResults(resp.data.email, resp.data.results);
			}).fail(function() {
			$body.html('<p style="padding:20px;color:#b32d2e;">Request failed. Please try again.</p>');
			});
		}

		$(document).on('click', '.email-router-usage-btn', function(e) {
			e.preventDefault();
			openModal($(this).data('email'));
		});
		$modal.on('click', '.email-router-modal-close, .email-router-modal-backdrop', closeModal);
		$(document).on('keydown', function(e) {
			if (e.key === 'Escape' && $modal.is(':visible')) {
			closeModal();
			}
		});
		})(jQuery);
	</script>
		<?php
	}

	/**
	 * Render subject patterns section
	 */
	private function render_subject_patterns_section() {
		$options = get_option( $this->option_name );
		$pairs   = isset( $options['subject_pattern_pairs'] ) ? $options['subject_pattern_pairs'] : array();

		echo '<div class="email-router-section">';
		echo '<h2>Subject Pattern Routing</h2>';

		echo '<div class="tablenav top">';
		echo '<div class="alignleft actions">';
		echo '<span class="displaying-num">' . count( $pairs ) . ' pattern rule' . ( count( $pairs ) !== 1 ? 's' : '' ) . '</span>';
		echo '</div>';
		echo '<div class="alignright actions">';
		echo '<button type="button" id="add-subject-pair" class="button button-primary">Add New Pattern</button>';
		echo '</div>';
		echo '</div>';

		echo '<table class="wp-list-table widefat fixed striped subject-pairs-table">';
		echo '<thead>';
		echo '<tr>';
		echo '<th scope="col" class="manage-column column-pattern">Subject Pattern</th>';
		echo '<th scope="col" class="manage-column column-recipients">Recipients</th>';
		echo '<th scope="col" class="manage-column column-actions">Actions</th>';
		echo '</tr>';
		echo '</thead>';
		echo '<tbody id="subject-pairs-tbody">';

		if ( empty( $pairs ) ) {
			echo '<tr class="no-items"><td colspan="3" style="text-align: center; padding: 20px; color: #999;">No subject patterns configured. Click "Add New Pattern" to get started.</td></tr>';
		} else {
			foreach ( $pairs as $index => $pair ) {
				$this->render_subject_pair_row( $index, $pair );
			}
		}

		echo '</tbody>';
		echo '</table>';
		echo '</div>';

		$this->render_recipients_source();
		$this->add_subject_pairs_javascript( count( $pairs ) );
		$this->add_email_tags_javascript();
	}

	/**
	 * Render blacklist section
	 */
	private function render_blacklist_section() {
		$options   = get_option( $this->option_name );
		$blacklist = isset( $options['email_blacklist'] ) ? $options['email_blacklist'] : array();

		echo '<div class="email-router-section">';
		echo '<h2>Email Blacklist</h2>';

		echo '<div class="tablenav top">';
		echo '<div class="alignleft actions">';
		echo '<span class="displaying-num">' . count( $blacklist ) . ' blacklisted email' . ( count( $blacklist ) !== 1 ? 's' : '' ) . '</span>';
		echo '</div>';
		echo '<div class="alignright actions">';
		echo '<button type="button" id="add-blacklist-email" class="button button-primary">Add Blacklist Email</button>';
		echo '</div>';
		echo '</div>';

		echo '<table class="wp-list-table widefat fixed striped blacklist-table">';
		echo '<thead>';
		echo '<tr>';
		echo '<th scope="col" class="manage-column column-email">Email Address</th>';
		echo '<th scope="col" class="manage-column column-status">Status</th>';
		echo '<th scope="col" class="manage-column column-actions">Actions</th>';
		echo '</tr>';
		echo '</thead>';
		echo '<tbody id="blacklist-tbody">';

		if ( empty( $blacklist ) ) {
			echo '<tr class="no-items"><td colspan="3" style="text-align: center; padding: 20px; color: #999;">No emails blacklisted. Click "Add Blacklist Email" to block email addresses.</td></tr>';
		} else {
			foreach ( $blacklist as $index => $email ) {
				$this->render_blacklist_row( $index, $email );
			}
		}

		echo '</tbody>';
		echo '</table>';
		echo '</div>';

		$this->add_blacklist_javascript( count( $blacklist ) );
	}

	/**
	 * Prints the replacements tab's inline JavaScript.
	 *
	 * @param int $count Number of rows already rendered; the next new row indexes from here.
	 */
	private function add_email_pairs_javascript( $count ) {
		echo '<script type="text/javascript">
    (function($) {
      $(document).ready(function() {
        var index = ' . (int) $count . ';
        var fieldBase = "' . esc_js( $this->option_name ) . '[email_replacement_pairs]";

        function generateFakeEmail() {
          var randomString = Math.random().toString(36).slice(2, 12);
          return randomString + "@emailrouter.local";
        }

        function escAttr(value) {
          return String(value == null ? "" : value)
            .replace(/&/g, "&amp;").replace(/"/g, "&quot;")
            .replace(/</g, "&lt;").replace(/>/g, "&gt;");
        }

        function buildRow(i, data) {
          data = data || {};
          var name = function(key) { return fieldBase + "[" + i + "][" + key + "]"; };
          return `<tr class="email-pair-row">
            <td class="column-title">
              <input type="text" name="${name("title")}" value="${escAttr(data.title)}" placeholder="Rule title" class="regular-text">
            </td>
            <td class="column-target">
              <input type="email" name="${name("target")}" value="${escAttr(data.target)}" placeholder="user@example.com" class="regular-text" required>
            </td>
            <td class="column-replacement">
              <div class="email-tags-field">
                <input type="hidden" class="email-tags-value" name="${name("replacement")}" value="${escAttr(data.replacement)}">
                <input type="email" class="email-tag-input regular-text" placeholder="Add a recipient and press Enter" autocomplete="off">
                <div class="email-tags-list"></div>
              </div>
            </td>
            <td class="column-actions">
              <div class="button-group">
                <button type="button" class="button button-small duplicate-email-pair" title="Duplicate rule" aria-label="Duplicate rule"><span class="dashicons dashicons-admin-page"></span></button>
                <button type="button" class="button button-small remove-email-pair" title="Remove rule" aria-label="Remove rule"><span class="dashicons dashicons-trash"></span></button>
              </div>
            </td>
          </tr>`;
        }

        $("#add-email-pair").on("click", function(e) {
          e.preventDefault();
          var $newRow = $(buildRow(index, { target: generateFakeEmail() }));
          if ($("#email-pairs-tbody .no-items").length) {
            $("#email-pairs-tbody").empty().append($newRow);
          } else {
            $("#email-pairs-tbody").prepend($newRow);
          }
          if (window.emailRouterInitTags) { window.emailRouterInitTags(); }
          index++;
        });

        $("#email-pairs-tbody").on("click", ".duplicate-email-pair", function(e) {
          e.preventDefault();
          var $row = $(this).closest("tr");
          var data = {
            title: $row.find(".column-title input").val() || "",
            target: $row.find(".column-target input").val() || "",
            replacement: $row.find(".email-tags-value").val() || ""
          };
          var $newRow = $(buildRow(index, data));
          $row.after($newRow);
          if (window.emailRouterInitTags) { window.emailRouterInitTags(); }
          index++;
        });

        $("#email-pairs-tbody").on("click", ".remove-email-pair", function(e) {
          e.preventDefault();
          var $row = $(this).closest("tr");
          var target = $row.find(".column-target input").val() || "this rule";
          if (!window.confirm("Remove the replacement rule for " + target + "?")) { return; }
          $row.remove();
          if ($("#email-pairs-tbody tr").length === 0) {
            $("#email-pairs-tbody").html(`<tr class="no-items"><td colspan="4" style="text-align: center; padding: 20px; color: #999;">No email replacements configured.</td></tr>`);
          }
        });
      });
    })(jQuery);
    </script>';
	}

	/**
	 * Prints the subject-patterns tab's inline JavaScript.
	 *
	 * @param int $count Number of rows already rendered; the next new row indexes from here.
	 */
	private function add_subject_pairs_javascript( $count ) {
		echo '<script type="text/javascript">
    (function($) {
      $(document).ready(function() {
        var index = ' . (int) $count . ';
        var fieldBase = "' . esc_js( $this->option_name ) . '[subject_pattern_pairs]";

        function escAttr(value) {
          return String(value == null ? "" : value)
            .replace(/&/g, "&amp;").replace(/"/g, "&quot;")
            .replace(/</g, "&lt;").replace(/>/g, "&gt;");
        }

        function buildRow(i, data) {
          data = data || {};
          var name = function(key) { return fieldBase + "[" + i + "][" + key + "]"; };
          return `<tr class="subject-pair-row">
            <td class="column-pattern">
              <input type="text" name="${name("pattern")}" value="${escAttr(data.pattern)}" placeholder="*Order* or Payment* or *Invoice*" class="regular-text" required>
            </td>
            <td class="column-recipients">
              <div class="email-tags-field">
                <input type="hidden" class="email-tags-value" name="${name("recipients")}" value="${escAttr(data.recipients)}">
                <input type="email" class="email-tag-input regular-text" placeholder="Add a recipient and press Enter" autocomplete="off">
                <div class="email-tags-list"></div>
              </div>
            </td>
            <td class="column-actions">
              <div class="button-group">
                <button type="button" class="button button-small duplicate-subject-pair" title="Duplicate rule" aria-label="Duplicate rule"><span class="dashicons dashicons-admin-page"></span></button>
                <button type="button" class="button button-small remove-subject-pair" title="Remove rule" aria-label="Remove rule"><span class="dashicons dashicons-trash"></span></button>
              </div>
            </td>
          </tr>`;
        }

        $("#add-subject-pair").on("click", function(e) {
          e.preventDefault();
          var $newRow = $(buildRow(index, {}));
          if ($("#subject-pairs-tbody .no-items").length) {
            $("#subject-pairs-tbody").empty().append($newRow);
          } else {
            $("#subject-pairs-tbody").prepend($newRow);
          }
          if (window.emailRouterInitTags) { window.emailRouterInitTags(); }
          index++;
        });

        $("#subject-pairs-tbody").on("click", ".duplicate-subject-pair", function(e) {
          e.preventDefault();
          var $row = $(this).closest("tr");
          var data = {
            pattern: $row.find(".column-pattern input").val() || "",
            recipients: $row.find(".email-tags-value").val() || ""
          };
          var $newRow = $(buildRow(index, data));
          $row.after($newRow);
          if (window.emailRouterInitTags) { window.emailRouterInitTags(); }
          index++;
        });

        $("#subject-pairs-tbody").on("click", ".remove-subject-pair", function(e) {
          e.preventDefault();
          var $row = $(this).closest("tr");
          var pattern = $row.find(".column-pattern input").val() || "this pattern";
          if (!window.confirm("Remove the subject pattern rule for " + pattern + "?")) { return; }
          $row.remove();
          if ($("#subject-pairs-tbody tr").length === 0) {
            $("#subject-pairs-tbody").html(`<tr class="no-items"><td colspan="3" style="text-align: center; padding: 20px; color: #999;">No subject patterns configured.</td></tr>`);
          }
        });
      });
    })(jQuery);
    </script>';
	}

	/**
	 * Prints the blacklist tab's inline JavaScript.
	 *
	 * @param int $count Number of rows already rendered; the next new row indexes from here.
	 */
	private function add_blacklist_javascript( $count ) {
		echo '<script type="text/javascript">
    (function($) {
      $(document).ready(function() {
        var index = ' . absint( $count ) . ';
        
        $("#add-blacklist-email").on("click", function(e) {
          e.preventDefault();
          var newRow = `<tr class="blacklist-row">
            <td class="column-email">
              <input type="email" name="' . esc_attr( $this->option_name ) . '[email_blacklist][${index}]" 
                placeholder="blocked@example.com" class="regular-text" required>
            </td>
            <td class="column-status">
              <span class="rule-status blocked">🚫 Blocked</span>
            </td>
            <td class="column-actions">
              <button type="button" class="button button-small remove-blacklist-email">Remove</button>
            </td>
          </tr>`;
          
          if ($("#blacklist-tbody .no-items").length) {
            $("#blacklist-tbody").html(newRow);
          } else {
            $("#blacklist-tbody").append(newRow);
          }
          index++;
        });
        
        $("#blacklist-tbody").on("click", ".remove-blacklist-email", function(e) {
          e.preventDefault();
          var $row = $(this).closest("tr");
          var email = $row.find(".column-email input").val() || "this address";
          if (!window.confirm("Remove " + email + " from the blacklist? It will start receiving mail again.")) { return; }
          $row.remove();
          if ($("#blacklist-tbody tr").length === 0) {
            $("#blacklist-tbody").html(`<tr class="no-items"><td colspan="3" style="text-align: center; padding: 20px; color: #999;">No emails blacklisted.</td></tr>`);
          }
        });
      });
    })(jQuery);
    </script>';
	}

	/**
	 * Renders the replacements tab.
	 */
	public function email_pairs_render() {
		$options = get_option( $this->option_name );
		$pairs   = isset( $options['email_replacement_pairs'] ) ? $options['email_replacement_pairs'] : array();
		?>

	<div id="email-pairs">
		<?php if ( empty( $pairs ) ) : ?>
		<div class="email-pair">
			<input type="email" name="<?php echo esc_attr( $this->option_name ); ?>[email_replacement_pairs][0][target]"
			placeholder="Target Email Address" style="width: 40%;" />
			<input type="text" name="<?php echo esc_attr( $this->option_name ); ?>[email_replacement_pairs][0][replacement]"
			placeholder="Replacement Emails (comma-separated)" style="width: 50%;" />
			<button class="remove-email-pair button-secondary" style="margin-left: 5px;">Remove</button>
		</div>
		<?php else : ?>
			<?php foreach ( $pairs as $index => $pair ) : ?>
			<div class="email-pair" style="margin-top:0.5rem">
			<input type="email"
				name="<?php echo esc_attr( $this->option_name ); ?>[email_replacement_pairs][<?php echo esc_attr( $index ); ?>][target]"
				value="<?php echo esc_attr( $pair['target'] ); ?>" placeholder="Target Email Address" style="width: 40%;" />
			<input type="text"
				name="<?php echo esc_attr( $this->option_name ); ?>[email_replacement_pairs][<?php echo esc_attr( $index ); ?>][replacement]"
				value="<?php echo esc_attr( $pair['replacement'] ); ?>" placeholder="Replacement Emails (comma-separated)"
				style="width: 50%;" />
			<button class="remove-email-pair button-secondary" style="margin-left: 5px;">Remove</button>
			</div>
		<?php endforeach; ?>
		<?php endif; ?>
	</div>

	<button id="add-email-pair" class="button-primary" style="margin-top:1rem;">Add Email Pair</button>

	<script type="text/javascript">
		(function($) {
		$(document).ready(function() {
			var $emailPairs = $('#email-pairs');
			var index = <?php echo count( $pairs ); ?>;

			$('#add-email-pair').on('click', function(e) {
			e.preventDefault();
			var newPair = `<div class="email-pair">
																									<input type="email" name="<?php echo esc_attr( $this->option_name ); ?>[email_replacement_pairs][${index}][target]" 
																									placeholder="Target Email Address" style="width: 40%;" />
																									<input type="text" name="<?php echo esc_attr( $this->option_name ); ?>[email_replacement_pairs][${index}][replacement]" 
																									placeholder="Replacement Emails (comma-separated)" style="width: 50%;" />
																									<button class="remove-email-pair button-secondary" style="margin-left: 5px;">Remove</button>
																								</div>`;
			$emailPairs.append(newPair);
			index++;
			});

			$emailPairs.on('click', '.remove-email-pair', function(e) {
			e.preventDefault();
			$(this).closest('.email-pair').remove();
			});
		});
		})(jQuery);
	</script>
		<?php
	}

	/**
	 * Renders the subject-patterns tab.
	 */
	public function subject_pairs_render() {
		$options = get_option( $this->option_name );
		$pairs   = isset( $options['subject_pattern_pairs'] ) ? $options['subject_pattern_pairs'] : array();
		?>

	<div id="subject-pairs">
		<?php if ( empty( $pairs ) ) : ?>
		<div class="subject-pair">
			<input type="text" name="<?php echo esc_attr( $this->option_name ); ?>[subject_pattern_pairs][0][pattern]"
			placeholder="Subject Pattern (e.g. *Order*)" style="width: 40%;" />
			<input type="text" name="<?php echo esc_attr( $this->option_name ); ?>[subject_pattern_pairs][0][recipients]"
			placeholder="Recipient Emails (comma-separated)" style="width: 50%;" />
			<button class="remove-subject-pair button-secondary" style="margin-left: 5px;">Remove</button>
		</div>
		<?php else : ?>
			<?php foreach ( $pairs as $index => $pair ) : ?>
			<div class="subject-pair" style="margin-top:0.5rem">
			<input type="text" name="<?php echo esc_attr( $this->option_name ); ?>[subject_pattern_pairs][<?php echo esc_attr( $index ); ?>][pattern]"
				value="<?php echo esc_attr( $pair['pattern'] ); ?>" placeholder="Subject Pattern (e.g. *Order*)"
				style="width: 40%;" />
			<input type="text"
				name="<?php echo esc_attr( $this->option_name ); ?>[subject_pattern_pairs][<?php echo esc_attr( $index ); ?>][recipients]"
				value="<?php echo esc_attr( $pair['recipients'] ); ?>" placeholder="Recipient Emails (comma-separated)"
				style="width: 50%;" />
			<button class="remove-subject-pair button-secondary" style="margin-left: 5px;">Remove</button>
			</div>
		<?php endforeach; ?>
		<?php endif; ?>
	</div>

	<button id="add-subject-pair" class="button-primary" style="margin-top:1rem;">Add Subject Pattern</button>

	<script type="text/javascript">
		(function($) {
		$(document).ready(function() {
			var $subjectPairs = $('#subject-pairs');
			var subjectIndex = <?php echo count( $pairs ); ?>;

			$('#add-subject-pair').on('click', function(e) {
			e.preventDefault();
			var newPair = `<div class="subject-pair">
																									<input type="text" name="<?php echo esc_attr( $this->option_name ); ?>[subject_pattern_pairs][${subjectIndex}][pattern]" 
																									placeholder="Subject Pattern (e.g. *Order*)" style="width: 40%;" />
																									<input type="text" name="<?php echo esc_attr( $this->option_name ); ?>[subject_pattern_pairs][${subjectIndex}][recipients]"
																									placeholder="Recipient Emails (comma-separated)" style="width: 50%;" />
																									<button class="remove-subject-pair button-secondary" style="margin-left: 5px;">Remove</button>
																								</div>`;
			$subjectPairs.append(newPair);
			subjectIndex++;
			});

			$subjectPairs.on('click', '.remove-subject-pair', function(e) {
			e.preventDefault();
			$(this).closest('.subject-pair').remove();
			});
		});
		})(jQuery);
	</script>
		<?php
	}

	/**
	 * Renders the blacklist tab.
	 */
	public function blacklist_render() {
		$options   = get_option( $this->option_name );
		$blacklist = isset( $options['email_blacklist'] ) ? $options['email_blacklist'] : array();
		?>

	<div id="blacklist-emails">
		<?php if ( empty( $blacklist ) ) : ?>
		<div class="blacklist-email">
			<input type="email" name="<?php echo esc_attr( $this->option_name ); ?>[email_blacklist][0]"
			placeholder="Email Address to Blacklist" style="width: 70%;" />
			<button class="remove-blacklist-email button-secondary" style="margin-left: 5px;">Remove</button>
		</div>
		<?php else : ?>
			<?php foreach ( $blacklist as $index => $email ) : ?>
			<div class="blacklist-email" style="margin-top:0.5rem">
			<input type="email" name="<?php echo esc_attr( $this->option_name ); ?>[email_blacklist][<?php echo esc_attr( $index ); ?>]"
				value="<?php echo esc_attr( $email ); ?>" placeholder="Email Address to Blacklist" style="width: 70%;" />
			<button class="remove-blacklist-email button-secondary" style="margin-left: 5px;">Remove</button>
			</div>
		<?php endforeach; ?>
		<?php endif; ?>
	</div>

	<button id="add-blacklist-email" class="button-primary" style="margin-top:1rem;">Add Blacklist Email</button>

	<script type="text/javascript">
		(function($) {
		$(document).ready(function() {
			var $blacklistEmails = $('#blacklist-emails');
			var blacklistIndex = <?php echo count( $blacklist ); ?>;

			$('#add-blacklist-email').on('click', function(e) {
			e.preventDefault();
			var newEmail = `<div class="blacklist-email">
																									<input type="email" name="<?php echo esc_attr( $this->option_name ); ?>[email_blacklist][${blacklistIndex}]" 
																									placeholder="Email Address to Blacklist" style="width: 70%;" />
																									<button class="remove-blacklist-email button-secondary" style="margin-left: 5px;">Remove</button>
																								</div>`;
			$blacklistEmails.append(newEmail);
			blacklistIndex++;
			});

			$blacklistEmails.on('click', '.remove-blacklist-email', function(e) {
			e.preventDefault();
			$(this).closest('.blacklist-email').remove();
			});
		});
		})(jQuery);
	</script>
		<?php
	}

	/**
	 * Substitutes target addresses with their replacement recipients.
	 *
	 * Hooked to wp_mail at priority 20, so it acts on whatever replace_by_subject()
	 * left in place. Applies the blacklist before returning. CC and BCC headers get
	 * the same replacement and blacklist treatment through route_copy_headers().
	 *
	 * @param array $args wp_mail arguments.
	 * @return array Arguments with the recipient list rewritten.
	 */
	public function replace_emails( $args ) {
		$options = get_option( $this->option_name );
		$pairs   = isset( $options['email_replacement_pairs'] ) ? $options['email_replacement_pairs'] : array();

		// Apply replacements. Each recipient is compared as a whole address, so a
		// sales@ rule leaves vehiclesales@ alone.
		foreach ( $pairs as $pair ) {
			$target_email       = trim( $pair['target'] );
			$replacement_emails = $pair['replacement'];
			if ( ! empty( $target_email ) && ! empty( $replacement_emails ) ) {
				$recipients = self::replace_address( self::split_addresses( $args['to'] ), $target_email, $replacement_emails );
				$args['to'] = implode( ',', $recipients );
				// qm/debug is Query Monitor's hook; the name is theirs, not ours to prefix.
				// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				do_action( 'qm/debug', $args['to'] );
			}
		}

		$args = $this->route_copy_headers( $args );

		// Apply blacklist filtering.
		$args = $this->apply_blacklist( $args );

		return $args;
	}

	/**
	 * Routes the addresses in CC and BCC headers.
	 *
	 * Address replacement and the blacklist apply to these the same as to `to`, so
	 * an alias used as a BCC is expanded and a blacklisted address never receives a
	 * copy. Subject routing is not applied: a subject rule redirects the primary
	 * recipients and leaves the copies alone. A header whose addresses are all
	 * blacklisted is dropped. Every other header passes through untouched.
	 *
	 * @param array $args wp_mail arguments.
	 * @return array Arguments with the CC and BCC headers rewritten.
	 */
	private function route_copy_headers( $args ) {
		if ( empty( $args['headers'] ) ) {
			return $args;
		}

		// wp_mail takes headers as an array of lines or a newline-separated string.
		$headers = $args['headers'];
		$lines   = is_array( $headers ) ? $headers : explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );
		$routed  = array();

		foreach ( $lines as $key => $line ) {
			if ( ! is_string( $line ) || ! preg_match( '/^\s*(cc|bcc)\s*:(.*)$/i', $line, $matches ) ) {
				$routed[ $key ] = $line;
				continue;
			}

			$addresses = $this->route_copy_addresses( $matches[2] );
			if ( ! empty( $addresses ) ) {
				$routed[ $key ] = $matches[1] . ': ' . implode( ', ', $addresses );
			}
		}

		if ( $routed !== $lines ) {
			do_action(
				// qm/debug is Query Monitor's hook; the name is theirs, not ours to prefix.
				// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				'qm/debug',
				array(
					'copy_headers_routed' => true,
					'original_headers'    => $lines,
					'routed_headers'      => $routed,
				)
			);
		}

		$args['headers'] = is_array( $headers ) ? $routed : implode( "\n", $routed );

		return $args;
	}

	/**
	 * Runs CC or BCC addresses through the replacement rules and the blacklist.
	 *
	 * Shared by the wp_mail path and the system email report so the two agree.
	 * The blacklist is matched on the bare address, ignoring case, so a header
	 * entry written as "Name <address>" is still caught.
	 *
	 * @param string|array $addresses Comma-separated list, or an array of entries.
	 * @return array Addresses the copy is delivered to.
	 */
	private function route_copy_addresses( $addresses ) {
		$options   = get_option( $this->option_name );
		$pairs     = isset( $options['email_replacement_pairs'] ) ? $options['email_replacement_pairs'] : array();
		$blacklist = isset( $options['email_blacklist'] ) ? array_filter( (array) $options['email_blacklist'] ) : array();
		$blacklist = array_map( 'strtolower', array_map( 'trim', $blacklist ) );

		$addresses = self::split_addresses( $addresses );

		foreach ( $pairs as $pair ) {
			$target_email       = isset( $pair['target'] ) ? trim( $pair['target'] ) : '';
			$replacement_emails = isset( $pair['replacement'] ) ? $pair['replacement'] : '';
			if ( ! empty( $target_email ) && ! empty( $replacement_emails ) ) {
				$addresses = self::replace_address( $addresses, $target_email, $replacement_emails );
			}
		}

		return array_values(
			array_filter(
				$addresses,
				function ( $address ) use ( $blacklist ) {
					return ! in_array( strtolower( self::bare_address( $address ) ), $blacklist, true );
				}
			)
		);
	}

	/**
	 * Redirects mail whose subject matches a configured pattern.
	 *
	 * Hooked to wp_mail at priority 10. A match replaces the recipient list outright,
	 * so with several matching patterns the last one wins. Applies the blacklist
	 * before returning.
	 *
	 * @param array $args wp_mail arguments.
	 * @return array Arguments with the recipient list rewritten.
	 */
	public function replace_by_subject( $args ) {
		if ( ! isset( $args['subject'] ) ) {
			return $args;
		}

		$options = get_option( $this->option_name );
		$pairs   = isset( $options['subject_pattern_pairs'] ) ? $options['subject_pattern_pairs'] : array();

		foreach ( $pairs as $pair ) {
			$pattern    = trim( $pair['pattern'] );
			$recipients = trim( $pair['recipients'] );

			if ( empty( $pattern ) || empty( $recipients ) ) {
				continue;
			}

			if ( preg_match( '/' . $pattern . '/i', $args['subject'] ) ) {
				// Split recipients by comma and clean them.
				$new_recipients = array_map( 'trim', explode( ',', $recipients ) );
				// A matching pattern replaces the recipient list outright.
				$args['to'] = array_unique( $new_recipients );

				do_action(
					// qm/debug is Query Monitor's hook; the name is theirs, not ours to prefix.
					// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
					'qm/debug',
					array(
						'subject'         => $args['subject'],
						'matched_pattern' => $pattern,
						'new_recipients'  => $args['to'],
					)
				);
			}
		}

		// Apply blacklist filtering.
		$args = $this->apply_blacklist( $args );

		return $args;
	}

	/**
	 * Strips every blacklisted address from the recipient list.
	 *
	 * @param array $args wp_mail arguments.
	 * @return array Arguments with blacklisted recipients removed.
	 */
	private function apply_blacklist( $args ) {
		$options   = get_option( $this->option_name );
		$blacklist = isset( $options['email_blacklist'] ) ? array_filter( $options['email_blacklist'] ) : array();

		if ( empty( $blacklist ) ) {
			return $args;
		}

		// Normalize the 'to' field to an array.
		$recipients = array();
		if ( is_array( $args['to'] ) ) {
			$recipients = $args['to'];
		} elseif ( is_string( $args['to'] ) ) {
			$recipients = array_map( 'trim', explode( ',', $args['to'] ) );
		}

		// Filter out blacklisted emails.
		$filtered_recipients = array_filter(
			$recipients,
			function ( $email ) use ( $blacklist ) {
				return ! in_array( trim( $email ), $blacklist, true );
			}
		);

		// Update the args with filtered recipients.
		$args['to'] = array_values( $filtered_recipients );

		// Log blacklist filtering if any emails were removed.
		if ( count( $recipients ) !== count( $filtered_recipients ) ) {
			do_action(
				// qm/debug is Query Monitor's hook; the name is theirs, not ours to prefix.
				// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				'qm/debug',
				array(
					'blacklist_applied'   => true,
					'original_recipients' => $recipients,
					'filtered_recipients' => $args['to'],
					'blacklisted_emails'  => array_diff( $recipients, $filtered_recipients ),
				)
			);
		}

		return $args;
	}

	/**
	 * AJAX handler for the "Where used?" modal. Scans the site for the posted
	 * email address and returns the matching usages as JSON.
	 */
	public function ajax_usage() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}

		check_ajax_referer( 'email_router_usage', 'nonce' );

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( empty( $email ) || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => 'Invalid email address.' ) );
		}

		wp_send_json_success(
			array(
				'email'   => $email,
				'results' => $this->find_email_usage( $email ),
			)
		);
	}

	/**
	 * Scan the site for everywhere the given email address is configured.
	 *
	 * Only literal addresses are matched; merge tags / dynamic recipients are
	 * left as-is. Each source is guarded so the scan degrades gracefully when a
	 * plugin (WooCommerce, Gravity Forms) is not active.
	 *
	 * @param string $email Address to search for.
	 * @return array<int, array{source:string, location:string, detail:string, link:string}>
	 */
	private function find_email_usage( $email ) {
		$email   = strtolower( trim( $email ) );
		$results = array();

		if ( empty( $email ) || ! is_email( $email ) ) {
			return $results;
		}

		// --- WordPress core ---
		if ( strtolower( (string) get_option( 'admin_email' ) ) === $email ) {
			$results[] = array(
				'source'   => 'WordPress',
				'location' => 'Administration email address',
				'detail'   => 'Settings → General',
				'link'     => admin_url( 'options-general.php' ),
			);
		}

		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$results[] = array(
				'source'   => 'WordPress',
				'location' => 'User account: ' . $user->user_login,
				'detail'   => 'Roles: ' . ( empty( $user->roles ) ? 'none' : implode( ', ', $user->roles ) ),
				'link'     => admin_url( 'user-edit.php?user_id=' . $user->ID ),
			);
		}

		// --- WooCommerce ---
		if ( class_exists( 'WooCommerce' ) && function_exists( 'WC' ) ) {
			$mailer = WC()->mailer();
			if ( $mailer && method_exists( $mailer, 'get_emails' ) ) {
				foreach ( $mailer->get_emails() as $wc_email ) {
					$recipient = isset( $wc_email->recipient ) ? $wc_email->recipient : '';
					if ( $recipient && $this->email_in_list( $email, $recipient ) ) {
						$results[] = array(
							'source'   => 'WooCommerce',
							'location' => 'Email: ' . $wc_email->get_title(),
							'detail'   => 'Recipient: ' . $recipient,
							'link'     => admin_url( 'admin.php?page=wc-settings&tab=email&section=' . strtolower( get_class( $wc_email ) ) ),
						);
					}
				}
			}

			$stock_recipient = get_option( 'woocommerce_stock_email_recipient' );
			if ( $stock_recipient && $this->email_in_list( $email, $stock_recipient ) ) {
				$results[] = array(
					'source'   => 'WooCommerce',
					'location' => 'Stock notification recipient',
					'detail'   => $stock_recipient,
					'link'     => admin_url( 'admin.php?page=wc-settings&tab=products&section=inventory' ),
				);
			}

			$from_address = get_option( 'woocommerce_email_from_address' );
			if ( $from_address && strtolower( trim( $from_address ) ) === $email ) {
				$results[] = array(
					'source'   => 'WooCommerce',
					'location' => '"From" address',
					'detail'   => $from_address,
					'link'     => admin_url( 'admin.php?page=wc-settings&tab=email' ),
				);
			}
		}

		// --- Gravity Forms notifications ---
		if ( class_exists( 'GFAPI' ) ) {
			$forms = GFAPI::get_forms();
			foreach ( $forms as $form ) {
				if ( empty( $form['notifications'] ) ) {
					continue;
				}
				foreach ( $form['notifications'] as $notification ) {
					$matched = array();
					foreach ( array( 'to', 'cc', 'bcc', 'from', 'replyTo' ) as $field ) {
						if ( ! empty( $notification[ $field ] ) && is_string( $notification[ $field ] ) && $this->email_in_list( $email, $notification[ $field ] ) ) {
							$matched[] = strtoupper( $field ) . ': ' . $notification[ $field ];
						}
					}
					if ( $matched ) {
						$results[] = array(
							'source'   => 'Gravity Forms',
							'location' => 'Form "' . $form['title'] . '" → notification "' . ( $notification['name'] ?? 'Untitled' ) . '"',
							'detail'   => implode( ' | ', $matched ),
							'link'     => admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=notification&id=' . $form['id'] . '&nid=' . ( $notification['id'] ?? '' ) ),
						);
					}
				}
			}
		}

		// --- This router's own rules ---
		$rules_link = admin_url( 'tools.php?page=email_router&tab=replacements' );
		$options    = get_option( $this->option_name, array() );
		foreach ( $options['email_replacement_pairs'] ?? array() as $pair ) {
			if ( $this->email_in_list( $email, $pair['replacement'] ?? '' ) ) {
				$results[] = array(
					'source'   => 'Email Router',
					'location' => 'Replacement rule (recipient)',
					'detail'   => ( $pair['target'] ?? '' ) . ' → ' . ( $pair['replacement'] ?? '' ),
					'link'     => $rules_link,
				);
			}
		}
		foreach ( $options['subject_pattern_pairs'] ?? array() as $pair ) {
			if ( $this->email_in_list( $email, $pair['recipients'] ?? '' ) ) {
				$results[] = array(
					'source'   => 'Email Router',
					'location' => 'Subject pattern recipient',
					'detail'   => 'Pattern: ' . ( $pair['pattern'] ?? '' ) . ' → ' . ( $pair['recipients'] ?? '' ),
					'link'     => admin_url( 'tools.php?page=email_router&tab=subjects' ),
				);
			}
		}
		foreach ( $options['email_blacklist'] ?? array() as $blacklisted ) {
			if ( strtolower( trim( (string) $blacklisted ) ) === $email ) {
				$results[] = array(
					'source'   => 'Email Router',
					'location' => 'Blacklisted (blocked from all mail)',
					'detail'   => $blacklisted,
					'link'     => admin_url( 'tools.php?page=email_router&tab=blacklist' ),
				);
			}
		}

		return $results;
	}

	/**
	 * Whether $email appears as a literal entry in a comma-separated recipient string.
	 *
	 * @param string $email         Already-lowercased address to look for.
	 * @param string $address_list  Comma-separated list of addresses (may include merge tags).
	 * @return bool
	 */
	private function email_in_list( $email, $address_list ) {
		foreach ( array_map( 'trim', explode( ',', (string) $address_list ) ) as $candidate ) {
			if ( strtolower( $candidate ) === $email ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Splits a recipient list into trimmed, non-empty entries.
	 *
	 * @param string|array $recipients Comma-separated string, or a possibly-nested array.
	 * @return array Recipient entries.
	 */
	private static function split_addresses( $recipients ) {
		$entries = array();
		foreach ( self::unique_flatten( (array) $recipients ) as $entry ) {
			$entries = array_merge( $entries, explode( ',', (string) $entry ) );
		}
		return array_values( array_filter( array_map( 'trim', $entries ), 'strlen' ) );
	}

	/**
	 * Swaps every entry whose address matches the target for the replacement list.
	 *
	 * @param array  $recipients  Recipient entries.
	 * @param string $target      Address to replace, compared whole and ignoring case.
	 * @param string $replacement Comma-separated replacement addresses.
	 * @return array Recipient entries with duplicates dropped.
	 */
	private static function replace_address( $recipients, $target, $replacement ) {
		$replaced = array();
		foreach ( $recipients as $recipient ) {
			if ( 0 === strcasecmp( self::bare_address( $recipient ), $target ) ) {
				$replaced = array_merge( $replaced, self::split_addresses( $replacement ) );
			} else {
				$replaced[] = $recipient;
			}
		}
		return array_values( array_unique( $replaced ) );
	}

	/**
	 * Extracts the address from a recipient entry such as "Name <a@example.com>".
	 *
	 * @param string $recipient Recipient entry.
	 * @return string The bare address.
	 */
	private static function bare_address( $recipient ) {
		if ( preg_match( '/<([^>]+)>/', $recipient, $matches ) ) {
			return trim( $matches[1] );
		}
		return trim( $recipient );
	}

	/**
	 * Flattens a possibly-nested array of addresses and drops duplicates.
	 *
	 * @param array $addresses Addresses, possibly nested.
	 * @return array Unique, flattened addresses.
	 */
	public static function unique_flatten( $addresses ) {
		$flattened = array();
		array_walk_recursive(
			$addresses,
			function ( $address ) use ( &$flattened ) {
				$flattened[] = $address;
			}
		);
		return array_unique( $flattened );
	}
}

EmailRouter::get_instance();
