<?php
/**
 * Plugin Name: Kenil Mangukiya
 * Description: LLM Text Generator – WordPress Integration
 * Version: 1.0.0
 * Author: Kenil
 */

if (!defined('ABSPATH')) exit;

/* -------------------------
   Database Table Creation
--------------------------*/
register_activation_hook(__FILE__, 'kmwp_create_history_table');

function kmwp_create_history_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'kmwp_file_history';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        website_url varchar(500) NOT NULL,
        output_type varchar(50) NOT NULL,
        summarized_content longtext,
        full_content longtext,
        file_path varchar(500),
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY website_url (website_url(191)),
        KEY created_at (created_at)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Create table on plugin load if it doesn't exist
add_action('plugins_loaded', function() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'kmwp_file_history';
    
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        kmwp_create_history_table();
    }
});

/* -------------------------
   Save File History
--------------------------*/
function kmwp_save_file_history($website_url, $output_type, $summarized_content = '', $full_content = '', $file_path = '') {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'kmwp_file_history';
    $user_id = get_current_user_id();
    
    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        error_log('KMWP: History table does not exist');
        return false;
    }
    
    // Calculate content hash for exact duplicate detection
    $content_hash = md5($summarized_content . $full_content);
    $content_length = strlen($summarized_content . $full_content);
    $content_preview = substr($summarized_content . $full_content, 0, 200); // First 200 chars for comparison
    
    error_log('KMWP: Checking for duplicates. URL: ' . $website_url . ', Type: ' . $output_type . ', Hash: ' . substr($content_hash, 0, 8) . '..., Length: ' . $content_length);
    
    // Check 1: Exact content match using PHP MD5 hash (most reliable)
    // Compare the hash we calculated in PHP with stored content
    $exact_duplicate = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id FROM $table_name 
            WHERE user_id = %d 
            AND website_url = %s 
            AND output_type = %s 
            AND MD5(CONCAT(COALESCE(summarized_content, ''), COALESCE(full_content, ''))) = %s
            ORDER BY created_at DESC 
            LIMIT 1",
            $user_id,
            sanitize_text_field($website_url),
            sanitize_text_field($output_type),
            $content_hash
        ),
        ARRAY_A
    );
    
    if ($exact_duplicate) {
        error_log('KMWP: Duplicate history entry prevented (exact content match). Existing ID: ' . $exact_duplicate['id']);
        return $exact_duplicate['id'];
    }
    
    // Check 1b: Also check by comparing content preview and length (more reliable than MD5 in some cases)
    $preview_duplicate = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id FROM $table_name 
            WHERE user_id = %d 
            AND website_url = %s 
            AND output_type = %s 
            AND LENGTH(CONCAT(COALESCE(summarized_content, ''), COALESCE(full_content, ''))) = %d
            AND LEFT(CONCAT(COALESCE(summarized_content, ''), COALESCE(full_content, '')), 200) = %s
            ORDER BY created_at DESC 
            LIMIT 1",
            $user_id,
            sanitize_text_field($website_url),
            sanitize_text_field($output_type),
            $content_length,
            $content_preview
        ),
        ARRAY_A
    );
    
    if ($preview_duplicate) {
        error_log('KMWP: Duplicate history entry prevented (content preview and length match). Existing ID: ' . $preview_duplicate['id']);
        return $preview_duplicate['id'];
    }
    
    // Check 2: Same URL + output_type + content length within last 10 seconds (catch rapid duplicates)
    // Extended time window to catch user double-clicks or rapid saves
    $recent_duplicate = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id FROM $table_name 
            WHERE user_id = %d 
            AND website_url = %s 
            AND output_type = %s 
            AND LENGTH(CONCAT(COALESCE(summarized_content, ''), COALESCE(full_content, ''))) = %d
            AND created_at > DATE_SUB(NOW(), INTERVAL 10 SECOND)
            ORDER BY created_at DESC 
            LIMIT 1",
            $user_id,
            sanitize_text_field($website_url),
            sanitize_text_field($output_type),
            $content_length
        ),
        ARRAY_A
    );
    
    if ($recent_duplicate) {
        error_log('KMWP: Duplicate history entry prevented (same URL, type, and length within 10 seconds). Existing ID: ' . $recent_duplicate['id']);
        return $recent_duplicate['id'];
    }
    
    // Check 3: Same URL + output_type within last 5 seconds (catch any rapid saves regardless of content)
    $rapid_duplicate = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id FROM $table_name 
            WHERE user_id = %d 
            AND website_url = %s 
            AND output_type = %s 
            AND created_at > DATE_SUB(NOW(), INTERVAL 5 SECOND)
            ORDER BY created_at DESC 
            LIMIT 1",
            $user_id,
            sanitize_text_field($website_url),
            sanitize_text_field($output_type)
        ),
        ARRAY_A
    );
    
    if ($rapid_duplicate) {
        error_log('KMWP: Duplicate history entry prevented (same URL and type within 5 seconds - rapid save). Existing ID: ' . $rapid_duplicate['id']);
        return $rapid_duplicate['id'];
    }
    
    // Prepare data
    $data = [
        'user_id' => $user_id,
        'website_url' => sanitize_text_field($website_url),
        'output_type' => sanitize_text_field($output_type),
        'summarized_content' => $summarized_content, // Don't use wp_kses_post as it might strip content
        'full_content' => $full_content,
        'file_path' => sanitize_text_field($file_path),
    ];
    
    $formats = ['%d', '%s', '%s', '%s', '%s', '%s'];
    
    // Final check right before insert to catch any race conditions
    // Check for same URL + output_type + content hash within last 3 seconds
    $last_check = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM $table_name 
            WHERE user_id = %d 
            AND website_url = %s 
            AND output_type = %s 
            AND MD5(CONCAT(COALESCE(summarized_content, ''), COALESCE(full_content, ''))) = %s
            AND created_at > DATE_SUB(NOW(), INTERVAL 3 SECOND)
            ORDER BY created_at DESC 
            LIMIT 1",
            $user_id,
            sanitize_text_field($website_url),
            sanitize_text_field($output_type),
            $content_hash
        )
    );
    
    if ($last_check) {
        error_log('KMWP: Duplicate prevented at final check (race condition). Existing ID: ' . $last_check);
        return intval($last_check);
    }
    
    $result = $wpdb->insert(
        $table_name,
        $data,
        $formats
    );
    
    if ($result === false) {
        error_log('KMWP: Failed to save history - ' . $wpdb->last_error);
        error_log('KMWP: Data being inserted: ' . print_r($data, true));
        return false;
    }
    
    error_log('KMWP: History saved successfully. ID: ' . $wpdb->insert_id);
    return $wpdb->insert_id;
}

/* -------------------------
   Update History with Backup Filenames
--------------------------*/
function kmwp_update_history_with_backup($original_file_path, $backup_file_path) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'kmwp_file_history';
    $user_id = get_current_user_id();
    
    $original_filename = basename($original_file_path);
    $backup_filename = basename($backup_file_path);
    
    // Find history entries that reference this file
    // Allow entries that already have backup for other files (for "Both" type)
    // Only exclude if THIS specific backup filename already exists in the path
    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, file_path, created_at FROM $table_name 
            WHERE user_id = %d 
            AND (file_path LIKE %s OR file_path LIKE %s)
            AND created_at < DATE_SUB(NOW(), INTERVAL 3 SECOND)
            AND file_path NOT LIKE %s
            ORDER BY created_at DESC
            LIMIT 1",
            $user_id,
            '%' . $wpdb->esc_like($original_filename) . '%',
            '%' . $wpdb->esc_like($original_file_path) . '%',
            '%' . $wpdb->esc_like($backup_filename) . '%'  // Don't update if this specific backup already exists
        ),
        ARRAY_A
    );
    
    if (!empty($results)) {
        $item = $results[0];
        $old_file_path = $item['file_path'];
        
        // Handle comma-separated paths (for "Both" option)
        $paths = explode(', ', $old_file_path);
        $new_paths = [];
        $updated = false;
        
        foreach ($paths as $path) {
            $path = trim($path);
            // Check if this path matches the original file (not already a backup)
            if (($path === $original_file_path || basename($path) === $original_filename) && strpos($path, '.backup.') === false) {
                // Replace with backup path
                $new_paths[] = $backup_file_path;
                $updated = true;
            } else {
                // Keep original path
                $new_paths[] = $path;
            }
        }
        
        if ($updated) {
            $new_file_path = implode(', ', $new_paths);
            
            // Update the history entry
            $result = $wpdb->update(
                $table_name,
                ['file_path' => $new_file_path],
                ['id' => $item['id']],
                ['%s'],
                ['%d']
            );
            
            if ($result !== false) {
                error_log('KMWP: Updated history entry ' . $item['id'] . ' with backup filename: ' . basename($backup_filename) . '. New path: ' . $new_file_path);
                return true;
            } else {
                error_log('KMWP: Failed to update history entry ' . $item['id'] . '. Error: ' . $wpdb->last_error);
            }
        }
    }
    
    return false;
}

/* -------------------------
   Admin Menu
--------------------------*/
add_action('admin_menu', function () {
    add_menu_page(
        'Kenil Mangukiya',
        'Kenil Mangukiya',
        'manage_options',
        'kmwp-dashboard',
        'kmwp_render_ui',
        'dashicons-text-page',
        20
    );
});

/* -------------------------
   Assets
--------------------------*/
add_action('admin_enqueue_scripts', function ($hook) {

    if ($hook !== 'toplevel_page_kmwp-dashboard') return;

    wp_enqueue_style(
        'kmwp-style',
        plugin_dir_url(__FILE__) . 'assets/css/style.css',
        [],
        time()
    );

    wp_enqueue_script(
        'kmwp-script',
        plugin_dir_url(__FILE__) . 'assets/js/script.js',
        ['jquery'],
        time(),
        true
    );

    wp_localize_script('kmwp-script', 'kmwp_ajax', [
        'ajax_url' => admin_url('admin-ajax.php')
    ]);
});

/* -------------------------
   Render UI
--------------------------*/
function kmwp_render_ui() {
    echo '<div class="wrap">';
    include plugin_dir_path(__FILE__) . 'admin/ui.php';
    echo '</div>';
}

/* -------------------------
   PYTHON PROXIES
--------------------------*/
function kmwp_proxy($endpoint, $method = 'POST', $body = null) {

    $args = [
        'timeout' => 120,
        'headers' => ['Content-Type' => 'application/json']
    ];

    if ($body) $args['body'] = json_encode($body);

    $url = "http://143.110.189.97:8010/$endpoint";

    return wp_remote_request($url, array_merge($args, ['method' => $method]));
}

/* prepare_generation */
add_action('wp_ajax_kmwp_prepare_generation', function () {

    $body = json_decode(file_get_contents('php://input'), true);
    $res = kmwp_proxy('prepare_generation', 'POST', $body);

    if (is_wp_error($res)) wp_send_json_error($res->get_error_message(), 500);
    wp_send_json(json_decode(wp_remote_retrieve_body($res), true));
});

/* process_batch */
add_action('wp_ajax_kmwp_process_batch', function () {

    $body = json_decode(file_get_contents('php://input'), true);
    $res = kmwp_proxy('process_batch', 'POST', $body);

    if (is_wp_error($res)) wp_send_json_error($res->get_error_message(), 500);
    wp_send_json(json_decode(wp_remote_retrieve_body($res), true));
});

/* finalize */
add_action('wp_ajax_kmwp_finalize', function () {

    $job_id = sanitize_text_field($_GET['job_id'] ?? '');
    $res = kmwp_proxy("finalize/$job_id", 'GET');

    if (is_wp_error($res)) wp_send_json_error($res->get_error_message(), 500);
    wp_send_json(json_decode(wp_remote_retrieve_body($res), true));
});

/* check_files_exist */
add_action('wp_ajax_kmwp_check_files_exist', function () {
    
    // Check user permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions', 403);
        return;
    }
    
    $body = json_decode(file_get_contents('php://input'), true);
    $output_type = sanitize_text_field($body['output_type'] ?? 'llms_txt');
    
    $existing_files = [];
    
    if ($output_type === 'llms_both') {
        if (file_exists(ABSPATH . 'llm.txt')) {
            $existing_files[] = 'llm.txt';
        }
        if (file_exists(ABSPATH . 'llm-full.txt')) {
            $existing_files[] = 'llm-full.txt';
        }
    } elseif ($output_type === 'llms_txt') {
        if (file_exists(ABSPATH . 'llm.txt')) {
            $existing_files[] = 'llm.txt';
        }
    } elseif ($output_type === 'llms_full_txt') {
        if (file_exists(ABSPATH . 'llm-full.txt')) {
            $existing_files[] = 'llm-full.txt';
        }
    }
    
    wp_send_json_success([
        'files_exist' => !empty($existing_files),
        'existing_files' => $existing_files
    ]);
});

/* save_to_root */
add_action('wp_ajax_kmwp_save_to_root', function () {
    
    // Check user permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions', 403);
        return;
    }
    
    // GLOBAL SAVE LOCK: Prevent multiple simultaneous save operations
    $global_lock_file = ABSPATH . '.kmwp_save_lock';
    $global_lock_handle = null;
    $max_wait = 10; // Wait up to 10 seconds for lock
    $wait_time = 0;
    
    while ($wait_time < $max_wait) {
        $global_lock_handle = @fopen($global_lock_file, 'x');
        if ($global_lock_handle !== false) {
            break;
        }
        
        // Check if lock is stale (older than 30 seconds)
        if (file_exists($global_lock_file)) {
            $lock_age = time() - filemtime($global_lock_file);
            if ($lock_age > 30) {
                @unlink($global_lock_file);
                continue;
            }
        }
        
        usleep(100000); // Wait 0.1 second
        $wait_time += 0.1;
    }
    
    if ($global_lock_handle === false) {
        wp_send_json_error('Another save operation is in progress. Please wait and try again.', 429);
        return;
    }
    
    // Write process ID to lock file
    fwrite($global_lock_handle, getmypid());
    fflush($global_lock_handle);
    
    // Ensure lock is released even if there's an error
    register_shutdown_function(function() use ($global_lock_file, $global_lock_handle) {
        if ($global_lock_handle !== false) {
            @fclose($global_lock_handle);
        }
        if (file_exists($global_lock_file)) {
            @unlink($global_lock_file);
        }
    });
    
    try {
        error_log('KMWP: ========================================');
        error_log('KMWP: [SAVE_TO_ROOT] Request started');
        error_log('KMWP: [SAVE_TO_ROOT] Process ID: ' . getmypid());
        error_log('KMWP: [SAVE_TO_ROOT] Timestamp: ' . date('Y-m-d H:i:s'));
        
        $body = json_decode(file_get_contents('php://input'), true);
        $output_type = sanitize_text_field($body['output_type'] ?? 'llms_txt');
        $confirm_overwrite = isset($body['confirm_overwrite']) ? (bool)$body['confirm_overwrite'] : false;
        $website_url = sanitize_text_field($body['website_url'] ?? '');
        
        // Debug: Log the received data
        error_log('KMWP: [SAVE_TO_ROOT] website_url: ' . $website_url);
        error_log('KMWP: [SAVE_TO_ROOT] output_type: ' . $output_type);
        error_log('KMWP: [SAVE_TO_ROOT] confirm_overwrite: ' . ($confirm_overwrite ? 'YES' : 'NO'));
        
        // CRITICAL: Check file existence BEFORE any file operations
        // This ensures we only backup files that existed before this request, not files created during this request
        $file_existed_before = [];
        $file_existed_before['llm.txt'] = file_exists(ABSPATH . 'llm.txt');
        $file_existed_before['llm-full.txt'] = file_exists(ABSPATH . 'llm-full.txt');
        
        error_log('KMWP: [SAVE_TO_ROOT] File existence BEFORE request:');
        error_log('KMWP: [SAVE_TO_ROOT] - llm.txt: ' . ($file_existed_before['llm.txt'] ? 'EXISTS' : 'NOT EXISTS'));
        error_log('KMWP: [SAVE_TO_ROOT] - llm-full.txt: ' . ($file_existed_before['llm-full.txt'] ? 'EXISTS' : 'NOT EXISTS'));
    
    // Initialize request-level backup registry
    if (!isset($GLOBALS['kmwp_backed_up_files'])) {
        $GLOBALS['kmwp_backed_up_files'] = [];
        error_log('KMWP: [REGISTRY] Initialized backup registry for this request. Process ID: ' . getmypid());
    } else {
        error_log('KMWP: [REGISTRY] Registry already exists. Current entries: ' . count($GLOBALS['kmwp_backed_up_files']));
        foreach ($GLOBALS['kmwp_backed_up_files'] as $key => $value) {
            error_log('KMWP: [REGISTRY] Entry: ' . basename($key) . ' -> ' . basename($value));
        }
    }
    
    /**
     * Request-level wrapper: Ensures each file is backed up at most once per request
     * 
     * @param string $file_path Path to the file to backup
     * @return string|null Backup file path, or null if backup failed or file doesn't exist
     */
    function kmwp_create_backup_once($file_path) {
        error_log('KMWP: [BACKUP_ONCE] Called for file: ' . basename($file_path) . ' (full path: ' . $file_path . ')');
        error_log('KMWP: [BACKUP_ONCE] Process ID: ' . getmypid() . ', Memory usage: ' . memory_get_usage(true));
        
        // Normalize path for registry key
        $normalized_path = realpath($file_path);
        if ($normalized_path === false) {
            $normalized_path = $file_path;
            error_log('KMWP: [BACKUP_ONCE] realpath() failed, using original path: ' . $file_path);
        } else {
            error_log('KMWP: [BACKUP_ONCE] Normalized path: ' . $normalized_path);
        }
        
        // Check if this file has already been backed up in this request
        if (isset($GLOBALS['kmwp_backed_up_files'][$normalized_path])) {
            error_log('KMWP: [BACKUP_ONCE] ✓ File already backed up in this request!');
            error_log('KMWP: [BACKUP_ONCE] Original: ' . basename($file_path) . ' -> Existing backup: ' . basename($GLOBALS['kmwp_backed_up_files'][$normalized_path]));
            error_log('KMWP: [BACKUP_ONCE] Registry size: ' . count($GLOBALS['kmwp_backed_up_files']));
            return $GLOBALS['kmwp_backed_up_files'][$normalized_path];
        }
        
        // ADDITIONAL CHECK: Check filesystem for very recent backups (last 5 seconds)
        // This prevents duplicates even if registry is reset between AJAX calls
        error_log('KMWP: [BACKUP_ONCE] Checking filesystem for recent backups...');
        $recent_backups = glob($file_path . '.backup.*');
        if (!empty($recent_backups)) {
            $recent_backups = array_filter($recent_backups, function($path) {
                return strpos($path, '.backup.lock') === false && file_exists($path);
            });
            
            foreach ($recent_backups as $recent_backup) {
                $backup_age = time() - filemtime($recent_backup);
                if ($backup_age <= 5) { // Backup created in last 5 seconds
                    error_log('KMWP: [BACKUP_ONCE] ✓ Very recent backup found on filesystem (age: ' . $backup_age . 's): ' . basename($recent_backup));
                    // Register it in the current request's registry to prevent future duplicates
                    $GLOBALS['kmwp_backed_up_files'][$normalized_path] = $recent_backup;
                    return $recent_backup;
                }
            }
        }
        
        error_log('KMWP: [BACKUP_ONCE] ✗ File NOT in registry and no recent backups found, proceeding to create backup...');
        error_log('KMWP: [BACKUP_ONCE] Registry before creation: ' . count($GLOBALS['kmwp_backed_up_files']) . ' entries');
        
        // Create backup using internal function
        $backup_path = kmwp_create_backup_internal($file_path);
        
        // Register the backup in the request-level registry
        if ($backup_path !== null) {
            $GLOBALS['kmwp_backed_up_files'][$normalized_path] = $backup_path;
            error_log('KMWP: [BACKUP_ONCE] ✓ Backup registered in registry');
            error_log('KMWP: [BACKUP_ONCE] Registry key: ' . basename($normalized_path) . ' -> Value: ' . basename($backup_path));
            error_log('KMWP: [BACKUP_ONCE] Registry size after registration: ' . count($GLOBALS['kmwp_backed_up_files']));
        } else {
            error_log('KMWP: [BACKUP_ONCE] ✗ Backup creation failed, not registering');
        }
        
        return $backup_path;
    }
    
    /**
     * Internal backup creation function: Creates exactly ONE backup file
     * No duplicate detection, no filesystem scanning, no cleanup logic
     * 
     * @param string $file_path Path to the file to backup
     * @return string|null Backup file path, or null if backup failed
     */
    function kmwp_create_backup_internal($file_path) {
        error_log('KMWP: [BACKUP_INTERNAL] Starting backup creation for: ' . basename($file_path));
        error_log('KMWP: [BACKUP_INTERNAL] Full path: ' . $file_path);
        error_log('KMWP: [BACKUP_INTERNAL] Process ID: ' . getmypid());
        
        if (!file_exists($file_path)) {
            error_log('KMWP: [BACKUP_INTERNAL] ✗ File does not exist: ' . $file_path);
            return null;
        }
        
        error_log('KMWP: [BACKUP_INTERNAL] ✓ File exists, reading content...');
        
        // Read the original file content into memory
        $original_content = @file_get_contents($file_path);
        if ($original_content === false) {
            error_log('KMWP: [BACKUP_INTERNAL] ✗ Failed to read file for backup: ' . $file_path);
            return null;
        }
        
        $original_size = strlen($original_content);
        error_log('KMWP: [BACKUP_INTERNAL] ✓ File read successfully. Size: ' . $original_size . ' bytes');
        
        // Generate unique backup filename with microsecond precision
        // Format: filename.backup.YYYY-MM-DD-HH-MM-SS-uuuuuu
        $microseconds = str_pad((int)(microtime(true) * 1000000) % 1000000, 6, '0', STR_PAD_LEFT);
        $timestamp = date('Y-m-d-H-i-s') . '-' . $microseconds;
        $backup_path = $file_path . '.backup.' . $timestamp;
        
        error_log('KMWP: [BACKUP_INTERNAL] Generated backup path: ' . basename($backup_path));
        error_log('KMWP: [BACKUP_INTERNAL] Timestamp: ' . $timestamp);
        
        // Ensure backup filename is unique (handle edge case where multiple backups in same microsecond)
        $counter = 0;
        while (file_exists($backup_path) && $counter < 100) {
            $counter++;
            $backup_path = $file_path . '.backup.' . $timestamp . '-' . $counter;
            error_log('KMWP: [BACKUP_INTERNAL] Collision detected, trying: ' . basename($backup_path) . ' (counter: ' . $counter . ')');
        }
        
        if ($counter > 0) {
            error_log('KMWP: [BACKUP_INTERNAL] Used counter: ' . $counter . ' to avoid collision');
        }
        
        error_log('KMWP: [BACKUP_INTERNAL] Final backup path: ' . basename($backup_path));
        error_log('KMWP: [BACKUP_INTERNAL] Writing backup file with LOCK_EX...');
        
        // Write the original content to backup file with exclusive lock
        $result = @file_put_contents($backup_path, $original_content, LOCK_EX);
        if ($result === false) {
            error_log('KMWP: [BACKUP_INTERNAL] ✗ Failed to create backup file: ' . $backup_path);
            return null;
        }
        
        error_log('KMWP: [BACKUP_INTERNAL] ✓ Backup file written successfully');
        error_log('KMWP: [BACKUP_INTERNAL] Backup: ' . basename($backup_path) . ' (size: ' . $original_size . ' bytes)');
        error_log('KMWP: [BACKUP_INTERNAL] File exists check: ' . (file_exists($backup_path) ? 'YES' : 'NO'));
        
        return $backup_path;
    }
    
    // Legacy function name kept for compatibility (now just calls the wrapper)
    function create_backup($file_path) {
        return kmwp_create_backup_once($file_path);
    }
    
    $saved_files = [];
    $errors = [];
    $backups_created = [];
    $files_backed_up = []; // Track which files have already been backed up in this operation
    
    // Handle "Both" option - save both files
    if ($output_type === 'llms_both') {
        $summarized_content = sanitize_textarea_field($body['summarized_content'] ?? '');
        $full_content = sanitize_textarea_field($body['full_content'] ?? '');
        
        // Save summarized version (llm.txt)
        if (!empty($summarized_content)) {
            $file_path_summary = ABSPATH . 'llm.txt';
            
            error_log('KMWP: [SAVE_BOTH] Processing llm.txt');
            error_log('KMWP: [SAVE_BOTH] File path: ' . $file_path_summary);
            error_log('KMWP: [SAVE_BOTH] File exists NOW: ' . (file_exists($file_path_summary) ? 'YES' : 'NO'));
            error_log('KMWP: [SAVE_BOTH] File existed BEFORE request: ' . ($file_existed_before['llm.txt'] ? 'YES' : 'NO'));
            error_log('KMWP: [SAVE_BOTH] Confirm overwrite: ' . ($confirm_overwrite ? 'YES' : 'NO'));
            error_log('KMWP: [SAVE_BOTH] Already in files_backed_up: ' . (in_array($file_path_summary, $files_backed_up) ? 'YES' : 'NO'));
            
            // Create backup ONLY if file existed BEFORE this request, user confirmed, and we haven't backed it up yet
            // This prevents backing up files that were just created in a previous duplicate request
            if ($file_existed_before['llm.txt'] && $confirm_overwrite && !in_array($file_path_summary, $files_backed_up)) {
                error_log('KMWP: [SAVE_BOTH] ✓ Conditions met, calling kmwp_create_backup_once for llm.txt');
                $backup = kmwp_create_backup_once($file_path_summary);
                error_log('KMWP: [SAVE_BOTH] Backup result for llm.txt: ' . ($backup ? basename($backup) : 'NULL'));
                
                if ($backup) {
                    $backups_created[] = basename($backup);
                    $files_backed_up[] = $file_path_summary; // Mark as backed up
                    // Update old history entry with backup filename
                    kmwp_update_history_with_backup($file_path_summary, $backup);
                }
            }
            
            $result_summary = file_put_contents($file_path_summary, $summarized_content);
            
            if ($result_summary !== false) {
                $saved_files[] = [
                    'filename' => 'llm.txt',
                    'file_url' => home_url('/llm.txt'),
                    'file_path' => $file_path_summary
                ];
            } else {
                $errors[] = 'Failed to save llm.txt';
            }
        }
        
        // Save full content version (llm-full.txt)
        if (!empty($full_content)) {
            $file_path_full = ABSPATH . 'llm-full.txt';
            
            error_log('KMWP: [SAVE_BOTH] Processing llm-full.txt');
            error_log('KMWP: [SAVE_BOTH] File path: ' . $file_path_full);
            error_log('KMWP: [SAVE_BOTH] File exists NOW: ' . (file_exists($file_path_full) ? 'YES' : 'NO'));
            error_log('KMWP: [SAVE_BOTH] File existed BEFORE request: ' . ($file_existed_before['llm-full.txt'] ? 'YES' : 'NO'));
            error_log('KMWP: [SAVE_BOTH] Confirm overwrite: ' . ($confirm_overwrite ? 'YES' : 'NO'));
            error_log('KMWP: [SAVE_BOTH] Already in files_backed_up: ' . (in_array($file_path_full, $files_backed_up) ? 'YES' : 'NO'));
            
            // Create backup ONLY if file existed BEFORE this request, user confirmed, and we haven't backed it up yet
            // This prevents backing up files that were just created in a previous duplicate request
            if ($file_existed_before['llm-full.txt'] && $confirm_overwrite && !in_array($file_path_full, $files_backed_up)) {
                error_log('KMWP: [SAVE_BOTH] ✓ Conditions met, calling kmwp_create_backup_once for llm-full.txt');
                $backup = kmwp_create_backup_once($file_path_full);
                error_log('KMWP: [SAVE_BOTH] Backup result for llm-full.txt: ' . ($backup ? basename($backup) : 'NULL'));
                if ($backup) {
                    $backups_created[] = basename($backup);
                    $files_backed_up[] = $file_path_full; // Mark as backed up
                    // Update old history entry with backup filename
                    kmwp_update_history_with_backup($file_path_full, $backup);
                }
            }
            
            $result_full = file_put_contents($file_path_full, $full_content);
            
            if ($result_full !== false) {
                $saved_files[] = [
                    'filename' => 'llm-full.txt',
                    'file_url' => home_url('/llm-full.txt'),
                    'file_path' => $file_path_full
                ];
            } else {
                $errors[] = 'Failed to save llm-full.txt';
            }
        }
        
        if (empty($saved_files)) {
            wp_send_json_error('No content to save or failed to save files', 400);
            return;
        }
        
        $response_data = [
            'message' => 'Both files saved successfully to website root',
            'files_saved' => array_column($saved_files, 'filename'),
            'files' => $saved_files
        ];
        
        if (!empty($backups_created)) {
            $response_data['backups_created'] = $backups_created;
            $response_data['message'] .= '. Backups created: ' . implode(', ', $backups_created);
        }
        
        if (!empty($errors)) {
            $response_data['errors'] = $errors;
        }
        
        // Save to history - use the actual file paths (not backup paths)
        // Make sure we're using the correct paths (llm.txt and llm-full.txt, not backup files)
        $file_paths = [];
        foreach ($saved_files as $file) {
            // Only use the actual file path, not backup paths
            if (strpos($file['file_path'], '.backup.') === false) {
                $file_paths[] = $file['file_path'];
            }
        }
        
        $history_id = kmwp_save_file_history(
            $website_url ?: 'Unknown',
            'llms_both',
            $summarized_content,
            $full_content,
            implode(', ', $file_paths)
        );
        
        if ($history_id === false) {
            error_log('KMWP: Failed to save history for llms_both. URL: ' . $website_url);
        }
        
        wp_send_json_success($response_data);
        return;
    }
    
    // Handle single file saves
    $content = sanitize_textarea_field($body['content'] ?? '');
    
    if (empty($content)) {
        wp_send_json_error('No content to save', 400);
        return;
    }
    
    // Determine filename based on output type
    $filename = 'llm.txt'; // Default
    if ($output_type === 'llms_full_txt') {
        $filename = 'llm-full.txt';
    }
    
    // Get WordPress root directory (ABSPATH)
    $file_path = ABSPATH . $filename;
    
    // Create backup if file exists and user confirmed
    $backup_created = null;
    error_log('KMWP: [SAVE_SINGLE] Processing single file save');
    error_log('KMWP: [SAVE_SINGLE] File path: ' . $file_path);
    error_log('KMWP: [SAVE_SINGLE] File exists NOW: ' . (file_exists($file_path) ? 'YES' : 'NO'));
    error_log('KMWP: [SAVE_SINGLE] File existed BEFORE request: ' . ($file_existed_before[$filename] ? 'YES' : 'NO'));
    error_log('KMWP: [SAVE_SINGLE] Confirm overwrite: ' . ($confirm_overwrite ? 'YES' : 'NO'));
    
    // Create backup ONLY if file existed BEFORE this request and user confirmed
    // This prevents backing up files that were just created in a previous duplicate request
    if ($file_existed_before[$filename] && $confirm_overwrite) {
        error_log('KMWP: [SAVE_SINGLE] ✓ Conditions met, calling kmwp_create_backup_once');
        $backup_created = kmwp_create_backup_once($file_path);
        error_log('KMWP: [SAVE_SINGLE] Backup result: ' . ($backup_created ? basename($backup_created) : 'NULL'));
        if ($backup_created) {
            // Update old history entry with backup filename
            kmwp_update_history_with_backup($file_path, $backup_created);
        }
    }
    
    // Write file to website root
    $result = file_put_contents($file_path, $content);
    
    if ($result === false) {
        wp_send_json_error('Failed to save file. Please check file permissions.', 500);
        return;
    }
    
    // Get the public URL
    $file_url = home_url('/' . $filename);
    
    $response_data = [
        'message' => 'File saved successfully to website root',
        'filename' => $filename,
        'file_path' => $file_path,
        'file_url' => $file_url
    ];
    
    if ($backup_created) {
        $response_data['backup_created'] = basename($backup_created);
        $response_data['message'] .= '. Backup created: ' . basename($backup_created);
    }
    
    // Save to history - use the actual file path (not backup path)
    // Make sure we're using the correct path (llm.txt or llm-full.txt, not backup file)
    $history_file_path = $file_path;
    if (strpos($file_path, '.backup.') !== false) {
        // If somehow we got a backup path, extract the original filename
        $history_file_path = ABSPATH . $filename;
    }
    
    $summarized = ($output_type === 'llms_txt') ? $content : '';
    $full = ($output_type === 'llms_full_txt') ? $content : '';
    
    $history_id = kmwp_save_file_history(
        $website_url ?: 'Unknown',
        $output_type,
        $summarized,
        $full,
        $history_file_path
    );
    
    if ($history_id === false) {
        error_log('KMWP: Failed to save history for ' . $output_type . '. URL: ' . $website_url);
    }
    
    wp_send_json_success($response_data);
    
    } finally {
        // Always release the global save lock
        if ($global_lock_handle !== false) {
            @fclose($global_lock_handle);
        }
        if (file_exists($global_lock_file)) {
            @unlink($global_lock_file);
        }
    }
});

/* get_history */
add_action('wp_ajax_kmwp_get_history', function () {
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions', 403);
        return;
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'kmwp_file_history';
    $user_id = get_current_user_id();
    
    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        error_log('KMWP: History table does not exist');
        wp_send_json_success([]); // Return empty array if table doesn't exist
        return;
    }
    
    $history = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE user_id = %d ORDER BY created_at DESC LIMIT 50",
            $user_id
        ),
        ARRAY_A
    );
    
    if ($history === false) {
        error_log('KMWP: Error fetching history - ' . $wpdb->last_error);
        wp_send_json_error('Database error: ' . $wpdb->last_error, 500);
        return;
    }
    
    error_log('KMWP: Returning ' . count($history) . ' history items');
    wp_send_json_success($history);
});

/* get_history_item */
add_action('wp_ajax_kmwp_get_history_item', function () {
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions', 403);
        return;
    }
    
    $history_id = intval($_GET['id'] ?? 0);
    
    if (!$history_id) {
        wp_send_json_error('Invalid history ID', 400);
        return;
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'kmwp_file_history';
    $user_id = get_current_user_id();
    
    $item = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d AND user_id = %d",
            $history_id,
            $user_id
        ),
        ARRAY_A
    );
    
    if (!$item) {
        wp_send_json_error('History item not found', 404);
        return;
    }
    
    wp_send_json_success($item);
});

/* delete_history_item */
add_action('wp_ajax_kmwp_delete_history_item', function () {
    
    error_log('KMWP: [DELETE] Delete request received');
    
    if (!current_user_can('manage_options')) {
        error_log('KMWP: [DELETE] Insufficient permissions');
        wp_send_json_error('Insufficient permissions', 403);
        return;
    }
    
    $history_id = intval($_POST['id'] ?? 0);
    
    if (!$history_id) {
        error_log('KMWP: [DELETE] Invalid history ID: ' . ($_POST['id'] ?? 'not set'));
        wp_send_json_error('Invalid history ID', 400);
        return;
    }
    
    error_log('KMWP: [DELETE] Processing delete for history ID: ' . $history_id);
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'kmwp_file_history';
    $user_id = get_current_user_id();
    
    // Get the history item first to get file paths and output type
    $item = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d AND user_id = %d",
            $history_id,
            $user_id
        ),
        ARRAY_A
    );
    
    if (!$item) {
        error_log('KMWP: [DELETE] History item not found. ID: ' . $history_id . ', User ID: ' . $user_id);
        // Item might have been deleted by a concurrent request - return success to avoid frontend error
        error_log('KMWP: [DELETE] Item already deleted (possibly by concurrent request), returning success');
        wp_send_json_success(['message' => 'History item already deleted', 'files_deleted' => [], 'files_failed' => []]);
        return;
    }
    
    error_log('KMWP: [DELETE] History item found. Output type: ' . $item['output_type']);
    error_log('KMWP: [DELETE] File path from DB: ' . ($item['file_path'] ?? 'empty'));
    
    // Determine which files should be deleted based on output_type
    $files_to_delete = [];
    $output_type = $item['output_type'];
    
    // Define target files based on output type
    if ($output_type === 'llms_both') {
        $files_to_delete[] = 'llm.txt';
        $files_to_delete[] = 'llm-full.txt';
        error_log('KMWP: [DELETE] Output type is "both" - will delete llm.txt and llm-full.txt');
    } elseif ($output_type === 'llms_txt') {
        $files_to_delete[] = 'llm.txt';
        error_log('KMWP: [DELETE] Output type is "llms_txt" - will delete llm.txt only');
    } elseif ($output_type === 'llms_full_txt') {
        $files_to_delete[] = 'llm-full.txt';
        error_log('KMWP: [DELETE] Output type is "llms_full_txt" - will delete llm-full.txt only');
    } else {
        error_log('KMWP: [DELETE] Unknown output type: ' . $output_type);
    }
    
    $files_deleted = [];
    $files_failed = [];
    $real_abspath = realpath(ABSPATH);
    
    if ($real_abspath === false) {
        error_log('KMWP: [DELETE] Failed to resolve ABSPATH: ' . ABSPATH);
        wp_send_json_error('Failed to resolve WordPress root path', 500);
        return;
    }
    
    error_log('KMWP: [DELETE] WordPress root: ' . $real_abspath);
    
    // Process files from database file_path (backup files)
    if (!empty($item['file_path'])) {
        $file_paths = preg_split('/,\s*/', $item['file_path'], -1, PREG_SPLIT_NO_EMPTY);
        error_log('KMWP: [DELETE] Found ' . count($file_paths) . ' file path(s) in database');
        
        foreach ($file_paths as $file_path) {
            $file_path = trim($file_path);
            
            if (empty($file_path) || substr($file_path, -3) === '...') {
                error_log('KMWP: [DELETE] Skipping truncated or empty path: ' . $file_path);
                continue;
            }
            
            $filename = basename($file_path);
            $is_backup = strpos($file_path, '.backup.') !== false;
            
            error_log('KMWP: [DELETE] Processing file from DB: ' . $filename . ' (backup: ' . ($is_backup ? 'yes' : 'no') . ')');
            
            // Check if this file matches our target files based on output_type
            $should_delete = false;
            foreach ($files_to_delete as $target_file) {
                // Check if filename contains target file (handles both current and backup files)
                if (strpos($filename, $target_file) !== false) {
                    $should_delete = true;
                    error_log('KMWP: [DELETE] File matches target: ' . $filename . ' matches ' . $target_file);
                    break;
                }
            }
            
            if (!$should_delete) {
                error_log('KMWP: [DELETE] Skipping file (does not match output_type): ' . $filename);
                continue;
            }
            
            // Normalize path
            $normalized_path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file_path);
            
            // Security check: ensure file is within WordPress root
            $real_file_path = realpath($normalized_path);
            
            if ($real_file_path === false) {
                error_log('KMWP: [DELETE] File does not exist: ' . $normalized_path);
                $files_failed[] = $filename . ' (not found)';
                continue;
            }
            
            $is_within_root = (stripos($real_file_path, $real_abspath) === 0);
            
            if (!$is_within_root) {
                error_log('KMWP: [DELETE] File outside WordPress root: ' . $real_file_path);
                $files_failed[] = $filename . ' (outside root)';
                continue;
            }
            
            // Attempt to delete
            if (@unlink($real_file_path)) {
                $files_deleted[] = $filename;
                error_log('KMWP: [DELETE] ✓ Successfully deleted: ' . $real_file_path);
            } else {
                $error = error_get_last();
                error_log('KMWP: [DELETE] ✗ Failed to delete: ' . $real_file_path . '. Error: ' . ($error ? $error['message'] : 'Unknown'));
                $files_failed[] = $filename . ' (delete failed)';
            }
        }
    }
    
    // Also check and delete current files from filesystem if they match output_type
    // Only delete if no newer history entries reference them
    error_log('KMWP: [DELETE] Checking current files in filesystem...');
    foreach ($files_to_delete as $target_file) {
        $current_file_path = $real_abspath . DIRECTORY_SEPARATOR . $target_file;
        
        error_log('KMWP: [DELETE] Checking current file: ' . $current_file_path);
        
        if (!file_exists($current_file_path)) {
            error_log('KMWP: [DELETE] Current file does not exist: ' . $target_file);
            continue;
        }
        
        // Check if there are newer history entries that reference this file
        $newer_entry = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name 
                WHERE user_id = %d 
                AND id != %d 
                AND created_at > %s 
                AND file_path LIKE %s",
                $user_id,
                $history_id,
                $item['created_at'],
                '%' . $wpdb->esc_like($target_file) . '%'
            )
        );
        
        error_log('KMWP: [DELETE] Newer entries referencing ' . $target_file . ': ' . $newer_entry);
        
        // Only delete current file if no newer entries reference it
        if ($newer_entry == 0) {
            $real_current_path = realpath($current_file_path);
            
            if ($real_current_path !== false && stripos($real_current_path, $real_abspath) === 0) {
                if (@unlink($real_current_path)) {
                    $files_deleted[] = $target_file;
                    error_log('KMWP: [DELETE] ✓ Successfully deleted current file: ' . $real_current_path);
                } else {
                    $error = error_get_last();
                    error_log('KMWP: [DELETE] ✗ Failed to delete current file: ' . $real_current_path . '. Error: ' . ($error ? $error['message'] : 'Unknown'));
                    $files_failed[] = $target_file . ' (delete failed)';
                }
            }
        } else {
            error_log('KMWP: [DELETE] Skipping current file (referenced by newer entry): ' . $target_file);
        }
    }
    
    // Delete from database
    error_log('KMWP: [DELETE] Deleting database entry...');
    $deleted = $wpdb->delete(
        $table_name,
        ['id' => $history_id, 'user_id' => $user_id],
        ['%d', '%d']
    );
    
    if ($deleted) {
        error_log('KMWP: [DELETE] ✓ Database entry deleted successfully');
        $message = 'History item deleted';
        if (!empty($files_deleted)) {
            $message .= '. Files deleted: ' . implode(', ', $files_deleted);
        }
        if (!empty($files_failed)) {
            $message .= '. Files failed to delete: ' . implode(', ', $files_failed);
        }
        error_log('KMWP: [DELETE] Final result - Deleted: ' . count($files_deleted) . ', Failed: ' . count($files_failed));
        wp_send_json_success(['message' => $message, 'files_deleted' => $files_deleted, 'files_failed' => $files_failed]);
    } else {
        error_log('KMWP: [DELETE] ✗ Failed to delete database entry. Error: ' . $wpdb->last_error);
        wp_send_json_error('Failed to delete history item', 500);
    }
});
