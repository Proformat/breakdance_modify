<?php
/**
 * Plugin Name: Breakdance Modify
 * Plugin URI: https://breakdance.com/
 * Description: The Breakdance website builder for WordPress makes it easy to create incredible websites with 100+ elements, mega menu builder, form builder, WooCommerce, dynamic data, and much more.
 * Author: SO
 * Version: 1.0.0
 * Author URI: https://example.com/
 */

add_action('admin_init', 'modify_breakdance_plugin');

function modify_breakdance_plugin() {
    $file_path = ABSPATH . 'wp-content/plugins/breakdance/subplugins/breakdance-elements/elements/FormBuilder/ajax.php';
    $search_text = 'return \Breakdance\Forms\handleSubmission(';
    $replace_text = 'return \Breakdance\Forms\handleSubmissionCustom(';
    $require_file = 'breakdance_form_modify.php';

    if (file_exists($file_path)) {
        $file_content = file_get_contents($file_path);
        
        if (strpos($file_content, $search_text) !== false) {
            // Znalazło szukany tekst i zamieni go na nowy
            $modified_content = str_replace($search_text, $replace_text, $file_content);
            file_put_contents($file_path, $modified_content);

            // Ładuje plik z nową funkcją 
            require_once $require_file;
        } else if (strpos($file_content, $replace_text) !== false) {
            // Ładuje plik z nową funkcją 
            require_once $require_file;
        }

    } 
}
