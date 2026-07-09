<?php
/**
 * Main plugin class.
 *
 * Handles admin menu registration, file parsing, and quiz question insertion
 * for the Tutor LMS Quiz Importer plugin.
 *
 * @package TutorLMSQuizImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NFM_Tutor_Quiz_Importer {
	const MENU_SLUG = 'nfm-tutor-quiz-importer';

	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'tutor-lms-quiz-importer', false, dirname( plugin_basename( NFM_TQI_PLUGIN_FILE ) ) . '/languages' );
	}

	public function add_admin_menu() {
		add_management_page(
			esc_html__( 'Tutor LMS Quiz Importer', 'tutor-lms-quiz-importer' ),
			esc_html__( 'Tutor LMS Quiz Importer', 'tutor-lms-quiz-importer' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	private function get_queryable_post_statuses() {
		return array( 'publish', 'private', 'draft', 'pending', 'future' );
	}

	private function get_courses() {
		return get_posts(
			array(
				'post_type'      => 'courses',
				'post_status'    => $this->get_queryable_post_statuses(),
				'posts_per_page' => -1,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);
	}

	private function get_course_quizzes( $course_id ) {
		$course_id = absint( $course_id );
		if ( ! $course_id ) {
			return array();
		}

		$topics = get_posts(
			array(
				'post_type'      => 'topics',
				'post_status'    => $this->get_queryable_post_statuses(),
				'post_parent'    => $course_id,
				'posts_per_page' => -1,
				'orderby'        => 'menu_order ID',
				'order'          => 'ASC',
			)
		);

		if ( empty( $topics ) ) {
			return array();
		}

		$quizzes = array();

		foreach ( $topics as $topic ) {
			$topic_quizzes = get_posts(
				array(
					'post_type'      => 'tutor_quiz',
					'post_status'    => $this->get_queryable_post_statuses(),
					'post_parent'    => $topic->ID,
					'posts_per_page' => -1,
					'orderby'        => 'menu_order ID',
					'order'          => 'ASC',
				)
			);

			foreach ( $topic_quizzes as $quiz ) {
				$quizzes[] = (object) array(
					'quiz_id'    => (int) $quiz->ID,
					'quiz_title' => $quiz->post_title,
					'topic_id'   => (int) $topic->ID,
					'topic_title' => $topic->post_title,
				);
			}
		}

		return $quizzes;
	}

	private function get_quizzes_by_course( $courses ) {
		$quizzes_by_course = array();

		foreach ( $courses as $course ) {
			$course_quizzes = $this->get_course_quizzes( $course->ID );

			$quizzes_by_course[ (string) $course->ID ] = array_map(
				static function ( $quiz ) {
					return array(
						'quiz_id'     => (int) $quiz->quiz_id,
						'quiz_title'  => $quiz->quiz_title,
						'topic_title' => $quiz->topic_title,
					);
				},
				$course_quizzes
			);
		}

		return $quizzes_by_course;
	}

	private function get_request_absint( $request, $key ) {
		if ( ! is_array( $request ) || ! isset( $request[ $key ] ) ) {
			return 0;
		}

		return absint( wp_unslash( $request[ $key ] ) );
	}

	private function get_request_quiz_id( $request ) {
		return $this->get_request_absint( $request, 'nfm_quiz_id' );
	}

	private function get_request_course_id( $request ) {
		$course_id = $this->get_request_absint( $request, 'nfm_course_id' );

		if ( $course_id ) {
			return $course_id;
		}

		$quiz_id = $this->get_request_quiz_id( $request );

		if ( ! $quiz_id ) {
			return 0;
		}

		return $this->get_course_id_for_quiz( $quiz_id );
	}

	private function get_course_id_for_quiz( $quiz_id ) {
		$quiz_id = absint( $quiz_id );

		if ( ! $quiz_id ) {
			return 0;
		}

		$topic_id = wp_get_post_parent_id( $quiz_id );
		if ( ! $topic_id || 'topics' !== get_post_type( $topic_id ) ) {
			return 0;
		}

		$course_id = wp_get_post_parent_id( $topic_id );
		if ( ! $course_id || 'courses' !== get_post_type( $course_id ) ) {
			return 0;
		}

		return $this->is_valid_course( $course_id ) ? (int) $course_id : 0;
	}

	private function is_valid_course( $course_id ) {
		$course = get_post( $course_id );

		return $course instanceof WP_Post
			&& 'courses' === $course->post_type
			&& ! in_array( $course->post_status, array( 'trash', 'auto-draft' ), true );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tutor-lms-quiz-importer' ) );
		}

		$result = null;
		if ( isset( $_POST['nfm_tutor_import_submit'] ) ) {
			$result = $this->handle_import();
		}

		$request            = $_POST;
		$courses            = $this->get_courses();
		$selected_course_id = $this->get_request_course_id( $request );
		$selected_quiz_id   = $this->get_request_quiz_id( $request );
		$quizzes            = $selected_course_id ? $this->get_course_quizzes( $selected_course_id ) : array();
		$quizzes_by_course  = $this->get_quizzes_by_course( $courses );
		$quiz_messages      = array(
			'selectCourseFirst' => esc_html__( 'Select a Tutor LMS course first.', 'tutor-lms-quiz-importer' ),
			'selectQuiz'        => esc_html__( '— Select a quiz —', 'tutor-lms-quiz-importer' ),
			'noQuizzes'         => esc_html__( 'No quizzes found for the selected course.', 'tutor-lms-quiz-importer' ),
			'quizHelp'          => esc_html__( 'Only quizzes that belong to the selected course are available.', 'tutor-lms-quiz-importer' ),
			'topicLabel'        => esc_html__( 'Topic:', 'tutor-lms-quiz-importer' ),
		);
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Tutor LMS Quiz Importer', 'tutor-lms-quiz-importer' ); ?></h1>
			<p>
				<?php echo wp_kses_post( __( 'Upload an <strong>.xlsx</strong> file with columns <code>Question</code>, <code>Option A</code>, <code>Option B</code>, <code>Option C</code>, or an equivalent CSV file. Questions are <strong>added</strong> to the selected quiz without deleting or replacing existing ones.', 'tutor-lms-quiz-importer' ) ); ?>
			</p>
			<p>
				<?php echo wp_kses_post( __( 'The correct answer is always set to <strong>Option A</strong>. If present, the <code>Correct (letter)</code> and <code>Correct (text)</code> columns are ignored.', 'tutor-lms-quiz-importer' ) ); ?>
			</p>

			<?php if ( is_array( $result ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $result['type'] ); ?> is-dismissible">
					<p><?php echo wp_kses_post( $result['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( empty( $courses ) ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html__( 'No Tutor LMS courses found.', 'tutor-lms-quiz-importer' ); ?></p></div>
			<?php else : ?>
				<form method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'nfm_tutor_import_action', 'nfm_tutor_import_nonce' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="nfm_course_id"><?php echo esc_html__( 'Tutor LMS course', 'tutor-lms-quiz-importer' ); ?></label></th>
							<td>
								<select name="nfm_course_id" id="nfm_course_id" required style="min-width: 520px; max-width: 100%;">
									<option value=""><?php echo esc_html__( '— Select a course —', 'tutor-lms-quiz-importer' ); ?></option>
									<?php foreach ( $courses as $course ) : ?>
										<option value="<?php echo esc_attr( $course->ID ); ?>" <?php selected( $selected_course_id, $course->ID ); ?>>
											<?php echo esc_html( '[' . $course->ID . '] ' . $course->post_title ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php echo esc_html__( 'Choose the Tutor LMS course before selecting a quiz.', 'tutor-lms-quiz-importer' ); ?>
								</p>
								<noscript>
									<p>
										<?php submit_button( esc_html__( 'Load quizzes for the selected course', 'tutor-lms-quiz-importer' ), 'secondary', 'nfm_load_course_quizzes', false ); ?>
									</p>
								</noscript>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nfm_quiz_id"><?php echo esc_html__( 'Destination quiz', 'tutor-lms-quiz-importer' ); ?></label></th>
							<td>
								<select name="nfm_quiz_id" id="nfm_quiz_id" required style="min-width: 520px; max-width: 100%;" data-selected-quiz="<?php echo esc_attr( $selected_quiz_id ); ?>" <?php disabled( ! $selected_course_id || empty( $quizzes ) ); ?>>
									<option value="">
										<?php
										echo esc_html(
											$selected_course_id
												? ( empty( $quizzes ) ? $quiz_messages['noQuizzes'] : $quiz_messages['selectQuiz'] )
												: $quiz_messages['selectCourseFirst']
										);
										?>
									</option>
									<?php foreach ( $quizzes as $quiz ) : ?>
										<option value="<?php echo esc_attr( $quiz->quiz_id ); ?>" <?php selected( $selected_quiz_id, $quiz->quiz_id ); ?>>
											<?php echo esc_html( '[' . $quiz->quiz_id . '] ' . $quiz->quiz_title . ' — ' . $quiz_messages['topicLabel'] . ' ' . $quiz->topic_title ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description" id="nfm_quiz_help">
									<?php
									echo esc_html(
										$selected_course_id
											? ( empty( $quizzes ) ? $quiz_messages['noQuizzes'] : $quiz_messages['quizHelp'] )
											: $quiz_messages['selectCourseFirst']
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nfm_import_file"><?php echo esc_html__( 'Excel/CSV file', 'tutor-lms-quiz-importer' ); ?></label></th>
							<td>
								<input type="file" name="nfm_import_file" id="nfm_import_file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
								<p class="description">
									<?php echo wp_kses_post( __( 'Recommended format: <code>.xlsx</code> with headers <code>ID_OLD</code>, <code>ID_NEW</code>, <code>Area</code>, <code>Level</code>, <code>Question</code>, <code>Option A</code>, <code>Option B</code>, <code>Option C</code>.', 'tutor-lms-quiz-importer' ) ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Options', 'tutor-lms-quiz-importer' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="nfm_skip_id_new" value="1" checked>
									<?php echo wp_kses_post( __( 'Automatically skip rows where <code>ID_NEW</code> has a value, when that column exists.', 'tutor-lms-quiz-importer' ) ); ?>
								</label>
								<br>
								<label>
									<input type="checkbox" name="nfm_skip_duplicates" value="1">
									<?php echo esc_html__( 'Skip questions already present in the same quiz with identical question text.', 'tutor-lms-quiz-importer' ); ?>
								</label>
							</td>
						</tr>
					</table>
					<?php submit_button( esc_html__( 'Import questions into the selected quiz', 'tutor-lms-quiz-importer' ), 'primary', 'nfm_tutor_import_submit' ); ?>
				</form>
				<script type="application/json" id="nfm-quiz-importer-config"><?php echo wp_json_encode( array( 'quizzesByCourse' => $quizzes_by_course, 'messages' => $quiz_messages ) ); ?></script>
				<script>
					document.addEventListener('DOMContentLoaded', function() {
						var courseSelect = document.getElementById('nfm_course_id');
						var quizSelect = document.getElementById('nfm_quiz_id');
						var quizHelp = document.getElementById('nfm_quiz_help');
						var configElement = document.getElementById('nfm-quiz-importer-config');
						var selectedQuiz = '';
						var config = {};
						var quizzesByCourse = {};
						var messages = {};

						if (!courseSelect || !quizSelect || !quizHelp || !configElement) {
							window.console && window.console.warn && window.console.warn('Tutor LMS Quiz Importer: course/quiz UI configuration was not found.');
							return;
						}

						selectedQuiz = quizSelect.getAttribute('data-selected-quiz') || '';

						try {
							config = JSON.parse(configElement.textContent || '{}');
						} catch (error) {
							window.console && window.console.warn && window.console.warn('Tutor LMS Quiz Importer: invalid quiz configuration payload.', error);
							return;
						}

						quizzesByCourse = config.quizzesByCourse || {};
						messages = config.messages || {};

						function buildQuizLabel(quiz) {
							return '[' + quiz.quiz_id + '] ' + quiz.quiz_title + ' — ' + messages.topicLabel + ' ' + quiz.topic_title;
						}

						function renderQuizOptions() {
							var courseId = courseSelect.value;
							var quizzes = quizzesByCourse[courseId] || [];
							var selectedQuizFound = false;
							var placeholder = document.createElement('option');

							quizSelect.innerHTML = '';
							placeholder.value = '';

							if (!courseId) {
								placeholder.textContent = messages.selectCourseFirst;
								quizSelect.disabled = true;
								quizHelp.textContent = messages.selectCourseFirst;
								quizSelect.appendChild(placeholder);
								return;
							}

							if (!quizzes.length) {
								placeholder.textContent = messages.noQuizzes;
								quizSelect.disabled = true;
								quizHelp.textContent = messages.noQuizzes;
								quizSelect.appendChild(placeholder);
								return;
							}

							placeholder.textContent = messages.selectQuiz;
							quizSelect.appendChild(placeholder);
							quizSelect.disabled = false;
							quizHelp.textContent = messages.quizHelp;

							quizzes.forEach(function(quiz) {
								var option = document.createElement('option');

								option.value = String(quiz.quiz_id);
								option.textContent = buildQuizLabel(quiz);

								if (selectedQuiz && String(quiz.quiz_id) === String(selectedQuiz)) {
									option.selected = true;
									selectedQuizFound = true;
								}

								quizSelect.appendChild(option);
							});

							if (!selectedQuizFound) {
								quizSelect.value = '';
							}
						}

						courseSelect.addEventListener('change', function() {
							selectedQuiz = '';
							renderQuizOptions();
						});

						renderQuizOptions();
					});
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	private function handle_import() {
		if ( ! isset( $_POST['nfm_tutor_import_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['nfm_tutor_import_nonce'] ), 'nfm_tutor_import_action' ) ) {
			return array( 'type' => 'error', 'message' => esc_html__( 'Invalid nonce. Please try again.', 'tutor-lms-quiz-importer' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'type' => 'error', 'message' => esc_html__( 'Insufficient permissions.', 'tutor-lms-quiz-importer' ) );
		}

		$course_id = $this->get_request_course_id( $_POST );
		$quiz_id   = $this->get_request_quiz_id( $_POST );

		if ( ! $course_id || ! $this->is_valid_course( $course_id ) ) {
			return array( 'type' => 'error', 'message' => esc_html__( 'Please select a valid Tutor LMS course.', 'tutor-lms-quiz-importer' ) );
		}

		if ( ! $quiz_id || ! $this->quiz_belongs_to_course( $quiz_id, $course_id ) ) {
			return array( 'type' => 'error', 'message' => esc_html__( 'The selected quiz does not belong to the selected course.', 'tutor-lms-quiz-importer' ) );
		}

		if ( empty( $_FILES['nfm_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['nfm_import_file']['tmp_name'] ) ) {
			return array( 'type' => 'error', 'message' => esc_html__( 'No file uploaded.', 'tutor-lms-quiz-importer' ) );
		}

		$tmp_name      = $_FILES['nfm_import_file']['tmp_name'];
		$original_name = isset( $_FILES['nfm_import_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['nfm_import_file']['name'] ) ) : '';
		$skip_id_new   = ! empty( $_POST['nfm_skip_id_new'] );
		$skip_dupes    = ! empty( $_POST['nfm_skip_duplicates'] );

		$parsed = $this->parse_import_file( $tmp_name, $original_name, $skip_id_new );
		if ( is_wp_error( $parsed ) ) {
			return array( 'type' => 'error', 'message' => esc_html( $parsed->get_error_message() ) );
		}

		if ( empty( $parsed['questions'] ) ) {
			return array(
				'type'    => 'warning',
				'message' => sprintf(
					/* translators: %d: skipped row count. */
					esc_html__( 'No valid questions found in the file. Rows skipped: %d.', 'tutor-lms-quiz-importer' ),
					intval( $parsed['skipped'] )
				),
			);
		}

		$imported = $this->insert_questions( $quiz_id, $parsed['questions'], $skip_dupes );
		if ( is_wp_error( $imported ) ) {
			return array( 'type' => 'error', 'message' => esc_html( $imported->get_error_message() ) );
		}

		return array(
			'type'    => 'success',
			'message' => sprintf(
				/* translators: 1: inserted question count, 2: skipped row count, 3: course ID, 4: quiz ID. */
				__( 'Import completed. Questions added: <strong>%1$d</strong>. Rows skipped: <strong>%2$d</strong>. Course: <strong>%3$d</strong>. Quiz: <strong>%4$d</strong>.', 'tutor-lms-quiz-importer' ),
				intval( $imported['inserted'] ),
				intval( $parsed['skipped'] + $imported['duplicates'] ),
				intval( $course_id ),
				intval( $quiz_id )
			),
		);
	}

	private function quiz_belongs_to_course( $quiz_id, $course_id ) {
		global $wpdb;
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT q.ID
                 FROM {$wpdb->posts} AS q
                 INNER JOIN {$wpdb->posts} AS t ON q.post_parent = t.ID
                 WHERE q.ID = %d
                   AND q.post_type = 'tutor_quiz'
                   AND t.post_type = 'topics'
                   AND t.post_parent = %d
                 LIMIT 1",
				$quiz_id,
				$course_id
			)
		);
		return ! empty( $found );
	}

	private function parse_import_file( $file_path, $original_name, $skip_id_new ) {
		$extension = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );

		if ( 'xlsx' === $extension ) {
			$rows = $this->read_xlsx_rows( $file_path );
		} elseif ( 'csv' === $extension ) {
			$rows = $this->read_csv_rows( $file_path );
		} else {
			return new WP_Error( 'invalid_file_type', __( 'Unsupported format. Please upload a .xlsx or .csv file.', 'tutor-lms-quiz-importer' ) );
		}

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		return $this->parse_table_rows( $rows, $skip_id_new );
	}

	private function read_csv_rows( $file_path ) {
		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return new WP_Error( 'csv_open_error', __( 'Unable to open the CSV file.', 'tutor-lms-quiz-importer' ) );
		}

		$first_line = fgets( $handle );
		if ( false === $first_line ) {
			fclose( $handle );
			return new WP_Error( 'csv_empty', __( 'The CSV file is empty.', 'tutor-lms-quiz-importer' ) );
		}

		$delimiter = $this->detect_delimiter( $first_line );
		rewind( $handle );

		$rows = array();
		while ( ( $row = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
			if ( isset( $row[0] ) ) {
				$row[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $row[0] );
			}
			$rows[] = $row;
		}
		fclose( $handle );

		return $rows;
	}

	private function detect_delimiter( $line ) {
		$delimiters = array( ',', ';', "\t" );
		$best = ',';
		$max  = 0;
		foreach ( $delimiters as $delimiter ) {
			$count = substr_count( $line, $delimiter );
			if ( $count > $max ) {
				$max  = $count;
				$best = $delimiter;
			}
		}
		return $best;
	}

	private function read_xlsx_rows( $file_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip_missing', __( 'ZipArchive is not available on the server, so .xlsx files cannot be read.', 'tutor-lms-quiz-importer' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $file_path ) ) {
			return new WP_Error( 'xlsx_open_error', __( 'Unable to open the .xlsx file.', 'tutor-lms-quiz-importer' ) );
		}

		$shared_strings = $this->read_xlsx_shared_strings( $zip );
		$sheet_path     = $this->get_first_worksheet_path( $zip );

		if ( ! $sheet_path ) {
			$zip->close();
			return new WP_Error( 'xlsx_sheet_error', __( 'No worksheet was found in the .xlsx file.', 'tutor-lms-quiz-importer' ) );
		}

		$xml = $zip->getFromName( $sheet_path );
		$zip->close();

		if ( false === $xml ) {
			return new WP_Error( 'xlsx_sheet_read_error', __( 'Unable to read the first worksheet from the .xlsx file.', 'tutor-lms-quiz-importer' ) );
		}

		$worksheet = simplexml_load_string( $xml );
		if ( ! $worksheet ) {
			return new WP_Error( 'xlsx_xml_error', __( 'The worksheet XML in the Excel file is invalid.', 'tutor-lms-quiz-importer' ) );
		}

		$worksheet->registerXPathNamespace( 'x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
		$rows = array();

		foreach ( $worksheet->xpath( '//x:sheetData/x:row' ) as $row ) {
			$row_values = array();
			foreach ( $row->c as $cell ) {
				$ref       = (string) $cell['r'];
				$col_index = $this->xlsx_column_index( $ref );
				$row_values[ $col_index ] = $this->xlsx_cell_value( $cell, $shared_strings );
			}
			if ( ! empty( $row_values ) ) {
				ksort( $row_values );
				$max_index = max( array_keys( $row_values ) );
				$filled    = array();
				for ( $i = 0; $i <= $max_index; $i++ ) {
					$filled[] = isset( $row_values[ $i ] ) ? $row_values[ $i ] : '';
				}
				$rows[] = $filled;
			}
		}

		return $rows;
	}

	private function read_xlsx_shared_strings( $zip ) {
		$xml = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false === $xml ) {
			return array();
		}

		$shared = simplexml_load_string( $xml );
		if ( ! $shared ) {
			return array();
		}

		$strings = array();
		foreach ( $shared->si as $si ) {
			$text = '';
			if ( isset( $si->t ) ) {
				$text = (string) $si->t;
			} elseif ( isset( $si->r ) ) {
				foreach ( $si->r as $run ) {
					$text .= (string) $run->t;
				}
			}
			$strings[] = $text;
		}
		return $strings;
	}

	private function get_first_worksheet_path( $zip ) {
		// Caso standard: prende il primo foglio indicato dal workbook.
		$workbook_xml = $zip->getFromName( 'xl/workbook.xml' );
		$rels_xml     = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );

		if ( false !== $workbook_xml && false !== $rels_xml ) {
			$workbook = simplexml_load_string( $workbook_xml );
			$rels     = simplexml_load_string( $rels_xml );
			if ( $workbook && $rels ) {
				$workbook->registerXPathNamespace( 'x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
				$workbook->registerXPathNamespace( 'r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' );
				$sheets = $workbook->xpath( '//x:sheets/x:sheet' );
				if ( ! empty( $sheets ) ) {
					$rid = (string) $sheets[0]->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' )['id'];
					foreach ( $rels->Relationship as $rel ) {
						if ( (string) $rel['Id'] === $rid ) {
							$target     = (string) $rel['Target'];
							$normalized = $this->normalize_xlsx_part_path( $target );
							if ( $normalized && false !== $zip->locateName( $normalized ) ) {
								return $normalized;
							}
						}
					}
				}
			}
		}

		// Fallback: cerca il primo worksheet realmente presente nello ZIP.
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( preg_match( '#^xl/worksheets/sheet[0-9]+\.xml$#', $name ) ) {
				return $name;
			}
		}

		return '';
	}

	private function normalize_xlsx_part_path( $target ) {
		$target = trim( (string) $target );
		if ( '' === $target ) {
			return '';
		}

		// Alcuni file usano Target assoluti tipo /xl/worksheets/sheet1.xml.
		if ( 0 === strpos( $target, '/xl/' ) ) {
			return ltrim( $target, '/' );
		}

		// Caso standard delle relazioni workbook: worksheets/sheet1.xml.
		if ( 0 !== strpos( $target, 'xl/' ) ) {
			$target = 'xl/' . ltrim( $target, '/' );
		}

		// Normalizza eventuali segmenti ../ o ./.
		$parts = array();
		foreach ( explode( '/', $target ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				array_pop( $parts );
				continue;
			}
			$parts[] = $part;
		}
		return implode( '/', $parts );
	}

	private function xlsx_column_index( $cell_ref ) {
		preg_match( '/^[A-Z]+/i', $cell_ref, $matches );
		$letters = isset( $matches[0] ) ? strtoupper( $matches[0] ) : 'A';
		$index   = 0;
		for ( $i = 0; $i < strlen( $letters ); $i++ ) {
			$index = $index * 26 + ( ord( $letters[ $i ] ) - 64 );
		}
		return $index - 1;
	}

	private function xlsx_cell_value( $cell, $shared_strings ) {
		$type  = (string) $cell['t'];
		$value = isset( $cell->v ) ? (string) $cell->v : '';

		if ( 's' === $type ) {
			$idx = is_numeric( $value ) ? (int) $value : -1;
			return isset( $shared_strings[ $idx ] ) ? $shared_strings[ $idx ] : '';
		}

		if ( 'inlineStr' === $type && isset( $cell->is ) ) {
			$text = '';
			if ( isset( $cell->is->t ) ) {
				$text = (string) $cell->is->t;
			} elseif ( isset( $cell->is->r ) ) {
				foreach ( $cell->is->r as $run ) {
					$text .= (string) $run->t;
				}
			}
			return $text;
		}

		if ( 'b' === $type ) {
			return '1' === $value ? 'TRUE' : 'FALSE';
		}

		return $value;
	}

	private function parse_table_rows( $rows, $skip_id_new ) {
		if ( empty( $rows ) ) {
			return array( 'questions' => array(), 'skipped' => 0 );
		}

		$header = array_shift( $rows );
		$map    = array();
		foreach ( $header as $index => $name ) {
			$key = $this->normalize_header( $name );
			if ( '' !== $key ) {
				$map[ $key ] = $index;
			}
		}

		$question_idx = $this->first_existing_index( $map, array( 'domanda', 'question', 'questiontitle', 'question_title', 'titolo', 'testodomanda', 'testo_domanda' ) );
		$a_idx        = $this->first_existing_index( $map, array( 'opzione_a', 'opzionea', 'risposta_a', 'rispostaa', 'answer_1', 'answer1', 'answer_a', 'answera' ) );
		$b_idx        = $this->first_existing_index( $map, array( 'opzione_b', 'opzioneb', 'risposta_b', 'rispostab', 'answer_2', 'answer2', 'answer_b', 'answerb' ) );
		$c_idx        = $this->first_existing_index( $map, array( 'opzione_c', 'opzionec', 'risposta_c', 'rispostac', 'answer_3', 'answer3', 'answer_c', 'answerc' ) );
		$id_new_idx   = $this->first_existing_index( $map, array( 'id_new', 'idnew' ) );

		if ( null === $question_idx || null === $a_idx || null === $b_idx ) {
			return new WP_Error( 'columns_error', __( 'Unrecognized columns. At least Question, Option A, and Option B are required.', 'tutor-lms-quiz-importer' ) );
		}

		$questions = array();
		$skipped   = 0;

		foreach ( $rows as $row ) {
			$id_new = null !== $id_new_idx ? $this->clean_plain_text( $row[ $id_new_idx ] ?? '' ) : '';
			if ( $skip_id_new && '' !== $id_new ) {
				$skipped++;
				continue;
			}

			$title = $this->clean_text( $row[ $question_idx ] ?? '' );
			$a     = $this->clean_text( $row[ $a_idx ] ?? '' );
			$b     = $this->clean_text( $row[ $b_idx ] ?? '' );
			$c     = null !== $c_idx ? $this->clean_text( $row[ $c_idx ] ?? '' ) : '';

			if ( '' === $title || '' === $a || '' === $b ) {
				$skipped++;
				continue;
			}

			$answers = array(
				array( 'title' => $a, 'view_format' => 'text' ),
				array( 'title' => $b, 'view_format' => 'text' ),
			);
			if ( '' !== $c ) {
				$answers[] = array( 'title' => $c, 'view_format' => 'text' );
			}

			$questions[] = array(
				'title'       => $title,
				'description' => '',
				'type'        => 'single_choice',
				'mark'        => '1.00',
				'answers'     => $answers,
			);
		}

		return array( 'questions' => $questions, 'skipped' => $skipped );
	}

	private function normalize_header( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = str_replace( array( 'à', 'è', 'é', 'ì', 'ò', 'ù' ), array( 'a', 'e', 'e', 'i', 'o', 'u' ), $value );
		$value = preg_replace( '/[^a-z0-9]+/', '_', $value );
		$value = preg_replace( '/_+/', '_', $value );
		return trim( $value, '_' );
	}

	private function first_existing_index( $map, $keys ) {
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $map ) ) {
				return $map[ $key ];
			}
		}
		return null;
	}

	private function clean_text( $value ) {
		$value = (string) $value;
		$value = trim( $value );
		$value = stripslashes( $value );
		return wp_kses_post( $value );
	}

	private function clean_plain_text( $value ) {
		return trim( wp_strip_all_tags( (string) $value ) );
	}

	private function get_next_question_order( $quiz_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'tutor_quiz_questions';
		$max   = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(question_order) FROM {$table} WHERE quiz_id = %d", $quiz_id ) );
		return $max ? (int) $max : 0;
	}

	private function question_already_exists( $quiz_id, $question_title ) {
		global $wpdb;
		$table = $wpdb->prefix . 'tutor_quiz_questions';
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT question_id FROM {$table} WHERE quiz_id = %d AND question_title = %s LIMIT 1",
				$quiz_id,
				$question_title
			)
		);
		return ! empty( $found );
	}

	private function insert_questions( $quiz_id, $questions, $skip_duplicates ) {
		global $wpdb;

		$questions_table = $wpdb->prefix . 'tutor_quiz_questions';
		$answers_table   = $wpdb->prefix . 'tutor_quiz_question_answers';
		$question_order  = $this->get_next_question_order( $quiz_id );
		$inserted        = 0;
		$duplicates      = 0;

		$wpdb->query( 'START TRANSACTION' );

		foreach ( $questions as $question ) {
			if ( $skip_duplicates && $this->question_already_exists( $quiz_id, $question['title'] ) ) {
				$duplicates++;
				continue;
			}

			$question_order++;
			$question_type = 'multiple_choice';
			$mark          = '1';

			// Impostazioni allineate alle domande create manualmente da Tutor LMS.
			$question_settings = array(
				'answer_required'             => '0',
				'question_mark'               => '1',
				'question_type'               => 'multiple_choice',
				'randomize_question'          => '1',
				'show_question_mark'          => '0',
				'has_multiple_correct_answer' => '0',
			);

			$ok = $wpdb->insert(
				$questions_table,
				array(
					'quiz_id'              => $quiz_id,
					'question_title'       => $question['title'],
					'question_description' => $question['description'],
					'answer_explanation'   => '',
					'question_type'        => $question_type,
					'question_mark'        => $mark,
					'question_settings'    => maybe_serialize( $question_settings ),
					'question_order'       => $question_order,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%d' )
			);

			if ( false === $ok ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error(
					'question_insert_error',
					sprintf(
						/* translators: %s: database error message. */
						__( 'Question insert error. Import aborted: %s', 'tutor-lms-quiz-importer' ),
						$wpdb->last_error
					)
				);
			}

			$question_id  = (int) $wpdb->insert_id;
			$answer_order = 0;

			foreach ( $question['answers'] as $answer ) {
				$answer_order++;
				$ok_answer = $wpdb->insert(
					$answers_table,
					array(
						'belongs_question_id'   => $question_id,
						'belongs_question_type' => $question_type,
						'answer_title'          => $answer['title'],
						'is_correct'            => ( 1 === $answer_order ) ? 1 : 0,
						'image_id'              => 0,
						'answer_two_gap_match'  => '',
						'answer_view_format'    => 'text',
						'answer_settings'       => '',
						'answer_order'          => $answer_order,
					),
					array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d' )
				);

				if ( false === $ok_answer ) {
					$wpdb->query( 'ROLLBACK' );
					return new WP_Error(
						'answer_insert_error',
						sprintf(
							/* translators: %s: database error message. */
							__( 'Answer insert error. Import aborted: %s', 'tutor-lms-quiz-importer' ),
							$wpdb->last_error
						)
					);
				}
			}

			$inserted++;
		}

		$wpdb->query( 'COMMIT' );

		return array(
			'inserted'   => $inserted,
			'duplicates' => $duplicates,
		);
	}
}
