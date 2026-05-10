<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function env_value($key, $default = null) {
    $value = getenv($key);
    return $value !== false && $value !== '' ? $value : $default;
}

function respond($payload, $status = 200) {
    http_response_code($status);
    echo json_encode($payload);
    exit();
}

function get_json_body() {
    $raw = file_get_contents("php://input");
    if (!$raw) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function array_is_list_compat($array) {
    if (!is_array($array)) return false;
    if (function_exists('array_is_list')) return array_is_list($array);
    return array_keys($array) === range(0, count($array) - 1);
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    $padding = strlen($data) % 4;
    if ($padding > 0) $data .= str_repeat('=', 4 - $padding);
    return base64_decode(strtr($data, '-_', '+/'));
}

function jwt_secret() {
    return env_value('JWT_SECRET', 'quizforge-dev-secret-change-me');
}

function create_jwt($payload) {
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $segments = [
        base64url_encode(json_encode($header)),
        base64url_encode(json_encode($payload))
    ];
    $signature = hash_hmac('sha256', implode('.', $segments), jwt_secret(), true);
    $segments[] = base64url_encode($signature);
    return implode('.', $segments);
}

function verify_jwt($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$headerB64, $payloadB64, $signatureB64] = $parts;
    $expected = base64url_encode(hash_hmac('sha256', $headerB64 . '.' . $payloadB64, jwt_secret(), true));
    if (!hash_equals($expected, $signatureB64)) return null;
    $payload = json_decode(base64url_decode($payloadB64), true);
    if (!is_array($payload)) return null;
    if (($payload['exp'] ?? 0) < time()) return null;
    return $payload;
}

function get_bearer_token() {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!$header && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (stripos($header, 'Bearer ') === 0) return trim(substr($header, 7));
    return '';
}

$databaseUrl = env_value('DATABASE_URL');
if ($databaseUrl) {
    $parts = parse_url($databaseUrl);
    $host = $parts['host'] ?? env_value('DB_HOST', 'localhost');
    $port = strval($parts['port'] ?? env_value('DB_PORT', '5432'));
    $dbname = isset($parts['path']) ? ltrim($parts['path'], '/') : env_value('DB_NAME', '');
    $user = $parts['user'] ?? env_value('DB_USER', '');
    $password = $parts['pass'] ?? env_value('DB_PASSWORD', '');
    parse_str($parts['query'] ?? '', $queryParams);
    $sslmode = $queryParams['sslmode'] ?? env_value('DB_SSLMODE', 'require');
} else {
    $host     = env_value('DB_HOST', "db.ienakmntjzlpkpaxefej.supabase.co");
    $port     = env_value('DB_PORT', "5432");
    $dbname   = env_value('DB_NAME', "postgres");
    $user     = env_value('DB_USER', "postgres");
    $password = env_value('DB_PASSWORD', "TheBestPasswordEver");
    $sslmode  = env_value('DB_SSLMODE', "require");
}

try {
    $conn = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=$sslmode",
        $user,
        $password
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    respond(["success" => false, "message" => $e->getMessage()], 500);
}

try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS app_users (
            id SERIAL PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'student',
            reset_token VARCHAR(255),
            reset_expires_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS mcq_tests (
            id SERIAL PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            topic VARCHAR(255),
            total_questions INT DEFAULT 0,
            time_per_question INT DEFAULT 60,
            created_by INT REFERENCES app_users(id) ON DELETE SET NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS mcq_questions (
            id SERIAL PRIMARY KEY,
            test_id INT REFERENCES mcq_tests(id) ON DELETE CASCADE,
            question_no INT,
            topic VARCHAR(255),
            question TEXT NOT NULL,
            options JSONB NOT NULL,
            correct_answer VARCHAR(255) NOT NULL,
            explanation TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS mcq_results (
            id SERIAL PRIMARY KEY,
            test_id INT REFERENCES mcq_tests(id) ON DELETE CASCADE,
            user_id INT REFERENCES app_users(id) ON DELETE CASCADE,
            score INT,
            total INT,
            correct INT,
            wrong INT,
            skipped INT,
            time_taken INT,
            answers JSONB,
            taken_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        ALTER TABLE mcq_results ADD COLUMN IF NOT EXISTS user_id INT REFERENCES app_users(id) ON DELETE CASCADE;
        ALTER TABLE mcq_tests ADD COLUMN IF NOT EXISTS created_by INT REFERENCES app_users(id) ON DELETE SET NULL;
        ALTER TABLE app_users ADD COLUMN IF NOT EXISTS reset_token VARCHAR(255);
        ALTER TABLE app_users ADD COLUMN IF NOT EXISTS reset_expires_at TIMESTAMP NULL;
        ALTER TABLE app_users ADD COLUMN IF NOT EXISTS role VARCHAR(20) NOT NULL DEFAULT 'student';
    ");
} catch (PDOException $e) {
    respond(["success" => false, "message" => "Schema error: " . $e->getMessage()], 500);
}

$adminEmail = env_value('ADMIN_EMAIL', 'admin@quizforge.local');
$adminPassword = env_value('ADMIN_PASSWORD', 'Admin@123');
$adminName = env_value('ADMIN_NAME', 'QuizForge Admin');
$adminStmt = $conn->prepare("SELECT id FROM app_users WHERE email = :email LIMIT 1");
$adminStmt->execute([':email' => $adminEmail]);
if (!$adminStmt->fetchColumn()) {
    $seedStmt = $conn->prepare("
        INSERT INTO app_users (name, email, password_hash, role)
        VALUES (:name, :email, :hash, 'admin')
    ");
    $seedStmt->execute([
        ':name' => $adminName,
        ':email' => $adminEmail,
        ':hash' => password_hash($adminPassword, PASSWORD_DEFAULT)
    ]);
}

function normalize_question($question, $fallbackTopic = '') {
    if (!is_array($question)) return null;
    $questionText = trim((string)($question['question'] ?? ''));
    $options = $question['options'] ?? [];
    $correct = $question['correct_answer'] ?? null;
    $explanation = trim((string)($question['explanation'] ?? ''));
    $topic = trim((string)($question['topic'] ?? $fallbackTopic));

    if ($questionText === '' || !is_array($options) || count($options) < 2) return null;

    $normalizedOptions = [];
    foreach ($options as $option) {
        $value = trim((string)$option);
        if ($value !== '') $normalizedOptions[] = $value;
    }
    if (count($normalizedOptions) < 2) return null;

    if (is_int($correct) || ctype_digit((string)$correct)) {
        $idx = intval($correct);
        if ($idx >= 0 && $idx < count($normalizedOptions)) {
            $correct = $normalizedOptions[$idx];
        }
    } elseif (is_string($correct)) {
        $candidate = trim($correct);
        $letters = ['A', 'B', 'C', 'D', 'E', 'F'];
        if (in_array(strtoupper($candidate), $letters, true)) {
            $letterIndex = array_search(strtoupper($candidate), $letters, true);
            if ($letterIndex !== false && isset($normalizedOptions[$letterIndex])) {
                $correct = $normalizedOptions[$letterIndex];
            }
        } else {
            $correct = $candidate;
        }
    }

    $correct = trim((string)$correct);
    if ($correct === '' || !in_array($correct, $normalizedOptions, true)) return null;

    return [
        'topic' => $topic,
        'question' => $questionText,
        'options' => $normalizedOptions,
        'correct_answer' => $correct,
        'explanation' => $explanation
    ];
}

function normalize_upload_payload($body) {
    $payload = is_array($body) ? $body : [];
    $questions = $payload['questions'] ?? $payload['items'] ?? $payload['mcqs'] ?? null;
    if ($questions === null && array_is_list_compat($payload)) {
        $questions = $payload;
        $payload = [];
    }
    if (!is_array($questions)) $questions = [];

    $topic = trim((string)($payload['topic'] ?? $payload['subject'] ?? ''));
    $title = trim((string)($payload['title'] ?? $payload['name'] ?? $payload['subject'] ?? 'Untitled Test'));
    $description = trim((string)($payload['description'] ?? $payload['summary'] ?? ''));
    $timePerQuestion = intval($payload['time_per_question'] ?? $payload['timePerQuestion'] ?? 60);
    if ($timePerQuestion < 0) $timePerQuestion = 60;

    $normalizedQuestions = [];
    foreach ($questions as $question) {
        $normalized = normalize_question($question, $topic);
        if ($normalized !== null) $normalizedQuestions[] = $normalized;
    }

    return [
        'title' => $title,
        'description' => $description,
        'topic' => $topic,
        'time_per_question' => $timePerQuestion,
        'questions' => $normalizedQuestions
    ];
}

function auth_user($conn, $required = true) {
    $token = get_bearer_token();
    if (!$token) {
        if ($required) respond(['success' => false, 'message' => 'Unauthorized'], 401);
        return null;
    }
    $payload = verify_jwt($token);
    if (!$payload) {
        if ($required) respond(['success' => false, 'message' => 'Invalid or expired token'], 401);
        return null;
    }
    $stmt = $conn->prepare("SELECT id, name, email, role, created_at FROM app_users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => intval($payload['sub'] ?? 0)]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user && $required) respond(['success' => false, 'message' => 'User not found'], 401);
    return $user ?: null;
}

function require_admin($user) {
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        respond(['success' => false, 'message' => 'Admin access required'], 403);
    }
}

function issue_auth_payload($user) {
    $now = time();
    $payload = [
        'sub' => intval($user['id']),
        'email' => $user['email'],
        'role' => $user['role'],
        'iat' => $now,
        'exp' => $now + (60 * 60 * 24 * 7)
    ];
    return [
        'token' => create_jwt($payload),
        'user' => [
            'id' => intval($user['id']),
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'created_at' => $user['created_at'] ?? null
        ]
    ];
}

function fetch_test($conn, $testId) {
    $stmt = $conn->prepare("
        SELECT t.*, u.name AS creator_name
        FROM mcq_tests t
        LEFT JOIN app_users u ON u.id = t.created_by
        WHERE t.id = :id
    ");
    $stmt->execute([':id' => $testId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function fetch_questions($conn, $testId) {
    $stmt = $conn->prepare("SELECT * FROM mcq_questions WHERE test_id = :id ORDER BY question_no ASC");
    $stmt->execute([':id' => $testId]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($questions as &$q) {
        $q['options'] = json_decode($q['options'], true);
    }
    return $questions;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$body = get_json_body();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$action) {
    $action = $body['action'] ?? '';
}

switch ($action) {

    case 'register':
        $name = trim((string)($body['name'] ?? ''));
        $email = strtolower(trim((string)($body['email'] ?? '')));
        $password = (string)($body['password'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
            respond(['success' => false, 'message' => 'Name, valid email, and password (min 6 chars) required.'], 422);
        }
        $stmt = $conn->prepare("SELECT id FROM app_users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        if ($stmt->fetchColumn()) {
            respond(['success' => false, 'message' => 'Email already registered.'], 409);
        }
        $insert = $conn->prepare("
            INSERT INTO app_users (name, email, password_hash, role)
            VALUES (:name, :email, :hash, 'student')
            RETURNING id, name, email, role, created_at
        ");
        $insert->execute([
            ':name' => $name,
            ':email' => $email,
            ':hash' => password_hash($password, PASSWORD_DEFAULT)
        ]);
        $user = $insert->fetch(PDO::FETCH_ASSOC);
        respond(['success' => true, 'message' => 'Registration successful.'] + issue_auth_payload($user));
        break;

    case 'login':
        $email = strtolower(trim((string)($body['email'] ?? '')));
        $password = (string)($body['password'] ?? '');
        $stmt = $conn->prepare("SELECT * FROM app_users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            respond(['success' => false, 'message' => 'Invalid email or password.'], 401);
        }
        respond(['success' => true, 'message' => 'Login successful.'] + issue_auth_payload($user));
        break;

    case 'me':
        $user = auth_user($conn);
        respond(['success' => true, 'user' => $user]);
        break;

    case 'change_password':
        $user = auth_user($conn);
        $currentPassword = (string)($body['current_password'] ?? '');
        $newPassword = (string)($body['new_password'] ?? '');
        if (strlen($newPassword) < 6) {
            respond(['success' => false, 'message' => 'New password must be at least 6 characters.'], 422);
        }
        $stmt = $conn->prepare("SELECT password_hash FROM app_users WHERE id = :id");
        $stmt->execute([':id' => $user['id']]);
        $hash = $stmt->fetchColumn();
        if (!$hash || !password_verify($currentPassword, $hash)) {
            respond(['success' => false, 'message' => 'Current password is incorrect.'], 401);
        }
        $update = $conn->prepare("UPDATE app_users SET password_hash = :hash, reset_token = NULL, reset_expires_at = NULL WHERE id = :id");
        $update->execute([':hash' => password_hash($newPassword, PASSWORD_DEFAULT), ':id' => $user['id']]);
        respond(['success' => true, 'message' => 'Password changed successfully.']);
        break;

    case 'request_password_reset':
        $email = strtolower(trim((string)($body['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(['success' => false, 'message' => 'Enter a valid email.'], 422);
        }
        $stmt = $conn->prepare("SELECT id FROM app_users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $userId = $stmt->fetchColumn();
        if (!$userId) {
            respond(['success' => true, 'message' => 'If the account exists, a reset token has been generated.']);
        }
        $token = bin2hex(random_bytes(16));
        $expires = date('Y-m-d H:i:s', time() + 3600);
        $update = $conn->prepare("UPDATE app_users SET reset_token = :token, reset_expires_at = :expires WHERE id = :id");
        $update->execute([':token' => $token, ':expires' => $expires, ':id' => $userId]);
        respond(['success' => true, 'message' => 'Reset token generated.', 'reset_token' => $token]);
        break;

    case 'reset_password':
        $email = strtolower(trim((string)($body['email'] ?? '')));
        $token = trim((string)($body['reset_token'] ?? ''));
        $newPassword = (string)($body['new_password'] ?? '');
        if ($token === '' || strlen($newPassword) < 6) {
            respond(['success' => false, 'message' => 'Reset token and new password (min 6 chars) required.'], 422);
        }
        $stmt = $conn->prepare("
            SELECT id, reset_expires_at FROM app_users
            WHERE email = :email AND reset_token = :token LIMIT 1
        ");
        $stmt->execute([':email' => $email, ':token' => $token]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$userRow || strtotime($userRow['reset_expires_at'] ?? '') < time()) {
            respond(['success' => false, 'message' => 'Reset token is invalid or expired.'], 400);
        }
        $update = $conn->prepare("
            UPDATE app_users SET password_hash = :hash, reset_token = NULL, reset_expires_at = NULL WHERE id = :id
        ");
        $update->execute([':hash' => password_hash($newPassword, PASSWORD_DEFAULT), ':id' => $userRow['id']]);
        respond(['success' => true, 'message' => 'Password reset successful.']);
        break;

    case 'list_tests':
        $stmt = $conn->query("
            SELECT t.*, u.name AS creator_name, COUNT(q.id) AS q_count
            FROM mcq_tests t
            LEFT JOIN mcq_questions q ON q.test_id = t.id
            LEFT JOIN app_users u ON u.id = t.created_by
            GROUP BY t.id, u.name
            ORDER BY t.created_at DESC
        ");
        respond(["success" => true, "tests" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    case 'upload_test':
        $user = auth_user($conn);
        require_admin($user);
        $normalized = normalize_upload_payload($body);
        if (empty($normalized['questions'])) {
            respond(["success" => false, "message" => "No valid questions provided."], 422);
        }
        try {
            $conn->beginTransaction();
            $stmt = $conn->prepare("
                INSERT INTO mcq_tests (title, description, topic, total_questions, time_per_question, created_by)
                VALUES (:title, :desc, :topic, :total, :tpq, :created_by)
                RETURNING id
            ");
            $stmt->execute([
                ':title' => $normalized['title'],
                ':desc' => $normalized['description'],
                ':topic' => $normalized['topic'],
                ':total' => count($normalized['questions']),
                ':tpq' => $normalized['time_per_question'],
                ':created_by' => $user['id']
            ]);
            $testId = $stmt->fetchColumn();
            $qstmt = $conn->prepare("
                INSERT INTO mcq_questions (test_id, question_no, topic, question, options, correct_answer, explanation)
                VALUES (:tid, :qno, :topic, :question, :options, :correct_answer, :explanation)
            ");
            foreach ($normalized['questions'] as $i => $q) {
                $qstmt->execute([
                    ':tid' => $testId,
                    ':qno' => $i + 1,
                    ':topic' => $q['topic'] ?: $normalized['topic'],
                    ':question' => $q['question'],
                    ':options' => json_encode($q['options']),
                    ':correct_answer' => $q['correct_answer'],
                    ':explanation' => $q['explanation']
                ]);
            }
            $conn->commit();
            respond(["success" => true, "test_id" => intval($testId), "message" => "Test uploaded successfully."]);
        } catch (PDOException $e) {
            $conn->rollBack();
            respond(["success" => false, "message" => $e->getMessage()], 500);
        }
        break;

    case 'get_test':
        $testId = intval($body['test_id'] ?? $_GET['test_id'] ?? 0);
        if (!$testId) respond(["success" => false, "message" => "Missing test_id"], 422);
        $test = fetch_test($conn, $testId);
        if (!$test) respond(["success" => false, "message" => "Test not found"], 404);
        respond(["success" => true, "test" => $test, "questions" => fetch_questions($conn, $testId)]);
        break;

    case 'delete_test':
        $user = auth_user($conn);
        require_admin($user);
        $testId = intval($body['test_id'] ?? 0);
        if (!$testId) respond(["success" => false, "message" => "Missing test_id"], 422);
        $conn->prepare("DELETE FROM mcq_results WHERE test_id = :id")->execute([':id' => $testId]);
        $conn->prepare("DELETE FROM mcq_tests WHERE id = :id")->execute([':id' => $testId]);
        respond(["success" => true, "message" => "Test deleted."]);
        break;

    case 'save_result':
        $user = auth_user($conn);
        $testId = intval($body['test_id'] ?? 0);
        $score = intval($body['score'] ?? 0);
        $total = intval($body['total'] ?? 0);
        $correct = intval($body['correct'] ?? 0);
        $wrong = intval($body['wrong'] ?? 0);
        $skipped = intval($body['skipped'] ?? 0);
        $timeTaken = intval($body['time_taken'] ?? 0);
        $answers = $body['answers'] ?? [];
        $stmt = $conn->prepare("
            INSERT INTO mcq_results (test_id, user_id, score, total, correct, wrong, skipped, time_taken, answers)
            VALUES (:test_id, :user_id, :score, :total, :correct, :wrong, :skipped, :time_taken, :answers)
            RETURNING id
        ");
        $stmt->execute([
            ':test_id' => $testId,
            ':user_id' => $user['id'],
            ':score' => $score,
            ':total' => $total,
            ':correct' => $correct,
            ':wrong' => $wrong,
            ':skipped' => $skipped,
            ':time_taken' => $timeTaken,
            ':answers' => json_encode($answers)
        ]);
        respond(['success' => true, 'result_id' => intval($stmt->fetchColumn())]);
        break;

    case 'get_history':
        $user = auth_user($conn);
        $testId = intval($_GET['test_id'] ?? $body['test_id'] ?? 0);
        if ($testId) {
            $stmt = $conn->prepare("
                SELECT r.*, t.title, t.topic
                FROM mcq_results r JOIN mcq_tests t ON t.id = r.test_id
                WHERE r.user_id = :user_id AND r.test_id = :test_id
                ORDER BY r.taken_at DESC LIMIT 20
            ");
            $stmt->execute([':user_id' => $user['id'], ':test_id' => $testId]);
        } else {
            $stmt = $conn->prepare("
                SELECT r.*, t.title, t.topic
                FROM mcq_results r JOIN mcq_tests t ON t.id = r.test_id
                WHERE r.user_id = :user_id
                ORDER BY r.taken_at DESC LIMIT 50
            ");
            $stmt->execute([':user_id' => $user['id']]);
        }
        respond(['success' => true, 'results' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    case 'get_dashboard':
        $user = auth_user($conn);
        $summaryStmt = $conn->prepare("
            SELECT
                COUNT(*) AS attempts,
                COALESCE(AVG(score), 0) AS avg_score,
                COALESCE(SUM(correct), 0) AS total_correct,
                COALESCE(SUM(wrong), 0) AS total_wrong,
                COALESCE(SUM(skipped), 0) AS total_skipped,
                COALESCE(SUM(time_taken), 0) AS total_time
            FROM mcq_results WHERE user_id = :user_id
        ");
        $summaryStmt->execute([':user_id' => $user['id']]);
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

        $topicStmt = $conn->prepare("
            SELECT
                topic_stats.topic,
                SUM(topic_stats.correct_count) AS correct_count,
                SUM(topic_stats.total_count) AS total_count
            FROM (
                SELECT
                    ans->>'topic' AS topic,
                    CASE WHEN (ans->>'wasCorrect') = 'true' THEN 1 ELSE 0 END AS correct_count,
                    1 AS total_count
                FROM mcq_results r,
                LATERAL jsonb_array_elements(r.answers) AS ans
                WHERE r.user_id = :user_id
            ) topic_stats
            GROUP BY topic_stats.topic
            ORDER BY total_count DESC, correct_count DESC
        ");
        $topicStmt->execute([':user_id' => $user['id']]);
        $topics = $topicStmt->fetchAll(PDO::FETCH_ASSOC);

        $recentStmt = $conn->prepare("
            SELECT r.*, t.title, t.topic
            FROM mcq_results r JOIN mcq_tests t ON t.id = r.test_id
            WHERE r.user_id = :user_id
            ORDER BY r.taken_at DESC LIMIT 8
        ");
        $recentStmt->execute([':user_id' => $user['id']]);

        respond([
            'success' => true,
            'summary' => $summary,
            'topics' => $topics,
            'recent' => $recentStmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
        break;

    case 'get_profile':
        $user = auth_user($conn);
        $statsStmt = $conn->prepare("
            SELECT COUNT(*) AS attempts, COALESCE(AVG(score), 0) AS avg_score, MAX(taken_at) AS last_attempt
            FROM mcq_results WHERE user_id = :user_id
        ");
        $statsStmt->execute([':user_id' => $user['id']]);
        respond([
            'success' => true,
            'user' => $user,
            'stats' => $statsStmt->fetch(PDO::FETCH_ASSOC)
        ]);
        break;

    case 'admin_overview':
        $user = auth_user($conn);
        require_admin($user);
        $stats = [];
        $stats['users'] = intval($conn->query("SELECT COUNT(*) FROM app_users")->fetchColumn());
        $stats['students'] = intval($conn->query("SELECT COUNT(*) FROM app_users WHERE role = 'student'")->fetchColumn());
        $stats['admins'] = intval($conn->query("SELECT COUNT(*) FROM app_users WHERE role = 'admin'")->fetchColumn());
        $stats['tests'] = intval($conn->query("SELECT COUNT(*) FROM mcq_tests")->fetchColumn());
        $stats['questions'] = intval($conn->query("SELECT COUNT(*) FROM mcq_questions")->fetchColumn());
        $stats['attempts'] = intval($conn->query("SELECT COUNT(*) FROM mcq_results")->fetchColumn());
        respond(['success' => true, 'stats' => $stats]);
        break;

    case 'admin_list_users':
        $user = auth_user($conn);
        require_admin($user);
        $stmt = $conn->query("
            SELECT u.id, u.name, u.email, u.role, u.created_at, COUNT(r.id) AS attempts
            FROM app_users u LEFT JOIN mcq_results r ON r.user_id = u.id
            GROUP BY u.id ORDER BY u.created_at DESC
        ");
        respond(['success' => true, 'users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    case 'admin_update_user_role':
        $user = auth_user($conn);
        require_admin($user);
        $targetId = intval($body['user_id'] ?? 0);
        $role = trim((string)($body['role'] ?? 'student'));
        if (!$targetId || !in_array($role, ['admin', 'student'], true)) {
            respond(['success' => false, 'message' => 'Valid user and role are required.'], 422);
        }
        $stmt = $conn->prepare("UPDATE app_users SET role = :role WHERE id = :id");
        $stmt->execute([':role' => $role, ':id' => $targetId]);
        respond(['success' => true, 'message' => 'User role updated.']);
        break;

    case 'admin_delete_user':
        $user = auth_user($conn);
        require_admin($user);
        $targetId = intval($body['user_id'] ?? 0);
        if (!$targetId || $targetId === intval($user['id'])) {
            respond(['success' => false, 'message' => 'Cannot delete this user.'], 422);
        }
        $conn->prepare("DELETE FROM mcq_results WHERE user_id = :id")->execute([':id' => $targetId]);
        $conn->prepare("DELETE FROM app_users WHERE id = :id")->execute([':id' => $targetId]);
        respond(['success' => true, 'message' => 'User deleted.']);
        break;

    case 'admin_list_tests':
        $user = auth_user($conn);
        require_admin($user);
        $stmt = $conn->query("
            SELECT t.*, u.name AS creator_name, COUNT(q.id) AS question_count
            FROM mcq_tests t
            LEFT JOIN mcq_questions q ON q.test_id = t.id
            LEFT JOIN app_users u ON u.id = t.created_by
            GROUP BY t.id, u.name ORDER BY t.created_at DESC
        ");
        respond(['success' => true, 'tests' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    case 'admin_get_test_editor':
        $user = auth_user($conn);
        require_admin($user);
        $testId = intval($body['test_id'] ?? $_GET['test_id'] ?? 0);
        if (!$testId) respond(['success' => false, 'message' => 'Missing test_id'], 422);
        $test = fetch_test($conn, $testId);
        if (!$test) respond(['success' => false, 'message' => 'Test not found'], 404);
        respond(['success' => true, 'test' => $test, 'questions' => fetch_questions($conn, $testId)]);
        break;

    case 'admin_save_question':
        $user = auth_user($conn);
        require_admin($user);
        $testId = intval($body['test_id'] ?? 0);
        $questionId = intval($body['question_id'] ?? 0);
        $normalized = normalize_question($body, trim((string)($body['topic'] ?? '')));
        if (!$testId || !$normalized) {
            respond(['success' => false, 'message' => 'Valid test and question fields are required.'], 422);
        }
        if ($questionId) {
            $stmt = $conn->prepare("
                UPDATE mcq_questions
                SET topic = :topic, question = :question, options = :options,
                    correct_answer = :correct_answer, explanation = :explanation
                WHERE id = :id AND test_id = :test_id
            ");
            $stmt->execute([
                ':topic' => $normalized['topic'],
                ':question' => $normalized['question'],
                ':options' => json_encode($normalized['options']),
                ':correct_answer' => $normalized['correct_answer'],
                ':explanation' => $normalized['explanation'],
                ':id' => $questionId,
                ':test_id' => $testId
            ]);
            respond(['success' => true, 'message' => 'Question updated.']);
        }
        $nextNoStmt = $conn->prepare("SELECT COALESCE(MAX(question_no), 0) + 1 FROM mcq_questions WHERE test_id = :test_id");
        $nextNoStmt->execute([':test_id' => $testId]);
        $nextNo = intval($nextNoStmt->fetchColumn());
        $stmt = $conn->prepare("
            INSERT INTO mcq_questions (test_id, question_no, topic, question, options, correct_answer, explanation)
            VALUES (:test_id, :question_no, :topic, :question, :options, :correct_answer, :explanation)
        ");
        $stmt->execute([
            ':test_id' => $testId,
            ':question_no' => $nextNo,
            ':topic' => $normalized['topic'],
            ':question' => $normalized['question'],
            ':options' => json_encode($normalized['options']),
            ':correct_answer' => $normalized['correct_answer'],
            ':explanation' => $normalized['explanation']
        ]);
        $countStmt = $conn->prepare("UPDATE mcq_tests SET total_questions = (SELECT COUNT(*) FROM mcq_questions WHERE test_id = :test_id) WHERE id = :test_id");
        $countStmt->execute([':test_id' => $testId]);
        respond(['success' => true, 'message' => 'Question added.']);
        break;

    case 'admin_delete_question':
        $user = auth_user($conn);
        require_admin($user);
        $testId = intval($body['test_id'] ?? 0);
        $questionId = intval($body['question_id'] ?? 0);
        if (!$testId || !$questionId) {
            respond(['success' => false, 'message' => 'Missing ids.'], 422);
        }
        $conn->prepare("DELETE FROM mcq_questions WHERE id = :id AND test_id = :test_id")
            ->execute([':id' => $questionId, ':test_id' => $testId]);
        $countStmt = $conn->prepare("UPDATE mcq_tests SET total_questions = (SELECT COUNT(*) FROM mcq_questions WHERE test_id = :test_id) WHERE id = :test_id");
        $countStmt->execute([':test_id' => $testId]);
        respond(['success' => true, 'message' => 'Question deleted.']);
        break;

    default:
        respond(["success" => false, "message" => "Unknown action: $action"], 404);
}
?>
