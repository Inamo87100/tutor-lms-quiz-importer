<?php
/**
 * Plugin Name: NFM Tutor Quiz Importer
 * Description: Importa domande da Excel/CSV in un quiz Tutor LMS esistente, scegliendo solo tra i quiz del corso ID 15. Le domande vengono aggiunte, non sostituite.
 * Version: 1.5.0
 * Author: Nuova Formamentis
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NFM_Tutor_Quiz_Importer {
    const COURSE_ID = 15;
    const MENU_SLUG = 'nfm-tutor-quiz-importer';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
    }

    public function add_admin_menu() {
        add_management_page(
            'NFM Import Quiz Tutor',
            'NFM Import Quiz Tutor',
            'manage_options',
            self::MENU_SLUG,
            array( $this, 'render_page' )
        );
    }

    private function get_course_quizzes() {
        global $wpdb;

        // Tutor LMS: course -> topics -> tutor_quiz.
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT 
                    q.ID AS quiz_id,
                    q.post_title AS quiz_title,
                    t.ID AS topic_id,
                    t.post_title AS topic_title
                 FROM {$wpdb->posts} AS t
                 INNER JOIN {$wpdb->posts} AS q
                    ON q.post_parent = t.ID
                 WHERE t.post_type = 'topics'
                   AND t.post_parent = %d
                   AND q.post_type = 'tutor_quiz'
                   AND q.post_status NOT IN ('trash', 'auto-draft')
                 ORDER BY t.menu_order ASC, q.menu_order ASC, q.ID ASC",
                self::COURSE_ID
            )
        );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permessi insufficienti.', 'nfm' ) );
        }

        $result = null;
        if ( isset( $_POST['nfm_tutor_import_submit'] ) ) {
            $result = $this->handle_import();
        }

        $quizzes = $this->get_course_quizzes();
        ?>
        <div class="wrap">
            <h1>NFM Import Quiz Tutor LMS</h1>
            <p><strong>Corso filtrato:</strong> ID <?php echo esc_html( self::COURSE_ID ); ?></p>
            <p>
                Carica un file <strong>.xlsx</strong> con colonne <code>Domanda</code>, <code>Opzione A</code>, <code>Opzione B</code>, <code>Opzione C</code>,
                oppure un CSV equivalente. Le domande vengono <strong>aggiunte</strong> al quiz selezionato, senza cancellare o sostituire quelle già presenti.
            </p>
            <p>
                La risposta corretta verrà impostata sempre come <strong>Opzione A</strong>. Le colonne <code>Corretta (lettera)</code> e <code>Corretta (testo)</code>, se presenti, non vengono usate.
            </p>

            <?php if ( is_array( $result ) ) : ?>
                <div class="notice notice-<?php echo esc_attr( $result['type'] ); ?> is-dismissible">
                    <p><?php echo wp_kses_post( $result['message'] ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( empty( $quizzes ) ) : ?>
                <div class="notice notice-warning"><p>Nessun quiz trovato per il corso ID <?php echo esc_html( self::COURSE_ID ); ?>.</p></div>
            <?php else : ?>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'nfm_tutor_import_action', 'nfm_tutor_import_nonce' ); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="nfm_quiz_id">Quiz di destinazione</label></th>
                            <td>
                                <select name="nfm_quiz_id" id="nfm_quiz_id" required style="min-width: 520px; max-width: 100%;">
                                    <option value="">— Seleziona quiz —</option>
                                    <?php foreach ( $quizzes as $quiz ) : ?>
                                        <option value="<?php echo esc_attr( $quiz->quiz_id ); ?>">
                                            <?php echo esc_html( '[' . $quiz->quiz_id . '] ' . $quiz->quiz_title . ' — Topic: ' . $quiz->topic_title ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="nfm_import_file">File Excel/CSV</label></th>
                            <td>
                                <input type="file" name="nfm_import_file" id="nfm_import_file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
                                <p class="description">
                                    Formato consigliato: <code>.xlsx</code> con intestazioni: <code>ID_OLD</code>, <code>ID_NEW</code>, <code>Area</code>, <code>Livello</code>, <code>Domanda</code>, <code>Opzione A</code>, <code>Opzione B</code>, <code>Opzione C</code>.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Controlli</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="nfm_skip_id_new" value="1" checked>
                                    Salta automaticamente le righe con <code>ID_NEW</code> valorizzato, se la colonna esiste.
                                </label>
                                <br>
                                <label>
                                    <input type="checkbox" name="nfm_skip_duplicates" value="1">
                                    Salta domande già presenti nello stesso quiz con identico testo domanda.
                                </label>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( 'Importa domande nel quiz selezionato', 'primary', 'nfm_tutor_import_submit' ); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private function handle_import() {
        if ( ! isset( $_POST['nfm_tutor_import_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nfm_tutor_import_nonce'] ) ), 'nfm_tutor_import_action' ) ) {
            return array( 'type' => 'error', 'message' => 'Nonce non valido. Riprova.' );
        }

        $quiz_id = isset( $_POST['nfm_quiz_id'] ) ? absint( $_POST['nfm_quiz_id'] ) : 0;
        if ( ! $quiz_id || ! $this->quiz_belongs_to_course( $quiz_id, self::COURSE_ID ) ) {
            return array( 'type' => 'error', 'message' => 'Quiz non valido oppure non appartenente al corso ID ' . esc_html( self::COURSE_ID ) . '.' );
        }

        if ( empty( $_FILES['nfm_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['nfm_import_file']['tmp_name'] ) ) {
            return array( 'type' => 'error', 'message' => 'Nessun file caricato.' );
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
            return array( 'type' => 'warning', 'message' => 'Nessuna domanda valida trovata nel file. Righe saltate: ' . intval( $parsed['skipped'] ) . '.' );
        }

        $imported = $this->insert_questions( $quiz_id, $parsed['questions'], $skip_dupes );
        if ( is_wp_error( $imported ) ) {
            return array( 'type' => 'error', 'message' => esc_html( $imported->get_error_message() ) );
        }

        return array(
            'type'    => 'success',
            'message' => 'Import completato. Domande aggiunte: <strong>' . intval( $imported['inserted'] ) . '</strong>. ' .
                         'Righe saltate: <strong>' . intval( $parsed['skipped'] + $imported['duplicates'] ) . '</strong>. ' .
                         'Quiz destinazione: <strong>' . intval( $quiz_id ) . '</strong>.'
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
            return new WP_Error( 'invalid_file_type', 'Formato non supportato. Carica un file .xlsx oppure .csv.' );
        }

        if ( is_wp_error( $rows ) ) {
            return $rows;
        }

        return $this->parse_table_rows( $rows, $skip_id_new );
    }

    private function read_csv_rows( $file_path ) {
        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            return new WP_Error( 'csv_open_error', 'Impossibile aprire il CSV.' );
        }

        $first_line = fgets( $handle );
        if ( false === $first_line ) {
            fclose( $handle );
            return new WP_Error( 'csv_empty', 'CSV vuoto.' );
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
            return new WP_Error( 'zip_missing', 'ZipArchive non disponibile sul server: impossibile leggere file .xlsx.' );
        }

        $zip = new ZipArchive();
        if ( true !== $zip->open( $file_path ) ) {
            return new WP_Error( 'xlsx_open_error', 'Impossibile aprire il file .xlsx.' );
        }

        $shared_strings = $this->read_xlsx_shared_strings( $zip );
        $sheet_path     = $this->get_first_worksheet_path( $zip );

        if ( ! $sheet_path ) {
            $zip->close();
            return new WP_Error( 'xlsx_sheet_error', 'Nessun foglio di lavoro trovato nel file .xlsx.' );
        }

        $xml = $zip->getFromName( $sheet_path );
        $zip->close();

        if ( false === $xml ) {
            return new WP_Error( 'xlsx_sheet_read_error', 'Impossibile leggere il primo foglio del file .xlsx.' );
        }

        $worksheet = simplexml_load_string( $xml );
        if ( ! $worksheet ) {
            return new WP_Error( 'xlsx_xml_error', 'XML del foglio Excel non valido.' );
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
                            $target = (string) $rel['Target'];
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
            return new WP_Error( 'columns_error', 'Colonne non riconosciute. Servono almeno Domanda, Opzione A e Opzione B.' );
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
        $max = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(question_order) FROM {$table} WHERE quiz_id = %d", $quiz_id ) );
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
                return new WP_Error( 'question_insert_error', 'Errore inserimento domanda. Import annullato: ' . $wpdb->last_error );
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
                    return new WP_Error( 'answer_insert_error', 'Errore inserimento risposta. Import annullato: ' . $wpdb->last_error );
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

new NFM_Tutor_Quiz_Importer();
