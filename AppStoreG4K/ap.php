<?php
session_start();

// Define main owner credentials
define('MAIN_OWNER_USERNAME', 'kalua');
define('MAIN_OWNER_PASSWORD', 'kkk'); 

// Define file paths (using absolute paths to avoid ambiguity)
define('DATABASE_FILE', __DIR__ . '/apps.txt');
define('USERS_FILE', __DIR__ . '/users.txt');
define('PENDING_LINKS_FILE', __DIR__ . '/pending_links.txt');

// Set a simple response header
header('Content-Type: application/json');

// Get the POST data from the request body
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Determine action: check GET (query), then POST (FormData), then JSON body
$action = $_GET['action'] ?? $_POST['action'] ?? ($data['action'] ?? '');

// Helper function to check if the user is a main owner
function is_main_owner() {
    return isset($_SESSION['is_main_owner']) && $_SESSION['is_main_owner'];
}

// Helper function to check if the user is a registered admin
function is_registered_admin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'];
}

switch ($action) {
    case 'login':
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';

        if ($username === MAIN_OWNER_USERNAME && $password === MAIN_OWNER_PASSWORD) {
            $_SESSION['is_main_owner'] = true;
            $_SESSION['is_admin'] = true;
            echo json_encode(['success' => true, 'message' => 'Main owner login successful.', 'is_main_owner' => true]);
        } else {
            $users = file_exists(USERS_FILE) ? file(USERS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
            $found_user = false;
            foreach ($users as $user_data) {
                list($user, $hashed_password) = explode(',', $user_data);
                if ($user === $username && password_verify($password, $hashed_password)) {
                    $_SESSION['is_admin'] = true;
                    $_SESSION['is_main_owner'] = false;
                    $found_user = true;
                    break;
                }
            }

            if ($found_user) {
                echo json_encode(['success' => true, 'message' => 'Admin login successful.', 'is_main_owner' => false]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
            }
        }
        break;

    case 'register':
        if (!is_main_owner()) {
            echo json_encode(['success' => false, 'message' => 'Only the main owner can register new users.']);
            exit;
        }

        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';

        if (empty($username) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Username and password are required.']);
            exit;
        }

        $users = file_exists(USERS_FILE) ? file(USERS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        foreach ($users as $user_data) {
            list($user, ) = explode(',', $user_data);
            if ($user === $username) {
                echo json_encode(['success' => false, 'message' => 'Username already exists.']);
                exit;
            }
        }

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $user_entry = $username . ',' . $hashed_password . "\n";

        if (file_put_contents(USERS_FILE, $user_entry, FILE_APPEND | LOCK_EX)) {
            echo json_encode(['success' => true, 'message' => 'User registered successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to register user. Check file permissions.']);
        }
        break;
        
    case 'add_pending_entry':
        if (!is_registered_admin()) {
            echo json_encode(['success' => false, 'message' => 'Authentication required.']);
            exit;
        }

        // Accept both JSON (from $data) and multipart/form-data (from $_POST/$_FILES)
        $title = $_POST['title'] ?? ($data['title'] ?? '');
        $description = $_POST['description'] ?? ($data['description'] ?? '');
        $links = isset($_POST['links'])
            ? json_decode($_POST['links'], true)
            : ($data['links'] ?? []);

        // Image URL or uploaded file
        $image_url = $_POST['image_url'] ?? ($data['image_url'] ?? '');

        if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . "/assets/";
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $filename = time() . "_" . basename($_FILES['image_file']['name']);
            $targetPath = $uploadDir . $filename;
            if (move_uploaded_file($_FILES['image_file']['tmp_name'], $targetPath)) {
                $image_url = "assets/" . $filename;
            }
        }

        if (empty($title) || empty($description) || empty($image_url) || empty($links)) {
            echo json_encode(['success' => false, 'message' => 'Title, description, image, and at least one link are required.']);
            exit;
        }

        $sanitized_entry = [
            'title' => strip_tags(trim($title)),
            'description' => strip_tags(trim($description)),
            'image_url' => filter_var(trim($image_url), FILTER_SANITIZE_URL),
            'links' => []
        ];

        foreach ($links as $link) {
            $sanitized_entry['links'][] = [
                'url' => filter_var(trim($link['url']), FILTER_SANITIZE_URL),
                'platform' => strip_tags(trim($link['platform'])),
                'architecture' => strip_tags(trim($link['architecture']))
            ];
        }

        $new_entry = json_encode($sanitized_entry) . "\n";

        if (file_put_contents(PENDING_LINKS_FILE, $new_entry, FILE_APPEND | LOCK_EX)) {
            echo json_encode(['success' => true, 'message' => 'Entry submitted for review!', 'image_url' => $sanitized_entry['image_url']]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to save pending link. Check file permissions.']);
        }
        break;

    case 'get_pending_links':
        if (!is_main_owner()) {
            echo json_encode(['success' => false, 'message' => 'Only the main owner can view pending links.']);
            exit;
        }

        $pending_links = [];
        if (file_exists(PENDING_LINKS_FILE)) {
            $lines = file(PENDING_LINKS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $pending_links[] = json_decode($line, true);
            }
        }
        echo json_encode(['success' => true, 'links' => $pending_links]);
        break;

    case 'approve_link':
        if (!is_main_owner()) {
            echo json_encode(['success' => false, 'message' => 'Only the main owner can approve links.']);
            exit;
        }
        
        $index = $data['index'] ?? -1;

        if ($index === -1) {
            echo json_encode(['success' => false, 'message' => 'Invalid index for approval.']);
            exit;
        }

        $pending_links = [];
        if (file_exists(PENDING_LINKS_FILE)) {
            $lines = file(PENDING_LINKS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $pending_links[] = json_decode($line, true);
            }
        }
        
        if (!isset($pending_links[$index])) {
            echo json_encode(['success' => false, 'message' => 'Link not found.']);
            exit;
        }

        $approved_link = $pending_links[$index];

        $links_string = implode(', ', array_map(function($link) {
            return $link['url'] . " (" . $link['platform'] . " - " . $link['architecture'] . ")";
        }, $approved_link['links']));

        $new_entry_text = sprintf(
            "\n***\n%s\n%s\n%s\n%s\n---\n",
            strip_tags($approved_link['title']),
            strip_tags($approved_link['description']),
            filter_var($approved_link['image_url'], FILTER_SANITIZE_URL),
            $links_string
        );
        
        if (file_put_contents(DATABASE_FILE, $new_entry_text, FILE_APPEND | LOCK_EX)) {
            unset($lines[$index]);
            file_put_contents(PENDING_LINKS_FILE, implode("\n", $lines), LOCK_EX);

            echo json_encode(['success' => true, 'message' => 'Link approved and added to the database.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to write to main database. Check file permissions.']);
        }
        break;

    case 'disapprove_link':
        if (!is_main_owner()) {
            echo json_encode(['success' => false, 'message' => 'Only the main owner can disapprove links.']);
            exit;
        }
        
        $index = $data['index'] ?? -1;

        if ($index === -1) {
            echo json_encode(['success' => false, 'message' => 'Invalid index for disapproval.']);
            exit;
        }

        $pending_links = [];
        if (file_exists(PENDING_LINKS_FILE)) {
            $lines = file(PENDING_LINKS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $pending_links[] = json_decode($line, true);
            }
        }
        
        if (!isset($pending_links[$index])) {
            echo json_encode(['success' => false, 'message' => 'Link not found.']);
            exit;
        }

        unset($lines[$index]);
        file_put_contents(PENDING_LINKS_FILE, implode("\n", $lines), LOCK_EX);

        echo json_encode(['success' => true, 'message' => 'Link disapproved and removed.']);
        break;

    // ========== MAIN ADMIN: BLOCK‑BASED MANAGEMENT ==========
    case 'get_apps_raw':
        if (!is_main_owner()) {
            echo json_encode(['success' => false, 'message' => 'Only main owner can view apps.']);
            exit;
        }
        if (!file_exists(DATABASE_FILE)) {
            echo json_encode(['success' => true, 'apps_raw' => []]);
            exit;
        }
        $content = file_get_contents(DATABASE_FILE);
        // Split by blocks: each block starts with "***" and ends with "---"
        $blocks = [];
        $raw_blocks = explode('***', $content);
        foreach ($raw_blocks as $raw_block) {
            $raw_block = trim($raw_block);
            if (empty($raw_block)) continue;
            // Each block may contain "---" at the end; we remove trailing "---"
            if (substr($raw_block, -3) === '---') {
                $raw_block = substr($raw_block, 0, -3);
            }
            $raw_block = trim($raw_block);
            if (!empty($raw_block)) {
                $blocks[] = $raw_block;
            }
        }
        echo json_encode(['success' => true, 'apps_raw' => $blocks]);
        break;

    case 'add_app_block':
        if (!is_main_owner()) {
            echo json_encode(['success' => false, 'message' => 'Only main owner can add apps.']);
            exit;
        }
        // Get data from either POST (multipart) or JSON
        $title = $_POST['title'] ?? ($data['title'] ?? '');
        $description = $_POST['description'] ?? ($data['description'] ?? '');
        $links = isset($_POST['links']) ? json_decode($_POST['links'], true) : ($data['links'] ?? []);
        $image_url = $_POST['image_url'] ?? ($data['image_url'] ?? '');

        // Handle file upload
        if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . "/assets/";
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $filename = time() . "_" . basename($_FILES['image_file']['name']);
            $targetPath = $uploadDir . $filename;
            if (move_uploaded_file($_FILES['image_file']['tmp_name'], $targetPath)) {
                $image_url = "assets/" . $filename;
            }
        }

        if (empty($title) || empty($description) || empty($image_url) || empty($links)) {
            echo json_encode(['success' => false, 'message' => 'All fields required.']);
            exit;
        }

        // Build links string in the format: url (platform - architecture)
        $link_strings = [];
        foreach ($links as $link) {
            $url = filter_var(trim($link['url']), FILTER_SANITIZE_URL);
            $platform = strip_tags(trim($link['platform']));
            $arch = strip_tags(trim($link['architecture']));
            $link_strings[] = "$url ($platform - $arch)";
        }
        $links_line = implode(', ', $link_strings);

        // Create a complete block with separators
        $new_block = "***\n" . strip_tags($title) . "\n" . strip_tags($description) . "\n" . filter_var($image_url, FILTER_SANITIZE_URL) . "\n" . $links_line . "\n---\n";

        if (file_put_contents(DATABASE_FILE, $new_block, FILE_APPEND | LOCK_EX)) {
            echo json_encode(['success' => true, 'message' => 'App added successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to add app. Check file permissions.']);
        }
        break;

    case 'update_app_block':
        if (!is_main_owner()) {
            echo json_encode(['success' => false, 'message' => 'Only main owner can update apps.']);
            exit;
        }
        // Get data from either source
        $index = $_POST['index'] ?? ($data['index'] ?? -1);
        $title = $_POST['title'] ?? ($data['title'] ?? '');
        $description = $_POST['description'] ?? ($data['description'] ?? '');
        $links = isset($_POST['links']) ? json_decode($_POST['links'], true) : ($data['links'] ?? []);
        $image_url = $_POST['image_url'] ?? ($data['image_url'] ?? '');

        // Handle file upload (overwrites any URL if file is provided)
        if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . "/assets/";
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $filename = time() . "_" . basename($_FILES['image_file']['name']);
            $targetPath = $uploadDir . $filename;
            if (move_uploaded_file($_FILES['image_file']['tmp_name'], $targetPath)) {
                $image_url = "assets/" . $filename;
            }
        }

        if ($index === -1 || empty($title) || empty($description) || empty($image_url) || empty($links)) {
            echo json_encode(['success' => false, 'message' => 'Invalid data.']);
            exit;
        }

        // Read current blocks
        if (!file_exists(DATABASE_FILE)) {
            echo json_encode(['success' => false, 'message' => 'No apps found.']);
            exit;
        }
        $content = file_get_contents(DATABASE_FILE);
        $blocks = [];
        $raw_blocks = explode('***', $content);
        foreach ($raw_blocks as $raw_block) {
            $raw_block = trim($raw_block);
            if (empty($raw_block)) continue;
            if (substr($raw_block, -3) === '---') {
                $raw_block = substr($raw_block, 0, -3);
            }
            $raw_block = trim($raw_block);
            if (!empty($raw_block)) {
                $blocks[] = $raw_block;
            }
        }

        if (!isset($blocks[$index])) {
            echo json_encode(['success' => false, 'message' => 'App not found (index out of range).']);
            exit;
        }

        // Build new block content (without separators)
        $link_strings = [];
        foreach ($links as $link) {
            $url = filter_var(trim($link['url']), FILTER_SANITIZE_URL);
            $platform = strip_tags(trim($link['platform']));
            $arch = strip_tags(trim($link['architecture']));
            $link_strings[] = "$url ($platform - $arch)";
        }
        $links_line = implode(', ', $link_strings);

        $new_block_content = strip_tags($title) . "\n" . strip_tags($description) . "\n" . filter_var($image_url, FILTER_SANITIZE_URL) . "\n" . $links_line;

        $blocks[$index] = $new_block_content;

        // Rebuild file with proper separators
        $new_content = '';
        foreach ($blocks as $block) {
            $new_content .= "***\n" . $block . "\n---\n\n";
        }

        // Atomic write: use temporary file then rename
        $temp_file = DATABASE_FILE . '.tmp';
        if (file_put_contents($temp_file, $new_content, LOCK_EX) === false) {
            echo json_encode(['success' => false, 'message' => 'Failed to write temporary file. Check disk space or permissions.']);
            exit;
        }
        if (!rename($temp_file, DATABASE_FILE)) {
            unlink($temp_file);
            echo json_encode(['success' => false, 'message' => 'Failed to replace database file. Check file permissions.']);
            exit;
        }

        echo json_encode(['success' => true, 'message' => 'App updated successfully.']);
        break;

    case 'delete_app_block':
        if (!is_main_owner()) {
            echo json_encode(['success' => false, 'message' => 'Only main owner can delete apps.']);
            exit;
        }
        $index = $data['index'] ?? -1;
        if ($index === -1) {
            echo json_encode(['success' => false, 'message' => 'Invalid index.']);
            exit;
        }
        // Read current blocks
        if (!file_exists(DATABASE_FILE)) {
            echo json_encode(['success' => false, 'message' => 'No apps found.']);
            exit;
        }
        $content = file_get_contents(DATABASE_FILE);
        $blocks = [];
        $raw_blocks = explode('***', $content);
        foreach ($raw_blocks as $raw_block) {
            $raw_block = trim($raw_block);
            if (empty($raw_block)) continue;
            if (substr($raw_block, -3) === '---') {
                $raw_block = substr($raw_block, 0, -3);
            }
            $raw_block = trim($raw_block);
            if (!empty($raw_block)) {
                $blocks[] = $raw_block;
            }
        }
        if (!isset($blocks[$index])) {
            echo json_encode(['success' => false, 'message' => 'App not found.']);
            exit;
        }
        unset($blocks[$index]);
        // Rebuild file with proper separators
        $new_content = '';
        foreach ($blocks as $block) {
            $new_content .= "***\n" . $block . "\n---\n\n";
        }
        if (file_put_contents(DATABASE_FILE, $new_content, LOCK_EX)) {
            echo json_encode(['success' => true, 'message' => 'App deleted successfully.']);
        } else {
            // Provide detailed error
            $perms = substr(sprintf('%o', fileperms(DATABASE_FILE)), -4);
            echo json_encode(['success' => false, 'message' => "Failed to delete app. File permissions: $perms. Check if the file is writable by the web server."]);
        }
        break;

    case 'logout':
        session_unset();
        session_destroy();
        echo json_encode(['success' => true, 'message' => 'Logged out.']);
        break;

    case 'check_session':
        echo json_encode(['is_main_owner' => is_main_owner(), 'is_admin' => is_registered_admin()]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action.']);
        break;
}
?>