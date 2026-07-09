<?php
/**
 * Plugin Name:  Tutor LMS Quiz Importer
 * Plugin URI:   https://github.com/Inamo87100/tutor-lms-quiz-importer
 * Description:  Imports questions from Excel/CSV into an existing Tutor LMS quiz after selecting a Tutor LMS course. Questions are added without replacing existing ones.
 * Version:      1.6.0
 * Author:       Nuova Formamentis
 * Text Domain:  tutor-lms-quiz-importer
 * Domain Path:  /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NFM_TQI_VERSION',     '1.6.0' );
define( 'NFM_TQI_PLUGIN_FILE', __FILE__ );
define( 'NFM_TQI_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'NFM_TQI_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

require_once NFM_TQI_PLUGIN_DIR . 'includes/class-nfm-tutor-quiz-importer.php';

new NFM_Tutor_Quiz_Importer();
